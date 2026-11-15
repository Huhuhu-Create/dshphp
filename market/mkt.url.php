<?php
/** 市场插件：URL 解析与查询参数处理 */
return [
    'id' => 'mkt.url', 'name' => 'URL 解析',
    'description' => '拆解 URL 的协议、主机、端口、路径、查询参数与片段；也能给 URL 追加参数',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'globe',
    'category' => 'dev', 'tags' => ['URL', 'query', '参数'], 'risk' => 'local',
    'usage' => '/url https://example.com/a?x=1#top 或 /url {"url":"...","add":{"y":"2"}}',
    'enabled' => true,
    'tools' => [[
        'name' => 'url_parse', 'description' => '解析 URL 结构；可选 add 对象用于追加查询参数并生成新 URL',
        'parameters' => ['type' => 'object', 'properties' => [
            'url' => ['type' => 'string', 'description' => '完整 URL'],
            'add' => ['type' => 'object', 'description' => '要追加的查询参数'],
        ], 'required' => ['url']],
    ]],
    'slash' => ['url' => '解析 URL：/url https://example.com/a?x=1'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $u = trim((string) ($args['url'] ?? ($args['input'] ?? '')));
        if ($u === '') return ['ok' => false, 'text' => '缺少 url 参数'];
        $p = parse_url($u);
        if (!is_array($p)) return ['ok' => false, 'text' => 'URL 格式无法解析'];
        $q = [];
        if (isset($p['query'])) parse_str((string) $p['query'], $q);
        $lines = [
            'scheme: ' . ($p['scheme'] ?? '（无）'), 'host: ' . ($p['host'] ?? '（无）'),
            'port: ' . (isset($p['port']) ? (string) $p['port'] : '（默认）'),
            'path: ' . ($p['path'] ?? '/'), 'fragment: ' . ($p['fragment'] ?? '（无）'),
            'user: ' . ($p['user'] ?? '（无）'),
            'query（' . count($q) . '）: ' . ($q ? str_cut((string) json_encode($q, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 800) : '（无）'),
        ];
        $add = $args['add'] ?? [];
        if (is_string($add)) { $d = json_decode($add, true); $add = is_array($d) ? $d : []; }
        if (is_array($add) && $add) {
            foreach ($add as $k => $v) $q[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $base = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '/');
            $new = $base . '?' . http_build_query($q) . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
            $lines[] = '追加参数后: ' . $new;
        }
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['parts' => $p, 'query' => $q]];
    },
];
