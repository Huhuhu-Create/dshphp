<?php
/** 市场插件：倒计时与年龄计算 */
return [
    'id' => 'mkt.countdown', 'name' => '倒计时',
    'description' => '计算距离目标日期还有多久，或计算从出生日到今天的精确年龄',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'clock',
    'category' => 'time', 'tags' => ['倒计时', '年龄', '天数'], 'risk' => 'local',
    'usage' => '/countdown 2027-01-01 或 /countdown {"birth":"1990-05-20"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'countdown', 'description' => 'target 为目标日期（倒计时），birth 为出生日期（算年龄）',
        'parameters' => ['type' => 'object', 'properties' => [
            'target' => ['type' => 'string', 'description' => '目标日期，如 2027-01-01'],
            'birth' => ['type' => 'string', 'description' => '出生日期，如 1990-05-20'],
        ], 'required' => []],
    ]],
    'slash' => ['countdown' => '倒计时：/countdown 2027-01-01'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $birth = trim((string) ($args['birth'] ?? ''));
        if ($birth !== '') {
            $b = strtotime($birth);
            if ($b === false) return ['ok' => false, 'text' => '出生日期无法解析'];
            $now = time();
            if ($b > $now) return ['ok' => false, 'text' => '出生日期不能晚于今天'];
            $y = (int) date('Y', $now) - (int) date('Y', $b);
            $m = (int) date('n', $now) - (int) date('n', $b);
            $d = (int) date('j', $now) - (int) date('j', $b);
            if ($d < 0) { $m--; $d += (int) date('t', strtotime('-1 month', $now)); }
            if ($m < 0) { $y--; $m += 12; }
            $days = (int) floor(($now - $b) / 86400);
            $next = strtotime('+' . (1) . ' year', $b);
            return ['ok' => true, 'text' => '年龄 ' . $y . ' 岁 ' . $m . ' 个月 ' . $d . ' 天；已生活 ' . $days . ' 天（约 ' .
                round($days / 365.2425, 2) . ' 年，' . number_format($days * 86400) . ' 秒）',
                'data' => ['years' => $y, 'months' => $m, 'days' => $d, 'total_days' => $days]];
        }
        $target = trim((string) ($args['target'] ?? ($args['input'] ?? '')));
        if ($target === '') return ['ok' => false, 'text' => '需要 target 或 birth 参数'];
        $t = strtotime($target);
        if ($t === false) return ['ok' => false, 'text' => '目标日期无法解析：' . $target];
        $diff = $t - time();
        $abs = abs($diff);
        $d = intdiv($abs, 86400); $h = intdiv($abs % 86400, 3600);
        $mi = intdiv($abs % 3600, 60); $s = $abs % 60;
        $word = $diff >= 0 ? '还有' : '已经过去';
        $week = ['日','一','二','三','四','五','六'][(int) date('w', $t)];
        return ['ok' => true, 'text' => '距离 ' . date('Y-m-d H:i:s', $t) . '（星期' . $week . '）' . $word . ' ' .
            $d . ' 天 ' . $h . ' 小时 ' . $mi . ' 分 ' . $s . ' 秒（共 ' . $abs . ' 秒）',
            'data' => ['seconds' => $diff, 'days' => $d]];
    },
];
