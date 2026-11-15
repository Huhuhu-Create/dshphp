<?php
/** 市场插件：命名风格 / URL 别名转换 */
return [
    'id' => 'mkt.slug', 'name' => '命名与别名',
    'description' => '在 camelCase / snake_case / kebab-case / PascalCase 之间转换，并生成 URL 别名',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'code',
    'category' => 'text', 'tags' => ['命名', 'slug', '驼峰'], 'risk' => 'local',
    'usage' => '/slug {"text":"helloWorld demo","style":"snake"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'name_style', 'description' => '转换命名风格。style: snake|kebab|camel|pascal|lower|upper|url',
        'parameters' => ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string', 'description' => '原文本（英文；中文原样保留）'],
            'style' => ['type' => 'string', 'description' => '目标风格，默认 url'],
        ], 'required' => ['text']],
    ]],
    'slash' => ['slug' => '参数为 JSON：/slug {"text":"Hello World","style":"kebab"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $t = trim((string) ($args['text'] ?? ($args['input'] ?? '')));
        if ($t === '') return ['ok' => false, 'text' => '缺少 text 参数'];
        if (strlen($t) > 500) return ['ok' => false, 'text' => '文本过长'];
        $style = strtolower(trim((string) ($args['style'] ?? 'url')));
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $t);
        $spaced = (string) preg_replace('~[^A-Za-z0-9\x{4e00}-\x{9fff}]+~u', ' ', $spaced);
        $words = array_values(array_filter(preg_split('/\s+/', trim($spaced)) ?: [], fn($w) => $w !== ''));
        $low = array_map(fn($w) => strtolower($w), $words);
        switch ($style) {
            case 'snake': $out = implode('_', $low); break;
            case 'kebab': case 'slug': $out = implode('-', $low); break;
            case 'camel':
                $out = array_shift($low) ?? '';
                foreach ($low as $w) $out .= ucfirst($w);
                break;
            case 'pascal': $out = implode('', array_map('ucfirst', $low)); break;
            case 'lower': $out = strtolower(implode(' ', $words)); break;
            case 'upper': $out = strtoupper(implode('_', $words)); break;
            case 'url': $out = implode('-', $low); break;
            default: return ['ok' => false, 'text' => '未知风格：' . $style];
        }
        return ['ok' => true, 'text' => $out, 'data' => ['result' => $out, 'style' => $style]];
    },
];
