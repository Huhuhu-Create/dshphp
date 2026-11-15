<?php
/** 市场插件：日期差与日期推算 */
return [
    'id' => 'mkt.datediff', 'name' => '日期差计算',
    'description' => '计算两个日期相差天数/工作日，或在某日期上加减天数、月数、年数',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'clock',
    'category' => 'time', 'tags' => ['日期', '天数', '工作日'], 'risk' => 'local',
    'usage' => '/datecalc {"a":"2026-01-01","b":"2026-12-31"} 或 {"date":"2026-01-01","add":30}',
    'enabled' => true,
    'tools' => [[
        'name' => 'date_calc', 'description' => '日期差或日期推算。有 b 时算差值；否则用 add（天）/months/years 推算',
        'parameters' => ['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'description' => '起始日期'],
            'b' => ['type' => 'string', 'description' => '结束日期'],
            'date' => ['type' => 'string', 'description' => '推算的基准日期'],
            'add' => ['type' => 'integer', 'description' => '加减天数'],
            'months' => ['type' => 'integer', 'description' => '加减月数'],
            'years' => ['type' => 'integer', 'description' => '加减年数'],
        ], 'required' => []],
    ]],
    'slash' => ['datecalc' => '参数为 JSON：/datecalc {"a":"2026-01-01","b":"2026-12-31"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $a = trim((string) ($args['a'] ?? ''));
        $b = trim((string) ($args['b'] ?? ''));
        if ($a !== '' && $b !== '') {
            $ta = strtotime($a); $tb = strtotime($b);
            if ($ta === false || $tb === false) return ['ok' => false, 'text' => '日期格式无法解析'];
            $d0 = strtotime(date('Y-m-d', min($ta, $tb))); $d1 = strtotime(date('Y-m-d', max($ta, $tb)));
            $days = (int) round(($d1 - $d0) / 86400);
            $work = 0;
            for ($t = $d0, $i = 0; $i < $days; $i++, $t += 86400) {
                $w = (int) date('w', $t);
                if ($w !== 0 && $w !== 6) $work++;
            }
            $sign = $ta > $tb ? '（a 晚于 b）' : '';
            return ['ok' => true, 'text' => $a . ' → ' . $b . '：相差 ' . $days . ' 天 / ' . $work . ' 个工作日 / 约 ' .
                round($days / 7, 2) . ' 周 / 约 ' . round($days / 30.44, 2) . ' 个月' . $sign,
                'data' => ['days' => $days, 'workdays' => $work]];
        }
        $date = $a !== '' ? $a : trim((string) ($args['date'] ?? ($args['input'] ?? '')));
        if ($date === '') return ['ok' => false, 'text' => '需要 a / b 或 date 参数'];
        $t = strtotime($date);
        if ($t === false) return ['ok' => false, 'text' => '日期格式无法解析：' . $date];
        $addD = max(-36500, min(36500, (int) ($args['add'] ?? 0)));
        $addM = max(-1200, min(1200, (int) ($args['months'] ?? 0)));
        $addY = max(-200, min(200, (int) ($args['years'] ?? 0)));
        $parts = [];
        if ($addY) $parts[] = ($addY > 0 ? '+' : '') . $addY . ' year';
        if ($addM) $parts[] = ($addM > 0 ? '+' : '') . $addM . ' month';
        if ($addD) $parts[] = ($addD > 0 ? '+' : '') . $addD . ' day';
        $new = $parts ? strtotime(implode(' ', $parts), $t) : $t;
        if ($new === false) return ['ok' => false, 'text' => '推算失败'];
        $week = ['日','一','二','三','四','五','六'][(int) date('w', $new)];
        return ['ok' => true, 'text' => $date . ' ' . ($parts ? '(' . implode(' ', $parts) . ') ' : '') . '→ ' .
            date('Y-m-d', $new) . '（星期' . $week . '，当年第 ' . ((int) date('z', $new) + 1) . ' 天，第 ' . date('W', $new) . ' 周）',
            'data' => ['result' => date('Y-m-d', $new), 'weekday' => $week]];
    },
];
