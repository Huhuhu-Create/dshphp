<?php
/**
 * CLI 自检：系统提示词预设是否注入到配置与 boot 的 chat 段。
 */
require 'app/bootstrap.php';
$c = cfg();
$list = $c['chat']['system_presets'] ?? [];
$ok = true;
echo 'presets=' . count($list) . "\n";
if (count($list) < 7) { echo "  FAIL 数量不足 7\n"; $ok = false; }
$ids = [];
foreach ($list as $i => $p) {
    if (empty($p['id']) || empty($p['name']) || empty($p['prompt'])) { echo "  FAIL 第 {$i} 个字段缺失\n"; $ok = false; }
    if (in_array($p['id'], $ids, true)) { echo "  FAIL 重复 id: {$p['id']}\n"; $ok = false; }
    $ids[] = $p['id'];
}
echo 'boot.chat.system_presets=' . (isset($c['chat']['system_presets']) ? 'yes' : 'no') . "\n";
if (!isset($c['chat']['system_presets'])) { $ok = false; }
// 确认 id 含 default（其余预设可随版本增减，但 default 必须存在）
echo 'has default=' . (in_array('default', $ids, true) ? 'yes' : 'no') . "\n";
if (!in_array('default', $ids, true)) { $ok = false; }
echo $ok ? "PRESETS-OK\n" : "PRESETS-FAIL\n";
