<?php
/** 市场插件：正则表达式测试（带安全限制） */
return [
    'id' => 'mkt.regex', 'name' => '正则测试',
    'description' => '测试 PCRE 正则：返回匹配结果与捕获组；限制长度，禁止 e 修饰符与危险构造',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'code',
    'category' => 'dev', 'tags' => ['正则', 'regex', '匹配'], 'risk' => 'local',
    'usage' => '/regex {"pattern":"\\d+","text":"abc123"}，pattern 可自带分隔符',
    'enabled' => true,
    'tools' => [[
        'name' => 'regex_test', 'description' => '对一段文本运行正则，返回匹配项与捕获组',
        'parameters' => ['type' => 'object', 'properties' => [
            'pattern' => ['type' => 'string', 'description' => 'PCRE 表达式'],
            'text' => ['type' => 'string', 'description' => '待匹配文本'],
        ], 'required' => ['pattern', 'text']],
    ]],
    'slash' => ['regex' => '参数为 JSON：/regex {"pattern":"[a-z]+","text":"hello 123"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $p = trim((string) ($args['pattern'] ?? ''));
        $t = (string) ($args['text'] ?? ($args['input'] ?? ''));
        if ($p === '' || $t === '') return ['ok' => false, 'text' => '缺少 pattern / text 参数'];
        if (strlen($p) > 200 || strlen($t) > 20000) return ['ok' => false, 'text' => '表达式或文本过长'];
        if (str_contains($p, '(?R)') || str_contains($p, '(?1)') || str_contains($p, '(?2)')) {
            return ['ok' => false, 'text' => '出于安全考虑，禁止递归引用类表达式'];
        }
        $full = $p;
        if (preg_match('~^(.)(.*)\1([imsxADSUXJu]*)$~s', $p, $pm)) {
            if (str_contains((string) $pm[3], 'e')) return ['ok' => false, 'text' => '禁止使用 e 修饰符（会执行代码）'];
        } else {
            $full = '~' . str_replace('~', '\~', $p) . '~u';
        }
        $n = @preg_match_all($full, $t, $mm, PREG_SET_ORDER);
        if ($n === false) return ['ok' => false, 'text' => '正则表达式无效，或超出引擎限制'];
        $lines = ['匹配 ' . $n . ' 处'];
        foreach (array_slice($mm, 0, 15) as $i => $one) {
            $lines[] = '#' . ($i + 1) . ': ' . str_cut((string) $one[0], 120) .
                (count($one) > 1 ? ' | 捕获组: ' . implode(' / ', array_map(fn($x) => str_cut((string) $x, 60), array_slice($one, 1))) : '');
        }
        if ($n > 15) $lines[] = '（仅显示前 15 条）';
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['count' => $n, 'matches' => array_slice($mm, 0, 15)]];
    },
];
