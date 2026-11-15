<?php
/** 市场插件：百分比与增长率 */
return [
    'id' => 'mkt.percent', 'name' => '百分比计算',
    'description' => '求占比、求部分值、增长率、折扣价、涨跌幅与百分点差',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'calc',
    'category' => 'calc', 'tags' => ['百分比', '折扣', '增长率'], 'risk' => 'local',
    'usage' => '/pct {"action":"growth","old":100,"new":130} 或 {"action":"discount","price":200,"off":20}',
    'enabled' => true,
    'tools' => [[
        'name' => 'percent_calc',
        'description' => '百分比计算。action: ratio(part/whole)|of(whole,pct)|growth(old,new)|discount(price,off)|change(old,new)|diff(a,b)',
        'parameters' => ['type' => 'object', 'properties' => [
            'action' => ['type' => 'string', 'description' => '计算类型'],
            'part' => ['type' => 'number', 'description' => '部分值'],
            'whole' => ['type' => 'number', 'description' => '整体值'],
            'pct' => ['type' => 'number', 'description' => '百分比数值'],
            'off' => ['type' => 'number', 'description' => '折扣（如 20 表示减 20%）'],
            'price' => ['type' => 'number', 'description' => '原价'],
            'old' => ['type' => 'number', 'description' => '旧值'],
            'new' => ['type' => 'number', 'description' => '新值'],
            'a' => ['type' => 'number', 'description' => '百分点 A'],
            'b' => ['type' => 'number', 'description' => '百分点 B'],
        ], 'required' => ['action']],
    ]],
    'slash' => ['pct' => '参数为 JSON：/pct {"action":"of","whole":200,"pct":15}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $a = strtolower(trim((string) ($args['action'] ?? '')));
        $n = fn($k) => (float) ($args[$k] ?? 0);
        $fmt = fn($x) => rtrim(rtrim(number_format((float) $x, 6, '.', ''), '0'), '.') ?: '0';
        switch ($a) {
            case 'ratio':
                if ($n('whole') == 0) return ['ok' => false, 'text' => 'whole 不能为 0'];
                $v = $n('part') / $n('whole') * 100;
                return ['ok' => true, 'text' => $fmt($n('part')) . ' 占 ' . $fmt($n('whole')) . ' 的 ' . $fmt($v) . '%', 'data' => ['percent' => $v]];
            case 'of':
                $v = $n('whole') * $n('pct') / 100;
                return ['ok' => true, 'text' => $fmt($n('whole')) . ' 的 ' . $fmt($n('pct')) . '% = ' . $fmt($v), 'data' => ['value' => $v]];
            case 'growth': case 'change':
                if ($n('old') == 0) return ['ok' => false, 'text' => 'old 不能为 0'];
                $v = ($n('new') - $n('old')) / abs($n('old')) * 100;
                return ['ok' => true, 'text' => $fmt($n('old')) . ' → ' . $fmt($n('new')) . '，' . ($v >= 0 ? '增长 ' : '下降 ') . $fmt(abs($v)) . '%', 'data' => ['percent' => $v]];
            case 'discount':
                $v = $n('price') * (1 - $n('off') / 100);
                return ['ok' => true, 'text' => '原价 ' . $fmt($n('price')) . '，减 ' . $fmt($n('off')) . '% 后 = ' . $fmt($v) . '（省 ' . $fmt($n('price') - $v) . '）', 'data' => ['price' => $v]];
            case 'diff':
                $v = $n('a') - $n('b');
                return ['ok' => true, 'text' => $fmt($n('a')) . '% 与 ' . $fmt($n('b')) . '% 相差 ' . $fmt(abs($v)) . ' 个百分点', 'data' => ['diff' => $v]];
            default:
                return ['ok' => false, 'text' => '未知计算：' . $a . '（可用 ratio/of/growth/discount/diff）'];
        }
    },
];
