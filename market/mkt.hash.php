<?php
/** 市场插件：哈希与校验和 */
return [
    'id' => 'mkt.hash', 'name' => '哈希校验',
    'description' => '计算 md5 / sha1 / sha256 / sha512 / crc32 / adler32，以及 HMAC-SHA256',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'shield',
    'category' => 'dev', 'tags' => ['哈希', 'sha256', 'hmac'], 'risk' => 'local',
    'usage' => '/digest {"text":"abc","algo":"sha256"}，或 /digest {"text":"abc","algo":"hmac","key":"k"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'hash_digest',
        'description' => '计算文本哈希。algo: md5|sha1|sha256|sha512|crc32|adler32|hmac；algo=all 返回全部',
        'parameters' => ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string', 'description' => '原文'],
            'algo' => ['type' => 'string', 'description' => '算法，默认 sha256'],
            'key' => ['type' => 'string', 'description' => 'hmac 的密钥'],
        ], 'required' => ['text']],
    ]],
    'slash' => ['digest' => '参数为 JSON：/digest {"text":"abc","algo":"sha256"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $t = (string) ($args['text'] ?? ($args['input'] ?? ''));
        if ($t === '') return ['ok' => false, 'text' => '缺少 text 参数'];
        if (strlen($t) > 200000) return ['ok' => false, 'text' => '文本过长'];
        $algo = strtolower(trim((string) ($args['algo'] ?? 'sha256')));
        $key = (string) ($args['key'] ?? '');
        if ($algo === 'hmac') {
            if ($key === '') return ['ok' => false, 'text' => 'hmac 需要 key 参数'];
            $v = hash_hmac('sha256', $t, $key);
            return ['ok' => true, 'text' => 'HMAC-SHA256: ' . $v, 'data' => ['hmac_sha256' => $v]];
        }
        $all = [
            'md5' => md5($t), 'sha1' => sha1($t),
            'sha256' => hash('sha256', $t), 'sha512' => hash('sha512', $t),
            'crc32' => (string) crc32($t),
            'adler32' => in_array('adler32', hash_algos(), true) ? (string) hash('adler32', $t) : '当前 PHP 不支持',
        ];
        if ($algo === 'all') {
            $lines = [];
            foreach ($all as $k => $v) $lines[] = strtoupper($k) . ': ' . $v;
            return ['ok' => true, 'text' => implode("\n", $lines), 'data' => $all];
        }
        if (!isset($all[$algo])) return ['ok' => false, 'text' => '未知算法：' . $algo . '（可用 ' . implode('/', array_keys($all)) . '/hmac/all）'];
        return ['ok' => true, 'text' => strtoupper($algo) . ': ' . $all[$algo], 'data' => ['algo' => $algo, 'value' => $all[$algo]]];
    },
];
