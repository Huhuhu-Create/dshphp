<?php
/** 市场插件：JWT 解析（只解码，不校验签名） */
return [
    'id' => 'mkt.jwt', 'name' => 'JWT 解析',
    'description' => '拆解 JWT 的 header / payload，显示过期时间与剩余秒数；仅解码不验签',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'shield',
    'category' => 'dev', 'tags' => ['JWT', 'token', 'base64'], 'risk' => 'local',
    'usage' => '/jwt eyJhbGciOi...（直接跟 token）',
    'enabled' => true,
    'tools' => [[
        'name' => 'jwt_decode', 'description' => '解析 JWT 字符串，返回头部、载荷与过期判断（不验证签名）',
        'parameters' => ['type' => 'object', 'properties' => [
            'token' => ['type' => 'string', 'description' => 'JWT 字符串'],
        ], 'required' => ['token']],
    ]],
    'slash' => ['jwt' => '解析 JWT：/jwt eyJhbGciOiJIUzI1NiJ9...'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $t = trim((string) ($args['token'] ?? ($args['input'] ?? '')));
        $t = (string) preg_replace('~^Bearer\s+~i', '', $t);
        if ($t === '') return ['ok' => false, 'text' => '缺少 token 参数'];
        $parts = explode('.', $t);
        if (count($parts) < 2 || count($parts) > 3) return ['ok' => false, 'text' => '不是合法的 JWT（应为 2 或 3 段）'];
        $dec = function (string $s): array {
            $s = strtr($s, '-_', '+/');
            $s .= str_repeat('=', (4 - strlen($s) % 4) % 4);
            $raw = base64_decode($s, true);
            if ($raw === false) return [];
            $d = json_decode($raw, true);
            return is_array($d) ? $d : [];
        };
        $head = $dec($parts[0]); $body = $dec($parts[1]);
        if (!$head && !$body) return ['ok' => false, 'text' => 'Base64URL 解码失败，token 格式不正确'];
        $lines = [
            'Header: ' . json_encode($head, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Payload: ' . str_cut((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 2000),
            'Signature: ' . (isset($parts[2]) && $parts[2] !== '' ? '存在（' . strlen($parts[2]) . ' 字符，本插件不验签）' : '无'),
        ];
        $exp = isset($body['exp']) ? (int) $body['exp'] : 0;
        if ($exp > 0) {
            $left = $exp - time();
            $lines[] = '过期时间: ' . date('Y-m-d H:i:s', $exp) . '（' . ($left > 0 ? '剩余 ' . $left . ' 秒' : '已过期 ' . abs($left) . ' 秒') . '）';
        }
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['header' => $head, 'payload' => $body, 'exp' => $exp]];
    },
];
