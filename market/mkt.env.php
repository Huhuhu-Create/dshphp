<?php
/** 市场插件：服务器环境自检（脱敏，不打印绝对路径） */
return [
    'id' => 'mkt.env', 'name' => '环境自检',
    'description' => '查看 PHP 版本、关键扩展与函数可用性、上传/执行限制、市场与插件统计',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'cpu',
    'category' => 'net', 'tags' => ['环境', '扩展', '自检'], 'risk' => 'read',
    'usage' => '/env',
    'enabled' => true,
    'tools' => [[
        'name' => 'env_check', 'description' => '返回服务器 PHP 环境摘要（不含密钥与真实路径）',
        'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
    ]],
    'slash' => ['env' => '服务器环境自检：/env'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $exts = ['curl', 'openssl', 'mbstring', 'json', 'pdo_sqlite', 'fileinfo', 'zlib', 'session', 'hash'];
        $on = []; $off = [];
        foreach ($exts as $e) { if (extension_loaded($e)) $on[] = $e; else $off[] = $e; }
        $funcs = ['curl_init', 'file_get_contents', 'fopen', 'json_encode', 'random_bytes', 'str_getcsv', 'version_compare'];
        $bad = array_values(array_filter($funcs, fn($f) => !function_exists($f)));
        $store = $ctx['store'] ?? null;
        if (!$store instanceof Store) $store = new Store();
        $st = $store->stats();
        $lines = [
            'PHP ' . PHP_VERSION . ' · dshphp ' . DSHPHP_VERSION . ' · 时区 ' . date_default_timezone_get(),
            '已加载扩展：' . ($on ? implode(', ', $on) : '（无）'),
            '未加载扩展：' . ($off ? implode(', ', $off) : '（无）'),
            '不可用的函数：' . ($bad ? implode(', ', $bad) : '（无，全部可用）'),
            'HTTP 通道：' . (function_exists('curl_init') ? 'cURL' : 'stream wrapper（https 需 openssl）'),
            'allow_url_fopen：' . (ini_get('allow_url_fopen') ? '开启' : '关闭'),
            'upload_max_filesize：' . (string) ini_get('upload_max_filesize') . ' · post_max_size：' . (string) ini_get('post_max_size'),
            'max_execution_time：' . (string) ini_get('max_execution_time') . 's · memory_limit：' . (string) ini_get('memory_limit'),
            '存储后端：' . ($st['driver'] ?? '?') . ' · data/ 可写：' . (!empty($st['writable']) ? '是' : '否'),
            '会话 ' . (int) ($st['sessions'] ?? 0) . ' 个 / 消息 ' . (int) ($st['messages'] ?? 0) . ' 条',
            '插件 ' . count(plugin_list($store)) . ' 个 · 工具 ' . count(plugin_toolmap($store)['tools']) .
                ' 个 · 技能 ' . count(skill_list($store)) . ' 个',
        ];
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['php' => PHP_VERSION, 'extensions' => $on]];
    },
];
