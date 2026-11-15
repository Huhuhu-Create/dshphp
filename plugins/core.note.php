<?php
/** 内置插件：会话笔记（持久化到 Store） */
return [
    'id' => 'core.note',
    'name' => '笔记本',
    'description' => '在当前会话里记笔记、列出笔记、删除笔记；数据保存在服务器',
    'version' => '1.0.0', 'author' => 'dshphp', 'icon' => 'note', 'enabled' => true,
    'tools' => [[
        'name' => 'note',
        'description' => '笔记操作。action 为 add（需 content）、list、clear；add 时可给 tag 分类。',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'description' => 'add | list | clear'],
                'content' => ['type' => 'string', 'description' => '笔记内容（add 时必填）'],
                'tag' => ['type' => 'string', 'description' => '标签，可选'],
            ],
            'required' => ['action'],
        ],
    ]],
    'slash' => ['note' => '查看笔记；/note 内容 可快速添加'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $store = $ctx['store'] ?? null;
        if (!$store instanceof Store) return ['ok' => false, 'text' => '存储不可用'];
        $sid = (string) ($ctx['session_id'] ?? 'global');
        $key = 'notes.' . $sid;
        $list = $store->kvGet($key, []);
        if (!is_array($list)) $list = [];
        $action = strtolower(trim((string) ($args['action'] ?? '')));
        if ($action === '') $action = isset($args['content']) || isset($args['input']) ? 'add' : 'list';
        if ($action === 'add') {
            $content = trim((string) ($args['content'] ?? ($args['input'] ?? '')));
            if ($content === '') return ['ok' => false, 'text' => '笔记内容为空'];
            $list[] = ['content' => str_cut($content, 2000), 'tag' => (string) ($args['tag'] ?? ''), 'ts' => now_ms()];
            if (count($list) > 200) $list = array_slice($list, -200);
            $store->kvSet($key, $list);
            return ['ok' => true, 'text' => '已记下第 ' . count($list) . ' 条笔记：' . str_cut($content, 100)];
        }
        if ($action === 'clear') {
            $store->kvSet($key, []);
            return ['ok' => true, 'text' => '笔记已清空'];
        }
        if (!$list) return ['ok' => true, 'text' => '（暂无笔记）'];
        $lines = [];
        foreach (array_values($list) as $i => $n) {
            $lines[] = ($i + 1) . '. [' . date('m-d H:i', (int) ($n['ts'] ?? 0) / 1000) . ']'
                . (!empty($n['tag']) ? ' #' . $n['tag'] : '') . ' ' . (string) ($n['content'] ?? '');
        }
        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => ['count' => count($list)]];
    },
];
