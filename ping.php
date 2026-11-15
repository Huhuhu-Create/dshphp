<?php
/**
 * 环境自检页：上传后第一个访问它，确认文件在 Web 能访问的位置、PHP 能执行、扩展齐全。
 * 排障完成后建议删除本文件；带 ?detail=1 才会显示脚本绝对路径。
 * 带 ?net=1 会从服务器侧探测各模型线路的出站连通性（DNS / TLS / HTTP），
 * 直接给出卡在哪一层的结论；只测 config.php 里配置的固定地址，不接收自定义 URL、不打印密钥。
 */
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once DSH_APP . '/automation.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$detail = isset($_GET['detail']);
$net = isset($_GET['net']);   // ?net=1 服务器侧出站网络自检
$sqliteOk = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
$installed = is_installed();
$info = $installed ? storage_info() : ['driver' => '未安装', 'dir' => '', 'file' => ''];
$dataDir = DSH_DATA_DIR . ($installed && $info['dir'] !== '' ? '/' . $info['dir'] : '');

echo "PING-OK\n";
echo "dshphp=" . DSHPHP_VERSION . "\n";
echo "php=" . PHP_VERSION . "\n";
echo "time=" . date('Y-m-d H:i:s') . " (" . date_default_timezone_get() . ")\n";
if ($detail) {
    echo "script=" . __FILE__ . "\n";
    echo "root=" . DSH_ROOT . "\n";
}
echo "\n[扩展]\n";
echo "json=" . (function_exists('json_encode') ? 'yes' : 'no') . "\n";
echo "curl=" . (function_exists('curl_init') ? 'yes' : 'no (stream 降级)') . "\n";
echo "openssl=" . (extension_loaded('openssl') ? 'yes' : 'no (https 上游可能不可用)') . "\n";
echo "pdo_sqlite=" . ($sqliteOk ? 'yes' : 'no (JSON 降级)') . "\n";
echo "mbstring=" . (extension_loaded('mbstring') ? 'yes' : 'no (内置 UTF-8 降级)') . "\n";
echo "allow_url_fopen=" . (ini_get('allow_url_fopen') ? 'yes' : 'no') . "\n";
echo "\n[权限]\n";
echo "data_dir_writable=" . (is_dir($dataDir) && is_writable($dataDir) ? 'yes' : 'no') . "\n";
echo "config_writable=" . (is_writable(DSH_ROOT) || ($installed && is_writable(DSH_ROOT . '/config.php')) ? 'yes' : 'no') . "\n";
echo "\n[状态]\n";
echo "installed=" . ($installed ? 'yes' : 'no') . "\n";
echo "storage_driver=" . (string) $info['driver'] . "\n";
echo "market_plugins=" . count(dsh_glob_php(DSH_MARKET_DIR)) . "\n";
echo "market_readable=" . (is_dir(DSH_MARKET_DIR) && is_readable(DSH_MARKET_DIR) ? 'yes' : 'no') . "\n";
if ($installed) {
    try {
        require_once DSH_APP . '/store.php';
        $s = new Store();
        $st = $s->stats();
        echo "storage_connected=yes\n";
        echo "sessions=" . (int) $st['sessions'] . " messages=" . (int) $st['messages'] . " bytes=" . (int) $st['bytes'] . "\n";
    } catch (Throwable $e) {
        echo "storage_connected=no (" . $e->getMessage() . ")\n";
    }
    echo "plugins=" . count(dsh_glob_php(DSH_PLUGIN_DIR)) . "\n";
    echo "skills=" . count(dsh_glob_md(DSH_SKILL_DIR)) . "\n";
    try {
        // 刻意只报状态，不打印自动化 JSON 的真实文件名
        $a = automation_read();
        $ai = automation_info();
        echo "automation_tasks=" . count($a['tasks']) . " enabled=" . (empty($a['enabled']) ? 'no' : 'yes') . "\n";
        echo "automation_driver=access-only keepalive=no cron=disabled\n";
        echo "automation_ticks=" . (int) ($a['meta']['ticks'] ?? 0) . " runs=" . count($a['runs']) . "\n";
        echo "automation_file=" . (is_file($ai['file']) ? 'ready' : 'pending')
            . " writable=" . (is_dir($ai['dir']) && is_writable($ai['dir']) ? 'yes' : 'no') . "\n";
    } catch (Throwable $e) {
        echo "automation=no (" . $e->getMessage() . ")\n";
    }
} else {
    echo "next=访问 install.php 完成安装\n";
}
if ($net) {
    echo "\n[网络自检 ?net=1]\n";
    $hasCurl = function_exists('curl_init');
    $hasSsl  = extension_loaded('openssl');
    $caSet   = ((string) ini_get('curl.cainfo') !== '' || (string) ini_get('openssl.cafile') !== '');
    echo "transport=" . ($hasCurl ? 'curl' : 'stream') . "\n";
    echo "curl=" . ($hasCurl ? 'yes' : 'no') . " openssl=" . ($hasSsl ? 'yes' : 'no')
        . " allow_url_fopen=" . (ini_get('allow_url_fopen') ? 'yes' : 'no') . "\n";
    echo "ca_bundle=" . ($caSet ? 'configured' : 'auto(系统证书存储)')
        . " native_ca=" . (defined('CURLSSLOPT_NATIVE_CA') ? 'yes' : 'no') . "\n";

    if (!$installed) {
        echo "verdict=尚未安装，跳过线路探测；请先访问 install.php\n";
    } else {
        $c = cfg();
        $targets = [];
        $primary = (string) ($c['llm']['base_url'] ?? '');
        if ($primary !== '') $targets[] = ['主路由', $primary];
        foreach ((array) ($c['routing']['candidates'] ?? []) as $r) {
            if (is_array($r) && !empty($r['enabled']) && !empty($r['base_url'])) {
                $targets[] = [(string) ($r['name'] ?? '线路'), (string) $r['base_url']];
            }
        }
        $allFail = true;
        $firstBrief = '';
        foreach ($targets as [$nm, $base]) {
            $url  = rtrim($base, '/');
            $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
            $ip   = $host !== '' ? @gethostbyname($host) : '';
            $dns  = ($ip !== '' && $ip !== $host) ? 'ok' : 'fail';
            $t0   = microtime(true);
            $r    = http_request($url, ['method' => 'GET', 'headers' => ['Accept: application/json'], 'timeout' => 6]);
            $ms   = (int) round((microtime(true) - $t0) * 1000);
            $st   = (int) ($r['status'] ?? 0);
            $brief = (string) ($r['brief'] ?? '');
            if ($st !== 0) $allFail = false;
            elseif ($firstBrief === '') $firstBrief = $brief !== '' ? $brief : (string) ($r['error'] ?? '');
            echo "route=" . $nm . " host=" . $host . " dns=" . $dns
                . " http=" . $st . " ms=" . $ms
                . " connect=" . ($st !== 0 ? 'ok' : 'fail')
                . ($st === 0 ? " reason=" . $brief : '') . "\n";
        }
        if (!$hasCurl && !$hasSsl) {
            echo "verdict=PHP 未启用 curl / openssl 扩展 → 所有 https 上游必然失败；启用扩展即可（本机用 php-local.ini）\n";
        } elseif ($allFail && $firstBrief !== '') {
            if (strpos($firstBrief, 'openssl') !== false) {
                echo "verdict=PHP 缺 openssl 扩展 → 启用后重试\n";
            } elseif (strpos($firstBrief, '证书') !== false) {
                echo "verdict=CA 根证书校验失败 → 配置 curl.cainfo / openssl.cafile，或让 libcurl 使用系统证书\n";
            } elseif (strpos($firstBrief, 'DNS') !== false) {
                echo "verdict=DNS 解析失败 → 检查服务器 DNS / hosts\n";
            } elseif (strpos($firstBrief, '超时') !== false || strpos($firstBrief, '不可达') !== false) {
                echo "verdict=出站网络不通或被阻断 → 换国内可直连的 API Key 最稳\n";
            } else {
                echo "verdict=所有出站连接失败：" . str_cut($firstBrief, 90) . "\n";
            }
        } elseif (!$allFail) {
            echo "verdict=网络与 TLS 可达；若聊天仍失败，去「设置 → 线路」点「测试全部线路」看鉴权/上游错误\n";
        } else {
            echo "verdict=没有配置任何线路\n";
        }
    }
    echo "note=本页不打印任何密钥，也不接受自定义 URL；探测地址只来自 config.php\n";
}

echo "\n[说明]\n";
echo "本页刻意不打印数据文件的真实名称与任何密钥。\n";
echo "若看到的是 PHP 源码或触发下载，说明 Nginx 缺少 location ~ \\.php$ 解析块。\n";
echo "排障完成后请删除 ping.php。\n";
