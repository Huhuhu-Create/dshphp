<?php
/** 市场插件：正则内容提取 */
return [
    'id' => 'mkt.extract', 'name' => '内容提取',
    'description' => '从文本里批量提取邮箱、网址、手机号、数字、中文词、话题标签，去重并限量返回',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'text',
    'category' => 'text', 'tags' => ['提取', '正则', '邮箱'], 'risk' => 'local',
    'usage' => '/extract {"text":"联系 a@b.com","kind":"email"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'extract_from_text',
        'description' => '从文本提取信息。kind: email|url|phone|number|cjk|hashtag|all',
        'parameters' => ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string', 'description' => '源文本'],
            'kind' => ['type' => 'string', 'description' => '提取类型，默认 all'],
        ], 'required' => ['text']],
    ]],
    'slash' => ['extract' => '参数为 JSON：/extract {"text":"...","kind":"email"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $t = (string) ($args['text'] ?? ($args['input'] ?? ''));
        if ($t === '') return ['ok' => false, 'text' => '缺少 text 参数'];
        if (strlen($t) > 100000) return ['ok' => false, 'text' => '文本过长'];
        $kind = strtolower(trim((string) ($args['kind'] ?? 'all')));
        $map = [
            'email' => '~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~',
            'url' => '~https?://[^\s<>"\x27，。；）]+~u',
            'phone' => '~(?<!\d)1[3-9]\d{9}(?!\d)~',
            'number' => '~-?\d+(?:\.\d+)?~',
            'cjk' => '~[\x{4e00}-\x{9fff}]{2,8}~u',
            'hashtag' => '~#[^\s#]{1,30}~u',
        ];
        $keys = $kind === 'all' ? array_keys($map) : [$kind];
        $found = [];
        foreach ($keys as $k) {
            if (!isset($map[$k])) return ['ok' => false, 'text' => '未知类型：' . $k . '（可用 ' . implode('/', array_keys($map)) . '/all）'];
            $n = preg_match_all($map[$k], $t, $mm);
            $vals = array_slice(array_values(array_unique($n ? $mm[0] : [])), 0, 40);
            if ($vals) $found[$k] = $vals;
        }
        if (!$found) return ['ok' => true, 'text' => '没有提取到匹配内容。', 'data' => ['found' => []]];
        $lines = [];
        foreach ($found as $k => $vals) $lines[] = $k . '（' . count($vals) . '）：' . implode('、', $vals);
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['found' => $found]];
    },
];
