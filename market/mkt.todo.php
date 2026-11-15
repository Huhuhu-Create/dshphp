<?php
/** 市场插件：待办清单（数据持久化在服务器） */
return [
    'id' => 'mkt.todo', 'name' => '待办清单',
    'description' => '新增、完成、删除、清空待办事项；数据存在本站数据库，跨会话保留',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'note',
    'category' => 'manage', 'tags' => ['待办', '任务', '清单'], 'risk' => 'store',
    'usage' => '/todo 买服务器 或 /todo {"action":"done","id":1}',
    'enabled' => true,
    'tools' => [[
        'name' => 'todo_list', 'description' => '待办操作。action: add（需 text）|list|done（需 id）|del（需 id）|clear',
        'parameters' => ['type' => 'object', 'properties' => [
            'action' => ['type' => 'string', 'description' => '操作名，默认 list'],
            'text' => ['type' => 'string', 'description' => '待办内容'],
            'id' => ['type' => 'integer', 'description' => '待办编号'],
        ], 'required' => []],
    ]],
    'slash' => ['todo' => '查看待办：/todo；新增：/todo 买服务器'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $store = $ctx['store'] ?? null;
        if (!$store instanceof Store) return ['ok' => false, 'text' => '存储不可用'];
        $list = $store->kvGet('plugin.todo', []);
        if (!is_array($list)) $list = [];
        $raw = $args['action'] ?? '';
        $action = is_string($raw) && $raw !== '' ? strtolower(trim($raw)) : '';
        $text = trim((string) ($args['text'] ?? ($args['input'] ?? '')));
        if ($action === '' && $text !== '') $action = 'add';
        if ($action === '') $action = 'list';
        $save = function (array $l) use ($store): void { $store->kvSet('plugin.todo', array_values($l)); };
        if ($action === 'add') {
            if ($text === '') return ['ok' => false, 'text' => '缺少待办内容'];
            if (count($list) >= 200) return ['ok' => false, 'text' => '待办已满（上限 200 条）'];
            $id = 1;
            foreach ($list as $x) if (is_array($x)) $id = max($id, (int) ($x['id'] ?? 0) + 1);
            $list[] = ['id' => $id, 'text' => str_cut($text, 300), 'done' => false, 'at' => date('Y-m-d H:i')];
            $save($list);
            return ['ok' => true, 'text' => '已添加待办 #' . $id . '：' . str_cut($text, 300) . '（共 ' . count($list) . ' 条）'];
        }
        if ($action === 'done' || $action === 'del') {
            $id = (int) ($args['id'] ?? 0);
            $found = false;
            foreach ($list as $i => $x) {
                if (!is_array($x) || (int) ($x['id'] ?? 0) !== $id) continue;
                $found = true;
                if ($action === 'done') { $list[$i]['done'] = true; $save($list); }
                else { unset($list[$i]); $save($list); }
                break;
            }
            return $found ? ['ok' => true, 'text' => '#' . $id . ($action === 'done' ? ' 已标记完成' : ' 已删除')]
                : ['ok' => false, 'text' => '没有找到 #' . $id];
        }
        if ($action === 'clear') {
            $save([]);
            return ['ok' => true, 'text' => '待办清单已清空'];
        }
        if (!$list) return ['ok' => true, 'text' => '待办清单是空的。用 /todo 写点什么 添加一条。'];
        $lines = []; $open = 0;
        foreach ($list as $x) {
            if (!is_array($x)) continue;
            $done = !empty($x['done']);
            if (!$done) $open++;
            $lines[] = '#' . (int) ($x['id'] ?? 0) . ' ' . ($done ? '[✓] ' : '[ ] ') . (string) ($x['text'] ?? '') .
                '（' . (string) ($x['at'] ?? '') . '）';
        }
        return ['ok' => true, 'text' => '待办 ' . count($lines) . ' 条，未完成 ' . $open . " 条：\n" . implode("\n", $lines),
            'data' => ['items' => array_values($list), 'open' => $open]];
    },
];
