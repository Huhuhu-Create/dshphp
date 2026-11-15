<?php
/** 本机 CLI 自检（tools/ 不进部署包）：插件 GUI API 清单聚合 / 资源 / 上下文 / 老插件兼容 */
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
require_once $root . '/app/store.php';
require_once $root . '/app/plugins.php';
require_once $root . '/app/plugin-ui.php';
$_GET['r'] = '';

$store = new Store();
$ids = ['mkt.sidebar', 'mkt.context', 'mkt.vtree', 'mkt.glass', 'mkt.passwords', 'mkt.browser'];
foreach ($ids as $id) { market_install($store, $id); plugin_set_enabled($store, $id, true); }
market_installed_ids($store, true);

$list = plugin_ui_manifest($store, 'app');
echo '[app] ui plugins = ' . count($list) . "\n";
foreach ($list as $x) {
    echo '  - ' . $x['id'] . '  css=' . strlen($x['css']) . 'B  js=' . strlen($x['js']) . 'B  scope=' . implode(',', $x['scope']) . "\n";
}
$boot = plugin_ui_boot($store, 'app');
echo '[boot] enabled=' . (int) $boot['enabled'] . ' cssUrl=' . $boot['cssUrl'] . "\n";
echo '[css ] bytes=' . strlen(plugin_ui_css($store, 'app')) . "\n";
echo '[js  ] bytes=' . strlen(plugin_ui_js($store, 'app')) . "\n";
echo '[login] ui plugins = ' . count(plugin_ui_manifest($store, 'login')) . "\n";

$dir = $root . '/tools/.ui-check';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
foreach ($list as $x) {
    file_put_contents($dir . '/' . $x['id'] . '.js',
        "module.exports = function (DSH, window, document) {\n" . $x['js'] . "\n};\n");
}

echo '[compat] market=' . count(market_scan()) . ' plugin_list=' . count(plugin_list($store)) . "\n";
$ctx = plugin_ui_context($store, '');
echo '[context] ok=' . (int) $ctx['ok'] . ' est=' . $ctx['est_tokens'] . ' limit=' . $ctx['context_limit'] . "\n";
echo '[apikey] ' . substr((string) cfg()['api_key'], 0, 4) . "…\n";

// 校验白名单：插件不能通过 files 读取目录外的文件
$fake = ['apiVersion' => 1, 'scope' => ['app'], 'files' => ['js' => ['../bootstrap.php', 'a/b.js', 'ok.js']]];
$n = plugin_ui_normalize($fake, 'test', $root . '/market/mkt.glass.php');
echo '[sandbox] normalize=' . ($n === null ? 'null' : 'ok') . ' dangerous-read=' . strlen($n['js'] ?? '') . "B\n";
echo "DONE\n";
