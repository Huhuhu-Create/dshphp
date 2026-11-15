<?php
/** 市场插件：键值记忆（跨会话持久化） */
return [
    'id' => 'mkt.memory', 'name' => '键值记忆',
    'description' => '让 DSH 记住一些键值对：设置、读取、删除、列出；数据留在服务器，跨会话可用',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'note',
    'category' => 'manage', 'tags' => ['记忆', '键值', '持久化'], 'risk' => 'store',
    'usage' => '/mem {"action":"set","key":"项目","value":"dshphp"} 或 /mem 项目',
    'enabled' => true,
    'tools' => [[
        'name' => 'kv_memory', 'description' => 'action: set（key+value）|get（key）|del（key）|list|clear',
        'parameters' => ['type' => 'object', 'properties' => [
            'action' => ['type' => 'string', 'description' => '操作名，默认 list'],
            'key' => ['type' => 'string', 'description' => '键名'],
            'value' => ['type' => 'string', 'description' => '值'],
        ], 'required' => []],
    ]],
    'slash' => ['mem' => '记忆：/mem {"action":"set","key":"项目","value":"dshphp"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $store = $ctx['store'] ?? null;
        if (!$store instanceof Store) return ['ok' => false, 'text' => '存储不可用'];
        $data = $store->kvGet('plugin.memory', []);
        if (!is_array($data)) $data = [];
        $save = function (array $d) use ($store): void { $store->kvSet('plugin.memory', $d); };
        $action = strtolower(trim((string) ($args['action'] ?? '')));
        $key = trim((string) ($args['key'] ?? ''));
        $rawInput = trim((string) ($args['input'] ?? ''));
        if ($action === '' && $key === '' && $rawInput !== '') { $action = 'get'; $key = $rawInput; }
        if ($action === '') $action = 'list';
        if (!preg_match('~^[\p{L}\p{N}_.\- ]{1,60}$~u', $key) && in_array($action, ['set', 'get', 'del'], true)) {
            return ['ok' => false, 'text' => 'key 只能是 1-60 个中英文、数字、下划线、点或连字符'];
        }
        switch ($action) {
            case 'set':
                $v = (string) ($args['value'] ?? '');
                if ($key === '' || $v === '') return ['ok' => false, 'text' => 'set 需要 key 与 value'];
                if (strlen($v) > 4000) return ['ok' => false, 'text' => '值过长（上限 4000 字节）'];
                if (count($data) >= 100 && !array_key_exists($key, $data)) return ['ok' => false, 'text' => '记忆条数已满（上限 100）'];
                $data[$key] = ['value' => $v, 'at' => date('Y-m-d H:i')];
                $save($data);
                return ['ok' => true, 'text' => '已记住 ' . $key . ' = ' . str_cut($v, 200)];
            case 'get':
                if ($key === '') return ['ok' => false, 'text' => 'get 需要 key'];
                return isset($data[$key]) ? ['ok' => true, 'text' => $key . ' = ' . (string) $data[$key]['value']]
                    : ['ok' => true, 'text' => '没有记住「' . $key . '」。'];
            case 'del':
                if ($key === '') return ['ok' => false, 'text' => 'del 需要 key'];
                if (!isset($data[$key])) return ['ok' => false, 'text' => '没有这条记忆'];
                unset($data[$key]); $save($data);
                return ['ok' => true, 'text' => '已忘记 ' . $key];
            case 'clear':
                $save([]);
                return ['ok' => true, 'text' => '全部记忆已清空'];
            case 'list':
                if (!$data) return ['ok' => true, 'text' => '目前没有任何记忆。'];
                $lines = [];
                foreach ($data as $k => $v) $lines[] = $k . ' = ' . str_cut((string) (is_array($v) ? ($v['value'] ?? '') : $v), 120);
                return ['ok' => true, 'text' => '共 ' . count($lines) . " 条记忆：\n" . implode("\n", $lines), 'data' => ['keys' => array_keys($data)]];
            default:
                return ['ok' => false, 'text' => '未知操作：' . $action . '（可用 set/get/del/list/clear）'];
        }
    },
];
