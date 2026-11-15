<?php
/** 内置插件：文本处理 */
return [
    'id' => 'core.text',
    'name' => '文本工具',
    'description' => '统计字数、大小写转换、Base64、哈希、JSON 美化、文本反转',
    'version' => '1.0.0', 'author' => 'dshphp', 'icon' => 'text', 'enabled' => true,
    'tools' => [[
        'name' => 'text_tool',
        'description' => '处理一段文本。action: count|upper|lower|b64encode|b64decode|md5|sha256|json|reverse|trim',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'description' => '操作名'],
                'text' => ['type' => 'string', 'description' => '要处理的文本'],
            ],
            'required' => ['action', 'text'],
        ],
    ]],
    'slash' => ['text' => '文本工具，参数为 JSON，如 /text {"action":"count","text":"你好" }'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $text = (string) ($args['text'] ?? ($args['input'] ?? ''));
        $action = strtolower(trim((string) ($args['action'] ?? 'count')));
        if (strlen($text) > 50000) return ['ok' => false, 'text' => '文本过长（上限 50000 字节）'];
        $chars = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        switch ($action) {
            case 'count':
                $out = '字符 ' . $chars . '，字节 ' . strlen($text) . '，行 ' . (substr_count($text, "\n") + 1) . '，词 ' . str_word_count($text);
                break;
            case 'upper': $out = strtoupper($text); break;
            case 'lower': $out = strtolower($text); break;
            case 'trim': $out = trim($text); break;
            case 'reverse': $out = function_exists('mb_str_split') ? implode('', array_reverse(mb_str_split($text, 1, 'UTF-8'))) : strrev($text); break;
            case 'b64encode': $out = base64_encode($text); break;
            case 'b64decode': $v = base64_decode($text, true); $out = $v === false ? '不是合法 Base64' : $v; break;
            case 'md5': $out = md5($text); break;
            case 'sha256': $out = hash('sha256', $text); break;
            case 'json':
                $j = json_decode($text, true);
                $out = $j === null && json_last_error() !== JSON_ERROR_NONE
                    ? '不是合法 JSON：' . json_last_error_msg()
                    : json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            default: return ['ok' => false, 'text' => '未知操作：' . $action];
        }
        return ['ok' => true, 'text' => str_cut((string) $out, 8000)];
    },
];
