<?php
/** 市场插件：时间戳转换 */
return [
    'id' => 'mkt.timestamp', 'name' => '时间戳转换',
    'description' => '秒/毫秒时间戳与日期时间互转，自动识别 10 位与 13 位时间戳',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'clock',
    'category' => 'time', 'tags' => ['时间戳', 'Unix', '日期'], 'risk' => 'local',
    'usage' => '/ts 1700000000 或 /ts {"date":"2026-01-01 12:00:00"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'timestamp_convert', 'description' => '时间戳转日期或日期转时间戳；value 为空时返回当前时间戳',
        'parameters' => ['type' => 'object', 'properties' => [
            'value' => ['type' => 'string', 'description' => '时间戳或日期字符串'],
            'date' => ['type' => 'string', 'description' => '要转时间戳的日期字符串'],
        ], 'required' => []],
    ]],
    'slash' => ['ts' => '时间戳转换：/ts 1700000000'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $date = trim((string) ($args['date'] ?? ''));
        if ($date !== '') {
            $t = strtotime($date);
            if ($t === false) return ['ok' => false, 'text' => '无法解析日期：' . $date];
            return ['ok' => true, 'text' => $date . ' → 时间戳 ' . $t . '（毫秒 ' . ($t * 1000) . '），' . date('Y-m-d H:i:s', $t) . ' 星期' .
                ['日','一','二','三','四','五','六'][(int) date('w', $t)], 'data' => ['timestamp' => $t, 'ms' => $t * 1000]];
        }
        $v = trim((string) ($args['value'] ?? ($args['input'] ?? '')));
        if ($v === '' || strtolower($v) === 'now') $v = (string) time();
        if (!preg_match('~^-?\d{1,15}$~', $v)) return ['ok' => false, 'text' => '需要纯数字时间戳，或 date 参数'];
        $n = (float) $v;
        $unit = '';
        if (abs($n) >= 1e12) { $n = (int) ($n / 1000); $unit = '毫秒'; }
        elseif (abs($n) >= 1e10) { $n = (int) ($n / 1000); $unit = '微秒'; }
        else $n = (int) $n;
        if ($n < 0 || $n > 2147483647 * 8) return ['ok' => false, 'text' => '时间戳超出可处理范围'];
        $week = ['日','一','二','三','四','五','六'][(int) date('w', $n)];
        return ['ok' => true, 'text' => ($unit !== '' ? $unit . '时间戳 ' : '时间戳 ') . $v . ' → ' . date('Y-m-d H:i:s', $n) .
            '（星期' . $week . '，UTC ' . gmdate('Y-m-d H:i:s', $n) . '，当年第 ' . date('z', $n) . ' 天）',
            'data' => ['timestamp' => $n, 'formatted' => date('Y-m-d H:i:s', $n), 'utc' => gmdate('c', $n)]];
    },
];
