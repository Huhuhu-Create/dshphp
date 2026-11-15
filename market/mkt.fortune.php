<?php
/** 市场插件：今日运势（按日期确定性生成，非玄学预测） */
return [
    'id' => 'mkt.fortune', 'name' => '今日运势',
    'description' => '按日期与名字生成当天固定的趣味运势、幸运色与宜忌，纯娱乐',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'spark',
    'category' => 'fun', 'tags' => ['运势', '趣味', '签文'], 'risk' => 'local',
    'usage' => '/fortune 或 /fortune 老板',
    'enabled' => true,
    'tools' => [[
        'name' => 'daily_fortune', 'description' => '生成今日趣味运势；同一天同一名字结果固定',
        'parameters' => ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'description' => '名字，可选'],
            'date' => ['type' => 'string', 'description' => '指定日期 YYYY-MM-DD，可选'],
        ], 'required' => []],
    ]],
    'slash' => ['fortune' => '今日运势：/fortune 或 /fortune 老板'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $name = trim((string) ($args['name'] ?? ($args['input'] ?? '')));
        if ($name === '') $name = '朋友';
        if (strlen($name) > 40) $name = str_cut($name, 40);
        $date = trim((string) ($args['date'] ?? ''));
        $t = $date !== '' ? strtotime($date) : time();
        if ($t === false) return ['ok' => false, 'text' => '日期格式无法解析'];
        $seed = date('Y-m-d', $t) . '|' . $name;
        $h = hash('sha256', $seed);
        $pick = function (array $list, int $offset) use ($h): string {
            $n = hexdec(substr($h, $offset, 2)) % count($list);
            return $list[$n];
        };
        $levels = ['大吉', '中吉', '小吉', '平', '小有波折', '宜稳不宜急'];
        $colors = ['靛蓝 #5b8cff', '松绿 #3ecf8e', '暖橙 #f5b95c', '暮紫 #a78bfa', '朱红 #ff6b6b'];
        $good = ['写代码', '整理文档', '备份数据', '喝杯咖啡', '早睡', '清理待办', '学点新东西', '散步'];
        $bad = ['临时改需求', '熬夜上线', '冲动下单', '跳过备份', '空腹开会', '反复纠结'];
        $tips = ['把复杂的事拆小，今天会顺很多。', '先确认再动手，能省一半返工。',
            '留一点空白时间给意外，节奏会更稳。', '重要的事放在精力最好的时段。',
            '别急着下结论，信息可能还没齐。', '今天适合收尾，不适合开新坑。'];
        $level = $pick($levels, 0);
        $stars = str_repeat('★', max(1, min(5, (int) (hexdec(substr($h, 2, 2)) % 5 + 1))));
        $lines = [
            date('Y-m-d', $t) . ' · ' . $name . ' 的今日运势（娱乐）',
            '运势：' . $level . ' ' . $stars,
            '幸运色：' . $pick($colors, 4) . ' · 幸运数字：' . (hexdec(substr($h, 6, 2)) % 10),
            '宜：' . $pick($good, 8) . '、' . $pick($good, 10),
            '忌：' . $pick($bad, 12),
            '提示：' . $pick($tips, 14),
            '（按日期与名字做哈希生成，同一天结果固定，不含任何真实预测）',
        ];
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['date' => date('Y-m-d', $t), 'level' => $level]];
    },
];
