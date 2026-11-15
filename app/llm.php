<?php
/**
 * LLM 网关 + 一轮对话编排（2.1）
 * 上游：任意 OpenAI 兼容 /chat/completions（含免 Key 的 OVH / Pollinations）
 * 支持：原生 function calling 工具循环、纯文本 TOOL_CALL 降级协议、400 自动瘦身重试
 *       ★ 多线路自动故障转移（429 / 5xx / 连接失败自动换线路 + 冷却）
 *       ★ 全部线路失败时用本地插件兜底回答确定性问题
 */
declare(strict_types=1);

function llm_config(): array {
    $c = cfg();
    $l = $c['llm'];
    return [
        'base_url' => rtrim((string) ($l['base_url'] ?? ''), '/'),
        'model' => (string) ($l['model'] ?? ''),
        'api_key' => (string) ($l['api_key'] ?? ''),
        'auth_mode' => (string) ($l['auth_mode'] ?? 'bearer'),
        'timeout' => max(10, (int) ($l['timeout'] ?? 90)),
        'extra_headers' => is_array($l['extra_headers'] ?? null) ? $l['extra_headers'] : [],
        'models' => is_array($l['models'] ?? null) ? $l['models'] : [],
    ];
}

function llm_endpoint(string $base): string {
    if ($base === '') return '';
    if (preg_match('~/chat/completions$~', $base)) return $base;
    return $base . '/chat/completions';
}

function llm_headers(array $l): array {
    $h = ['Accept: application/json'];
    switch ($l['auth_mode']) {
        case 'none': break;
        case 'bearer-empty': $h[] = 'Authorization: Bearer'; break; // OVH：带 token 反而 403
        case 'x-api-key':
            if ($l['api_key'] !== '') $h[] = 'X-API-Key: ' . $l['api_key'];
            break;
        case 'bearer':
        default:
            if ($l['api_key'] !== '') $h[] = 'Authorization: Bearer ' . $l['api_key'];
            break;
    }
    foreach ($l['extra_headers'] as $k => $v) {
        if (!is_string($k) || is_int($k)) continue;
        $h[] = $k . ': ' . (string) $v;
    }
    return $h;
}

/** 由 index.php 注入 Store，用于跨请求共享线路健康状态 */
function llm_set_store(?Store $store = null): void { $GLOBALS['DSH_LLM_STORE'] = $store; }

// ── 线路配置 ────────────────────────────────────────────────────────────────
function llm_routing(): array {
    $c = cfg();
    $r = is_array($c['routing'] ?? null) ? $c['routing'] : [];
    return [
        'mode' => (string) ($r['mode'] ?? 'auto') === 'fixed' ? 'fixed' : 'auto',
        'cooldown' => max(0, min(3600, (int) ($r['cooldown'] ?? 120))),
        'max_switches' => max(0, min(6, (int) ($r['max_switches'] ?? 3))),
        'local_fallback' => !array_key_exists('local_fallback', $r) || !empty($r['local_fallback']),
        'candidates' => is_array($r['candidates'] ?? null) ? $r['candidates'] : [],
    ];
}

/** 把任意配置数组规范化成一条线路 */
function llm_route_normalize(array $a, string $key, string $name): ?array {
    $base = rtrim(trim((string) ($a['base_url'] ?? '')), '/');
    $model = trim((string) ($a['model'] ?? ''));
    if ($base === '' || $model === '') return null;
    return [
        'key' => $key, 'name' => $name !== '' ? $name : $key,
        'base_url' => $base, 'model' => $model,
        'api_key' => (string) ($a['api_key'] ?? ''),
        'auth_mode' => (string) ($a['auth_mode'] ?? 'bearer'),
        'timeout' => max(10, (int) ($a['timeout'] ?? 90)),
        'extra_headers' => is_array($a['extra_headers'] ?? null) ? $a['extra_headers'] : [],
        'enabled' => !array_key_exists('enabled', $a) || !empty($a['enabled']),
    ];
}

function llm_route_id(array $r): string {
    return substr(md5($r['base_url'] . '|' . $r['model'] . '|' . $r['auth_mode'] . '|' . $r['api_key']), 0, 16);
}

/**
 * 候选线路：主路由永远第一；其后是用户自定义候选，或（默认）所有免 Key 预设。
 * $all=true 时返回包含禁用线路的清单（给设置页用）。
 */
function llm_routes(bool $all = false): array {
    $c = cfg();
    $rt = llm_routing();
    $primary = llm_route_normalize($c['llm'], 'primary', '主路由 · ' . (string) ($c['route'] ?: '自定义'));
    $out = [];
    $seen = [];
    if ($primary) {
        $primary['enabled'] = true;
        $out[] = $primary;
        $seen[llm_route_id($primary)] = true;
    }
    if ($rt['mode'] === 'fixed' && !$all) return $out;

    $auto = !$rt['candidates'];
    $list = $auto ? array_filter(preset_routes(), fn($p) => is_array($p) && !empty($p['keyless'])) : $rt['candidates'];
    foreach ($list as $i => $cand) {
        if (!is_array($cand)) continue;
        // 自动模式下用预设 id 作 key（稳定，便于设置页按预设还原公开凭据）
        $key = $auto ? (string) $i : (string) ($cand['key'] ?? ('cand_' . $i));
        $r = llm_route_normalize($cand, $key, (string) ($cand['name'] ?? $key));
        if (!$r) continue;
        $id = llm_route_id($r);
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        if ($all || $r['enabled']) $out[] = $r;
    }
    return $out;
}

// ── 线路健康状态（跨请求共享；无 Store 时退回 session）─────────────────────
function llm_health(): array {
    $s = $GLOBALS['DSH_LLM_STORE'] ?? null;
    $v = ($s instanceof Store) ? $s->kvGet('route.health', []) : ($_SESSION['route.health'] ?? []);
    return is_array($v) ? $v : [];
}
function llm_health_save(array $h): void {
    $s = $GLOBALS['DSH_LLM_STORE'] ?? null;
    if ($s instanceof Store) $s->kvSet('route.health', $h);
    else $_SESSION['route.health'] = $h;
}
function llm_mark_fail(string $id, int $cooldown, string $reason): void {
    if ($cooldown <= 0) return;
    $h = llm_health();
    $now = time();
    $h[$id] = [
        'until' => $now + $cooldown,
        'reason' => str_cut($reason, 120),
        'fails' => (int) ($h[$id]['fails'] ?? 0) + 1,
        'at' => $now,
    ];
    foreach ($h as $k => $v) {
        if (!is_array($v) || (int) ($v['until'] ?? 0) < $now - 3600) unset($h[$k]);
    }
    llm_health_save($h);
}
function llm_mark_ok(string $id): void {
    $h = llm_health();
    if (!isset($h[$id])) return;
    unset($h[$id]);
    llm_health_save($h);
}
function llm_health_reset(): void { llm_health_save([]); }

/** 给设置页看的线路清单（绝不暴露 API Key / 数据文件名） */
function llm_routes_public(): array {
    $h = llm_health();
    $now = time();
    $out = [];
    foreach (llm_routes(true) as $r) {
        $id = llm_route_id($r);
        $cd = max(0, (int) ($h[$id]['until'] ?? 0) - $now);
        $out[] = [
            'key' => $r['key'], 'name' => $r['name'], 'model' => $r['model'],
            'base_url' => $r['base_url'], 'enabled' => (bool) $r['enabled'],
            'auth_mode' => $r['auth_mode'], 'has_key' => $r['api_key'] !== '',
            'cooldown_left' => $cd, 'fails' => (int) ($h[$id]['fails'] ?? 0),
            'last_error' => (string) ($h[$id]['reason'] ?? ''),
            'primary' => $r['key'] === 'primary',
        ];
    }
    return $out;
}

/** 这些错误换线路有意义；400/401/403/404 属于请求或配置问题，不盲目乱切 */
function llm_is_switchable(int $status): bool {
    // 402 是 Pollinations 免费额度用尽（1 reply / 45s per IP），换下一条仍可能可用
    return $status === 0 || $status === 402 || $status === 408 || $status === 429 || $status >= 500;
}

/** 把上游报错翻译成短中文，绝不把整段 JSON 甩给用户 */
function llm_http_error(int $status, string $body): string {
    $msg = '';
    $j = json_decode($body, true);
    if (is_array($j)) {
        $e = $j['error'] ?? null;
        $msg = is_array($e) ? (string) ($e['message'] ?? '') : (string) ($e ?: ($j['message'] ?? ''));
    }
    $map = [
        0 => '连接失败或超时', 401 => '鉴权失败（API Key 无效）', 402 => '免费额度已用完（上游限流）',
        403 => '上游拒绝访问（鉴权方式可能不对）',
        404 => '上游接口不存在（检查 Base URL）', 408 => '上游请求超时', 429 => '上游限流（请求过于频繁）',
        500 => '上游服务内部错误', 502 => '上游网关错误', 503 => '上游服务暂时不可用', 504 => '上游网关超时',
    ];
    $text = $map[$status] ?? ('上游 HTTP ' . $status);
    $detail = trim(preg_replace('/\s+/u', ' ', strip_tags($msg)) ?: '');
    if ($detail === '') $detail = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?: '');
    if ($detail !== '') $text .= '（' . str_cut($detail, 90) . '）';
    return $text;
}

// ── 单次上游请求 ───────────────────────────────────────────────────────────
/**
 * 对一条指定线路发一次 /chat/completions。
 * 返回 ['ok','status','content','tool_calls','usage','raw','error']
 */
function llm_chat_once(array $messages, array $opt, array $route): array {
    $endpoint = llm_endpoint($route['base_url']);
    $out = ['ok' => false, 'status' => 0, 'content' => '', 'tool_calls' => [], 'usage' => [],
        'raw' => [], 'error' => '', 'brief' => ''];
    if ($endpoint === '' || $route['model'] === '') {
        $out['error'] = '线路未配置 base_url / model';
        return $out;
    }
    // 只有主路由允许本次请求携带的 model 覆盖；备用线路必须用自己支持的模型名
    $model = ($route['key'] === 'primary' && !empty($opt['model'])) ? (string) $opt['model'] : $route['model'];
    $payload = ['model' => $model, 'messages' => $messages, 'stream' => !empty($opt['stream'])];
    if (isset($opt['temperature'])) $payload['temperature'] = (float) $opt['temperature'];
    if (isset($opt['max_tokens'])) $payload['max_tokens'] = (int) $opt['max_tokens'];
    if (!empty($opt['tools'])) {
        $payload['tools'] = array_values($opt['tools']);
        $payload['tool_choice'] = 'auto';
    }

    $r = http_request($endpoint, [
        'method' => 'POST',
        'headers' => llm_headers($route),
        'body' => $payload,
        'timeout' => (int) ($opt['timeout'] ?? $route['timeout']),
    ]);
    $out['status'] = $r['status'];
    if (!$r['ok'] && $r['status'] === 0) {
        // brief 是脱敏后的中文原因（如「PHP 未启用 openssl 扩展」），不会把服务器路径带进聊天记录
        $out['brief'] = (string) ($r['brief'] ?? http_error_brief((string) $r['error']));
        $out['error'] = '连接失败：' . str_cut($out['brief'], 120);
        return $out;
    }
    $j = json_decode($r['body'], true);
    if (!is_array($j)) {
        $out['error'] = '上游返回不是 JSON（HTTP ' . $r['status'] . '）';
        return $out;
    }
    $out['raw'] = $j;
    $out['usage'] = is_array($j['usage'] ?? null) ? $j['usage'] : [];
    if (!$r['ok']) {
        $out['error'] = llm_http_error($r['status'], $r['body']);
        return $out;
    }
    $msg = $j['choices'][0]['message'] ?? [];
    if (!is_array($msg)) $msg = [];
    $content = $msg['content'] ?? '';
    if (is_array($content)) {
        $buf = '';
        foreach ($content as $seg) {
            if (is_array($seg) && isset($seg['text'])) $buf .= (string) $seg['text'];
            elseif (is_string($seg)) $buf .= $seg;
        }
        $content = $buf;
    }
    if ($content === '' || $content === null) $content = (string) ($msg['reasoning_content'] ?? '');
    $out['content'] = (string) $content;
    $tc = $msg['tool_calls'] ?? [];
    $out['tool_calls'] = is_array($tc) ? $tc : [];
    $out['ok'] = true;
    return $out;
}

/** 汇总多次尝试，生成给用户看的中文说明（带具体原因，不再一律写「连不上」） */
function llm_failure_message(array $tried): string {
    if (!$tried) return '没有可用的模型线路，请到设置页填写 base_url / model';
    $parts = [];
    foreach ($tried as $t) {
        $st = (int) ($t['status'] ?? 0);
        $brief = (string) ($t['brief'] ?? '');
        $why = ($st === 429 || $st === 402) ? '限流'
            : ($st === 0 ? ($brief !== '' ? $brief : '连不上') : 'HTTP ' . $st);
        $parts[] = (string) ($t['name'] ?? '线路') . '（' . $why . '）';
    }
    return '所有模型线路都不可用（' . implode('、', $parts) . '）';
}

/**
 * 主入口：按候选顺序尝试；429/5xx/连接失败自动换下一条并冷却失败线路。
 * 返回 llm_chat_once 的结构 + ['route'=>..., 'tried'=>[...]]
 */
function llm_chat(array $messages, array $opt = [], ?callable $emit = null): array {
    $rt = llm_routing();
    $routes = llm_routes();
    if (!$routes) {
        return ['ok' => false, 'status' => 0, 'content' => '', 'tool_calls' => [], 'usage' => [],
            'raw' => [], 'error' => '未配置模型路由，请到设置页填写 base_url / model',
            'route' => [], 'tried' => []];
    }
    if (!empty($opt['route_key'])) {
        $found = array_values(array_filter($routes, fn($r) => $r['key'] === (string) $opt['route_key']));
        if ($found) $routes = $found;
    }

    $h = llm_health();
    $now = time();
    $ready = [];
    $cooling = [];
    foreach ($routes as $r) {
        $id = llm_route_id($r);
        if ((int) ($h[$id]['until'] ?? 0) > $now) $cooling[] = $r;
        else $ready[] = $r;
    }
    if (!$ready && $cooling) {
        // 全部在冷却：挑最早恢复的那条试一次（可能已经解封）
        usort($cooling, fn($a, $b) => (int) ($h[llm_route_id($a)]['until'] ?? 0) <=> (int) ($h[llm_route_id($b)]['until'] ?? 0));
        $ready = [$cooling[0]];
    }
    $max = min(count($ready), 1 + $rt['max_switches']);
    $tried = [];
    $last = null;
    for ($i = 0; $i < $max; $i++) {
        $route = $ready[$i];
        $res = llm_chat_once($messages, $opt, $route);
        $res['route'] = [
            'key' => $route['key'], 'name' => $route['name'],
            'model' => $route['model'], 'base_url' => $route['base_url'],
        ];
        $tried[] = [
            'key' => $route['key'], 'name' => $route['name'],
            'status' => $res['status'], 'ok' => $res['ok'], 'error' => $res['error'],
            'brief' => (string) ($res['brief'] ?? ''),
        ];
        if ($res['ok']) {
            llm_mark_ok(llm_route_id($route));
            if ($i > 0) {
                $note = '主线路不可用，已自动切换到 ' . $route['name'];
                if ($emit !== null) $emit('stage', ['stage' => 'switch', 'note' => $note, 'route' => $res['route']]);
            }
            $res['tried'] = $tried;
            return $res;
        }
        $last = $res;
        $switchable = llm_is_switchable($res['status']);
        if ($switchable) llm_mark_fail(llm_route_id($route), $rt['cooldown'], $res['error']);
        if (!$switchable) break; // 配置/请求错误，换线路也没用
        if ($emit !== null && $i + 1 < $max) {
            $emit('stage', ['stage' => 'switching', 'note' => $res['error'] . '，正在尝试下一条线路…']);
        }
    }
    $last['tried'] = $tried;
    // 汇总单独存一份，绝不覆盖 $last['error']：那里面是最后一条线路的真实原因，
    // 一旦被汇总文案盖掉，用户就只看到「连不上」，永远查不到 DNS / TLS / 扩展缺失。
    $last['failure_summary'] = $tried ? llm_failure_message($tried) : $last['error'];
    return $last;
}

/** 从纯文本回复里解析 TOOL_CALL {"name":...,"arguments":{...}} */
function llm_parse_text_tool(string $text): ?array {
    if (!preg_match('/TOOL_CALL\s*(\{[^\n]*\})/i', $text, $m)) return null;
    $j = json_decode($m[1], true);
    if (!is_array($j) || empty($j['name'])) return null;
    return [
        'name' => (string) $j['name'],
        'arguments' => is_array($j['arguments'] ?? null) ? $j['arguments'] : [],
        'raw_line' => $m[0],
    ];
}

function llm_available_models(): array {
    $l = llm_config();
    if ($l['models']) return array_values($l['models']);
    return [$l['model'] !== '' ? $l['model'] : 'default'];
}

/**
 * 一键从上游拉取可用模型列表（OpenAI 兼容 /models 端点）。
 * 优先用传入的 base_url / auth_mode / api_key，缺失时回退到当前配置。
 * 兼容几种返回形状：{data:[{id}]}（OpenAI / DeepSeek / OpenRouter）、
 * {models:[...]}、以及纯数组。返回 ['ok'=>bool,'models'=>[...],'error'=>string]
 */
function llm_fetch_models(?string $base = null, ?string $authMode = null, ?string $key = null): array {
    $l = llm_config();
    if ($base !== null && $base !== '') $l['base_url'] = rtrim($base, '/');
    if ($authMode !== null && $authMode !== '') $l['auth_mode'] = $authMode;
    if ($key !== null && $key !== '') $l['api_key'] = $key;
    $base = rtrim((string) $l['base_url'], '/');
    if ($base === '') return ['ok' => false, 'error' => '请先在上方填写 API Base URL'];
    // 与 chat 端点约定一致：base_url 已是「/v1」这一层，直接拼 /models
    $url = $base . '/models';
    $r = http_request($url, [
        'method' => 'GET',
        'headers' => llm_headers($l),
        'timeout' => 25,
    ]);
    if (!$r['ok']) {
        $err = $r['status'] ? ('HTTP ' . $r['status']) : ($r['brief'] ?: '连接失败');
        return ['ok' => false, 'error' => '获取模型列表失败：' . $err];
    }
    $j = json_decode($r['body'], true);
    if (!is_array($j)) return ['ok' => false, 'error' => '上游返回不是 JSON（HTTP ' . $r['status'] . '）'];
    $ids = llm_parse_models($j);
    if (!$ids) return ['ok' => false, 'error' => '上游未返回任何模型（该端点可能不支持列出模型）'];
    return ['ok' => true, 'models' => $ids];
}

/** 从上游 /models 返回里提取模型 ID；兼容 {data:[{id}]} / {models:[...]} / 纯数组 */
function llm_parse_models(array $j): array {
    $ids = [];
    $grab = function (array $arr) use (&$ids): void {
        foreach ($arr as $m) {
            if (is_array($m) && isset($m['id'])) $ids[] = (string) $m['id'];
            elseif (is_string($m) || is_numeric($m)) $ids[] = (string) $m;
        }
    };
    if (isset($j['data']) && is_array($j['data'])) $grab($j['data']);
    elseif (isset($j['models']) && is_array($j['models'])) $grab($j['models']);
    elseif (isset($j[0])) $grab($j); // 纯数组
    return array_values(array_unique(array_filter($ids, function ($x): bool { return $x !== ''; })));
}

/** 测试单条指定配置（不走故障转移，便于定位问题） */
function llm_test(?string $base = null, ?string $model = null, ?string $key = null, ?string $authMode = null): array {
    $l = llm_config();
    if ($base !== null && $base !== '') $l['base_url'] = rtrim($base, '/');
    if ($model !== null && $model !== '') $l['model'] = $model;
    if ($key !== null) $l['api_key'] = $key;
    if ($authMode !== null && $authMode !== '') $l['auth_mode'] = $authMode;
    $route = llm_route_normalize($l, 'test', '测试线路');
    if (!$route) return ['ok' => false, 'status' => 0, 'error' => '缺少 base_url / model', 'reply' => ''];
    $route['timeout'] = min(25, $route['timeout']);
    $r = llm_chat_once([['role' => 'user', 'content' => '只回复 OK 两个字母']], [], $route);
    return [
        'ok' => $r['ok'] && trim($r['content']) !== '',
        'status' => $r['status'],
        'reply' => str_cut(trim($r['content']), 120),
        'error' => $r['ok'] ? (trim($r['content']) === '' ? '上游未返回内容' : '') : $r['error'],
    ];
}

/** 逐条测试所有启用线路（设置页「测试全部线路」） */
function llm_test_all(): array {
    $out = [];
    foreach (llm_routes(true) as $r) {
        if (!$r['enabled']) {
            $out[] = ['key' => $r['key'], 'name' => $r['name'], 'ok' => false, 'status' => 0, 'error' => '已停用', 'skipped' => true];
            continue;
        }
        $route = $r;
        $route['timeout'] = min(25, $route['timeout']);
        $res = llm_chat_once([['role' => 'user', 'content' => '只回复 OK 两个字母']], [], $route);
        $out[] = [
            'key' => $r['key'], 'name' => $r['name'], 'model' => $r['model'],
            'ok' => $res['ok'] && trim($res['content']) !== '',
            'status' => $res['status'], 'reply' => str_cut(trim($res['content']), 60),
            'error' => $res['ok'] ? '' : $res['error'],
        ];
    }
    return $out;
}

/** 全部线路失败、且本地插件也答不了时，给用户看的排障说明（按失败类型给不同建议） */
function llm_offline_message(array $tried, string $lastErr): string {
    $parts = [];
    $allConnectFail = !empty($tried);
    $rate = false; $auth = false; $upstream = false;
    foreach ($tried as $t) {
        $st = (int) ($t['status'] ?? 0);
        $brief = (string) ($t['brief'] ?? '');
        if ($st === 429 || $st === 402) $rate = true;
        if ($st === 401 || $st === 403) $auth = true;
        if ($st >= 500) $upstream = true;
        if ($st !== 0) $allConnectFail = false;
        $why = ($st === 429 || $st === 402) ? '限流'
            : ($st === 0 ? ($brief !== '' ? $brief : '连不上') : 'HTTP ' . $st);
        $parts[] = (string) ($t['name'] ?? '线路') . '：' . $why;
    }
    $s = "⚠️ 模型线路暂时无法使用，本次没有生成回复。\n\n";
    if ($parts) $s .= "已尝试：" . implode('、', $parts) . "\n\n";

    if ($allConnectFail) {
        $s .= "这不是免费接口限流，而是「服务器根本没连上上游」（PHP 扩展 / CA 证书 / DNS / 出站网络）。可以：\n"
            . "· 访问 ping.php?net=1 做一次服务器侧网络自检，会直接指出卡在哪一层\n"
            . "· 检查服务器 PHP 是否启用 curl 与 openssl、是否配置了 CA 根证书\n"
            . "· 若是出境网络不通，填一个国内可直连的 API Key（DeepSeek / 硅基流动等）最稳\n";
    } elseif ($auth) {
        $s .= "看起来是鉴权问题（Key 无效或鉴权方式不对）。可以：\n"
            . "· 在「设置 → 线路」点「测试全部线路」，看每条的具体报错\n"
            . "· 核对 API Key / Base URL / 鉴权方式，或换成自己的 Key\n";
    } elseif ($rate) {
        $s .= "免费接口按 IP 限流很常见，通常等 2~5 分钟会自动恢复。可以：\n"
            . "· 在「设置 → 线路」点「测试全部线路」，找一条当前可用的\n"
            . "· 或填入自己的 API Key（DeepSeek / OpenRouter / 硅基流动等），最稳定\n";
    } elseif ($upstream) {
        $s .= "上游服务暂时故障（5xx），稍等几分钟再试。可以：\n"
            . "· 在「设置 → 线路」点「测试全部线路」，确认哪条已恢复\n";
    } else {
        $s .= "· 在「设置 → 线路」点「测试全部线路」，看每条的具体报错\n"
            . "· 或填入自己的 API Key（DeepSeek / OpenRouter / 硅基流动等），最稳定\n";
    }
    $s .= "· 算式、时间这类问题即使模型全挂，本站也能用本地插件直接回答\n";
    if ($lastErr !== '') $s .= "\n技术细节：" . str_cut($lastErr, 180);
    return $s;
}

/**
 * 本地兜底：不消耗模型额度，用内置插件回答确定性问题。
 * 只在「所有线路都失败」时调用；答不了返回 null。
 */
function local_answer(Store $store, string $text): ?array {
    $s = trim($text);
    if ($s === '' || strlen($s) > 160) return null;
    $note = "\n\n【离线兜底】当前模型线路都不可用，这条由本站插件直接计算，不是模型回答。";

    if (preg_match('/(现在几点|几点了|当前时间|今天.*(日期|几号)|今天是.*号|星期几)/u', $s)) {
        $r = plugin_invoke($store, 'core.time', 'get_time', [], ['offline' => true]);
        if (!empty($r['ok']) && trim((string) ($r['text'] ?? '')) !== '') {
            return ['text' => trim((string) $r['text']) . $note, 'plugin' => 'core.time'];
        }
    }
    // 剥离提问词与尾部「=？」，剩下的必须是纯算式且含运算符
    $expr = preg_replace('/(请问|帮我|计算一下|计算|算一下|算下|算一算|等于多少|是多少|等于|结果|答案|是多少\？|？|\?|=|＝|\s|，|,)/u', '', $s) ?? '';
    if ($expr !== '' && strlen($expr) <= 120
        && preg_match('/^[0-9+\-*\/%^().a-zA-Z]+$/', $expr)
        && preg_match('/[0-9]/', $expr) && preg_match('/[+\-*\/%^]/', $expr)) {
        $r = plugin_invoke($store, 'core.calc', 'calculate', ['expression' => $expr], ['offline' => true]);
        if (!empty($r['ok']) && trim((string) ($r['text'] ?? '')) !== '') {
            return ['text' => trim((string) $r['text']) . $note, 'plugin' => 'core.calc'];
        }
    }
    return null;
}

/**
 * 完整一轮对话：历史 → 模型 → 工具循环 → 落库。
 * $emit 用于 SSE 阶段推送：function(string $event, array $data): void
 */
function chat_turn(Store $store, string $sid, string $userText, array $opt = [], ?callable $emit = null): array {
    llm_set_store($store);
    $c = cfg();
    $cc = $c['chat'];
    $session = $store->session($sid);
    if (!$session) return ['ok' => false, 'error' => '会话不存在'];
    $emit = $emit ?? (function (string $e, array $d): void {});

    $store->addMessage($sid, ['role' => 'user', 'content' => $userText]);
    // 首条消息自动生成标题
    $hist = $store->messages($sid, 0);
    if (count($hist) === 1) {
        $session['title'] = str_cut(preg_replace('/\s+/u', ' ', trim($userText)) ?: '新对话', 24);
        $session['updated'] = now_ms();
        $store->saveSession($session);
    }

    $tm = plugin_toolmap($store);
    $useTools = array_key_exists('tools', $opt)
        ? (bool) $opt['tools']
        : (!empty($cc['tools_enabled']) && !empty($tm['tools']));
    $system = (string) ($opt['system'] ?? '');
    if ($system === '') $system = (string) ($session['system'] ?? '');
    if ($system === '') $system = (string) ($cc['system_prompt'] ?? '你是 DSH，一个中文 AI 助手。');
    // 2.4.1：本轮消息 + 最近几条用户消息作为按需技能的触发上下文（多轮对话里不会突然失效）
    $skillCtx = [];
    foreach (array_slice($hist, -8) as $m) {
        if (($m['role'] ?? '') === 'user') $skillCtx[] = (string) ($m['content'] ?? '');
    }
    $system .= skill_prompt($store, str_cut(implode(' ', $skillCtx), 2000));
    $textMode = !empty($opt['text_tool_mode']);
    $promptInjected = false;
    if ($useTools && $textMode) { $system .= plugin_tools_prompt($store); $promptInjected = true; }

    $limit = max(2, (int) ($cc['history_limit'] ?? 20));
    $past = $store->messages($sid, $limit + 1);
    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($past as $m) {
        $role = (string) ($m['role'] ?? 'user');
        if (!in_array($role, ['user', 'assistant', 'tool', 'system'], true)) continue;
        $one = ['role' => $role, 'content' => (string) ($m['content'] ?? '')];
        if ($role === 'tool' && !empty($m['tool_call_id'])) $one['tool_call_id'] = (string) $m['tool_call_id'];
        if ($role === 'assistant' && !empty($m['tool_calls']) && is_array($m['tool_calls'])) $one['tool_calls'] = $m['tool_calls'];
        $messages[] = $one;
    }

    $maxRounds = max(1, min(8, (int) ($cc['max_tool_rounds'] ?? 4)));
    $toolLog = [];
    $final = '';
    $lastErr = '';
    $totalUsage = [];
    $nativeTools = $useTools && !$textMode;
    $shrunk = false;
    $routeUsed = [];
    $tried = [];

    for ($round = 0; $round <= $maxRounds; $round++) {
        $emit('stage', ['stage' => 'thinking', 'round' => $round + 1]);
        $reqOpt = [
            'temperature' => (float) ($opt['temperature'] ?? ($cc['temperature'] ?? 0.7)),
            'max_tokens' => (int) ($opt['max_tokens'] ?? ($cc['max_tokens'] ?? 2048)),
        ];
        if (!empty($opt['model'])) $reqOpt['model'] = (string) $opt['model'];
        if ($nativeTools) $reqOpt['tools'] = $tm['tools'];

        $res = llm_chat($messages, $reqOpt, $emit);
        if (!empty($res['route'])) $routeUsed = $res['route'];
        if (!empty($res['tried'])) $tried = $res['tried'];
        if (!$res['ok']) {
            $lastErr = $res['error'];
            // 免费端点常见：不支持 tools / temperature / max_tokens → 逐个瘦身重试
            if (in_array($res['status'], [400, 404, 422], true) && !$shrunk) {
                $shrunk = true;
                if ($nativeTools) {
                    $nativeTools = false;
                    $textMode = $useTools;
                    if (!$promptInjected) {
                        $messages[0]['content'] .= plugin_tools_prompt($store);
                        $promptInjected = true;
                    }
                    $emit('stage', ['stage' => 'fallback', 'note' => '上游不支持 function calling，已切换文本工具协议']);
                    $round--;
                    continue;
                }
                $res2 = llm_chat($messages, ['model' => (string) ($opt['model'] ?? llm_config()['model'])], $emit);
                if ($res2['ok']) { $res = $res2; $shrunk = true; }
            }
            if (!$res['ok']) break;
        }
        if (is_array($res['usage']) && $res['usage']) $totalUsage = $res['usage'];

        $toolCalls = [];
        if ($nativeTools && $res['tool_calls']) {
            foreach ($res['tool_calls'] as $tc) {
                if (!is_array($tc)) continue;
                $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                $toolCalls[] = [
                    'id' => (string) ($tc['id'] ?? ('call_' . rand_hex(6))),
                    'name' => (string) ($fn['name'] ?? ''),
                    'arguments' => $fn['arguments'] ?? '{}',
                ];
            }
        } elseif ($textMode) {
            $p = llm_parse_text_tool($res['content']);
            if ($p) $toolCalls[] = [
                'id' => 'call_' . rand_hex(6), 'name' => $p['name'],
                'arguments' => json_encode($p['arguments'], JSON_UNESCAPED_UNICODE),
                'raw_line' => $p['raw_line'],
            ];
        }

        if ($toolCalls) {
            $assistantMsg = ['role' => 'assistant', 'content' => $res['content']];
            if ($nativeTools) $assistantMsg['tool_calls'] = $res['tool_calls'];
            $messages[] = $assistantMsg;
            foreach ($toolCalls as $call) {
                $args = is_string($call['arguments']) ? $call['arguments'] : json_encode($call['arguments'], JSON_UNESCAPED_UNICODE);
                $d = json_decode((string) $args, true);
                $emit('tool', ['name' => $call['name'], 'arguments' => is_array($d) ? $d : (string) $args, 'stage' => 'running']);
                $r = plugin_invoke_tool($store, $call['name'], $args, ['session_id' => $sid]);
                $text = (string) ($r['text'] ?? '');
                if ($text === '') $text = $r['ok'] ? '(工具无返回)' : '(工具执行失败)';
                $toolLog[] = [
                    'name' => $call['name'], 'arguments' => is_array($d) ? $d : (string) $args,
                    'ok' => (bool) $r['ok'], 'result' => str_cut($text, 4000),
                ];
                $emit('tool', ['name' => $call['name'], 'ok' => (bool) $r['ok'], 'result' => str_cut($text, 1000), 'stage' => 'done']);
                if ($nativeTools) {
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => str_cut($text, 8000)];
                } else {
                    $messages[] = ['role' => 'user', 'content' => 'TOOL_RESULT ' . json_encode(['name' => $call['name'], 'result' => str_cut($text, 8000)], JSON_UNESCAPED_UNICODE) . "\n请基于工具结果继续回答，不要再输出 TOOL_CALL。"];
                }
            }
            continue; // 带着工具结果再问一轮
        }

        $final = trim($res['content']);
        if ($final === '' && !empty($res['raw']['choices'][0]['message']['reasoning_content'])) {
            $final = trim((string) $res['raw']['choices'][0]['message']['reasoning_content']);
        }
        break;
    }

    $offline = false;
    if ($final === '') {
        if ($toolLog) {
            $lastTool = end($toolLog);
            $final = "工具调用已结束，但模型没有给出文字回复。\n\n最后一次工具结果：\n" . str_cut((string) ($lastTool['result'] ?? ''), 1500);
        } elseif (llm_routing()['local_fallback']) {
            $local = local_answer($store, $userText);
            if ($local !== null) {
                $final = $local['text'];
                $offline = 'plugin';
                $lastErr = '';
            }
        }
        if ($final === '') $final = llm_offline_message($tried, $lastErr);
        $ok = $offline !== false;
    } else {
        $ok = true;
    }
    $modelUsed = (string) ($routeUsed['model'] ?? ($opt['model'] ?? llm_config()['model']));
    $store->addMessage($sid, [
        'role' => 'assistant', 'content' => $final,
        'model' => $modelUsed, 'tools' => $toolLog,
        'route' => $routeUsed['name'] ?? '', 'offline' => $offline,
    ]);
    $session['updated'] = now_ms();
    $store->saveSession($session);

    // 分段推送最终文本，让流式端点有打字机效果（服务端分段，不是上游逐 token）
    $chunks = preg_split('/(?<=[\n。！？.!?])/u', $final, -1, PREG_SPLIT_NO_EMPTY) ?: [$final];
    foreach ($chunks as $ch) $emit('delta', ['text' => $ch]);

    return [
        'ok' => $ok, 'message' => ['role' => 'assistant', 'content' => $final],
        'error' => $ok ? '' : $lastErr, 'tool_log' => $toolLog,
        'usage' => $totalUsage, 'model' => $modelUsed,
        'route_used' => $routeUsed, 'tried' => $tried, 'offline' => $offline,
        'session_id' => $sid,
    ];
}
