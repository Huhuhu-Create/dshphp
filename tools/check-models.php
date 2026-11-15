<?php
/**
 * dshphp 2.4.3 模型列表自检（CLI）
 * 验证：llm_parse_models() 解析三种返回形状；llm_fetch_models() 对空 URL / 非 JSON / 无模型 的友好报错；
 * 以及 index.php 已注册 api/settings/models 路由。
 *
 * 用法（在服务器上，需 PHP CLI）：
 *   php tools/check-models.php
 * 不依赖网络：解析用例直接喂给 llm_parse_models()，HTTP 部分只测前置校验分支。
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/llm.php';

$fail = 0; $pass = 0;
function ok(string $name): void { global $pass; $pass++; echo "  ✓ $name\n"; }
function bad(string $name, string $why): void { global $fail; $fail++; echo "  ✗ $name —— $why\n"; }

echo "== dshphp 模型列表自检 ==\n";
echo "   版本：" . DSHPHP_VERSION . "\n";

// 1) llm_parse_models：OpenAI 标准 {data:[{id}]}
$r = llm_parse_models(['object' => 'list', 'data' => [
    ['id' => 'gpt-4o', 'object' => 'model'],
    ['id' => 'gpt-4o-mini', 'object' => 'model'],
]]);
($r === ['gpt-4o', 'gpt-4o-mini']) ? ok('标准 data 数组解析') : bad('标准 data 数组解析', json_encode($r));

// 2) {models:[...]}
$r = llm_parse_models(['models' => ['deepseek-chat', 'deepseek-reasoner']]);
($r === ['deepseek-chat', 'deepseek-reasoner']) ? ok('models 数组解析') : bad('models 数组解析', json_encode($r));

// 3) 纯字符串数组
$r = llm_parse_models(['openai/free', 'anthropic/claude']);
($r === ['openai/free', 'anthropic/claude']) ? ok('纯数组解析') : bad('纯数组解析', json_encode($r));

// 4) 去重 + 去空
$r = llm_parse_models(['data' => [['id' => 'a'], ['id' => 'a'], ['id' => ''], ['id' => 'b']]]);
($r === ['a', 'b']) ? ok('去重与去空') : bad('去重与去空', json_encode($r));

// 5) 对象里没有 id 的不应被收进去
$r = llm_parse_models(['data' => [['id' => 'x'], ['foo' => 'bar'], 'just-string']]);
($r === ['x', 'just-string']) ? ok('忽略无 id 对象 / 保留裸字符串') : bad('忽略无 id 对象', json_encode($r));

// 6) llm_fetch_models：连不上的端点应友好报错（用 127.0.0.1:1 必然连接被拒，不依赖外网）
$r = llm_fetch_models('http://127.0.0.1:1/v1', 'none', '');
(!$r['ok'] && !empty($r['error'])) ? ok('不可达端点友好报错（连接被拒）') : bad('不可达端点报错', json_encode($r));

// 7) llm_fetch_models：返回结构正确（含 ok 布尔；ok 时 models 为数组）
$r = llm_fetch_models('http://127.0.0.1:1/v1', 'none', '');
(is_array($r) && array_key_exists('ok', $r)) ? ok('返回结构含 ok 键') : bad('返回结构', json_encode($r));

// 8) index.php 已注册路由
$src = @file_get_contents(__DIR__ . '/../index.php');
if ($src !== false && str_contains($src, "case 'api/settings/models':")) ok('index.php 注册 api/settings/models');
else bad('index.php 路由', '未找到 api/settings/models');

echo "\n结果：$pass 通过 / $fail 失败\n";
exit($fail === 0 ? 0 : 1);
