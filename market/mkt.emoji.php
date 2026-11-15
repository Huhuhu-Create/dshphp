<?php
/** 市场插件：Emoji 关键词速查 */
return [
    'id' => 'mkt.emoji', 'name' => 'Emoji 速查',
    'description' => '按中文或英文关键词查找对应 Emoji，或随机来一个；也可列出全部关键词',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'spark',
    'category' => 'fun', 'tags' => ['emoji', '表情', '符号'], 'risk' => 'local',
    'usage' => '/emoji 猫 或 /emoji {"all":true}',
    'enabled' => true,
    'tools' => [[
        'name' => 'emoji_lookup', 'description' => '按关键词查 Emoji；all=true 列出全部收录关键词',
        'parameters' => ['type' => 'object', 'properties' => [
            'keyword' => ['type' => 'string', 'description' => '关键词'],
            'all' => ['type' => 'boolean', 'description' => '列出全部'],
        ], 'required' => []],
    ]],
    'slash' => ['emoji' => 'Emoji 速查：/emoji 猫'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $map = [
            '笑' => '😄', '开心' => '😊', '哭' => '😢', '生气' => '😠', '惊讶' => '😲', '思考' => '🤔',
            '赞' => '👍', '好' => '👌', '鼓掌' => '👏', '加油' => '💪', '握手' => '🤝', '拜托' => '🙏',
            '爱心' => '❤️', '心碎' => '💔', '星星' => '⭐', '火' => '🔥', '闪电' => '⚡', '水' => '💧',
            '太阳' => '☀️', '月亮' => '🌙', '彩虹' => '🌈', '花' => '🌸', '树' => '🌳', '叶子' => '🍃',
            '猫' => '🐱', '狗' => '🐶', '熊猫' => '🐼', '兔子' => '🐰', '鸟' => '🐦', '鱼' => '🐟',
            '电脑' => '💻', '手机' => '📱', '代码' => '👨‍💻', 'bug' => '🐛', '火箭' => '🚀', '齿轮' => '⚙️',
            '书' => '📚', '笔记' => '📝', '邮件' => '📧', '日历' => '📅', '时钟' => '⏰', '钥匙' => '🔑',
            '锁' => '🔒', '盾' => '🛡️', '工具' => '🛠️', '灯泡' => '💡', '靶心' => '🎯', '礼物' => '🎁',
            '咖啡' => '☕', '茶' => '🍵', '饭' => '🍚', '面' => '🍜', '蛋糕' => '🎂', '啤酒' => '🍺',
            '音乐' => '🎵', '游戏' => '🎮', '电影' => '🎬', '相机' => '📷', '画' => '🎨', '运动' => '🏃',
            '成功' => '✅', '失败' => '❌', '警告' => '⚠️', '问题' => '❓', '禁止' => '🚫', '完成' => '🎉',
        ];
        if (!empty($args['all'])) {
            $lines = [];
            foreach ($map as $k => $v) $lines[] = $k . ' ' . $v;
            return ['ok' => true, 'text' => '收录 ' . count($map) . " 个关键词：\n" . implode('  ', $lines)];
        }
        $kw = trim((string) ($args['keyword'] ?? ($args['input'] ?? '')));
        if ($kw === '') {
            $k = array_rand($map);
            return ['ok' => true, 'text' => '随机 Emoji：' . $map[$k] . '（' . $k . '）', 'data' => ['emoji' => $map[$k]]];
        }
        if (isset($map[$kw])) return ['ok' => true, 'text' => $kw . ' → ' . $map[$kw], 'data' => ['emoji' => $map[$kw]]];
        $hits = [];
        foreach ($map as $k => $v) if (str_contains($k, $kw) || str_contains($kw, $k)) $hits[] = $k . ' ' . $v;
        if ($hits) return ['ok' => true, 'text' => '模糊匹配：' . implode('  ', array_slice($hits, 0, 20))];
        return ['ok' => false, 'text' => '没有找到「' . $kw . '」对应的 Emoji（用 {"all":true} 查看全部）'];
    },
];
