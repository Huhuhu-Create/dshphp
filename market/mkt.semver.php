<?php
/** 市场插件：语义化版本比较 */
return [
    'id' => 'mkt.semver', 'name' => '版本比较',
    'description' => '比较两个语义化版本号的大小，或给一组版本排序；支持 v 前缀与预发布标识',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'code',
    'category' => 'dev', 'tags' => ['版本', 'semver', '排序'], 'risk' => 'local',
    'usage' => '/semver {"a":"1.2.3","b":"1.10.0"} 或 /semver {"versions":["2.0.0","1.9.9"]}',
    'enabled' => true,
    'tools' => [[
        'name' => 'semver_compare', 'description' => '比较两个版本号，或对 versions 数组排序',
        'parameters' => ['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'description' => '版本 A'],
            'b' => ['type' => 'string', 'description' => '版本 B'],
            'versions' => ['type' => 'array', 'description' => '版本数组（排序模式）', 'items' => ['type' => 'string']],
        ], 'required' => []],
    ]],
    'slash' => ['semver' => '参数为 JSON：/semver {"a":"1.2.3","b":"1.10.0"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $vs = $args['versions'] ?? null;
        if (is_string($vs)) { $d = json_decode($vs, true); $vs = is_array($d) ? $d : null; }
        if (is_array($vs) && $vs) {
            $list = array_values(array_filter(array_map('strval', $vs), fn($x) => trim($x) !== ''));
            if (count($list) > 100) return ['ok' => false, 'text' => '版本数量过多'];
            usort($list, fn($x, $y) => version_compare(ltrim($x, 'vV'), ltrim($y, 'vV')));
            return ['ok' => true, 'text' => '从旧到新：' . implode(' < ', $list), 'data' => ['sorted' => $list]];
        }
        $a = trim((string) ($args['a'] ?? ''));
        $b = trim((string) ($args['b'] ?? ''));
        if ($a === '' || $b === '') return ['ok' => false, 'text' => '需要 a / b 或 versions 参数'];
        if (strlen($a) > 60 || strlen($b) > 60) return ['ok' => false, 'text' => '版本号过长'];
        $c = version_compare(ltrim($a, 'vV'), ltrim($b, 'vV'));
        $word = $c < 0 ? '小于' : ($c > 0 ? '大于' : '等于');
        return ['ok' => true, 'text' => $a . ' ' . $word . ' ' . $b, 'data' => ['result' => $c]];
    },
];
