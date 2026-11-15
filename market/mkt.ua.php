<?php
/** 市场插件：User-Agent 解析 */
return [
    'id' => 'mkt.ua', 'name' => 'UA 解析',
    'description' => '从 User-Agent 字符串判断浏览器、操作系统、设备类型与常见爬虫',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'globe',
    'category' => 'net', 'tags' => ['UA', '浏览器', '爬虫'], 'risk' => 'local',
    'usage' => '/ua Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120...',
    'enabled' => true,
    'tools' => [[
        'name' => 'ua_parse', 'description' => '解析 User-Agent；留空则解析当前请求的 UA',
        'parameters' => ['type' => 'object', 'properties' => [
            'ua' => ['type' => 'string', 'description' => 'User-Agent 字符串'],
        ], 'required' => []],
    ]],
    'slash' => ['ua' => 'UA 解析：/ua 后面直接跟 UA 字符串'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $ua = trim((string) ($args['ua'] ?? ($args['input'] ?? '')));
        if ($ua === '') $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') return ['ok' => false, 'text' => '没有可解析的 User-Agent'];
        if (strlen($ua) > 2000) return ['ok' => false, 'text' => 'UA 过长'];
        $bots = ['bot', 'crawler', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit', 'curl/', 'wget/', 'python-requests', 'headlesschrome'];
        $low = strtolower($ua);
        $isBot = false; $botName = '';
        foreach ($bots as $b) if (str_contains($low, $b)) { $isBot = true; $botName = $b; break; }
        $os = '未知';
        foreach (['Windows NT 10.0' => 'Windows 10/11', 'Windows NT 6.3' => 'Windows 8.1', 'Windows' => 'Windows',
            'Android' => 'Android', 'iPhone' => 'iOS (iPhone)', 'iPad' => 'iOS (iPad)', 'Mac OS X' => 'macOS',
            'Linux' => 'Linux', 'CrOS' => 'ChromeOS'] as $k => $v) {
            if (str_contains($low, strtolower($k))) { $os = $v; break; }
        }
        $browser = '未知'; $ver = '';
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Chrome', 'Firefox/' => 'Firefox',
            'Safari/' => 'Safari', 'MSIE ' => 'IE', 'Trident/' => 'IE'] as $k => $v) {
            $p = stripos($ua, $k);
            if ($p !== false) {
                $browser = $v;
                if (preg_match('~' . preg_quote($k, '~') . '([\d.]+)~i', $ua, $m)) $ver = $m[1];
                break;
            }
        }
        $mobile = (bool) preg_match('~Mobile|Android|iPhone|iPad~i', $ua);
        $lines = ['UA: ' . str_cut($ua, 200), '浏览器：' . $browser . ($ver !== '' ? ' ' . $ver : ''),
            '操作系统：' . $os, '设备：' . ($mobile ? '移动设备' : '桌面/其它'),
            '机器人：' . ($isBot ? '是（匹配 ' . $botName . '）' : '否')];
        return ['ok' => true, 'text' => implode("\n", $lines),
            'data' => ['browser' => $browser, 'version' => $ver, 'os' => $os, 'mobile' => $mobile, 'bot' => $isBot]];
    },
];
