<?php
/** 市场插件：日期信息与公历节日速查（不编造调休安排） */
return [
    'id' => 'mkt.dayinfo', 'name' => '日期速查',
    'description' => '查看某天是星期几、年内第几天、第几周，以及公历固定节日与距离下一个节日的天数',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'clock',
    'category' => 'time', 'tags' => ['日历', '节日', '星期'], 'risk' => 'local',
    'usage' => '/dayinfo 2026-10-01（留空为今天）',
    'enabled' => true,
    'tools' => [[
        'name' => 'day_info', 'description' => '查询日期的星期、周序、公历节日，并列出未来最近的固定节日',
        'parameters' => ['type' => 'object', 'properties' => [
            'date' => ['type' => 'string', 'description' => '日期，如 2026-10-01；留空为今天'],
        ], 'required' => []],
    ]],
    'slash' => ['dayinfo' => '日期速查：/dayinfo 2026-10-01'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $d = trim((string) ($args['date'] ?? ($args['input'] ?? '')));
        $t = $d === '' ? time() : strtotime($d);
        if ($t === false) return ['ok' => false, 'text' => '日期无法解析：' . $d];
        $fixed = [
            '01-01' => '元旦', '02-14' => '情人节', '03-08' => '国际妇女节', '03-12' => '植树节',
            '04-01' => '愚人节', '05-01' => '国际劳动节', '05-04' => '青年节', '06-01' => '儿童节',
            '07-01' => '建党节', '08-01' => '建军节', '09-10' => '教师节', '10-01' => '国庆节',
            '11-11' => '双十一', '12-24' => '平安夜', '12-25' => '圣诞节', '12-31' => '跨年夜',
        ];
        $md = date('m-d', $t);
        $week = ['日','一','二','三','四','五','六'][(int) date('w', $t)];
        $lines = [
            '日期 ' . date('Y-m-d', $t) . '，星期' . $week,
            '年内第 ' . ((int) date('z', $t) + 1) . ' 天 / 共 ' . ((int) date('L', $t) ? 366 : 365) . ' 天，第 ' . date('W', $t) . ' 周',
            '当月共 ' . date('t', $t) . ' 天，季度 Q' . ceil((int) date('n', $t) / 3),
            '今天节日：' . ($fixed[$md] ?? '无固定公历节日'),
        ];
        $upcoming = [];
        for ($i = 1; $i <= 366; $i++) {
            $x = $t + $i * 86400;
            if (isset($fixed[date('m-d', $x)])) $upcoming[] = ['date' => date('Y-m-d', $x), 'name' => $fixed[date('m-d', $x)], 'days' => $i];
            if (count($upcoming) >= 3) break;
        }
        if ($upcoming) $lines[] = '接下来的固定节日：' . implode('、', array_map(fn($u) => $u['name'] . '（' . $u['date'] . '，' . $u['days'] . ' 天后）', $upcoming));
        $lines[] = '注：仅列公历固定节日，不含农历节日与法定调休安排。';
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['date' => date('Y-m-d', $t), 'weekday' => $week, 'upcoming' => $upcoming]];
    },
];
