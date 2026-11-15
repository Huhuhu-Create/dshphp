<?php
/** 市场插件：摩斯电码编解码 */
return [
    'id' => 'mkt.morse', 'name' => '摩斯电码',
    'description' => '把英文与数字编成摩斯电码，或把 . - / 的电码解回文字',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'spark',
    'category' => 'fun', 'tags' => ['摩斯', '编码', '趣味'], 'risk' => 'local',
    'usage' => '/morse SOS 或 /morse "... --- ..."',
    'enabled' => true,
    'tools' => [[
        'name' => 'morse_code', 'description' => '自动识别方向：含 . 或 - 则解码，否则编码（字母用空格、单词用 / 分隔）',
        'parameters' => ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string', 'description' => '文字或电码'],
        ], 'required' => ['text']],
    ]],
    'slash' => ['morse' => '摩斯电码：/morse SOS'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $t = strtoupper(trim((string) ($args['text'] ?? ($args['input'] ?? ''))));
        if ($t === '') return ['ok' => false, 'text' => '缺少 text 参数'];
        if (strlen($t) > 2000) return ['ok' => false, 'text' => '文本过长'];
        $map = [
            'A' => '.-', 'B' => '-...', 'C' => '-.-.', 'D' => '-..', 'E' => '.', 'F' => '..-.',
            'G' => '--.', 'H' => '....', 'I' => '..', 'J' => '.---', 'K' => '-.-', 'L' => '.-..',
            'M' => '--', 'N' => '-.', 'O' => '---', 'P' => '.--.', 'Q' => '--.-', 'R' => '.-.',
            'S' => '...', 'T' => '-', 'U' => '..-', 'V' => '...-', 'W' => '.--', 'X' => '-..-',
            'Y' => '-.--', 'Z' => '--..', '0' => '-----', '1' => '.----', '2' => '..---',
            '3' => '...--', '4' => '....-', '5' => '.....', '6' => '-....', '7' => '--...',
            '8' => '---..', '9' => '----.', '.' => '.-.-.-', ',' => '--..--', '?' => '..--..',
            '!' => '-.-.--', '/' => '-..-.', ':' => '---...', '=' => '-...-',
        ];
        if (str_contains($t, '.') || str_contains($t, '-')) {
            $rev = array_flip($map);
            $words = [];
            foreach (explode('/', $t) as $word) {
                $buf = [];
                foreach (preg_split('/\s+/', trim($word)) ?: [] as $code) {
                    if ($code === '') continue;
                    $buf[] = $rev[$code] ?? '?';
                }
                $words[] = implode('', $buf);
            }
            return ['ok' => true, 'text' => '解码：' . implode(' ', $words), 'data' => ['decoded' => implode(' ', $words)]];
        }
        $words = [];
        foreach (preg_split('/\s+/', $t) ?: [] as $word) {
            $buf = [];
            foreach (str_split($word) as $ch) $buf[] = $map[$ch] ?? '';
            $words[] = implode(' ', array_filter($buf, fn($x) => $x !== ''));
        }
        $out = implode(' / ', $words);
        return ['ok' => true, 'text' => '编码：' . $out . "\n（. 为点、- 为划，空格分隔字母，/ 分隔单词）", 'data' => ['encoded' => $out]];
    },
];
