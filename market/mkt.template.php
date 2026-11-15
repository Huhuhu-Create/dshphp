<?php
/** 市场插件：模板变量替换 */
return [
    'id' => 'mkt.template', 'name' => '模板渲染',
    'description' => '把 {{变量}} 替换成给定值，内置 date / time / uuid / timestamp 变量',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'code',
    'category' => 'text', 'tags' => ['模板', '变量', '替换'], 'risk' => 'local',
    'usage' => '/tpl {"template":"你好 {{name}}，今天是 {{date}}","vars":{"name":"老板"}}',
    'enabled' => true,
    'tools' => [[
        'name' => 'render_template', 'description' => '渲染 {{key}} 模板；vars 是变量键值对，未提供的变量保留原样',
        'parameters' => ['type' => 'object', 'properties' => [
            'template' => ['type' => 'string', 'description' => '模板文本'],
            'vars' => ['type' => 'object', 'description' => '变量键值对'],
        ], 'required' => ['template']],
    ]],
    'slash' => ['tpl' => '参数为 JSON：/tpl {"template":"Hi {{name}}","vars":{"name":"DSH"}}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $tpl = (string) ($args['template'] ?? ($args['input'] ?? ''));
        if ($tpl === '') return ['ok' => false, 'text' => '缺少 template 参数'];
        if (strlen($tpl) > 50000) return ['ok' => false, 'text' => '模板过长'];
        $vars = $args['vars'] ?? [];
        if (is_string($vars)) { $d = json_decode($vars, true); $vars = is_array($d) ? $d : []; }
        if (!is_array($vars)) $vars = [];
        $builtin = ['date' => date('Y-m-d'), 'time' => date('H:i:s'), 'datetime' => date('Y-m-d H:i:s'),
            'timestamp' => (string) time(), 'uuid' => uuid4(), 'year' => date('Y')];
        $vars = array_merge($builtin, $vars);
        $out = preg_replace_callback('/\{\{\s*([A-Za-z0-9_.-]{1,40})\s*\}\}/', function ($m) use ($vars) {
            $k = $m[1];
            return array_key_exists($k, $vars) ? (string) $vars[$k] : $m[0];
        }, $tpl);
        $left = [];
        if (preg_match_all('/\{\{\s*([A-Za-z0-9_.-]{1,40})\s*\}\}/', (string) $out, $mm)) $left = array_values(array_unique($mm[1]));
        return ['ok' => true, 'text' => str_cut((string) $out, 6000) . ($left ? "\n（未替换变量：" . implode(', ', $left) . '）' : ''),
            'data' => ['result' => (string) $out, 'unresolved' => $left]];
    },
];
