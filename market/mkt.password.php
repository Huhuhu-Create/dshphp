<?php
/** 市场插件：密码生成与强度评估 */
return [
    'id' => 'mkt.password', 'name' => '密码工具',
    'description' => '用 CSPRNG 生成强密码，或评估一段密码的强度并给出改进建议',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'shield',
    'category' => 'life', 'tags' => ['密码', '强度', '随机'], 'risk' => 'local',
    'usage' => '/pw 20（生成 20 位）或 /pw {"check":"abc123"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'password_tool', 'description' => '生成密码（length、no_symbols）或评估强度（check）',
        'parameters' => ['type' => 'object', 'properties' => [
            'length' => ['type' => 'integer', 'description' => '生成长度 8-64，默认 16'],
            'no_symbols' => ['type' => 'boolean', 'description' => '不含特殊符号'],
            'check' => ['type' => 'string', 'description' => '要评估的密码'],
        ], 'required' => []],
    ]],
    'slash' => ['pw' => '生成密码：/pw 20；评估：/pw {"check":"abc123"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $check = (string) ($args['check'] ?? '');
        if ($check !== '') {
            if (strlen($check) > 200) return ['ok' => false, 'text' => '密码过长'];
            $score = 0; $notes = [];
            $len = strlen($check);
            if ($len >= 8) $score += 1; else $notes[] = '长度不足 8 位';
            if ($len >= 12) $score += 1;
            if ($len >= 16) $score += 1;
            if (preg_match('~[a-z]~', $check)) $score += 1; else $notes[] = '缺少小写字母';
            if (preg_match('~[A-Z]~', $check)) $score += 1; else $notes[] = '缺少大写字母';
            if (preg_match('~\d~', $check)) $score += 1; else $notes[] = '缺少数字';
            if (preg_match('~[^A-Za-z0-9]~', $check)) $score += 1; else $notes[] = '缺少符号';
            if (preg_match('~(.)\1{2,}~', $check)) { $score -= 1; $notes[] = '存在连续重复字符'; }
            if (preg_match('~^(?:123|abc|password|qwerty|admin|111|000)~i', $check)) { $score -= 2; $notes[] = '包含常见弱口令前缀'; }
            $score = max(0, min(5, (int) round($score / 2)));
            $word = ['很弱', '弱', '一般', '较强', '强'][$score];
            return ['ok' => true, 'text' => '强度：' . $word . '（' . $score . '/5，长度 ' . $len . '）' .
                ($notes ? "\n建议：" . implode('；', $notes) : "\n没有明显弱点，记得不要在多个站点复用。"),
                'data' => ['score' => $score, 'length' => $len, 'notes' => $notes]];
        }
        $len = max(8, min(64, (int) ($args['length'] ?? ($args['input'] ?? 16))));
        $sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKMNPQRSTUVWXYZ', '23456789'];
        if (empty($args['no_symbols'])) $sets[] = '!@#$%^&*_-+=?';
        $all = implode('', $sets);
        $chars = [];
        foreach ($sets as $s) $chars[] = $s[random_int(0, strlen($s) - 1)];
        while (count($chars) < $len) $chars[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i); [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        $pw = implode('', $chars);
        return ['ok' => true, 'text' => '生成的 ' . $len . ' 位密码：' . $pw . "\n（已剔除易混淆的 0/O/1/l/I；请立即存入密码管理器，不要贴在聊天里复用）",
            'data' => ['password' => $pw, 'length' => $len]];
    },
];
