<?php
/** 市场插件：随机数与抽签 */
return [
    'id' => 'mkt.random', 'name' => '随机与抽签',
    'description' => '生成随机整数/浮点数、掷骰子、抛硬币、从列表中抽样或洗牌',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'spark',
    'category' => 'calc', 'tags' => ['随机', '抽签', '骰子'], 'risk' => 'local',
    'usage' => '/draw {"action":"int","min":1,"max":100} 或 /draw {"action":"pick","items":["A","B"]}',
    'enabled' => true,
    'tools' => [[
        'name' => 'random_draw',
        'description' => 'action: int|float|dice(sides,count)|coin|pick(items,k)|shuffle(items)|uuid',
        'parameters' => ['type' => 'object', 'properties' => [
            'action' => ['type' => 'string', 'description' => '操作名，默认 int'],
            'min' => ['type' => 'number', 'description' => '最小值'],
            'max' => ['type' => 'number', 'description' => '最大值'],
            'sides' => ['type' => 'integer', 'description' => '骰子面数，默认 6'],
            'count' => ['type' => 'integer', 'description' => '骰子个数，默认 1'],
            'items' => ['type' => 'array', 'description' => '候选列表', 'items' => ['type' => 'string']],
            'k' => ['type' => 'integer', 'description' => '抽取个数，默认 1'],
        ], 'required' => []],
    ]],
    'slash' => ['draw' => '随机抽签：/draw {"action":"dice","sides":20,"count":2}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $a = strtolower(trim((string) ($args['action'] ?? 'int')));
        $items = $args['items'] ?? [];
        if (is_string($items)) { $d = json_decode($items, true); $items = is_array($d) ? $d : (preg_split('/[\s,，]+/', trim($items)) ?: []); }
        if (!is_array($items)) $items = [];
        switch ($a) {
            case 'int':
                $lo = (int) ($args['min'] ?? 1); $hi = (int) ($args['max'] ?? 100);
                if ($lo > $hi) [$lo, $hi] = [$hi, $lo];
                $v = random_int($lo, $hi);
                return ['ok' => true, 'text' => '随机整数（' . $lo . '~' . $hi . '）：' . $v, 'data' => ['value' => $v]];
            case 'float':
                $v = random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
                return ['ok' => true, 'text' => '随机浮点数 [0,1)：' . rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.'), 'data' => ['value' => $v]];
            case 'dice':
                $sides = max(2, min(1000, (int) ($args['sides'] ?? 6)));
                $cnt = max(1, min(50, (int) ($args['count'] ?? 1)));
                $rolls = [];
                for ($i = 0; $i < $cnt; $i++) $rolls[] = random_int(1, $sides);
                return ['ok' => true, 'text' => '掷 ' . $cnt . ' 个 d' . $sides . '：' . implode(' + ', $rolls) . ' = ' . array_sum($rolls),
                    'data' => ['rolls' => $rolls, 'sum' => array_sum($rolls)]];
            case 'coin':
                $v = random_int(0, 1) ? '正面' : '反面';
                return ['ok' => true, 'text' => '抛硬币：' . $v, 'data' => ['result' => $v]];
            case 'pick':
                if (!$items) return ['ok' => false, 'text' => 'pick 需要 items 数组'];
                $k = max(1, min(count($items), (int) ($args['k'] ?? 1)));
                $pool = array_values($items); shuffle($pool);
                $chosen = array_slice($pool, 0, $k);
                return ['ok' => true, 'text' => '抽中：' . implode('、', array_map('strval', $chosen)), 'data' => ['picked' => $chosen]];
            case 'shuffle':
                if (!$items) return ['ok' => false, 'text' => 'shuffle 需要 items 数组'];
                $pool = array_values($items); shuffle($pool);
                return ['ok' => true, 'text' => '洗牌结果：' . implode('、', array_map('strval', $pool)), 'data' => ['shuffled' => $pool]];
            case 'uuid':
                $v = uuid4();
                return ['ok' => true, 'text' => 'UUID v4：' . $v, 'data' => ['uuid' => $v]];
            default:
                return ['ok' => false, 'text' => '未知操作：' . $a . '（可用 int/float/dice/coin/pick/shuffle/uuid）'];
        }
    },
];
