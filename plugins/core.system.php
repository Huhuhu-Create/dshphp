<?php
/** 内置插件：系统自检（不泄露密钥与真实路径） */
return [
    'id' => 'core.system',
    'name' => '系统状态',
    'description' => '查看 dshphp 运行状态：版本、PHP、存储后端、插件与技能数量、当前模型',
    'version' => '1.0.0', 'author' => 'dshphp', 'icon' => 'cpu', 'enabled' => true,
    'tools' => [[
        'name' => 'system_status',
        'description' => '返回服务器运行状态摘要，用于自检或排障',
        'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
    ]],
    'slash' => ['ping' => '自检：返回 PONG 与系统状态'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $c = cfg();
        $store = $ctx['store'] ?? null;
        $st = $store instanceof Store ? $store->stats() : ['driver' => 'unknown'];
        $info = [
            'PONG',
            'dshphp ' . DSHPHP_VERSION,
            'PHP ' . PHP_VERSION,
            '存储后端 ' . ($st['driver'] ?? '?') . '，' . (int) ($st['sessions'] ?? 0) . ' 个会话 / ' . (int) ($st['messages'] ?? 0) . ' 条消息',
            '插件 ' . count(plugin_list($store)) . ' 个，技能 ' . count(skill_list($store)) . ' 个',
            '模型 ' . (string) $c['llm']['model'],
            'HTTP 通道 ' . (function_exists('curl_init') ? 'cURL' : 'stream'),
            '时间 ' . date('Y-m-d H:i:s'),
        ];
        return ['ok' => true, 'text' => implode("\n", $info), 'data' => ['php' => PHP_VERSION, 'driver' => $st['driver'] ?? '?']];
    },
];
