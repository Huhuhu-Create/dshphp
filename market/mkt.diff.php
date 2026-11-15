<?php
/** 市场插件：文本差异比对（按行的 LCS） */
return [
    'id' => 'mkt.diff', 'name' => '文本比对',
    'description' => '比较两段文本的逐行差异，输出 + 新增 / - 删除 / 相同行',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'text',
    'category' => 'text', 'tags' => ['diff', '比对', '差异'], 'risk' => 'local',
    'usage' => '把两段文本分别填进 a、b 字段，例如 /diff {"a":"...","b":"..."}',
    'enabled' => true,
    'tools' => [[
        'name' => 'text_diff', 'description' => '按行比较两段文本，返回新增/删除/相同的行',
        'parameters' => ['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'description' => '原文'],
            'b' => ['type' => 'string', 'description' => '新文'],
        ], 'required' => ['a', 'b']],
    ]],
    'slash' => ['diff' => '参数为 JSON：/diff {"a":"旧文本","b":"新文本"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $a = (string) ($args['a'] ?? '');
        $b = (string) ($args['b'] ?? '');
        $A = preg_split('/\r\n|\n|\r/', $a) ?: [];
        $B = preg_split('/\r\n|\n|\r/', $b) ?: [];
        if (count($A) > 300 || count($B) > 300) return ['ok' => false, 'text' => '行数过多（上限 300 行）'];
        $n = count($A); $m = count($B);
        $dp = [];
        for ($i = 0; $i <= $n; $i++) $dp[$i] = array_fill(0, $m + 1, 0);
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $A[$i] === $B[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $out = []; $i = 0; $j = 0; $add = 0; $del = 0; $same = 0;
        while ($i < $n && $j < $m) {
            if ($A[$i] === $B[$j]) { $out[] = '  ' . $A[$i]; $i++; $j++; $same++; }
            elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) { $out[] = '- ' . $A[$i]; $i++; $del++; }
            else { $out[] = '+ ' . $B[$j]; $j++; $add++; }
        }
        while ($i < $n) { $out[] = '- ' . $A[$i]; $i++; $del++; }
        while ($j < $m) { $out[] = '+ ' . $B[$j]; $j++; $add++; }
        $head = "比对结果：+ 新增 {$add} 行 / - 删除 {$del} 行 / 相同 {$same} 行";
        return ['ok' => true, 'text' => $head . "\n" . str_cut(implode("\n", $out), 6000),
            'data' => ['added' => $add, 'removed' => $del, 'same' => $same]];
    },
];
