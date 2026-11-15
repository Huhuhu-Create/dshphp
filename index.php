<?php
/**
 * dshphp 2.0 唯一入口 —— 页面 + 全部 API 都走 index.php?r=xxx
 *
 * 对外（X-API-Key 或 Authorization: Bearer <API Key>）：
 *   GET  index.php?r=v1/models
 *   POST index.php?r=v1/chat/completions          OpenAI 兼容网关
 *   GET  index.php?r=api/status
 *   GET  index.php?r=api/session/list
 *   POST index.php?r=api/session/create|rename|delete|clear
 *   GET  index.php?r=api/session/get&sid=xxx
 *   POST index.php?r=api/chat/send                {"sid":"...","text":"..."}
 *   POST index.php?r=api/chat/stream              SSE 阶段事件
 *   GET  index.php?r=api/plugin/list
 *   POST index.php?r=api/plugin/toggle|run
 *   GET  index.php?r=api/market/list
 *   POST index.php?r=api/market/install|uninstall|batch|run
 *   GET  index.php?r=api/skill/list
 *   POST index.php?r=api/skill/toggle
 *   GET  index.php?r=api/settings/get
 *   POST index.php?r=api/settings/save|test
 *   GET  index.php?r=api/routes/list
 *   POST index.php?r=api/routes/test|reset      多线路健康检查与冷却管理
 *   GET  index.php?r=api/automation/list        自动化任务表 + 运行日志 + 统计
 *   POST index.php?r=api/automation/tick|toggle|save|run|upsert|delete|logs|export|import
 *   GET  index.php?r=plugin/ui.css|ui.js        2.4 插件 GUI API：已启用插件的聚合样式/脚本
 *   GET  index.php?r=api/ui/context             当前会话上下文用量（供「上下文显示」插件）
 *   GET/POST index.php?r=api/ui/credentials     附加登录凭据列表 / 增删（供「多密码」插件）
 *   自动化任务仅在 index.php 被访问时推进；没有外部心跳、没有常驻进程（cron.php 已停用）。
 *
 * 浏览器：会话登录 + CSRF（X-CSRF-Token）。
 */
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once DSH_APP . '/store.php';
require_once DSH_APP . '/plugins.php';
require_once DSH_APP . '/plugin-ui.php';
require_once DSH_APP . '/llm.php';
require_once DSH_APP . '/view.php';
require_once DSH_APP . '/automation.php';

if (!is_installed()) { header('Location: install.php'); exit; }

$r = (string) ($_GET['r'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// ── 表单登录 / 登出 ────────────────────────────────────────────────────────
if ($r === 'api/login' && $isPost) {
    $b = body_json();
    $pw = (string) ($b['password'] ?? '');
    $ok = auth_verify($pw);
    if (req_header('accept') !== '' && str_contains(req_header('accept'), 'application/json')) {
        json_out($ok ? ['ok' => true] : ['ok' => false, 'error' => '密码错误'], $ok ? 200 : 401);
    }
    if (!$ok) { render_login('密码错误或尝试次数过多，请稍后再试'); exit; }
    header('Location: index.php'); exit;
}
if ($r === 'api/logout' || $r === 'logout') {
    auth_logout();
    if (str_starts_with($r, 'api/')) json_out(['ok' => true]);
    header('Location: index.php'); exit;
}

// ── 存储实例 ──────────────────────────────────────────────────────────────
try {
    $store = new Store();
    llm_set_store($store); // 线路健康状态跨请求共享
} catch (Throwable $e) {
    if (str_starts_with($r, 'api/') || str_starts_with($r, 'v1/')) {
        json_out(['ok' => false, 'error' => '数据目录不可写：' . $e->getMessage() . '（请检查 data/ 权限）'], 503);
    }
    render_login('数据目录不可写，请检查 data/ 目录权限'); exit;
}

// 唯一调度驱动：本次请求访问了 index.php，就推进一次调度（内部有 10 秒合并窗口 + flock 互斥）。
// 没有前端定时心跳、没有外部 cron、没有常驻进程 —— 零访问即零执行。
automation_auto_tick($store);

// ── 插件 GUI API 资源（2.4）───────────────────────────────────────────────
// 主界面资源需登录；登录页资源（s=login）允许匿名，只输出声明了 login scope 的插件。
// 二者都不接受用户传入的文件路径，聚合内容由服务端按「已安装 + 已启用」过滤。
if ($r === 'plugin/ui.css' || $r === 'plugin/ui.js') {
    $uiScope = (string) ($_GET['s'] ?? 'app');
    if (!in_array($uiScope, ['app', 'login'], true)) $uiScope = 'app';
    if ($uiScope === 'app') api_guard(false);
    plugin_ui_asset($store, $r === 'plugin/ui.css' ? 'css' : 'js', $uiScope);
}

// ── API ───────────────────────────────────────────────────────────────────
if (str_starts_with($r, 'api/') || str_starts_with($r, 'v1/')) {
    api_guard($isPost);
    $b = body_json();
    $sid = (string) ($b['sid'] ?? ($_GET['sid'] ?? ''));

    switch ($r) {
        // ── 状态 ──────────────────────────────────────────────────────
        case 'api/status':
            $c = cfg();
            json_out(['ok' => true, 'data' => [
                'version' => DSHPHP_VERSION, 'php' => PHP_VERSION,
                'driver' => $store->driver(), 'stats' => $store->stats(),
                'route' => (string) $c['route'], 'model' => (string) $c['llm']['model'],
                'plugins' => count(plugin_list($store)), 'skills' => count(skill_list($store)),
                'tools' => count(plugin_toolmap($store)['tools']),
                'http' => function_exists('curl_init') ? 'curl' : 'stream',
                'routing_mode' => llm_routing()['mode'],
                'routes' => count(llm_routes()),
                'market_total' => count(market_scan()),
                'market_installed' => count(market_installed_ids($store)),
                'automation' => automation_stats(automation_read()),
                'time' => date('Y-m-d H:i:s'),
            ]]);
            break;

        // ── 会话 ──────────────────────────────────────────────────────
        case 'api/session/list':
            json_out(['ok' => true, 'sessions' => $store->sessions()]);
            break;

        case 'api/session/create':
            $newId = 'ses_' . rand_hex(10);
            $store->saveSession([
                'id' => $newId, 'title' => (string) ($b['title'] ?? '新对话'),
                'model' => '', 'system' => str_cut((string) ($b['system'] ?? ''), 4000),
                'created' => now_ms(), 'updated' => now_ms(),
            ]);
            json_out(['ok' => true, 'session' => $store->session($newId)]);
            break;

        case 'api/session/get':
            $s = $store->session($sid);
            if (!$s) json_out(['ok' => false, 'error' => '会话不存在'], 404);
            json_out(['ok' => true, 'session' => $s, 'messages' => $store->messages($sid, 0)]);
            break;

        case 'api/session/rename':
            $s = $store->session($sid);
            if (!$s) json_out(['ok' => false, 'error' => '会话不存在'], 404);
            if (isset($b['title'])) $s['title'] = str_cut(trim((string) $b['title']), 60) ?: '新对话';
            if (isset($b['system'])) $s['system'] = str_cut((string) $b['system'], 4000);
            if (isset($b['model'])) $s['model'] = (string) $b['model'];
            $s['updated'] = now_ms();
            $store->saveSession($s);
            json_out(['ok' => true, 'session' => $s]);
            break;

        case 'api/session/delete':
            $store->deleteSession($sid);
            json_out(['ok' => true]);
            break;

        case 'api/session/clear':
            if (!$store->session($sid)) json_out(['ok' => false, 'error' => '会话不存在'], 404);
            $store->clearMessages($sid);
            json_out(['ok' => true]);
            break;

        // ── 对话 ──────────────────────────────────────────────────────
        case 'api/chat/send':
        case 'api/chat/stream':
            $text = trim((string) ($b['text'] ?? ''));
            if ($text === '' || $sid === '') json_out(['ok' => false, 'error' => '缺少 text / sid'], 400);
            if (!$store->session($sid)) json_out(['ok' => false, 'error' => '会话不存在'], 404);
            rate_limit();
            if (strlen($text) > 20000) json_out(['ok' => false, 'error' => '消息过长（上限 20000 字节）'], 400);

            // /命令：直接跑插件，不消耗模型额度
            $slash = plugin_slash($store, $text, ['session_id' => $sid]);
            if ($slash !== null) {
                $store->addMessage($sid, ['role' => 'user', 'content' => $text]);
                $store->addMessage($sid, ['role' => 'assistant', 'content' => $slash['text'], 'plugin' => $slash['plugin'] ?? '']);
                $res = ['ok' => (bool) $slash['ok'], 'message' => ['role' => 'assistant', 'content' => $slash['text']],
                    'tool_log' => [['name' => (string) ($slash['plugin'] ?? 'slash'), 'ok' => (bool) $slash['ok'], 'result' => str_cut((string) $slash['text'], 1000)]],
                    'usage' => [], 'model' => 'plugin', 'session_id' => $sid, 'slash' => true];
                if ($r === 'api/chat/stream') { sse_start(); sse_emit('delta', ['text' => (string) $slash['text']]); sse_emit('done', $res); exit; }
                json_out($res);
            }

            $opt = [];
            if (isset($b['model']) && (string) $b['model'] !== '') $opt['model'] = (string) $b['model'];
            if (isset($b['temperature'])) $opt['temperature'] = (float) $b['temperature'];
            if (isset($b['system'])) $opt['system'] = (string) $b['system'];
            if (array_key_exists('tools', $b)) $opt['tools'] = (bool) $b['tools'];
            if (array_key_exists('text_tool_mode', $b)) $opt['text_tool_mode'] = (bool) $b['text_tool_mode'];

            if ($r === 'api/chat/send') {
                $res = chat_turn($store, $sid, $text, $opt);
                plugin_ui_record_usage($store, $sid, $res['usage'] ?? []);
                json_out($res);
            }
            sse_start();
            $res = chat_turn($store, $sid, $text, $opt, function (string $ev, array $data): void { sse_emit($ev, $data); });
            plugin_ui_record_usage($store, $sid, $res['usage'] ?? []);
            sse_emit($res['ok'] ? 'done' : 'error', $res);
            exit;

        // ── 插件 ──────────────────────────────────────────────────────
        case 'api/plugin/list':
            json_out(['ok' => true, 'plugins' => plugin_list($store)]);
            break;

        case 'api/plugin/toggle':
            $id = (string) ($b['id'] ?? '');
            json_out(plugin_set_enabled($store, $id, !empty($b['enabled']))
                ? ['ok' => true] : ['ok' => false, 'error' => '插件不存在'], 404);
            break;

        case 'api/plugin/run':
            $id = (string) ($b['id'] ?? '');
            $args = $b['args'] ?? [];
            if (!is_array($args)) $args = [];
            $tool = (string) ($b['tool'] ?? '');
            if ($tool === '') {
                foreach (plugin_scan() as $p) {
                    if ($p['id'] === $id && isset($p['tools'][0]['name'])) { $tool = (string) $p['tools'][0]['name']; break; }
                }
            }
            json_out(plugin_invoke($store, $id, $tool, $args, ['session_id' => $sid]));
            break;

        // ── 插件市场 ──────────────────────────────────────────────────
        case 'api/market/list':
            json_out(market_list($store));
            break;

        case 'api/market/install':
            $mid = (string) ($b['id'] ?? '');
            $res = market_install($store, $mid);
            if (!$res['ok']) json_out($res, 404);
            // 安装即显式启用，避免「装了却没工具」的困惑
            plugin_set_enabled($store, $mid, true);
            json_out($res + ['market' => market_list($store)]);
            break;

        case 'api/market/uninstall':
            $id = (string) ($b['id'] ?? '');
            if (!market_valid_id($id)) json_out(['ok' => false, 'error' => '插件不存在'], 404);
            json_out(market_uninstall($store, $id) + ['market' => market_list($store)]);
            break;

        case 'api/market/batch':
            $res = market_batch($store, $b);
            if (!$res['ok']) json_out($res, 400);
            // 批量安装后统一启用；批量卸载不需要额外处理
            if (($b['mode'] ?? 'replace') !== 'remove') {
                foreach ($res['installed'] as $mid) plugin_set_enabled($store, $mid, true);
            }
            json_out($res + ['market' => market_list($store)]);
            break;

        case 'api/market/run':
            $id = (string) ($b['id'] ?? '');
            if (!market_valid_id($id) || !in_array($id, market_installed_ids($store, true), true)) {
                json_out(['ok' => false, 'error' => '插件不存在或尚未安装'], 404);
            }
            $args = $b['args'] ?? [];
            if (!is_array($args)) $args = [];
            $tool = (string) ($b['tool'] ?? '');
            if ($tool === '') {
                $m = market_scan()[$id];
                $tool = (string) ($m['tools'][0] ?? '');
            }
            json_out(plugin_invoke($store, $id, $tool, $args, ['session_id' => $sid]));
            break;

        // ── 技能 ──────────────────────────────────────────────────────
        case 'api/skill/list':
            json_out(['ok' => true, 'skills' => skill_list($store)]);
            break;

        case 'api/skill/toggle':
            // mode=auto：清除显式开关，回到 frontmatter 的按需/常驻默认
            $skillMode = (string) ($b['mode'] ?? '') === 'auto' ? 'auto' : null;
            json_out(skill_set_enabled($store, (string) ($b['id'] ?? ''), !empty($b['enabled']), $skillMode)
                ? ['ok' => true] : ['ok' => false, 'error' => '技能不存在'], 404);
            break;

        // ── 设置 ──────────────────────────────────────────────────────
        case 'api/settings/get':
            $c = cfg();
            json_out(['ok' => true, 'settings' => [
                'route' => (string) $c['route'],
                'llm' => [
                    'base_url' => (string) $c['llm']['base_url'], 'model' => (string) $c['llm']['model'],
                    'api_key' => (string) $c['llm']['api_key'] === '' ? '' : '••••••••',
                    'api_key_set' => (string) $c['llm']['api_key'] !== '',
                    'auth_mode' => (string) $c['llm']['auth_mode'], 'timeout' => (int) $c['llm']['timeout'],
                    'models' => $c['llm']['models'],
                ],
                'chat' => $c['chat'], 'security' => $c['security'],
                'routing' => llm_routing(), 'routes' => llm_routes_public(),
                'presets' => preset_routes(),
            ]]);
            break;

        case 'api/settings/save':
            $c = cfg();
            $l = $b['llm'] ?? [];
            if (is_array($l)) {
                foreach (['base_url', 'model', 'auth_mode'] as $k) if (isset($l[$k])) $c['llm'][$k] = trim((string) $l[$k]);
                if (isset($l['api_key']) && (string) $l['api_key'] !== '••••••••') $c['llm']['api_key'] = (string) $l['api_key'];
                if (isset($l['timeout'])) $c['llm']['timeout'] = max(10, min(300, (int) $l['timeout']));
                if (isset($l['models']) && is_array($l['models'])) $c['llm']['models'] = array_values(array_map('strval', $l['models']));
            }
            if (isset($b['route'])) $c['route'] = (string) $b['route'];
            $ch = $b['chat'] ?? [];
            if (is_array($ch)) {
                if (isset($ch['system_prompt'])) $c['chat']['system_prompt'] = str_cut((string) $ch['system_prompt'], 8000);
                if (isset($ch['temperature'])) $c['chat']['temperature'] = max(0.0, min(2.0, (float) $ch['temperature']));
                if (isset($ch['max_tokens'])) $c['chat']['max_tokens'] = max(64, min(32000, (int) $ch['max_tokens']));
                if (isset($ch['history_limit'])) $c['chat']['history_limit'] = max(2, min(100, (int) $ch['history_limit']));
                if (isset($ch['max_tool_rounds'])) $c['chat']['max_tool_rounds'] = max(1, min(8, (int) $ch['max_tool_rounds']));
                foreach (['tools_enabled', 'stream_default'] as $k) if (array_key_exists($k, $ch)) $c['chat'][$k] = (bool) $ch[$k];
            }
            $sec = $b['security'] ?? [];
            if (is_array($sec)) {
                if (isset($sec['allow_plugins'])) $c['security']['allow_plugins'] = (bool) $sec['allow_plugins'];
                if (isset($sec['requests_per_minute'])) $c['security']['requests_per_minute'] = max(0, min(600, (int) $sec['requests_per_minute']));
            }
            $ro = $b['routing'] ?? [];
            if (is_array($ro)) {
                if (isset($ro['mode'])) $c['routing']['mode'] = (string) $ro['mode'] === 'fixed' ? 'fixed' : 'auto';
                if (isset($ro['cooldown'])) $c['routing']['cooldown'] = max(0, min(3600, (int) $ro['cooldown']));
                if (isset($ro['max_switches'])) $c['routing']['max_switches'] = max(0, min(6, (int) $ro['max_switches']));
                if (array_key_exists('local_fallback', $ro)) $c['routing']['local_fallback'] = (bool) $ro['local_fallback'];
                if (isset($ro['candidates']) && is_array($ro['candidates'])) {
                    $old = [];
                    foreach ((array) ($c['routing']['candidates'] ?? []) as $oc) {
                        if (is_array($oc)) $old[(string) ($oc['key'] ?? '')] = $oc;
                    }
                    $cand = [];
                    foreach ($ro['candidates'] as $i => $x) {
                        if (!is_array($x)) continue;
                        $base = rtrim(trim((string) ($x['base_url'] ?? '')), '/');
                        $model = trim((string) ($x['model'] ?? ''));
                        if ($base === '' || $model === '') continue;
                        if (!preg_match('~^https?://~i', $base)) continue;
                        // 用 ~ 作分隔符：字符类里的 / 若用 / 分隔符会截断模式（Unknown modifier）
                        if (!preg_match('~^[A-Za-z0-9_./:-]+$~', $model)) continue;
                        $key = (string) ($x['key'] ?? ('cand_' . $i));
                        $apiKey = (string) ($x['api_key'] ?? '');
                        if ($apiKey === '' || $apiKey === '••••••••') $apiKey = (string) ($old[$key]['api_key'] ?? '');
                        $auth = (string) ($x['auth_mode'] ?? 'bearer');
                        $cand[] = [
                            'key' => $key,
                            'name' => str_cut(trim((string) ($x['name'] ?? $key)), 60) ?: $key,
                            'base_url' => $base, 'model' => $model, 'api_key' => $apiKey,
                            'auth_mode' => in_array($auth, ['bearer', 'bearer-empty', 'x-api-key', 'none'], true) ? $auth : 'bearer',
                            'enabled' => !array_key_exists('enabled', $x) || !empty($x['enabled']),
                        ];
                    }
                    $c['routing']['candidates'] = $cand;
                }
            }
            if (!is_writable(DSH_ROOT . '/config.php')) json_out(['ok' => false, 'error' => 'config.php 不可写，请用 FTP 设置可写后再保存'], 403);
            $written = @file_put_contents(DSH_ROOT . '/config.php', "<?php\nreturn " . var_export($c, true) . ";\n", LOCK_EX);
            json_out($written !== false ? ['ok' => true] : ['ok' => false, 'error' => '写入 config.php 失败'], $written !== false ? 200 : 500);
            break;

        case 'api/settings/test':
            json_out(llm_test(
                isset($b['base_url']) ? (string) $b['base_url'] : null,
                isset($b['model']) ? (string) $b['model'] : null,
                null,
                isset($b['auth_mode']) ? (string) $b['auth_mode'] : null
            ));
            break;

        // 一键从上游拉取可用模型列表（OpenAI 兼容 /models）
        case 'api/settings/models':
            json_out(llm_fetch_models(
                isset($b['base_url']) ? (string) $b['base_url'] : null,
                isset($b['auth_mode']) ? (string) $b['auth_mode'] : null,
                isset($b['api_key']) ? (string) $b['api_key'] : null
            ));
            break;

        // ── 线路（自动故障转移）──────────────────────────────────────
        case 'api/routes/list':
            $h = llm_health();
            json_out(['ok' => true, 'routes' => llm_routes_public(), 'routing' => llm_routing(),
                'health' => ['entries' => count($h)]]);
            break;

        case 'api/routes/test':
            if (function_exists('set_time_limit')) @set_time_limit(150);
            json_out(['ok' => true, 'results' => llm_test_all()]);
            break;

        case 'api/routes/reset':
            llm_health_reset();
            json_out(['ok' => true, 'note' => '已清除所有线路冷却，下一条消息会重新从主线路开始尝试']);
            break;

        // ── 自动化任务（2.3：JSON 配置 + 心跳驱动调度）──────────────────
        case 'api/automation/list':
            json_out(automation_reply());
            break;

        case 'api/automation/tick':
            json_out(automation_reply(['tick' => automation_tick($store, true, null)]));
            break;

        case 'api/automation/toggle':
            $r = automation_toggle((string) ($b['id'] ?? ''), !empty($b['enabled']));
            if (!$r['ok']) json_out($r, 404);
            json_out(automation_reply($r));
            break;

        case 'api/automation/save':
            $r = ['ok' => true];
            if (array_key_exists('enabled', $b)) $r = automation_set_enabled(!empty($b['enabled']));
            if (isset($b['max_log'])) {
                automation_mutate(function (array $d) use ($b): array {
                    $d['max_log'] = max(20, min(2000, (int) $b['max_log']));
                    return $d;
                });
            }
            json_out(automation_reply($r));
            break;

        case 'api/automation/run':
            $rid = (string) ($b['id'] ?? '');
            json_out(automation_reply(['tick' => automation_tick($store, true, $rid !== '' ? $rid : null)]));
            break;

        case 'api/automation/upsert':
            $task = $b['task'] ?? $b;
            if (!is_array($task)) $task = [];
            $r = automation_upsert($task);
            if (!$r['ok']) json_out($r, 400);
            json_out(automation_reply($r));
            break;

        case 'api/automation/delete':
            $r = automation_delete((string) ($b['id'] ?? ''));
            if (!$r['ok']) json_out($r, 404);
            json_out(automation_reply($r));
            break;

        case 'api/automation/logs':
            json_out(automation_logs((int) ($b['limit'] ?? 100)));
            break;

        case 'api/automation/export':
            json_out(['ok' => true, 'json' => automation_export()]);
            break;

        case 'api/automation/import':
            json_out(automation_reply(automation_import((string) ($b['json'] ?? ''))));
            break;

        // ── 插件 GUI API（2.4）──────────────────────────────────────────
        case 'api/ui/context':
            json_out(plugin_ui_context($store, $sid));
            break;

        case 'api/ui/credentials':
            if (!$isPost) {
                json_out(['ok' => true, 'credentials' => auth_credentials_public(),
                    'main_set' => (string) cfg()['admin_password_hash'] !== '']);
            }
            $act = (string) ($b['action'] ?? '');
            $label = trim(str_cut((string) ($b['label'] ?? ''), 32));
            $c = cfg();
            $creds = auth_credentials();
            if ($act === 'add') {
                $secret = (string) ($b['secret'] ?? '');
                $type = (string) ($b['type'] ?? 'password');
                $type = in_array($type, ['password', 'gesture'], true) ? $type : 'password';
                if ($label === '' || strlen($secret) < 4 || strlen($secret) > 200) {
                    json_out(['ok' => false, 'error' => '名称不能为空，凭据长度需为 4～200 字节'], 400);
                }
                if (isset($creds[$label])) json_out(['ok' => false, 'error' => '同名凭据已存在'], 400);
                if (count($creds) >= 8) json_out(['ok' => false, 'error' => '最多 8 个附加凭据'], 400);
                $creds[$label] = [
                    'type' => $type, 'hash' => password_hash($secret, PASSWORD_DEFAULT), 'created' => time(),
                ];
            } elseif ($act === 'remove') {
                if ($label === '' || !isset($creds[$label])) json_out(['ok' => false, 'error' => '凭据不存在'], 404);
                unset($creds[$label]);
                if (!$creds && (string) $c['admin_password_hash'] === '') {
                    json_out(['ok' => false, 'error' => '不能删除最后一个凭据'], 400);
                }
            } else {
                json_out(['ok' => false, 'error' => '未知操作'], 400);
            }
            $c['credentials'] = $creds;
            if (!is_writable(DSH_ROOT . '/config.php')) json_out(['ok' => false, 'error' => 'config.php 不可写，请用 FTP 设置可写'], 403);
            $w = @file_put_contents(DSH_ROOT . '/config.php', "<?php\nreturn " . var_export($c, true) . ";\n", LOCK_EX);
            if ($w === false) json_out(['ok' => false, 'error' => '写入 config.php 失败'], 500);
            // 只改配置文件，不销毁任何会话：增删凭据不会把已登录的其它设备踢下线
            $pub = [];
            foreach ($creds as $k => $v) $pub[] = ['label' => (string) $k, 'type' => $v['type'], 'created' => $v['created']];
            json_out(['ok' => true, 'credentials' => $pub]);
            break;

        case 'api/models':
            json_out(['ok' => true, 'models' => llm_available_models()]);
            break;

        // ── OpenAI 兼容网关（其它程序可直接把 dshphp 当模型用）────────
        case 'v1/models':
            $models = llm_available_models();
            json_out(['object' => 'list', 'data' => array_map(fn($m) => [
                'id' => (string) $m, 'object' => 'model', 'created' => time(), 'owned_by' => 'dshphp',
            ], $models)]);
            break;

        case 'v1/chat/completions':
            $msgs = $b['messages'] ?? [];
            if (!is_array($msgs) || !$msgs) json_out(['error' => ['message' => 'messages 必填', 'type' => 'invalid_request_error']], 400);
            rate_limit();
            $clean = [];
            foreach ($msgs as $m) {
                if (!is_array($m)) continue;
                $role = (string) ($m['role'] ?? 'user');
                $content = $m['content'] ?? '';
                if (is_array($content)) {
                    $t = '';
                    foreach ($content as $seg) if (is_array($seg) && isset($seg['text'])) $t .= (string) $seg['text'];
                    $content = $t;
                }
                $clean[] = ['role' => $role, 'content' => (string) $content];
            }
            $opt = ['model' => (string) ($b['model'] ?? '')];
            if (isset($b['temperature'])) $opt['temperature'] = (float) $b['temperature'];
            if (isset($b['max_tokens'])) $opt['max_tokens'] = (int) $b['max_tokens'];
            if (!empty($b['tools']) && is_array($b['tools'])) $opt['tools'] = $b['tools'];
            $res = llm_chat($clean, $opt);
            // 只有「上游不支持 tools」才去工具重试；429/5xx 已由网关自动换线路，不重复撞
            if (!$res['ok'] && !empty($opt['tools']) && in_array($res['status'], [400, 404, 422], true)) {
                unset($opt['tools']);
                $res = llm_chat($clean, $opt);
            }
            if (!$res['ok']) {
                json_out(['error' => ['message' => $res['error'], 'type' => 'upstream_error', 'code' => $res['status']]], 502);
            }
            $msg = ['role' => 'assistant', 'content' => $res['content']];
            if ($res['tool_calls']) $msg['tool_calls'] = $res['tool_calls'];
            $payload = [
                'id' => 'chatcmpl-' . rand_hex(12), 'object' => 'chat.completion', 'created' => time(),
                'model' => (string) ($opt['model'] ?: llm_config()['model']),
                'choices' => [['index' => 0, 'message' => $msg, 'finish_reason' => $res['tool_calls'] ? 'tool_calls' : 'stop']],
                'usage' => $res['usage'] ?: ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            ];
            if (!empty($b['stream'])) {
                header('Content-Type: text/event-stream; charset=utf-8');
                header('Cache-Control: no-cache');
                header('X-Accel-Buffering: no');
                $chunk = ['id' => $payload['id'], 'object' => 'chat.completion.chunk', 'created' => time(),
                    'model' => $payload['model'], 'choices' => [['index' => 0, 'delta' => $msg, 'finish_reason' => null]]];
                echo 'data: ' . json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                echo 'data: ' . json_encode(['id' => $payload['id'], 'object' => 'chat.completion.chunk', 'created' => time(),
                    'model' => $payload['model'], 'choices' => [['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop']]], JSON_UNESCAPED_UNICODE) . "\n\n";
                echo "data: [DONE]\n\n";
                exit;
            }
            json_out($payload);
            break;

        default:
            json_out(['ok' => false, 'error' => '未知路由：' . $r], 404);
    }
}

// ── 页面 ──────────────────────────────────────────────────────────────────
if (!auth_check()) { render_login(); exit; }
render_page($store);

/** 自动化任务：任何写操作后一次性回传任务表 + 运行日志 + 统计，前端不必二次请求 */
function automation_reply(array $extra = []): array {
    return array_merge(automation_list(), $extra);
}

/** SSE 辅助函数（全局，供上面闭包调用） */
function sse_start(): void {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) @ob_end_clean();
    echo ": dshphp stream\n\n";
    @flush();
}
function sse_emit(string $event, array $data): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    if (ob_get_level() > 0) @ob_flush();
    @flush();
}
