<?php
/** 市场插件：进制转换（2/8/10/16/36/62） */
return [
    'id' => 'mkt.base', 'name' => '进制转换',
    'description' => '在 2、8、10、16、36、62 进制之间转换正整数，或查看同一数字的多种表示',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'code',
    'category' => 'dev', 'tags' => ['进制', '二进制', '十六进制'], 'risk' => 'local',
    'usage' => '/base {"value":"ff","from":16,"to":10} 或 /base {"value":"255","all":true}',
    'enabled' => true,
    'tools' => [[
        'name' => 'base_convert', 'description' => '进制转换。value 为正整数字符串，from/to 为 2-62；all=true 返回全部常用进制',
        'parameters' => ['type' => 'object', 'properties' => [
            'value' => ['type' => 'string', 'description' => '要转换的值'],
            'from' => ['type' => 'integer', 'description' => '原进制，默认 10'],
            'to' => ['type' => 'integer', 'description' => '目标进制，默认 16'],
            'all' => ['type' => 'boolean', 'description' => '是否返回全部常用进制'],
        ], 'required' => ['value']],
    ]],
    'slash' => ['base' => '参数为 JSON：/base {"value":"255","all":true}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $v = strtoupper(trim((string) ($args['value'] ?? ($args['input'] ?? ''))));
        if ($v === '') return ['ok' => false, 'text' => '缺少 value 参数'];
        if (strlen($v) > 40) return ['ok' => false, 'text' => '数值过长'];
        $from = max(2, min(62, (int) ($args['from'] ?? 10)));
        $to = max(2, min(62, (int) ($args['to'] ?? 16)));
        $digits = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $dec = '0';
        foreach (str_split($v) as $ch) {
            $d = strpos($digits, $ch);
            if ($d === false || $d >= $from) return ['ok' => false, 'text' => '字符 ' . $ch . ' 不属于 ' . $from . ' 进制'];
            // 字符串十进制乘法：dec = dec * from + d
            $carry = $d; $s = '';
            for ($i = strlen($dec) - 1; $i >= 0; $i--) {
                $n = ((int) $dec[$i]) * $from + $carry;
                $s = (string) ($n % 10) . $s; $carry = intdiv($n, 10);
            }
            while ($carry > 0) { $s = (string) ($carry % 10) . $s; $carry = intdiv($carry, 10); }
            $dec = ltrim($s, '0') ?: '0';
        }
        if (strlen($dec) > 60) return ['ok' => false, 'text' => '数值过大'];
        $render = function (string $num, int $base) use ($digits): string {
            if ($num === '0') return '0';
            $out = '';
            while ($num !== '0') {
                $rem = 0; $q = '';
                for ($i = 0; $i < strlen($num); $i++) {
                    $cur = $rem * 10 + (int) $num[$i];
                    $q .= (string) intdiv($cur, $base);
                    $rem = $cur % $base;
                }
                $out = $digits[$rem] . $out;
                $num = ltrim($q, '0') ?: '0';
            }
            return $out;
        };
        if (!empty($args['all'])) {
            $map = [];
            foreach ([2, 8, 10, 16, 36, 62] as $b) $map['base' . $b] = $render($dec, $b);
            $lines = [];
            foreach ($map as $k => $x) $lines[] = $k . ': ' . $x;
            return ['ok' => true, 'text' => implode("\n", $lines), 'data' => $map];
        }
        $out = $render($dec, $to);
        return ['ok' => true, 'text' => $v . ' (' . $from . ' 进制) = ' . $out . ' (' . $to . ' 进制)，十进制 ' . $dec,
            'data' => ['decimal' => $dec, 'result' => $out]];
    },
];
