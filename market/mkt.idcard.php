<?php
/** 市场插件：身份证号校验（18 位国标校验码） */
return [
    'id' => 'mkt.idcard', 'name' => '身份证校验',
    'description' => '校验 18 位身份证号校验码，解析出生日期、性别与地区码前两位',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'shield',
    'category' => 'life', 'tags' => ['身份证', '校验', '格式'], 'risk' => 'local',
    'usage' => '/idcard 11010119900307001X（建议用虚构号码测试）',
    'enabled' => true,
    'tools' => [[
        'name' => 'idcard_check', 'description' => '校验身份证号并解析出生日期/性别；输出自动脱敏中间位',
        'parameters' => ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string', 'description' => '18 位身份证号'],
        ], 'required' => ['id']],
    ]],
    'slash' => ['idcard' => '身份证校验：/idcard 18位号码'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $id = strtoupper(trim((string) ($args['id'] ?? ($args['input'] ?? ''))));
        $id = str_replace(' ', '', $id);
        if ($id === '') return ['ok' => false, 'text' => '缺少 id 参数'];
        if (!preg_match('~^\d{17}[\dX]$~', $id)) return ['ok' => false, 'text' => '格式不对：应为 17 位数字 + 1 位数字或 X'];
        $ymd = substr($id, 6, 8);
        $y = (int) substr($ymd, 0, 4); $m = (int) substr($ymd, 4, 2); $d = (int) substr($ymd, 6, 2);
        if (!checkdate($m, $d, $y) || $y < 1900 || $y > (int) date('Y')) return ['ok' => false, 'text' => '出生日期不合法：' . $ymd];
        $w = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $codes = '10X98765432';
        $sum = 0;
        for ($i = 0; $i < 17; $i++) $sum += (int) $id[$i] * $w[$i];
        $expect = $codes[$sum % 11];
        $valid = $expect === $id[17];
        $masked = substr($id, 0, 6) . '********' . substr($id, 14);
        $sex = ((int) $id[16]) % 2 ? '男' : '女';
        $prov = ['11' => '北京', '12' => '天津', '13' => '河北', '14' => '山西', '15' => '内蒙古',
            '21' => '辽宁', '22' => '吉林', '23' => '黑龙江', '31' => '上海', '32' => '江苏', '33' => '浙江',
            '34' => '安徽', '35' => '福建', '36' => '江西', '37' => '山东', '41' => '河南', '42' => '湖北',
            '43' => '湖南', '44' => '广东', '45' => '广西', '46' => '海南', '50' => '重庆', '51' => '四川',
            '52' => '贵州', '53' => '云南', '54' => '西藏', '61' => '陕西', '62' => '甘肃', '63' => '青海',
            '64' => '宁夏', '65' => '新疆', '71' => '中国台湾', '81' => '中国香港', '82' => '中国澳门'];
        $area = $prov[substr($id, 0, 2)] ?? '未知地区码';
        return ['ok' => true, 'text' => ($valid ? '校验通过 ✓' : '校验失败 ✗（正确校验位应为 ' . $expect . '）') .
            "\n脱敏显示 " . $masked . "\n出生日期 " . $y . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT) .
            ' · 性别 ' . $sex . ' · 地区码 ' . substr($id, 0, 2) . '（' . $area . '）',
            'data' => ['valid' => $valid, 'birth' => $ymd, 'gender' => $sex, 'masked' => $masked]];
    },
];
