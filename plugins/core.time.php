<?php
/** 内置插件：时间日期 */
return [
    'id' => 'core.time',
    'name' => '时间日期',
    'description' => '获取当前日期时间、时间戳、星期，或做简单的日期换算',
    'version' => '1.0.0', 'author' => 'dshphp', 'icon' => 'clock', 'enabled' => true,
    'tools' => [[
        'name' => 'get_time',
        'description' => '获取当前本地时间。传 format 可自定义 PHP date 格式；传 offset 可按天数偏移（如 1 表示明天）。',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'format' => ['type' => 'string', 'description' => 'PHP date 格式，默认 Y-m-d H:i:s'],
                'offset' => ['type' => 'integer', 'description' => '天数偏移，默认 0'],
            ],
            'required' => [],
        ],
    ]],
    'slash' => ['time' => '查看当前时间，可跟格式如 /time Y-m-d'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $fmt = trim((string) ($args['format'] ?? ($args['input'] ?? '')));
        if ($fmt === '' || strlen($fmt) > 40) $fmt = 'Y-m-d H:i:s';
        $offset = (int) ($args['offset'] ?? 0);
        $ts = time() + $offset * 86400;
        $week = ['日', '一', '二', '三', '四', '五', '六'][(int) date('w', $ts)];
        return [
            'ok' => true,
            'text' => date($fmt, $ts) . "（星期{$week}，时间戳 {$ts}，时区 " . date_default_timezone_get() . '）',
            'data' => ['timestamp' => $ts, 'formatted' => date($fmt, $ts)],
        ];
    },
];
