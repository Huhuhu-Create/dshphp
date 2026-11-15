<?php
/** 市场插件：复利与等额本息 */
return [
    'id' => 'mkt.finance', 'name' => '财务计算',
    'description' => '计算复利终值、定投终值、等额本息月供与总利息',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'calc',
    'category' => 'calc', 'tags' => ['复利', '贷款', '月供'], 'risk' => 'local',
    'usage' => '/finance {"action":"loan","principal":1000000,"rate":4.2,"years":30}',
    'enabled' => true,
    'tools' => [[
        'name' => 'finance_calc',
        'description' => 'action: compound(本金 principal, 年利率 rate%, 年数 years)|dca(每月定投 monthly)|loan(贷款 principal, 年利率 rate%, 年限 years)',
        'parameters' => ['type' => 'object', 'properties' => [
            'action' => ['type' => 'string', 'description' => '计算类型'],
            'principal' => ['type' => 'number', 'description' => '本金'],
            'monthly' => ['type' => 'number', 'description' => '每月定投额'],
            'rate' => ['type' => 'number', 'description' => '年利率百分数，如 4.2 表示 4.2%'],
            'years' => ['type' => 'number', 'description' => '年数'],
        ], 'required' => ['action']],
    ]],
    'slash' => ['finance' => '参数为 JSON：/finance {"action":"compound","principal":10000,"rate":5,"years":10}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $a = strtolower(trim((string) ($args['action'] ?? '')));
        $p = (float) ($args['principal'] ?? 0);
        $m = (float) ($args['monthly'] ?? 0);
        $r = (float) ($args['rate'] ?? 0) / 100;
        $y = (float) ($args['years'] ?? 0);
        $money = fn($x) => number_format((float) $x, 2, '.', ',');
        if (!in_array($a, ['compound', 'dca', 'loan'], true)) return ['ok' => false, 'text' => '未知计算：' . $a . '（可用 compound/dca/loan）'];
        if ($y <= 0 || $y > 200) return ['ok' => false, 'text' => 'years 需在 0-200 之间'];
        if ($r < 0 || $r > 5) return ['ok' => false, 'text' => 'rate 数值不合理（请填百分数，如 4.2）'];
        if ($a === 'compound') {
            $fv = $p * pow(1 + $r, $y);
            return ['ok' => true, 'text' => '本金 ¥' . $money($p) . '，年利率 ' . ($r * 100) . '%，' . $y . ' 年后复利终值 ¥' . $money($fv) . '（利息 ¥' . $money($fv - $p) . '）',
                'data' => ['future_value' => $fv, 'interest' => $fv - $p]];
        }
        if ($a === 'dca') {
            $i = $r / 12; $n = $y * 12;
            $fv = $i == 0 ? $m * $n : $m * (pow(1 + $i, $n) - 1) / $i;
            $put = $p + $m * $n;
            return ['ok' => true, 'text' => '每月定投 ¥' . $money($m) . '，' . $y . ' 年共投入 ¥' . $money($put) . '，终值约 ¥' . $money($fv) . '（收益 ¥' . $money($fv - $put) . '）',
                'data' => ['future_value' => $fv, 'invested' => $put]];
        }
        $i = $r / 12; $n = (int) round($y * 12);
        $pay = $i == 0 ? $p / $n : $p * $i * pow(1 + $i, $n) / (pow(1 + $i, $n) - 1);
        $total = $pay * $n;
        return ['ok' => true, 'text' => '贷款 ¥' . $money($p) . '，年利率 ' . ($r * 100) . '%，' . $y . ' 年（' . $n . ' 期）等额本息：每月 ¥' . $money($pay) . '，总还款 ¥' . $money($total) . '，总利息 ¥' . $money($total - $p),
            'data' => ['monthly_payment' => $pay, 'total' => $total, 'interest' => $total - $p]];
    },
];
