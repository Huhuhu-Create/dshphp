<?php
/** 内置插件：工具编排（一次调用串起多个插件） */
return [
    'id' => 'core.chain',
    'name' => '工具编排',
    'description' => '把多个工具按顺序串成一条流水线，按顺序执行并汇总结果',
    'version' => '1.0.0', 'author' => 'dshphp', 'icon' => 'flow', 'enabled' => false,
    'tools' => [[
        'name' => 'run_chain',
        'description' => '串行执行多个工具。steps 是数组，每项 {"name":"工具名","arguments":{...}}',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'steps' => ['type' => 'array', 'description' => '步骤数组，最多 6 步', 'items' => ['type' => 'object']],
            ],
            'required' => ['steps'],
        ],
    ]],
    'slash' => ['chain' => '参数为 JSON：/chain {"steps":[...]}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $steps = $args['steps'] ?? ($args['input'] ?? []);
        if (is_string($steps)) { $d = json_decode($steps, true); $steps = is_array($d) ? $d : []; }
        if (!is_array($steps) || !$steps) return ['ok' => false, 'text' => '缺少 steps'];
        $store = $ctx['store'] ?? null;
        if (!$store instanceof Store) return ['ok' => false, 'text' => '存储不可用'];
        $out = [];
        foreach (array_slice(array_values($steps), 0, 6) as $i => $s) {
            if (!is_array($s)) continue;
            $name = (string) ($s['name'] ?? '');
            if ($name === '') { $out[] = ($i + 1) . '. 跳过（缺少 name）'; continue; }
            $r = plugin_invoke_tool($store, $name, $s['arguments'] ?? [], $ctx);
            $out[] = ($i + 1) . '. ' . $name . ' → ' . ($r['ok'] ? 'OK' : 'FAIL') . '：' . str_cut((string) ($r['text'] ?? ''), 500);
        }
        return ['ok' => true, 'text' => implode("\n", $out), 'data' => ['steps' => count($out)]];
    },
];
