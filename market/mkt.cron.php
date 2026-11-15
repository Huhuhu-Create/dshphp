<?php
/** 市场插件：Cron 表达式解释与下次执行时间 */
return [
    'id' => 'mkt.cron', 'name' => 'Cron 表达式',
    'description' => '解释 5 段 Cron 表达式的含义，并计算未来 7 天内的下次执行时间',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'clock',
    'category' => 'dev', 'tags' => ['cron', '定时任务', '调度'], 'risk' => 'local',
    'usage' => '/cron "*/5 * * * *" 或 /cron {"expr":"0 9 * * 1-5"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'cron_explain', 'description' => '解释 Cron 表达式并给出下次执行时间（分 时 日 月 周）',
        'parameters' => ['type' => 'object', 'properties' => [
            'expr' => ['type' => 'string', 'description' => '5 段 Cron 表达式'],
        ], 'required' => ['expr']],
    ]],
    'slash' => ['cron' => '解释 Cron：/cron */5 * * * *（或直接跟表达式）'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $e = trim((string) ($args['expr'] ?? ($args['input'] ?? '')));
        if ($e === '') return ['ok' => false, 'text' => '缺少 expr 参数'];
        $parts = preg_split('/\s+/', $e) ?: [];
        if (count($parts) !== 5) return ['ok' => false, 'text' => '需要 5 段表达式（分 时 日 月 周），当前 ' . count($parts) . ' 段'];
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        $names = ['分钟', '小时', '日', '月', '星期（0/7=周日）'];
        $sets = [];
        foreach ($parts as $i => $seg) {
            $vals = [];
            foreach (explode(',', $seg) as $piece) {
                $step = 1;
                if (str_contains($piece, '/')) { [$piece, $st] = explode('/', $piece, 2); $step = max(1, (int) $st); }
                [$lo, $hi] = $ranges[$i];
                if ($piece === '*') { $a = $lo; $b = $hi; }
                elseif (str_contains($piece, '-')) { [$a, $b] = array_map('intval', explode('-', $piece, 2)); }
                else { $a = (int) $piece; $b = $hi === $lo ? $a : ($piece === '*' ? $hi : $a); }
                $a = max($lo, $a); $b = min($hi, $b);
                if ($a > $b) return ['ok' => false, 'text' => '第 ' . ($i + 1) . ' 段范围不合法：' . $seg];
                for ($k = $a; $k <= $b; $k += $step) $vals[$k] = true;
            }
            if ($i === 4) { if (isset($vals[7])) $vals[0] = true; }
            $sets[$i] = $vals;
            if (!$vals) return ['ok' => false, 'text' => '第 ' . ($i + 1) . ' 段没有匹配值'];
        }
        $lines = [];
        foreach ($parts as $i => $seg) {
            $v = array_keys($sets[$i]); sort($v);
            $shown = count($v) > 12 ? implode(',', array_slice($v, 0, 10)) . '…（共 ' . count($v) . ' 个值）' : implode(',', $v);
            $lines[] = $names[$i] . ' [' . $seg . '] → ' . $shown;
        }
        $next = null;
        $t = (int) (floor(time() / 60) * 60) + 60;
        for ($k = 0; $k < 10080; $k++, $t += 60) {
            $d = getdate($t);
            if (!isset($sets[0][$d['minutes']]) || !isset($sets[1][$d['hours']]) ||
                !isset($sets[2][$d['mday']]) || !isset($sets[3][$d['mon']]) || !isset($sets[4][$d['wday']])) continue;
            $next = $t; break;
        }
        $lines[] = $next ? '下次执行：' . date('Y-m-d H:i', $next) . '（星期' . ['日','一','二','三','四','五','六'][(int) date('w', $next)] . '）'
            : '未来 7 天内没有匹配的执行时刻';
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['next' => $next ? date('c', $next) : null]];
    },
];
