<?php
/** 市场插件：描述性统计 */
return [
    'id' => 'mkt.stats', 'name' => '统计描述',
    'description' => '对一组数字求均值、中位数、众数、方差、标准差、极值、求和与分位数',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'calc',
    'category' => 'calc', 'tags' => ['统计', '平均数', '方差'], 'risk' => 'local',
    'usage' => '/stats [1,2,3,4,5] 或 /stats {"numbers":[1,2,3],"q":0.9}',
    'enabled' => true,
    'tools' => [[
        'name' => 'describe_numbers', 'description' => '对数字数组做描述性统计；可选 q 指定分位数（0-1）',
        'parameters' => ['type' => 'object', 'properties' => [
            'numbers' => ['type' => 'array', 'description' => '数字数组', 'items' => ['type' => 'number']],
            'q' => ['type' => 'number', 'description' => '分位数，如 0.9'],
        ], 'required' => ['numbers']],
    ]],
    'slash' => ['stats' => '直接跟数字：/stats 1 2 3 4 5'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $raw = $args['numbers'] ?? ($args['input'] ?? null);
        if (is_string($raw)) {
            $d = json_decode($raw, true);
            $nums = is_array($d) ? $d : array_values(array_filter(preg_split('/[\s,，]+/', trim($raw)) ?: [], fn($x) => $x !== ''));
        } elseif (is_array($raw)) $nums = $raw;
        else $nums = [];
        $nums = array_values(array_filter(array_map(fn($x) => is_numeric($x) ? (float) $x : null, $nums), fn($x) => $x !== null));
        if (!$nums) return ['ok' => false, 'text' => '没有可统计的数字'];
        if (count($nums) > 5000) return ['ok' => false, 'text' => '数据点过多（上限 5000）'];
        $n = count($nums); $sum = array_sum($nums); $mean = $sum / $n;
        $sorted = $nums; sort($sorted, SORT_NUMERIC);
        $median = $n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[intdiv($n, 2) - 1] + $sorted[intdiv($n, 2)]) / 2;
        $var = 0.0;
        foreach ($nums as $x) $var += ($x - $mean) ** 2;
        $var /= $n;
        $freq = [];
        foreach ($nums as $x) { $k = (string) $x; $freq[$k] = ($freq[$k] ?? 0) + 1; }
        arsort($freq); $mode = (float) array_key_first($freq);
        $fmt = fn($x) => rtrim(rtrim(number_format((float) $x, 6, '.', ''), '0'), '.') ?: '0';
        $lines = ["样本数 {$n}", '求和 ' . $fmt($sum), '均值 ' . $fmt($mean), '中位数 ' . $fmt($median),
            '众数 ' . $fmt($mode), '最小 ' . $fmt($sorted[0]), '最大 ' . $fmt($sorted[$n - 1]),
            '方差（总体） ' . $fmt($var), '标准差 ' . $fmt(sqrt($var)), '极差 ' . $fmt($sorted[$n - 1] - $sorted[0])];
        $q = $args['q'] ?? null;
        if (is_numeric($q) && (float) $q >= 0 && (float) $q <= 1) {
            $pos = (float) $q * ($n - 1); $lo = (int) floor($pos); $hi = (int) ceil($pos);
            $qv = $lo === $hi ? $sorted[$lo] : $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
            $lines[] = '分位数 q=' . $fmt((float) $q) . ' → ' . $fmt($qv);
        }
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => [
            'count' => $n, 'sum' => $sum, 'mean' => $mean, 'median' => $median,
            'min' => $sorted[0], 'max' => $sorted[$n - 1], 'variance' => $var, 'stddev' => sqrt($var),
        ]];
    },
];
