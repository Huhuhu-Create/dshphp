<?php
/**
 * 技能系统自检（CLI，开发期工具，不上传）
 *   php -c php-local.ini tools/check-skills.php
 *
 * 验证：frontmatter 解析、常驻/按需模式、触发词命中、单轮上限、三态开关兼容。
 * 会临时写 skill.state，结束时恢复原值。
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/plugins.php';
$_GET['r'] = '';
$store = new Store();
$fail = 0;
function skill_ok(string $label, bool $cond, string $extra = ''): void {
    global $fail;
    if (!$cond) $fail++;
    echo ($cond ? '[OK]   ' : '[FAIL] ') . $label . ($extra !== '' ? ' — ' . $extra : '') . PHP_EOL;
}

$origState = $store->kvGet('skill.state', null);
$store->kvSet('skill.state', []);

$scan = skill_scan();
echo '[scan] 共 ' . count($scan) . ' 个技能' . PHP_EOL;
skill_ok('技能总数 = 16（2 老 + 14 新）', count($scan) === 16, (string) count($scan));

$byId = [];
foreach ($scan as $s) $byId[$s['id']] = $s;

foreach (['assistant-basics', 'code-review'] as $id) {
    skill_ok("老技能 $id 仍为常驻且无触发词",
        isset($byId[$id]) && $byId[$id]['default_mode'] === 'always' && !$byId[$id]['triggers']);
}
$newOnes = ['writing-polish', 'summarize', 'translation', 'html-preview', 'web-design',
    'php-deploy', 'debug-method', 'data-analysis', 'sql-helper', 'regex-helper',
    'prompt-craft', 'learning-tutor', 'plugin-dev', 'automation-cron'];
$badNew = [];
foreach ($newOnes as $id) {
    if (!isset($byId[$id]) || $byId[$id]['default_mode'] !== 'auto' || count($byId[$id]['triggers']) < 3
        || $byId[$id]['name'] === $id || trim($byId[$id]['body']) === '') $badNew[] = $id;
}
skill_ok('14 个新技能均为按需模式且 frontmatter 解析正确', !$badNew, implode(',', $badNew));

$list = skill_list($store);
$listOk = true;
foreach ($list as $s) {
    if (!isset($s['mode'], $s['enabled'], $s['auto'], $s['when']) || isset($s['body'])) { $listOk = false; break; }
}
skill_ok('skill_list 字段齐全且不泄漏正文', $listOk);
skill_ok('默认启用数 = 16（按需技能面板里也算启用）',
    count(array_filter($list, fn($s) => $s['enabled'])) === 16);

$base = skill_prompt($store, '');
skill_ok('无上下文时只注入 2 个常驻技能',
    substr_count($base, '### 技能：') === 2
    && strpos($base, 'DSH 助手基本准则') !== false
    && strpos($base, '代码审查清单') !== false
    && strpos($base, '中文写作与润色') === false);
skill_ok('旧调用签名 skill_prompt($store) 仍可用', substr_count(skill_prompt($store), '### 技能：') === 2);

$hit = skill_prompt($store, '帮我写一个校验邮箱的正则');
skill_ok('触发词命中按需技能', strpos($hit, '正则表达式') !== false && strpos($hit, '本轮按需命中') !== false);
skill_ok('未命中的技能不进提示词', strpos($hit, '中文写作与润色') === false);

$multi = skill_prompt($store, '翻译这段 SQL，顺便讲下正则和 cron 定时任务，还有配色设计');
$cnt = substr_count($multi, '### 技能：');
skill_ok('单轮按需技能上限 3 个（含 2 常驻共 ≤5）', $cnt <= 5 && $cnt >= 3, (string) $cnt);

$id = 'regex-helper';
skill_set_enabled($store, $id, false);
skill_ok('显式关闭生效', skill_mode($byId[$id], skill_state($store)) === 'off'
    && strpos(skill_prompt($store, '正则'), '正则表达式') === false);
skill_set_enabled($store, $id, true);
skill_ok('显式常驻生效（不命中也注入）', skill_mode($byId[$id], skill_state($store)) === 'always'
    && strpos(skill_prompt($store, ''), '正则表达式') !== false);
skill_set_enabled($store, $id, false, 'auto');
skill_ok('mode=auto 恢复按需默认', skill_mode($byId[$id], skill_state($store)) === 'auto'
    && strpos(skill_prompt($store, ''), '正则表达式') === false
    && strpos(skill_prompt($store, '正则'), '正则表达式') !== false);
skill_ok('未知技能 toggle 返回 false', skill_set_enabled($store, 'not-exist', true) === false);

$store->kvSet('skill.state', is_array($origState) ? $origState : []);
echo $fail === 0 ? "SKILLS-CHECK: ALL PASS\n" : "SKILLS-CHECK: $fail FAILED\n";
exit($fail === 0 ? 0 : 1);
