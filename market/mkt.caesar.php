<?php
/** 市场插件：凯撒密码 / ROT13 */
return [
    'id' => 'mkt.caesar', 'name' => '凯撒密码',
    'description' => '对英文字母做移位加密、解密与 ROT13，也可一次列出全部 25 种移位结果',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'spark',
    'category' => 'fun', 'tags' => ['凯撒', 'ROT13', '加密'], 'risk' => 'local',
    'usage' => '/caesar {"text":"hello","shift":3} 或 /caesar {"text":"hello","all":true}',
    'enabled' => true,
    'tools' => [[
        'name' => 'caesar_cipher', 'description' => 'shift 为正表示加密、负数表示解密；rot13=true 或 all=true 有特殊模式',
        'parameters' => ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string', 'description' => '英文字母文本'],
            'shift' => ['type' => 'integer', 'description' => '移位数，默认 3'],
            'rot13' => ['type' => 'boolean', 'description' => '使用 ROT13'],
            'all' => ['type' => 'boolean', 'description' => '列出全部 25 种移位'],
        ], 'required' => ['text']],
    ]],
    'slash' => ['caesar' => '参数为 JSON：/caesar {"text":"hello","shift":3}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $t = (string) ($args['text'] ?? ($args['input'] ?? ''));
        if ($t === '') return ['ok' => false, 'text' => '缺少 text 参数'];
        if (strlen($t) > 5000) return ['ok' => false, 'text' => '文本过长'];
        $shift = !empty($args['rot13']) ? 13 : max(-25, min(25, (int) ($args['shift'] ?? 3)));
        $convert = function (string $s, int $k): string {
            $k = (($k % 26) + 26) % 26;
            $out = '';
            for ($i = 0; $i < strlen($s); $i++) {
                $o = ord($s[$i]);
                if ($o >= 65 && $o <= 90) $out .= chr(65 + (($o - 65 + $k) % 26));
                elseif ($o >= 97 && $o <= 122) $out .= chr(97 + (($o - 97 + $k) % 26));
                else $out .= $s[$i];
            }
            return $out;
        };
        if (!empty($args['all'])) {
            $lines = [];
            for ($k = 1; $k <= 25; $k++) $lines[] = str_pad((string) $k, 2, ' ', STR_PAD_LEFT) . ': ' . str_cut($convert($t, $k), 160);
            return ['ok' => true, 'text' => implode("\n", $lines)];
        }
        return ['ok' => true, 'text' => '移位 ' . $shift . '：' . str_cut($convert($t, $shift), 4000) .
            "\n（解密时用 -" . $shift . '）', 'data' => ['result' => $convert($t, $shift), 'shift' => $shift]];
    },
];
