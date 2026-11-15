<?php
/**
 * 插件与技能引擎（零依赖）
 *
 * 插件 = plugins/*.php，文件 return 一个数组：
 *   id / name / description / version / author / icon / enabled
 *   tools: [ ['name'=>..., 'description'=>..., 'parameters'=> <JSON Schema> ], ... ]
 *   slash: [ '命令' => '说明' ]            可选，网页里 /命令 直接调用
 *   run: function(string $tool, array $args, array $ctx): array
 *        → ['ok'=>bool, 'text'=>string, 'data'=>mixed]
 *   ui: [ ... ]                            2.4 可选，插件自定义 GUI 清单（见 app/plugin-ui.php）
 *
 * 技能 = skills/*.md，frontmatter 的 name/description + 正文注入系统提示词，
 * 与 DSH 桌面版的 SKILL.md 概念一致。
 * 2.4.1 起支持可选的 when（触发词）与 default（on/auto/off）：
 * 带 when 的技能默认「按需」，只有用户消息命中触发词才注入，避免技能变多后撑爆上下文。
 */
declare(strict_types=1);

require_once __DIR__ . '/market.php';

function plugin_files(): array {
    $out = [];
    foreach (dsh_glob_php(DSH_PLUGIN_DIR) as $f) {
        if (basename($f)[0] === '.') continue;
        $out[] = $f;
    }
    // 已安装的市场插件与本地插件走完全相同的加载路径
    foreach (market_installed_files() as $f) {
        if (!in_array($f, $out, true)) $out[] = $f;
    }
    sort($out);
    return $out;
}

function plugin_scan(): array {
    $list = [];
    foreach (plugin_files() as $f) {
        try {
            $v = plugin_load_file($f);
        } catch (Throwable $e) {
            $list[] = [
                'id' => 'broken.' . basename($f, '.php'), 'name' => basename($f),
                'description' => '插件加载失败：' . $e->getMessage(), 'version' => '',
                'author' => '', 'icon' => 'alert', 'enabled' => false, 'default_enabled' => false,
                'tools' => [], 'slash' => [], 'broken' => true, 'file' => basename($f),
                'path' => $f, 'ui' => null,
                'source' => dirname($f) === DSH_MARKET_DIR ? 'market' : 'local',
                'category' => '', '_run' => null,
            ];
            continue;
        }
        if (!is_array($v)) continue;
        $id = (string) ($v['id'] ?? basename($f, '.php'));
        $tools = is_array($v['tools'] ?? null) ? $v['tools'] : [];
        $slash = is_array($v['slash'] ?? null) ? $v['slash'] : [];
        $list[] = [
            'id' => $id,
            'name' => (string) ($v['name'] ?? $id),
            'description' => (string) ($v['description'] ?? ''),
            'version' => (string) ($v['version'] ?? '1.0.0'),
            'author' => (string) ($v['author'] ?? ''),
            'icon' => (string) ($v['icon'] ?? 'puzzle'),
            'enabled' => (bool) ($v['enabled'] ?? true),
            'default_enabled' => (bool) ($v['enabled'] ?? true),
            'tools' => $tools,
            'slash' => $slash,
            'broken' => false,
            'file' => basename($f),
            'path' => $f,                              // 真实路径，仅供服务端读 ui 白名单资源，绝不下发前端
            'ui' => is_array($v['ui'] ?? null) ? $v['ui'] : null,
            'source' => dirname($f) === DSH_MARKET_DIR ? 'market' : 'local',
            'category' => (string) ($v['category'] ?? ''),
            '_run' => $v['run'] ?? null,
        ];
    }
    return $list;
}

function plugin_state(Store $store): array {
    $v = $store->kvGet('plugin.state', null);
    return is_array($v) ? $v : [];
}

function plugin_is_enabled(Store $store, array $p): bool {
    $st = plugin_state($store);
    return array_key_exists($p['id'], $st) ? (bool) $st[$p['id']] : (bool) $p['default_enabled'];
}

function plugin_public(Store $store, array $p): array {
    $toolNames = [];
    foreach ($p['tools'] as $t) if (is_array($t)) $toolNames[] = (string) ($t['name'] ?? '');
    return [
        'id' => $p['id'], 'name' => $p['name'], 'description' => $p['description'],
        'version' => $p['version'], 'author' => $p['author'], 'icon' => $p['icon'],
        'enabled' => plugin_is_enabled($store, $p) && !$p['broken'],
        'broken' => $p['broken'], 'file' => $p['file'],
        'source' => $p['source'] ?? 'local', 'category' => $p['category'] ?? '',
        'tools' => $toolNames, 'slash' => $p['slash'],
        // 只下发摘要，不把 CSS/JS 正文塞进首屏 boot
        'ui' => is_array($p['ui'] ?? null) ? [
            'apiVersion' => (int) ($p['ui']['apiVersion'] ?? 1),
            'scope' => is_array($p['ui']['scope'] ?? null) ? array_values(array_map('strval', $p['ui']['scope'])) : ['app'],
            'mounts' => is_array($p['ui']['mounts'] ?? null) ? array_values(array_map('strval', $p['ui']['mounts'])) : [],
        ] : null,
    ];
}

function plugin_list(Store $store): array {
    return array_map(fn($p) => plugin_public($store, $p), plugin_scan());
}

function plugin_set_enabled(Store $store, string $id, bool $on): bool {
    $all = plugin_scan();
    $found = false;
    foreach ($all as $p) if ($p['id'] === $id) $found = true;
    if (!$found) return false;
    $st = plugin_state($store);
    $st[$id] = $on;
    $store->kvSet('plugin.state', $st);
    return true;
}

/** OpenAI function-calling 工具表 + 反向映射（工具名 → 插件/工具） */
function plugin_toolmap(Store $store): array {
    $c = cfg();
    if (empty($c['security']['allow_plugins'])) return ['tools' => [], 'map' => []];
    $tools = [];
    $map = [];
    foreach (plugin_scan() as $p) {
        if ($p['broken'] || !plugin_is_enabled($store, $p)) continue;
        foreach ($p['tools'] as $t) {
            if (!is_array($t)) continue;
            $orig = (string) ($t['name'] ?? '');
            if ($orig === '') continue;
            $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $p['id'] . '__' . $orig);
            $safe = substr((string) $safe, 0, 64);
            if (isset($map[$safe])) continue;
            $params = $t['parameters'] ?? ['type' => 'object', 'properties' => new stdClass()];
            if (is_array($params) && $params === []) $params = ['type' => 'object', 'properties' => new stdClass()];
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $safe,
                    'description' => str_cut((string) ($t['description'] ?? $p['description'] ?? ''), 300),
                    'parameters' => $params,
                ],
            ];
            $map[$safe] = ['plugin' => $p['id'], 'tool' => $orig, 'label' => $p['name'] . ' / ' . $orig];
        }
    }
    return ['tools' => $tools, 'map' => $map];
}

/** 给不支持 function calling 的模型用的纯文本工具清单 */
function plugin_tools_prompt(Store $store): string {
    $tm = plugin_toolmap($store);
    if (!$tm['tools']) return '';
    $lines = ["\n\n## 可用工具（需要调用时，只输出一行 JSON，不要解释）",
        '格式：TOOL_CALL {"name":"工具名","arguments":{...}}',
        '可用工具：'];
    foreach ($tm['tools'] as $t) {
        $f = $t['function'];
        $props = $f['parameters']['properties'] ?? [];
        $desc = [];
        foreach ((array) $props as $k => $v) $desc[] = $k . ':' . (is_array($v) ? (string) ($v['type'] ?? 'any') : 'any');
        $lines[] = '- ' . $f['name'] . '(' . implode(', ', $desc) . ') — ' . $f['description'];
    }
    $lines[] = '收到工具结果后再继续回答用户。不要编造工具结果。';
    return implode("\n", $lines);
}

/** 执行插件（按插件 id 手动调用，或按工具名由模型调用） */
function plugin_invoke(Store $store, string $id, string $tool, array $args, array $ctx = []): array {
    foreach (plugin_scan() as $p) {
        if ($p['id'] !== $id) continue;
        if ($p['broken']) return ['ok' => false, 'text' => '插件加载失败', 'error' => 'broken'];
        if (!plugin_is_enabled($store, $p)) return ['ok' => false, 'text' => '插件已禁用', 'error' => 'disabled'];
        if (!is_callable($p['_run'])) return ['ok' => false, 'text' => '插件没有可执行的 run', 'error' => 'no-run'];
        $ctx = array_merge(['store' => $store, 'config' => cfg(), 'plugin' => $p['id']], $ctx);
        try {
            $r = ($p['_run'])($tool, $args, $ctx);
            if (!is_array($r)) $r = ['ok' => true, 'text' => (string) $r];
            return [
                'ok' => (bool) ($r['ok'] ?? true),
                'text' => (string) ($r['text'] ?? ''),
                'data' => $r['data'] ?? null,
                'plugin' => $p['id'], 'tool' => $tool,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => '插件执行异常：' . $e->getMessage(), 'error' => 'exception', 'plugin' => $p['id'], 'tool' => $tool];
        }
    }
    return ['ok' => false, 'text' => '插件不存在：' . $id, 'error' => 'not-found'];
}

function plugin_invoke_tool(Store $store, string $toolName, $arguments, array $ctx = []): array {
    $tm = plugin_toolmap($store);
    if (!isset($tm['map'][$toolName])) return ['ok' => false, 'text' => '未知工具：' . $toolName, 'error' => 'unknown-tool'];
    if (is_string($arguments)) {
        $d = json_decode($arguments, true);
        $args = is_array($d) ? $d : [];
    } elseif (is_array($arguments)) {
        $args = $arguments;
    } else {
        $args = [];
    }
    $m = $tm['map'][$toolName];
    return plugin_invoke($store, $m['plugin'], $m['tool'], $args, $ctx);
}

/** /命令：网页输入 /time 之类时直接跑插件，参数用 JSON 或纯文本 */
function plugin_slash(Store $store, string $text, array $ctx = []): ?array {
    if ($text === '' || $text[0] !== '/') return null;
    $parts = preg_split('/\s+/', trim(substr($text, 1)), 2);
    $cmd = (string) ($parts[0] ?? '');
    $rest = trim((string) ($parts[1] ?? ''));
    if ($cmd === '') return null;
    foreach (plugin_scan() as $p) {
        if ($p['broken'] || !isset($p['slash'][$cmd])) continue;
        $args = [];
        if ($rest !== '') {
            $d = json_decode($rest, true);
            $args = is_array($d) ? $d : ['input' => $rest];
        }
        $tool = (string) (isset($p['tools'][0]['name']) ? $p['tools'][0]['name'] : $cmd);
        return plugin_invoke($store, $p['id'], $tool, $args, $ctx);
    }
    return null;
}

// ── 技能 ──────────────────────────────────────────────────────────────────
/**
 * frontmatter 可选字段：
 *   when: 触发词（逗号 / 中文逗号 / 顿号 / 竖线分隔，命中任意一个即启用）
 *   default: on=常驻（老技能默认） / auto=按需 / off=默认关闭
 * 无 when 且未写 default 时为 on，保持 2.4.0 及更早版本的行为不变。
 */
function skill_scan(): array {
    if (!is_dir(DSH_SKILL_DIR)) return [];
    $out = [];
    foreach (dsh_glob_md(DSH_SKILL_DIR) as $f) {
        $raw = (string) @file_get_contents($f);
        $name = basename($f, '.md');
        $desc = '';
        $body = $raw;
        $whenRaw = '';
        $default = '';
        // 注意：不能用 \R —— 字节模式下它会把 UTF-8 续字节 0x85（如「清」）当换行，切出半个汉字
        if (preg_match('/^---[ \t]*\r?\n(.*?)\r?\n---[ \t]*\r?\n(.*)$/s', $raw, $m)) {
            foreach (preg_split('/\r\n|\n|\r/', $m[1]) as $line) {
                if (preg_match('/^\s*(name|description|when|default)\s*:\s*(.+)$/u', $line, $kv)) {
                    $v = trim($kv[2]);
                    $k = strtolower($kv[1]);
                    if ($k === 'name') $name = $v;
                    elseif ($k === 'description') $desc = $v;
                    elseif ($k === 'when') $whenRaw = $v;
                    elseif ($k === 'default') $default = strtolower($v);
                }
            }
            $body = $m[2];
        }
        if ($desc === '') {
            $first = trim(str_cut((string) preg_replace('/^#.*$/m', '', $body), 90));
            $desc = $first !== '' ? $first : '自定义技能';
        }
        $triggers = [];
        foreach (preg_split('/[,，、|]+/u', $whenRaw) as $w) {
            $w = trim($w);
            if ($w !== '') $triggers[] = $w;
        }
        $triggers = array_slice(array_values(array_unique($triggers)), 0, 24);
        if ($default === 'off') $mode = 'off';
        elseif ($default === 'on') $mode = 'always';
        elseif ($default === 'auto') $mode = 'auto';
        else $mode = $triggers ? 'auto' : 'always';
        $out[] = [
            'id' => basename($f, '.md'), 'name' => $name, 'description' => $desc,
            'body' => trim($body), 'file' => basename($f),
            'triggers' => $triggers, 'default_mode' => $mode,
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));
    return $out;
}

function skill_state(Store $store): array {
    $v = $store->kvGet('skill.state', null);
    return is_array($v) ? $v : [];
}

/** 有效模式：用户在面板里的显式开关优先，否则用 frontmatter 的 default。 */
function skill_mode(array $s, array $st): string {
    if (array_key_exists($s['id'], $st)) return $st[$s['id']] ? 'always' : 'off';
    return (string) ($s['default_mode'] ?? 'always');
}

function skill_list(Store $store): array {
    $st = skill_state($store);
    return array_map(function ($s) use ($st) {
        $mode = skill_mode($s, $st);
        return [
            'id' => $s['id'], 'name' => $s['name'], 'description' => $s['description'],
            'file' => $s['file'], 'mode' => $mode, 'enabled' => $mode !== 'off',
            'auto' => !empty($s['triggers']), 'when' => implode(' / ', $s['triggers']),
        ];
    }, skill_scan());
}

/** $mode = 'auto' 时清除显式开关，回到 frontmatter 默认（按需）。 */
function skill_set_enabled(Store $store, string $id, bool $on, ?string $mode = null): bool {
    foreach (skill_scan() as $s) {
        if ($s['id'] !== $id) continue;
        $st = skill_state($store);
        if ($mode === 'auto') unset($st[$id]);
        else $st[$id] = $on;
        $store->kvSet('skill.state', $st);
        return true;
    }
    return false;
}

/**
 * 拼进系统提示词的技能正文。
 * $context = 本轮用户消息 + 最近几条用户消息；按需技能命中触发词才注入，
 * 单轮最多 3 个（按命中词数排序），避免技能库变大后拖慢免费模型。
 * 无上下文（例如外部直接调 skill_prompt）时只注入常驻技能。
 */
function skill_prompt(Store $store, ?string $context = null): string {
    $st = skill_state($store);
    $text = (string) ($context ?? '');
    $always = [];
    $matched = [];
    foreach (skill_scan() as $s) {
        $mode = skill_mode($s, $st);
        if ($mode === 'off') continue;
        if ($mode === 'always') { $always[] = [$s, '']; continue; }
        if ($text === '') continue;
        $score = 0;
        foreach ($s['triggers'] as $w) {
            if ($w !== '' && stripos($text, $w) !== false) $score++;
        }
        if ($score > 0) $matched[] = [$s, $score];
    }
    usort($matched, fn($a, $b) => ($b[1] <=> $a[1]) ?: strcmp($a[0]['id'], $b[0]['id']));
    $picked = array_merge($always, array_slice($matched, 0, 3));
    $parts = [];
    foreach ($picked as [$s, $score]) {
        $tag = $score === '' ? '' : '（本轮按需命中）';
        $parts[] = "### 技能：" . $s['name'] . $tag . "\n" . str_cut($s['body'], 6000);
    }
    return $parts ? "\n\n## 本轮启用的技能（请遵循）\n" . implode("\n\n", $parts) : '';
}
