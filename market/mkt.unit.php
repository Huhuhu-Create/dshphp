<?php
/** 市场插件：单位换算 */
return [
    'id' => 'mkt.unit', 'name' => '单位换算',
    'description' => '长度、重量、温度、面积、存储、时间、速度的单位换算；也可列出某一类全部单位',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'calc',
    'category' => 'calc', 'tags' => ['单位', '换算', '温度'], 'risk' => 'local',
    'usage' => '/unit {"value":1,"from":"km","to":"m"} 或 /unit {"list":"length"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'unit_convert', 'description' => '单位换算。类别 length/mass/temp/area/data/time/speed；list 可列出该类支持的单位',
        'parameters' => ['type' => 'object', 'properties' => [
            'value' => ['type' => 'number', 'description' => '数值'],
            'from' => ['type' => 'string', 'description' => '原单位'],
            'to' => ['type' => 'string', 'description' => '目标单位'],
            'list' => ['type' => 'string', 'description' => '只列出某类单位'],
        ], 'required' => []],
    ]],
    'slash' => ['unit' => '参数为 JSON：/unit {"value":1,"from":"km","to":"m"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $groups = [
            'length' => ['mm' => 0.001, 'cm' => 0.01, 'm' => 1, 'km' => 1000, 'in' => 0.0254, 'ft' => 0.3048, 'yd' => 0.9144, 'mile' => 1609.344, 'li' => 500],
            'mass' => ['mg' => 0.000001, 'g' => 0.001, 'kg' => 1, 't' => 1000, 'lb' => 0.45359237, 'oz' => 0.028349523125, 'jin' => 0.5],
            'area' => ['m2' => 1, 'cm2' => 0.0001, 'km2' => 1000000, 'ha' => 10000, 'mu' => 666.6666667, 'ft2' => 0.09290304],
            'data' => ['b' => 1, 'kb' => 1024, 'mb' => 1048576, 'gb' => 1073741824, 'tb' => 1099511627776],
            'time' => ['s' => 1, 'ms' => 0.001, 'min' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800],
            'speed' => ['mps' => 1, 'kmh' => 0.2777777778, 'mph' => 0.44704, 'knot' => 0.5144444444],
            'temp' => ['c' => 1, 'f' => 1, 'k' => 1],
        ];
        $names = ['length' => '长度', 'mass' => '重量', 'area' => '面积', 'data' => '存储', 'time' => '时间', 'speed' => '速度', 'temp' => '温度'];
        if (isset($args['list']) && (string) $args['list'] !== '') {
            $g = strtolower((string) $args['list']);
            if (!isset($groups[$g])) return ['ok' => false, 'text' => '未知类别（可用 ' . implode('/', array_keys($groups)) . '）'];
            return ['ok' => true, 'text' => $names[$g] . '可用单位：' . implode(', ', array_keys($groups[$g]))];
        }
        $v = $args['value'] ?? null;
        $from = strtolower(trim((string) ($args['from'] ?? '')));
        $to = strtolower(trim((string) ($args['to'] ?? '')));
        if ($v === null || !is_numeric($v) || $from === '' || $to === '') return ['ok' => false, 'text' => '需要 value / from / to 参数'];
        $v = (float) $v;
        if ($from === $to) return ['ok' => true, 'text' => $v . ' ' . $from . ' = ' . $v . ' ' . $to];
        foreach ($groups as $g => $units) {
            if (!isset($units[$from]) || !isset($units[$to])) continue;
            if ($g === 'temp') {
                $c = $from === 'c' ? $v : ($from === 'f' ? ($v - 32) * 5 / 9 : $v - 273.15);
                $out = $to === 'c' ? $c : ($to === 'f' ? $c * 9 / 5 + 32 : $c + 273.15);
            } else {
                $out = $v * $units[$from] / $units[$to];
            }
            if (!is_finite($out)) return ['ok' => false, 'text' => '结果超出范围'];
            $fmt = function (float $x): string {
                if (abs($x) >= 1e12 || (abs($x) < 1e-6 && $x != 0)) return rtrim(rtrim(sprintf('%.10f', $x), '0'), '.');
                return rtrim(rtrim(number_format($x, 8, '.', ''), '0'), '.') ?: '0';
            };
            return ['ok' => true, 'text' => $fmt($v) . ' ' . $from . ' = ' . $fmt($out) . ' ' . $to . '（' . $names[$g] . '）',
                'data' => ['value' => $out, 'group' => $g]];
        }
        return ['ok' => false, 'text' => '两个单位不在同一类别，或拼写不支持。用 {"list":"length"} 查看可用单位'];
    },
];
