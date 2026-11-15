<?php
/** 内置插件：HTTP GET（带 SSRF 防护） */
return [
    'id' => 'core.http',
    'name' => '网页读取',
    'description' => '抓取公开网页或 API 的文本内容；禁止访问内网/本机地址，限制大小与超时',
    'version' => '1.0.0', 'author' => 'dshphp', 'icon' => 'globe', 'enabled' => false,
    'tools' => [[
        'name' => 'http_get',
        'description' => '读取一个公开 URL 的文本内容（仅 GET，仅 http/https，禁止内网地址）',
        'parameters' => [
            'type' => 'object',
            'properties' => ['url' => ['type' => 'string', 'description' => '完整的 http/https URL']],
            'required' => ['url'],
        ],
    ]],
    'slash' => ['get' => '读取公开网页，如 /get https://example.com'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $url = trim((string) ($args['url'] ?? ($args['input'] ?? '')));
        if ($url === '') return ['ok' => false, 'text' => '缺少 url 参数'];
        if (!preg_match('~^https?://~i', $url)) return ['ok' => false, 'text' => '只允许 http/https'];
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) return ['ok' => false, 'text' => 'URL 无法解析'];
        $host = strtolower((string) $p['host']);
        $port = (int) ($p['port'] ?? (str_starts_with($url, 'https:') ? 443 : 80));
        if (!in_array($port, [80, 443, 8080, 8443], true)) return ['ok' => false, 'text' => '端口不允许：' . $port];
        $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]', '169.254.169.254'];
        if (in_array($host, $blocked, true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return ['ok' => false, 'text' => '禁止访问本机/内网地址'];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['ok' => false, 'text' => '禁止访问私有/保留 IP'];
            }
        } else {
            $ip = @gethostbyname($host);
            if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return ['ok' => false, 'text' => '域名解析到内网地址，已拒绝'];
                }
            }
        }
        $r = http_request($url, [
            'method' => 'GET', 'timeout' => 12,
            'headers' => ['User-Agent: dshphp/2.0 (+web-reader)', 'Accept: text/*, application/json, application/xml, */*'],
        ]);
        if (!$r['ok'] && $r['status'] === 0) return ['ok' => false, 'text' => '请求失败：' . $r['error']];
        $body = $r['body'];
        if (strlen($body) > 65536) $body = substr($body, 0, 65536) . "\n…（已截断）";
        if (!preg_match('~text/|json|xml|javascript~i', implode(' ', $r['headers']))) {
            return ['ok' => false, 'text' => '响应不是文本类型（HTTP ' . $r['status'] . '）'];
        }
        $text = strip_tags($body);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return [
            'ok' => $r['ok'],
            'text' => 'HTTP ' . $r['status'] . "\n" . str_cut(trim($text), 6000),
            'data' => ['status' => $r['status'], 'length' => strlen($body)],
        ];
    },
];
