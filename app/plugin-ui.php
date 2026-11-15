<?php
/**
 * dshphp 2.4.0 —— 插件 GUI API 后端（有侵入式扩展点）
 *
 * 职责：
 *   1. 解析插件清单里可选的 ui 段（apiVersion / scope / css / js / files / mounts / permissions）
 *   2. 校验并聚合「已安装 + 已启用 + 未损坏」插件的 CSS / JS，只从插件自身目录读白名单资源
 *   3. 通过 index.php?r=plugin/ui.css|ui.js 输出，禁用 / 卸载 / 未启用的插件绝不泄漏
 *   4. 给前端运行时提供轻量 boot 元信息、上下文用量记录与查询
 *
 * 兼容：ui 段完全可选，老插件没有它时行为与 2.3.x 完全一致。
 * 安全：插件 JS 视同服务端可信代码（与 plugins/*.php 同级，由管理员经 FTP 放置）。
 *      资源端点不接受用户传入的文件路径；app scope 需登录，login scope 只允许匿名读取声明了该 scope 的插件。
 */
declare(strict_types=1);

require_once __DIR__ . '/plugins.php';

define('DSH_UI_API_VERSION', 1);

function plugin_ui_config(): array {
    $u = cfg()['ui'] ?? [];
    if (!is_array($u)) $u = [];
    return [
        'enabled' => !array_key_exists('enabled', $u) || (bool) $u['enabled'],
        'allow_login_scope' => !array_key_exists('allow_login_scope', $u) || (bool) $u['allow_login_scope'],
    ];
}

function plugin_ui_enabled(): bool { return plugin_ui_config()['enabled']; }

function plugin_ui_store(): ?Store {
    return function_exists('market_state_store') ? market_state_store() : null;
}

/** 字符串数组清洗：去空、去重、限长、限条数 */
function plugin_ui_strings($v, int $max = 24, int $len = 64): array {
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $x) {
        $s = str_cut((string) $x, $len);
        if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
        if (count($out) >= $max) break;
    }
    return $out;
}

/**
 * 读取插件同目录下的白名单资源。
 * 只允许纯文件名（不含 / \ ..），realpath 必须落回插件所在目录，避免任意文件读取。
 */
function plugin_ui_read_asset(string $pluginPath, string $name, string $ext): string {
    $name = trim((string) $name);
    if ($name === '' || strlen($name) > 64) return '';
    if (!preg_match('~^[A-Za-z0-9_.-]+$~', $name)) return '';
    if (str_contains($name, '..')) return '';
    if (!str_ends_with(strtolower($name), '.' . $ext)) return '';
    $dir = realpath(dirname($pluginPath));
    if ($dir === false) return '';
    $full = realpath($dir . DIRECTORY_SEPARATOR . $name);
    if ($full === false || !is_file($full)) return '';
    if (dirname($full) !== $dir) return '';
    $size = (int) @filesize($full);
    if ($size <= 0 || $size > 131072) return '';
    return (string) @file_get_contents($full);
}

/** 规范化单个插件的 ui 段；返回 null 表示不参与 UI 聚合 */
function plugin_ui_normalize($raw, string $id, string $path): ?array {
    if (!is_array($raw)) return null;
    if ((int) ($raw['apiVersion'] ?? 1) !== DSH_UI_API_VERSION) return null;

    $scope = plugin_ui_strings($raw['scope'] ?? ['app'], 4, 16);
    $scope = array_values(array_filter($scope, fn($x) => $x === 'app' || $x === 'login'));
    if (!$scope) $scope = ['app'];

    $css = (string) ($raw['css'] ?? '');
    $js  = (string) ($raw['js'] ?? '');
    if (strlen($css) > 65536 || strlen($js) > 131072) return null; // 超限则整套不加载，避免截断产生坏语法

    $files = is_array($raw['files'] ?? null) ? $raw['files'] : [];
    $cssFiles = is_array($files['css'] ?? null) ? $files['css'] : [];
    $jsFiles  = is_array($files['js'] ?? null) ? $files['js'] : [];
    foreach ($cssFiles as $f) $css .= "\n" . plugin_ui_read_asset($path, (string) $f, 'css');
    foreach ($jsFiles as $f)  $js  .= "\n" . plugin_ui_read_asset($path, (string) $f, 'js');
    if (strlen($css) > 131072 || strlen($js) > 262144) return null;

    return [
        'id' => $id,
        'name' => (string) ($raw['name'] ?? $id),
        'scope' => $scope,
        'css' => $css,
        'js' => $js,
        'mounts' => plugin_ui_strings($raw['mounts'] ?? []),
        'permissions' => plugin_ui_strings($raw['permissions'] ?? []),
        'actions' => plugin_ui_strings($raw['actions'] ?? []),
    ];
}

/** 当前 scope 下参与聚合的插件列表 */
function plugin_ui_manifest(?Store $store = null, string $scope = 'app'): array {
    if (!plugin_ui_enabled()) return [];
    if (!in_array($scope, ['app', 'login'], true)) $scope = 'app';
    $s = $store ?? plugin_ui_store();
    if (!$s) return [];
    $out = [];
    foreach (plugin_scan() as $p) {
        if (!empty($p['broken']) || !is_array($p['ui'] ?? null)) continue;
        if (!plugin_is_enabled($s, $p)) continue;
        $n = plugin_ui_normalize($p['ui'], (string) $p['id'], (string) ($p['path'] ?? ''));
        if ($n === null || !in_array($scope, $n['scope'], true)) continue;
        $n['name'] = (string) $p['name'];
        $out[] = $n;
    }
    return $out;
}

/** 资源版本串：插件集合或文件变动即变化，避免浏览器缓存旧插件代码 */
function plugin_ui_version(?Store $store, string $scope, array $list): string {
    $sig = DSHPHP_VERSION . '|' . $scope . '|' . count($list);
    foreach ($list as $it) $sig .= '|' . $it['id'] . ':' . strlen($it['css']) . ':' . strlen($it['js']);
    return substr(sha1($sig), 0, 12);
}

/** 注入到 window.DSH_BOOT 的 ui 元信息（不含 CSS/JS 正文，避免首屏膨胀） */
function plugin_ui_boot(?Store $store = null, string $scope = 'app'): array {
    if (!in_array($scope, ['app', 'login'], true)) $scope = 'app';
    if ($scope === 'login' && !plugin_ui_config()['allow_login_scope']) $scope = 'app';
    $list = plugin_ui_manifest($store, $scope);
    $v = plugin_ui_version($store, $scope, $list);
    $plugins = array_map(fn($it) => [
        'id' => $it['id'], 'name' => $it['name'], 'scope' => $it['scope'],
        'mounts' => $it['mounts'], 'permissions' => $it['permissions'], 'actions' => $it['actions'],
    ], $list);
    return [
        'enabled' => plugin_ui_enabled(),
        'apiVersion' => DSH_UI_API_VERSION,
        'scope' => $scope,
        'plugins' => $plugins,
        'version' => $v,
        'cssUrl' => 'index.php?r=plugin/ui.css&s=' . $scope . '&v=' . $v,
        'jsUrl'  => 'index.php?r=plugin/ui.js&s=' . $scope . '&v=' . $v,
        'slots' => ['sidebar-top', 'sidebar-foot', 'topbar', 'composer', 'status', 'overlay', 'login', 'toasts'],
    ];
}

function plugin_ui_css(?Store $store = null, string $scope = 'app'): string {
    $out = ["/* dshphp plugin ui bundle · scope=" . $scope . " · v" . DSHPHP_VERSION . " */"];
    foreach (plugin_ui_manifest($store, $scope) as $it) {
        if (trim($it['css']) === '') continue;
        $out[] = "\n/* ui: " . $it['id'] . " */\n" . $it['css'];
    }
    $body = implode("\n", $out) . "\n";
    return strlen($body) > 524288 ? $out[0] . "\n" : $body;
}

function plugin_ui_js(?Store $store = null, string $scope = 'app'): string {
    $items = [];
    foreach (plugin_ui_manifest($store, $scope) as $it) {
        $items[] = [
            'id' => $it['id'], 'name' => $it['name'], 'scope' => $it['scope'],
            'mounts' => $it['mounts'], 'permissions' => $it['permissions'], 'actions' => $it['actions'],
            'code' => $it['js'],
        ];
    }
    $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) $json = '[]';
    return "/* dshphp plugin ui bundle · scope=" . $scope . " · v" . DSHPHP_VERSION . " */\n"
        . "(function(){var D=window.DSH;if(!D||!D._bundle)return;D._bundle(" . $json . ");})();\n";
}

/** 输出聚合资源：no sniff + ETag；内容随启用状态变化，禁用插件不会从缓存里复活 */
function plugin_ui_asset(?Store $store, string $type, string $scope): void {
    if ($scope === 'login' && !plugin_ui_config()['allow_login_scope']) $scope = 'app';
    $body = $type === 'css' ? plugin_ui_css($store, $scope) : plugin_ui_js($store, $scope);
    if (!headers_sent()) {
        header('Content-Type: ' . ($type === 'css' ? 'text/css' : 'application/javascript') . '; charset=utf-8');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        $etag = '"' . sha1($body) . '"';
        header('ETag: ' . $etag);
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
    }
    echo $body;
    exit;
}

// ── 上下文用量：给「上下文显示」插件用的确定性数据 ──────────────────────────
function plugin_ui_usage_state(?Store $store = null): array {
    $s = $store ?? plugin_ui_store();
    $v = $s ? $s->kvGet('ui.usage', []) : [];
    return is_array($v) ? $v : [];
}

function plugin_ui_record_usage(?Store $store, string $sid, $usage): void {
    $s = $store ?? plugin_ui_store();
    if (!$s || !is_array($usage) || !$usage) return;
    $total = (int) ($usage['total_tokens'] ?? 0);
    if ($total <= 0) return;
    $v = plugin_ui_usage_state($s);
    $v['total'] = (int) ($v['total'] ?? 0) + $total;
    $v['last'] = [
        'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
        'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
        'total_tokens' => $total,
        'ts' => now_ms(),
    ];
    $v['calls'] = (int) ($v['calls'] ?? 0) + 1;
    $v['updated'] = now_ms();
    $s->kvSet('ui.usage', $v);
}

/** 估算当前会话占用的上下文（不调模型，纯本地确定性计算） */
function plugin_ui_context(?Store $store, string $sid): array {
    $s = $store ?? plugin_ui_store();
    $c = cfg();
    $msgs = ($s && $sid !== '') ? $s->messages($sid, 0) : [];
    $bytes = strlen((string) $c['chat']['system_prompt']);
    foreach ($msgs as $m) $bytes += strlen((string) ($m['content'] ?? ''));
    $est = (int) round($bytes / 3.5); // 中文约 3 字节/token，英文约 4 字节/token
    $limit = max(512, (int) $c['chat']['max_tokens'] * 2 + 4096);
    $u = plugin_ui_usage_state($s);
    return [
        'ok' => true,
        'session_id' => $sid,
        'messages' => count($msgs),
        'bytes' => $bytes,
        'est_tokens' => $est,
        'context_limit' => $limit,
        'ratio' => min(1.0, $est / $limit),
        'max_tokens' => (int) $c['chat']['max_tokens'],
        'history_limit' => (int) $c['chat']['history_limit'],
        'usage_last' => $u['last'] ?? null,
        'usage_total' => (int) ($u['total'] ?? 0),
        'calls' => (int) ($u['calls'] ?? 0),
    ];
}
