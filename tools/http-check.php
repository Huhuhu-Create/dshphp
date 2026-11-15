<?php
/** 本机 HTTP 自检（tools/ 不进部署包）：页面注入 / 资源端点 / 鉴权隔离 / 老接口回归 */
declare(strict_types=1);
$root = dirname(__DIR__);
$c = require $root . '/config.php';
$key = (string) ($c['api_key'] ?? '');
$base = 'http://127.0.0.1:8097/index.php';

function req(string $url, array $headers = []): array {
    $h = "Accept: */*\r\n";
    foreach ($headers as $k => $v) $h .= $k . ': ' . $v . "\r\n";
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => $h, 'timeout' => 15]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/ (\d{3}) /', $http_response_header[0], $m)) $code = (int) $m[1];
    return [$code, (string) $body, $http_response_header ?? []];
}
function has(string $s, string $needle): string { return strpos($s, $needle) !== false ? 'yes' : 'NO'; }

[$code, $html] = req($base);
echo "[login page] HTTP $code bytes=" . strlen($html)
    . ' runtime=' . has($html, 'assets/dsh-ui.js')
    . ' loginCss=' . has($html, 'plugin/ui.css&amp;s=login')
    . ' loginJs=' . has($html, 'plugin/ui.js&amp;s=login')
    . ' slot=' . has($html, 'dsh-slot-login')
    . ' boot=' . has($html, 'window.DSH_BOOT') . "\n";
echo "  head> " . str_replace("\n", ' | ', substr($html, 0, 700)) . "\n";

[$code, $body] = req($base . '?r=plugin/ui.js&s=login');
echo "[login bundle] HTTP $code bytes=" . strlen($body)
    . ' glass=' . has($body, 'mkt.glass')
    . ' passwords=' . has($body, 'mkt.passwords')
    . ' sidebar(should be NO)=' . has($body, '"id":"mkt.sidebar"') . "\n";

[$code, $body] = req($base . '?r=plugin/ui.js&s=app');
echo "[app bundle no key] HTTP $code (expect 401) bytes=" . strlen($body) . "\n";

[$code, $body, $hdr] = req($base . '?r=plugin/ui.js&s=app', ['X-API-Key' => $key]);
$ids = ['mkt.sidebar', 'mkt.context', 'mkt.vtree', 'mkt.glass', 'mkt.passwords', 'mkt.browser'];
$found = 0;
foreach ($ids as $id) if (strpos($body, '"id":"' . $id . '"') !== false) $found++;
echo "[app bundle with key] HTTP $code bytes=" . strlen($body) . " plugins=$found/6"
    . ' browser=' . has($body, '"id":"mkt.browser"') . "\n";

[$code, $body] = req($base . '?r=plugin/ui.css&s=app', ['X-API-Key' => $key]);
echo "[app css] HTTP $code bytes=" . strlen($body) . ' hasSidebarCss=' . has($body, '.dsh-sb-tools') . "\n";

[$code, $body] = req($base . '?r=api/ui/context', ['X-API-Key' => $key]);
$j = json_decode($body, true);
echo "[context api] HTTP $code ok=" . (int) ($j['ok'] ?? false) . ' est=' . ($j['est_tokens'] ?? '-') . "\n";

[$code, $body] = req($base . '?r=api/skill/list', ['X-API-Key' => $key]);
$j = json_decode($body, true);
$sk = $j['skills'] ?? [];
$auto = count(array_filter($sk, fn($s) => ($s['mode'] ?? '') === 'auto'));
echo "[skill list] HTTP $code total=" . count($sk) . " auto=$auto"
    . ' hasMode=' . has($body, '"mode"') . ' noBody=' . (has($body, '"body"') === 'NO' ? 'yes' : 'NO') . "\n";

[$code, $body] = req($base . '?r=api/status', ['X-API-Key' => $key]);
$j = json_decode($body, true);
echo "[old api/status] HTTP $code ok=" . (int) ($j['ok'] ?? false) . ' version=' . ($j['data']['version'] ?? '-') . "\n";

[$code, $body] = req($base . '?r=api/market/list', ['X-API-Key' => $key]);
$j = json_decode($body, true);
$cats = array_column($j['categories'] ?? [], 'key');
echo "[market list] HTTP $code total=" . ($j['stats']['total'] ?? '-')
    . ' beauty=' . (int) in_array('beauty', $cats, true)
    . ' utility=' . (int) in_array('utility', $cats, true) . "\n";
echo "DONE\n";
