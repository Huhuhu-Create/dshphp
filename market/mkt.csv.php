<?php
/** 市场插件：CSV 解析与生成 */
return [
    'id' => 'mkt.csv', 'name' => 'CSV 工具',
    'description' => '解析 CSV 文本为表格、转成 Markdown 表格，或把行数组生成 CSV',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'text',
    'category' => 'calc', 'tags' => ['CSV', '表格', '转换'], 'risk' => 'local',
    'usage' => '/csv {"text":"a,b\\n1,2","action":"markdown"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'csv_tool', 'description' => 'action: table|markdown|generate；table/markdown 用 text，generate 用 rows 数组',
        'parameters' => ['type' => 'object', 'properties' => [
            'action' => ['type' => 'string', 'description' => '操作名，默认 markdown'],
            'text' => ['type' => 'string', 'description' => 'CSV 文本'],
            'rows' => ['type' => 'array', 'description' => '生成 CSV 用的行数组', 'items' => ['type' => 'array']],
            'header' => ['type' => 'boolean', 'description' => '首行是否为表头，默认 true'],
        ], 'required' => []],
    ]],
    'slash' => ['csv' => '参数为 JSON：/csv {"text":"name,age\\nA,18","action":"markdown"}'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $a = strtolower(trim((string) ($args['action'] ?? 'markdown')));
        if ($a === 'generate') {
            $rows = $args['rows'] ?? [];
            if (!is_array($rows) || !$rows) return ['ok' => false, 'text' => 'generate 需要 rows 数组'];
            $out = [];
            foreach (array_slice($rows, 0, 200) as $row) {
                if (!is_array($row)) continue;
                $cells = array_map(function ($c) {
                    $s = is_scalar($c) ? (string) $c : (string) json_encode($c, JSON_UNESCAPED_UNICODE);
                    return (str_contains($s, ',') || str_contains($s, '"') || str_contains($s, "\n"))
                        ? '"' . str_replace('"', '""', $s) . '"' : $s;
                }, $row);
                $out[] = implode(',', $cells);
            }
            return ['ok' => true, 'text' => implode("\n", $out), 'data' => ['csv' => implode("\n", $out)]];
        }
        $text = (string) ($args['text'] ?? ($args['input'] ?? ''));
        if ($text === '') return ['ok' => false, 'text' => '缺少 text 参数'];
        if (strlen($text) > 200000) return ['ok' => false, 'text' => '文本过长'];
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $rows = [];
        foreach (array_slice($lines, 0, 300) as $line) {
            if (trim($line) === '') continue;
            $rows[] = str_getcsv($line, ',', '"', '\\');
        }
        if (!$rows) return ['ok' => false, 'text' => '没有解析到数据行'];
        $cols = 0;
        foreach ($rows as $r) $cols = max($cols, count($r));
        if ($a === 'table') {
            $j = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return ['ok' => true, 'text' => str_cut((string) $j, 6000), 'data' => ['rows' => $rows]];
        }
        if ($a === 'markdown') {
            $head = $args['header'] ?? true;
            $first = $rows[0];
            if ($head) {
                $md = '| ' . implode(' | ', array_map('strval', $first)) . " |\n|" . implode('|', array_fill(0, max(1, count($first)), '---')) . '|';
                $body = array_slice($rows, 1);
            } else {
                $md = '| ' . implode(' | ', array_map(fn($i) => '列' . ($i + 1), range(0, $cols - 1))) . " |\n|" . implode('|', array_fill(0, $cols, '---')) . '|';
                $body = $rows;
            }
            foreach (array_slice($body, 0, 60) as $r) {
                $r = array_slice(array_pad(array_map(fn($c) => str_replace('|', '\|', (string) $c), $r), $cols, ''), 0, $cols);
                $md .= "\n| " . implode(' | ', $r) . ' |';
            }
            if (count($body) > 60) $md .= "\n\n（仅显示前 60 行）";
            return ['ok' => true, 'text' => $md, 'data' => ['rows' => count($rows), 'cols' => $cols]];
        }
        return ['ok' => false, 'text' => '未知操作：' . $a . '（可用 table/markdown/generate）'];
    },
];
