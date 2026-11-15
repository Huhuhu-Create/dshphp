<?php
/** 市场插件：中文占位文本生成 */
return [
    'id' => 'mkt.lorem', 'name' => '中文占位文本',
    'description' => '生成用于排版测试的中文假文（Lorem ipsum 中文版），可指定段落与句数',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'text',
    'category' => 'text', 'tags' => ['假文', '排版', '占位'], 'risk' => 'local',
    'usage' => '/lorem 3（生成 3 段）或 /lorem {"paras":2,"sentences":4}',
    'enabled' => true,
    'tools' => [[
        'name' => 'lorem_zh', 'description' => '生成指定段数的中文占位文本，用于页面排版测试',
        'parameters' => ['type' => 'object', 'properties' => [
            'paras' => ['type' => 'integer', 'description' => '段落数 1-10，默认 2'],
            'sentences' => ['type' => 'integer', 'description' => '每段句数 1-8，默认 4'],
        ], 'required' => []],
    ]],
    'slash' => ['lorem' => '生成中文占位文本：/lorem 3 或 /lorem {"paras":2,"sentences":4}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $raw = $args['paras'] ?? ($args['input'] ?? 2);
        if (is_string($raw) && !is_numeric($raw)) { $d = json_decode($raw, true); if (is_array($d)) $args = array_merge($args, $d); $raw = $args['paras'] ?? 2; }
        $paras = max(1, min(10, (int) $raw));
        $sents = max(1, min(8, (int) ($args['sentences'] ?? 4)));
        $sub = ['清晰的界面', '稳定的服务', '简洁的表达', '可靠的数据', '自然的交互', '合理的结构',
            '准确的信息', '轻量的实现', '灵活的扩展', '一致的体验', '可读的代码', '务实的方案'];
        $verb = ['能够提升', '有助于构建', '需要兼顾', '通常依赖', '往往来自', '可以简化', '始终围绕', '最终服务于'];
        $obj = ['整体效率', '用户理解', '维护成本', '响应速度', '信息密度', '协作流程', '长期迭代', '交付质量'];
        $out = [];
        for ($p = 0; $p < $paras; $p++) {
            $buf = [];
            for ($s = 0; $s < $sents; $s++) {
                $buf[] = $sub[random_int(0, count($sub) - 1)] . $verb[random_int(0, count($verb) - 1)] .
                    $obj[random_int(0, count($obj) - 1)] . '，' .
                    $sub[random_int(0, count($sub) - 1)] . '因此成为设计时需要优先考量的部分。';
            }
            $out[] = implode('', $buf);
        }
        return ['ok' => true, 'text' => implode("\n\n", $out), 'data' => ['paras' => $paras, 'sentences' => $sents]];
    },
];
