<?php
/** 市场插件：编码与解码 */
return [
    'id' => 'mkt.encode', 'name' => '编码解码',
    'description' => 'URL、HTML、Base64、十六进制与 Unicode 转义的编码和解码',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'code',
    'category' => 'text', 'tags' => ['编码', 'Base64', 'URL'], 'risk' => 'local',
    'usage' => '对话里说「把 xxx 做 base64」，或用 /encode {"action":"b64e","text":"hi"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'encode_text',
        'description' => '编码或解码文本。action: urlencode|urldecode|b64e|b64d|html|htmld|hex|hexd|uni|unid',
        'parameters' => ['type' => 'object', 'properties' => [
            'action' => ['type' => 'string', 'description' => '操作名'],
            'text' => ['type' => 'string', 'description' => '要处理的文本'],
        ], 'required' => ['action', 'text']],
    ]],
    'slash' => ['encode' => '参数为 JSON：/encode {"action":"b64e","text":"hi"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $a = strtolower(trim((string) ($args['action'] ?? '')));
        $t = (string) ($args['text'] ?? ($args['input'] ?? ''));
        if ($t === '') return ['ok' => false, 'text' => '缺少 text 参数'];
        if (strlen($t) > 100000) return ['ok' => false, 'text' => '文本过长'];
        $out = null;
        switch ($a) {
            case 'urlencode': case 'url': $out = urlencode($t); break;
            case 'urldecode': case 'unurl': $out = urldecode($t); break;
            case 'b64e': case 'base64': $out = base64_encode($t); break;
            case 'b64d': $v = base64_decode($t, true); $out = $v === false ? null : $v; break;
            case 'html': $out = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); break;
            case 'htmld': $out = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'); break;
            case 'hex': $out = bin2hex($t); break;
            case 'hexd': $v = @hex2bin($t); $out = $v === false ? null : $v; break;
            case 'uni': case 'unicode':
                $j = json_encode($t, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                $out = $j === false ? null : substr((string) $j, 1, -1);
                break;
            case 'unid':
                $v = json_decode('"' . addcslashes($t, "\"\\") . '"', true);
                $out = is_string($v) ? $v : null;
                break;
            default: return ['ok' => false, 'text' => '未知操作：' . $a . '（可用 urlencode/urldecode/b64e/b64d/html/htmld/hex/hexd/uni/unid）'];
        }
        if ($out === null) return ['ok' => false, 'text' => '解码失败：输入格式不合法'];
        return ['ok' => true, 'text' => str_cut((string) $out, 4000), 'data' => ['result' => (string) $out]];
    },
];
