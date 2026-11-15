<?php
/** 市场插件：MIME 类型速查 */
return [
    'id' => 'mkt.mime', 'name' => 'MIME 速查',
    'description' => '按扩展名查 Content-Type，或按 Content-Type 反查常见扩展名；也可列出全部',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'globe',
    'category' => 'net', 'tags' => ['MIME', 'Content-Type', '扩展名'], 'risk' => 'local',
    'usage' => '/mime .php 或 /mime application/json',
    'enabled' => true,
    'tools' => [[
        'name' => 'mime_lookup', 'description' => '扩展名 ↔ MIME 类型互查；all=true 列出全部',
        'parameters' => ['type' => 'object', 'properties' => [
            'value' => ['type' => 'string', 'description' => '扩展名或 MIME 类型'],
            'all' => ['type' => 'boolean', 'description' => '列出全部'],
        ], 'required' => []],
    ]],
    'slash' => ['mime' => 'MIME 速查：/mime .webp'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $map = [
            'html' => 'text/html', 'htm' => 'text/html', 'php' => 'application/x-httpd-php',
            'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
            'json' => 'application/json', 'xml' => 'application/xml', 'txt' => 'text/plain',
            'md' => 'text/markdown', 'csv' => 'text/csv', 'pdf' => 'application/pdf',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4', 'webm' => 'video/webm',
            'zip' => 'application/zip', 'gz' => 'application/gzip', 'tar' => 'application/x-tar',
            '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            'bin' => 'application/octet-stream', 'exe' => 'application/octet-stream',
            'sqlite' => 'application/vnd.sqlite3', 'db' => 'application/octet-stream',
            'yaml' => 'application/yaml', 'yml' => 'application/yaml', 'wasm' => 'application/wasm',
        ];
        if (!empty($args['all'])) {
            $lines = [];
            foreach ($map as $k => $v) $lines[] = '.' . $k . ' → ' . $v;
            return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['map' => $map]];
        }
        $v = strtolower(ltrim(trim((string) ($args['value'] ?? ($args['input'] ?? ''))), '.'));
        if ($v === '') return ['ok' => false, 'text' => '缺少 value 参数'];
        if (isset($map[$v])) return ['ok' => true, 'text' => '.' . $v . ' → ' . $map[$v], 'data' => ['mime' => $map[$v]]];
        $exts = array_keys($map, $v, true);
        if ($exts) return ['ok' => true, 'text' => $v . ' → ' . implode(', ', array_map(fn($e) => '.' . $e, $exts))];
        return ['ok' => false, 'text' => '未收录 ' . $v . '（用 {"all":true} 查看全部）'];
    },
];
