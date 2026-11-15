<?php
/**
 * 插件开发自检工具（仅 CLI 可用）
 * 用法：php -n tools/check-plugins.php
 * 检查 market/ 下每个插件能否加载、典型用法是否返回成功、元数据是否冲突。
 * 需要已安装（存在 config.php）；会临时把全部市场插件装一遍再还原安装状态。
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once DSH_APP . '/store.php';
require_once DSH_APP . '/plugins.php';

if (!is_installed()) { fwrite(STDERR, "请先访问 install.php 完成安装\n"); exit(1); }

/** 每个插件的典型成功调用；新增插件时在这里补一条即可 */
function check_cases(): array {
    return [
        'mkt.wordcount' => ['text' => 'Hello 世界 123'],
        'mkt.encode' => ['action' => 'b64e', 'text' => 'hi'],
        'mkt.diff' => ['a' => "a\nb", 'b' => "a\nc"],
        'mkt.template' => ['template' => 'Hi {{name}}', 'vars' => ['name' => 'DSH']],
        'mkt.lorem' => ['paras' => 1, 'sentences' => 2],
        'mkt.extract' => ['text' => 'a@b.com 和 13800138000', 'kind' => 'email'],
        'mkt.slug' => ['text' => 'helloWorld demo', 'style' => 'snake'],
        'mkt.hash' => ['text' => 'abc', 'algo' => 'sha256'],
        'mkt.base' => ['value' => 'ff', 'from' => 16, 'to' => 10],
        'mkt.json' => ['text' => '{"a":{"b":1}}', 'action' => 'get', 'path' => 'a.b'],
        'mkt.regex' => ['pattern' => '[0-9]+', 'text' => 'a12b'],
        'mkt.jwt' => ['token' => 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjIxMDAwMDAwMDB9.x'],
        'mkt.url' => ['url' => 'https://example.com/a?x=1'],
        'mkt.semver' => ['a' => '1.2.3', 'b' => '1.10.0'],
        'mkt.cron' => ['expr' => '*/5 * * * *'],
        'mkt.unit' => ['value' => 1, 'from' => 'km', 'to' => 'm'],
        'mkt.percent' => ['action' => 'of', 'whole' => 200, 'pct' => 15],
        'mkt.finance' => ['action' => 'compound', 'principal' => 10000, 'rate' => 5, 'years' => 10],
        'mkt.stats' => ['numbers' => [1, 2, 3, 4, 5]],
        'mkt.random' => ['action' => 'dice', 'sides' => 6, 'count' => 2],
        'mkt.csv' => ['text' => "a,b\n1,2", 'action' => 'markdown'],
        'mkt.bignum' => ['a' => '999999999999', 'b' => '888888888888', 'op' => 'mul'],
        'mkt.timestamp' => ['value' => '1700000000'],
        'mkt.datediff' => ['a' => '2026-01-01', 'b' => '2026-12-31'],
        'mkt.countdown' => ['target' => '2027-01-01'],
        'mkt.dayinfo' => ['date' => '2026-10-01'],
        'mkt.ip' => ['ip' => '192.168.1.0/24'],
        'mkt.httpcode' => ['code' => '429'],
        'mkt.mime' => ['value' => 'webp'],
        'mkt.env' => [],
        'mkt.ua' => ['ua' => 'Mozilla/5.0 Chrome/120.0'],
        'mkt.idcard' => ['id' => '11010119900307001X'],
        'mkt.phone' => ['phone' => '13800138000'],
        'mkt.color' => ['color' => '#5b8cff'],
        'mkt.password' => ['length' => 16],
        'mkt.morse' => ['text' => 'SOS'],
        'mkt.caesar' => ['text' => 'hello', 'shift' => 3],
        'mkt.decision' => ['options' => ['A', 'B']],
        'mkt.fortune' => ['name' => '老板'],
        'mkt.emoji' => ['keyword' => '猫'],
        'mkt.todo' => ['action' => 'list'],
        'mkt.memory' => ['action' => 'list'],
        'mkt.vtree' => ['action' => 'stats'],
    ];
}

$store = new Store();
$before = market_installed_ids($store, true);
market_batch($store, ['preset' => 'all']);

$all = market_scan();
$cases = check_cases();
$errs = [];
$ran = 0; $success = 0;
foreach ($all as $id => $p) {
    if (!empty($p['broken'])) { $errs[] = $id . ' 无法加载'; continue; }
    // 2.4 纯界面插件没有 tools / run，只校验 ui 清单，不走 plugin_invoke
    if (empty($p['tools']) && !empty($p['ui'])) {
        $ran++;
        if ((int) ($p['ui']['apiVersion'] ?? 0) !== 1) $errs[] = $id . ' ui 清单 apiVersion 非法';
        elseif (empty($p['ui']['scope'])) $errs[] = $id . ' ui 清单缺 scope';
        else $success++;
        continue;
    }
    if (!isset($cases[$id])) $errs[] = $id . ' 缺少典型用例';
    $tool = $p['tools'][0] ?? '';
    $r = plugin_invoke($store, $id, $tool, $cases[$id] ?? [], ['session_id' => 'check']);
    $ran++;
    if (!is_array($r)) { $errs[] = $id . ' 返回非数组'; continue; }
    if (!empty($r['ok'])) $success++;
    else $errs[] = $id . ' 典型用法失败：' . str_cut((string) ($r['text'] ?? ''), 120);
    if (($r['error'] ?? '') === 'exception') $errs[] = $id . ' 执行异常：' . ($r['text'] ?? '');
    if (($r['error'] ?? '') === 'not-found') $errs[] = $id . ' 未被 plugin_scan 收录';
    if (($r['text'] ?? '') === '') $errs[] = $id . ' 返回空文本';
}
$toolCount = count(plugin_toolmap($store)['tools']);
market_set($store, $before);

echo '插件总数：' . count($all) . '，实跑：' . $ran . '，成功：' . $success . "\n";
echo '分类：' . json_encode(array_count_values(array_column($all, 'category')), JSON_UNESCAPED_UNICODE) . "\n";
echo '全部安装时工具表：' . $toolCount . " 个\n";
if ($errs) { echo "问题：\n - " . implode("\n - ", $errs) . "\n"; exit(1); }
echo "全部插件可加载、典型用法全部成功 ✓\n";
