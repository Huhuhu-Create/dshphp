<?php
/** 市场插件：字数统计 */
return [
    'id' => 'mkt.wordcount', 'name' => '字数统计',
    'description' => '统计文本的字节数、字符数、中文数、词数、行数与预计阅读时长',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'text',
    'category' => 'text', 'tags' => ['字数', '统计', '阅读时长'], 'risk' => 'local',
    'usage' => '/wordcount 后面直接跟文本，或让其统计一段内容',
    'enabled' => true,
    'tools' => [[
        'name' => 'word_count', 'description' => '统计一段文本的字符、中文、词数、行数与阅读时长',
        'parameters' => ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string', 'description' => '要统计的文本'],
        ], 'required' => ['text']],
    ]],
    'slash' => ['wordcount' => '统计文本字数：/wordcount 这里放文本'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $t = (string) ($args['text'] ?? ($args['input'] ?? ''));
        if ($t === '') return ['ok' => false, 'text' => '缺少 text 参数'];
        if (strlen($t) > 200000) return ['ok' => false, 'text' => '文本过长（上限 200KB）'];
        $chars = function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : (int) preg_match_all('/./us', $t);
        $cjk = (int) preg_match_all('/[\x{4e00}-\x{9fff}]/u', $t);
        $words = (int) preg_match_all('/[A-Za-z0-9_\x27]+|[\x{4e00}-\x{9fff}]/u', $t);
        $lines = substr_count($t, "\n") + 1;
        $mins = max(1, (int) ceil($words / 300));
        $text = "字节 " . strlen($t) . " · 字符 {$chars} · 中文 {$cjk} · 词/字 {$words} · 行 {$lines} · 预计阅读约 {$mins} 分钟";
        return ['ok' => true, 'text' => $text, 'data' => [
            'bytes' => strlen($t), 'chars' => $chars, 'cjk' => $cjk,
            'words' => $words, 'lines' => $lines, 'minutes' => $mins,
        ]];
    },
];
