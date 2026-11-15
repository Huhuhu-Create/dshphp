<?php
/** 市场插件：HTTP 状态码速查 */
return [
    'id' => 'mkt.httpcode', 'name' => 'HTTP 状态码',
    'description' => '查询 HTTP 状态码含义与排障建议，或按类别列出常见状态码',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'globe',
    'category' => 'net', 'tags' => ['HTTP', '状态码', '排障'], 'risk' => 'local',
    'usage' => '/httpcode 429 或 /httpcode 5xx',
    'enabled' => true,
    'tools' => [[
        'name' => 'http_code', 'description' => '查询状态码含义；输 4xx / 5xx 可列出该类别',
        'parameters' => ['type' => 'object', 'properties' => [
            'code' => ['type' => 'string', 'description' => '状态码，如 404 / 4xx'],
        ], 'required' => ['code']],
    ]],
    'slash' => ['httpcode' => '状态码速查：/httpcode 429'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $c = strtoupper(trim((string) ($args['code'] ?? ($args['input'] ?? ''))));
        if ($c === '') return ['ok' => false, 'text' => '缺少 code 参数'];
        $map = [
            '200' => 'OK，请求成功', '201' => 'Created，资源已创建', '202' => 'Accepted，已接受但未处理完',
            '204' => 'No Content，成功但无响应体', '206' => 'Partial Content，断点续传',
            '301' => 'Moved Permanently，永久重定向', '302' => 'Found，临时重定向',
            '304' => 'Not Modified，命中缓存', '307' => 'Temporary Redirect，保持方法重定向',
            '400' => 'Bad Request，请求格式或参数有误', '401' => 'Unauthorized，缺少或错误的身份凭据',
            '403' => 'Forbidden，有身份但无权限', '404' => 'Not Found，资源不存在（很可能是文件没上传到位）',
            '405' => 'Method Not Allowed，HTTP 方法不对', '408' => 'Request Timeout，请求超时',
            '409' => 'Conflict，资源状态冲突', '413' => 'Payload Too Large，请求体过大',
            '422' => 'Unprocessable Entity，参数校验失败', '429' => 'Too Many Requests，被限流（免费模型最常见）',
            '500' => 'Internal Server Error，服务端代码异常', '502' => 'Bad Gateway，网关拿到无效响应',
            '503' => 'Service Unavailable，服务暂不可用', '504' => 'Gateway Timeout，网关等待超时',
        ];
        if (preg_match('~^([1-5])XX$~', $c, $m)) {
            $hits = array_filter($map, fn($k) => $k[0] === $m[1], ARRAY_FILTER_USE_KEY);
            return ['ok' => true, 'text' => $c . ' 类别共收录 ' . count($hits) . " 条：\n" .
                implode("\n", array_map(fn($k, $v) => $k . ' → ' . $v, array_keys($hits), array_values($hits)))];
        }
        if (!isset($map[$c])) return ['ok' => false, 'text' => '未收录 ' . $c . '，可试 200/301/400/401/403/404/429/500/502/503/504 或 4xx/5xx'];
        $tips = '';
        if ($c === '429') $tips = "\n排障：免费端点按 IP 限流，dshphp 会自动切换备用线路；要稳定就填自己的 API Key。";
        if ($c === '404') $tips = "\n排障：静态资源 200 但入口 .php 返回 404，通常是该文件没上传到 Web 可访问的位置。";
        if ($c === '500') $tips = "\n排障：检查 PHP 版本、扩展与 data/ 目录写权限；display_errors 关闭时看服务器错误日志。";
        return ['ok' => true, 'text' => $c . ' → ' . $map[$c] . $tips, 'data' => ['code' => $c, 'meaning' => $map[$c]]];
    },
];
