<?php
/** 市场插件：大整数运算（字符串实现，不依赖 bcmath / gmp） */
return [
    'id' => 'mkt.bignum', 'name' => '大数运算',
    'description' => '用字符串实现超大整数的加、减、乘与比较，不依赖 bcmath 或 gmp 扩展',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'calc',
    'category' => 'calc', 'tags' => ['大数', '精度', '整数'], 'risk' => 'local',
    'usage' => '/bignum {"a":"12345678901234567890","b":"99999999","op":"mul"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'bigint_calc', 'description' => '大整数运算。op: add|sub|mul|compare；a、b 为十进制整数字符串（可带负号）',
        'parameters' => ['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'description' => '第一个整数'],
            'b' => ['type' => 'string', 'description' => '第二个整数'],
            'op' => ['type' => 'string', 'description' => '运算类型，默认 add'],
        ], 'required' => ['a', 'b']],
    ]],
    'slash' => ['bignum' => '参数为 JSON：/bignum {"a":"999999999999","b":"888888888888","op":"mul"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $a = trim((string) ($args['a'] ?? ''));
        $b = trim((string) ($args['b'] ?? ''));
        $op = strtolower(trim((string) ($args['op'] ?? 'add')));
        if ($a === '' || $b === '') return ['ok' => false, 'text' => '缺少 a / b 参数'];
        if (strlen($a) > 300 || strlen($b) > 300) return ['ok' => false, 'text' => '数字过长（上限 300 位）'];
        if (!preg_match('~^-?\d+$~', $a) || !preg_match('~^-?\d+$~', $b)) return ['ok' => false, 'text' => '只支持十进制整数（可带负号）'];
        $signA = $a[0] === '-' ? -1 : 1; $signB = $b[0] === '-' ? -1 : 1;
        $da = ltrim(ltrim($a, '-'), '0') ?: '0';
        $db = ltrim(ltrim($b, '-'), '0') ?: '0';
        $cmp = function (string $x, string $y): int {
            if (strlen($x) !== strlen($y)) return strlen($x) <=> strlen($y);
            return strcmp($x, $y) <=> 0;
        };
        $addAbs = function (string $x, string $y): string {
            $i = strlen($x) - 1; $j = strlen($y) - 1; $carry = 0; $s = '';
            while ($i >= 0 || $j >= 0 || $carry) {
                $n = $carry + ($i >= 0 ? (int) $x[$i] : 0) + ($j >= 0 ? (int) $y[$j] : 0);
                $s = (string) ($n % 10) . $s; $carry = intdiv($n, 10);
                $i--; $j--;
            }
            return ltrim($s, '0') ?: '0';
        };
        $subAbs = function (string $x, string $y) use ($cmp): string { // 要求 x >= y
            $i = strlen($x) - 1; $j = strlen($y) - 1; $borrow = 0; $s = '';
            while ($i >= 0) {
                $n = (int) $x[$i] - $borrow - ($j >= 0 ? (int) $y[$j] : 0);
                if ($n < 0) { $n += 10; $borrow = 1; } else $borrow = 0;
                $s = (string) $n . $s; $i--; $j--;
            }
            return ltrim($s, '0') ?: '0';
        };
        $mulAbs = function (string $x, string $y) use ($addAbs): string {
            $res = '0';
            for ($i = strlen($y) - 1, $z = ''; $i >= 0; $i--, $z .= '0') {
                $d = (int) $y[$i]; $carry = 0; $line = '';
                for ($k = strlen($x) - 1; $k >= 0; $k--) {
                    $n = (int) $x[$k] * $d + $carry;
                    $line = (string) ($n % 10) . $line; $carry = intdiv($n, 10);
                }
                if ($carry) $line = (string) $carry . $line;
                $res = $addAbs($res, ltrim($line, '0') . $z ?: '0');
            }
            return $res;
        };
        if ($op === 'compare') {
            $c = $cmp($da, $db);
            if ($signA !== $signB) $c = $signA > $signB ? 1 : -1;
            elseif ($c !== 0 && $signA < 0) $c = -$c;
            return ['ok' => true, 'text' => $a . ($c === 0 ? ' 等于 ' : ($c > 0 ? ' 大于 ' : ' 小于 ')) . $b, 'data' => ['compare' => $c]];
        }
        if ($op === 'mul') {
            $v = $mulAbs($da, $db);
            $neg = $signA !== $signB && $v !== '0';
            $out = ($neg ? '-' : '') . $v;
            return ['ok' => true, 'text' => $a . ' × ' . $b . ' = ' . $out, 'data' => ['result' => $out, 'digits' => strlen($v)]];
        }
        if ($op === 'add' || $op === 'sub') {
            $effB = ($op === 'sub' ? -$signB : $signB);
            $v = null; $neg = false;
            if ($signA === $effB) { $v = $addAbs($da, $db); $neg = $signA < 0 && $v !== '0'; }
            else {
                $c = $cmp($da, $db);
                if ($c === 0) $v = '0';
                elseif ($c > 0) { $v = $subAbs($da, $db); $neg = $signA < 0; }
                else { $v = $subAbs($db, $da); $neg = $effB < 0; }
                if ($v === '0') $neg = false;
            }
            $out = ($neg ? '-' : '') . $v;
            return ['ok' => true, 'text' => $a . ($op === 'add' ? ' + ' : ' − ') . $b . ' = ' . $out, 'data' => ['result' => $out]];
        }
        return ['ok' => false, 'text' => '未知运算：' . $op . '（可用 add/sub/mul/compare）'];
    },
];
