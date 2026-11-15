<?php
/** 市场插件：手机号校验与脱敏 */
return [
    'id' => 'mkt.phone', 'name' => '手机号校验',
    'description' => '校验中国大陆手机号格式、按号段粗分运营商、归属地前缀与脱敏显示',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'shield',
    'category' => 'life', 'tags' => ['手机号', '运营商', '脱敏'], 'risk' => 'local',
    'usage' => '/phone 13800138000',
    'enabled' => true,
    'tools' => [[
        'name' => 'phone_check', 'description' => '校验手机号、判断号段与运营商（号段为粗略划分，携号转网后可能不同）',
        'parameters' => ['type' => 'object', 'properties' => [
            'phone' => ['type' => 'string', 'description' => '手机号码'],
        ], 'required' => ['phone']],
    ]],
    'slash' => ['phone' => '手机号校验：/phone 13800138000'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $p = (string) preg_replace('~[\s\-()+]~', '', trim((string) ($args['phone'] ?? ($args['input'] ?? ''))));
        if ($p === '') return ['ok' => false, 'text' => '缺少 phone 参数'];
        if (!preg_match('~^1[3-9]\d{9}$~', $p)) return ['ok' => false, 'text' => '不是合法的大陆手机号（应为 1 开头 11 位）'];
        $pre3 = substr($p, 0, 3); $pre4 = substr($p, 0, 4);
        $cm = ['134','135','136','137','138','139','147','148','150','151','152','157','158','159','172','178','182','183','184','187','188','195','197','198'];
        $cu = ['130','131','132','145','146','155','156','166','167','175','176','185','186','196'];
        $ct = ['133','149','153','162','173','174','177','180','181','189','190','191','193','199'];
        $isp = in_array($pre3, $cm, true) ? '中国移动' : (in_array($pre3, $cu, true) ? '中国联通' : (in_array($pre3, $ct, true) ? '中国电信' : '其它 / 虚拟运营商'));
        if (in_array($pre4, ['1700','1701','1702'], true)) $isp = '虚拟运营商（移动）';
        return ['ok' => true, 'text' => '格式合法 ✓ · 号段 ' . $pre3 . '（' . $isp . '，号段粗分，携号转网后可能不同）· 脱敏 ' .
            substr($p, 0, 3) . '****' . substr($p, 7) . ' · 长度 ' . strlen($p) . ' 位',
            'data' => ['valid' => true, 'prefix' => $pre3, 'isp' => $isp, 'masked' => substr($p, 0, 3) . '****' . substr($p, 7)]];
    },
];
