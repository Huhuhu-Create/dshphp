<?php
/** 市场插件：决策助手 */
return [
    'id' => 'mkt.decision', 'name' => '决策助手',
    'description' => '在多个选项里随机抽一个、给「是/否」建议，或按权重做决定',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'spark',
    'category' => 'fun', 'tags' => ['抽签', '选择', '决策'], 'risk' => 'local',
    'usage' => '/decide 选项A 选项B 选项C，或 /decide {"question":"要不要去？"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'make_decision', 'description' => '传 options 数组做抽签；不传则返回「是/否/再想想」建议',
        'parameters' => ['type' => 'object', 'properties' => [
            'options' => ['type' => 'array', 'description' => '候选选项', 'items' => ['type' => 'string']],
            'question' => ['type' => 'string', 'description' => '要决定的问题'],
        ], 'required' => []],
    ]],
    'slash' => ['decide' => '抽签：/decide 吃饭 睡觉 写代码'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $q = trim((string) ($args['question'] ?? ''));
        $opts = $args['options'] ?? ($args['input'] ?? null);
        if (is_string($opts)) {
            $d = json_decode($opts, true);
            $opts = is_array($d) ? $d : (preg_split('/[\s,，、]+/', trim($opts)) ?: []);
        }
        if (!is_array($opts)) $opts = [];
        $opts = array_values(array_filter(array_map('strval', $opts), fn($x) => trim($x) !== ''));
        if ($opts) {
            if (count($opts) > 100) return ['ok' => false, 'text' => '选项过多（上限 100）'];
            $pick = $opts[random_int(0, count($opts) - 1)];
            return ['ok' => true, 'text' => ($q !== '' ? '问题：' . $q . "\n" : '') .
                '在 ' . count($opts) . ' 个选项（' . implode('、', $opts) . '）中抽中：👉 ' . $pick,
                'data' => ['picked' => $pick, 'options' => $opts]];
        }
        $answers = ['可以，去做吧 ✓', '再等等，时机未到', '先做小范围试点', '不建议，风险偏高', '换个思路会更顺', '问得好，答案是可以'];
        $pick = $answers[random_int(0, count($answers) - 1)];
        return ['ok' => true, 'text' => ($q !== '' ? '问题：' . $q . "\n" : '') . '建议：' . $pick .
            "\n（娱乐性质；真要决策还是列出选项用 /decide A B C）", 'data' => ['answer' => $pick]];
    },
];
