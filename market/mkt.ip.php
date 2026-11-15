<?php
/** 市场插件：IP 与 CIDR 计算 */
return [
    'id' => 'mkt.ip', 'name' => 'IP 与网段',
    'description' => '校验 IPv4/IPv6、判断是否私有地址、计算 CIDR 网段范围与可用地址数',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'globe',
    'category' => 'net', 'tags' => ['IP', 'CIDR', '内网'], 'risk' => 'local',
    'usage' => '/ip 192.168.1.10 或 /ip 10.0.0.0/24',
    'enabled' => true,
    'tools' => [[
        'name' => 'ip_info', 'description' => '分析 IP 或 CIDR：类型、私有/公网、网段首尾、掩码与地址数',
        'parameters' => ['type' => 'object', 'properties' => [
            'ip' => ['type' => 'string', 'description' => 'IP 地址或 CIDR 网段'],
        ], 'required' => ['ip']],
    ]],
    'slash' => ['ip' => 'IP 分析：/ip 192.168.1.0/24'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $raw = trim((string) ($args['ip'] ?? ($args['input'] ?? '')));
        if ($raw === '') return ['ok' => false, 'text' => '缺少 ip 参数'];
        [$addr, $bits] = array_pad(explode('/', $raw, 2), 2, null);
        $isV4 = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!$isV4 && !$isV6) return ['ok' => false, 'text' => '不是合法的 IP 地址：' . $addr];
        $private = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        $lines = ['地址 ' . $addr, '版本 IPv' . ($isV4 ? '4' : '6'),
            '类型 ' . ($private ? '私有/保留地址' : '公网地址')];
        if ($bits !== null && $bits !== '') {
            if (!ctype_digit((string) $bits)) return ['ok' => false, 'text' => '掩码位数不合法'];
            $max = $isV4 ? 32 : 128; $b = (int) $bits;
            if ($b < 0 || $b > $max) return ['ok' => false, 'text' => '掩码位数需在 0-' . $max . ' 之间'];
            if ($isV4) {
                $ip = ip2long($addr);
                $mask = $b === 0 ? 0 : (0xFFFFFFFF << (32 - $b)) & 0xFFFFFFFF;
                $net = $ip & $mask; $bc = $net | (~$mask & 0xFFFFFFFF);
                $count = 2 ** (32 - $b);
                $lines[] = '掩码 /' . $b . ' = ' . long2ip($mask);
                $lines[] = '网络地址 ' . long2ip($net) . '，广播地址 ' . long2ip($bc);
                $lines[] = '可用地址范围 ' . long2ip($net + ($count > 2 ? 1 : 0)) . ' ~ ' . long2ip($bc - ($count > 2 ? 1 : 0));
                $lines[] = '地址总数 ' . sprintf('%.0f', $count) . '，可用主机 ' . sprintf('%.0f', max(0, $count - 2));
                $inside = ($ip >= $net && $ip <= $bc);
                $lines[] = $addr . ($inside ? ' 属于' : ' 不属于') . '该网段';
            } else {
                $lines[] = 'IPv6 /' . $b . '，地址总数 2^' . (128 - $b) . '（不展开具体范围）';
            }
        } elseif ($isV4) {
            $lines[] = '整数表示 ' . sprintf('%u', ip2long($addr)) . '，反解 ' . long2ip(ip2long($addr));
        }
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['ip' => $addr, 'private' => $private]];
    },
];
