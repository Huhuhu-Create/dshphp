<?php
/** 市场插件：JSON 校验 / 美化 / 压缩 / 路径取值 */
return [
    'id' => 'mkt.json', 'name' => 'JSON 工具',
    'description' => '校验 JSON 合法性、美化或压缩、按键路径取值、列出顶层键',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'code',
    'category' => 'dev', 'tags' => ['JSON', '格式化', '校验'], 'risk' => 'local',
    'usage' => '/json {"text":"{\"a\":1}","action":"pretty"} 或 {"action":"get","path":"a.b"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'json_tool', 'description' => 'JSON 处理。action: check|pretty|minify|keys|get；get 时用 path（点号分隔）',
        'parameters' => ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string', 'description' => 'JSON 文本'],
            'action' => ['type' => 'string', 'description' => '操作名，默认 check'],
            'path' => ['type' => 'string', 'description' => 'get 时的点号路径'],
        ], 'required' => ['text']],
    ]],
    'slash' => ['json' => '参数为 JSON：/json {"text":"{\"a\":1}","action":"pretty"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $t = (string) ($args['text'] ?? ($args['input'] ?? ''));
        if ($t === '') return ['ok' => false, 'text' => '缺少 text 参数'];
        if (strlen($t) > 200000) return ['ok' => false, 'text' => '文本过长'];
        $action = strtolower(trim((string) ($args['action'] ?? 'check')));
        $d = json_decode($t, true);
        if ($action === 'check') {
            return ['ok' => true, 'text' => json_last_error() === JSON_ERROR_NONE
                ? 'JSON 合法 · ' . json_last_error_msg() . ' · 顶层类型 ' . gettype($d)
                : 'JSON 不合法：' . json_last_error_msg()];
        }
        if (json_last_error() !== JSON_ERROR_NONE) return ['ok' => false, 'text' => 'JSON 不合法：' . json_last_error_msg()];
        if ($action === 'pretty') {
            $j = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return ['ok' => true, 'text' => str_cut((string) $j, 6000)];
        }
        if ($action === 'minify') {
            $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return ['ok' => true, 'text' => str_cut((string) $j, 6000)];
        }
        if ($action === 'keys') {
            $keys = is_array($d) ? array_map('strval', array_keys($d)) : [];
            return ['ok' => true, 'text' => $keys ? '顶层键（' . count($keys) . '）：' . implode(', ', $keys) : '不是对象/数组，或没有键',
                'data' => ['keys' => $keys]];
        }
        if ($action === 'get') {
            $path = trim((string) ($args['path'] ?? ''));
            $cur = $d;
            foreach (explode('.', $path) as $seg) {
                if ($seg === '') continue;
                if (is_array($cur) && array_key_exists($seg, $cur)) $cur = $cur[$seg];
                elseif (is_array($cur) && ctype_digit($seg) && array_key_exists((int) $seg, $cur)) $cur = $cur[(int) $seg];
                else return ['ok' => false, 'text' => '路径不存在：' . $path];
            }
            $j = json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return ['ok' => true, 'text' => str_cut((string) $j, 6000), 'data' => ['value' => $cur]];
        }
        return ['ok' => false, 'text' => '未知操作：' . $action . '（可用 check/pretty/minify/keys/get）'];
    },
];
