<?php
/**
 * 插件市场引擎（零依赖、零文件写入）
 *
 * 设计要点（适配 FTP 部署的 PHP 主机）：
 *   · 插件源码常驻 market/*.php，与 plugins/*.php 格式完全一致
 *   · 「安装」只往数据库 kv 写一条 id，不复制文件 —— 不需要 plugins/ 可写
 *   · plugin_scan() 会把已安装的市场插件一起纳入，因此工具/开关/斜杠命令全部复用原机制
 *   · 「卸载」只删记录，用户自己上传到 plugins/ 的文件永远不受影响
 *
 * 市场插件文件约定：只 return 一个数组，禁止定义顶层 class / function，
 * 因为 plugin_scan() 在一次请求里可能多次 require 同一个文件。
 */
declare(strict_types=1);

if (!class_exists('Store')) require_once __DIR__ . '/store.php';

function market_categories(): array {
    return [
        'text'   => ['key' => 'text',   'name' => '文本处理', 'icon' => 'text',   'desc' => '统计、编码、比对、提取与模板'],
        'dev'    => ['key' => 'dev',    'name' => '开发编码', 'icon' => 'code',   'desc' => '哈希、JSON、正则、JWT、URL、版本与 Cron'],
        'calc'   => ['key' => 'calc',   'name' => '计算数据', 'icon' => 'calc',   'desc' => '单位、百分比、财务、统计、随机与 CSV'],
        'time'   => ['key' => 'time',   'name' => '时间日期', 'icon' => 'clock',  'desc' => '时间戳、日期差、倒计时与节日速查'],
        'net'    => ['key' => 'net',    'name' => '网络系统', 'icon' => 'globe',  'desc' => 'IP / CIDR、状态码、MIME、环境与 UA'],
        'life'   => ['key' => 'life',   'name' => '生活校验', 'icon' => 'shield', 'desc' => '身份证、手机号、颜色与密码'],
        'fun'    => ['key' => 'fun',    'name' => '趣味创作', 'icon' => 'spark',  'desc' => '摩斯电码、凯撒、抽签、运势与 Emoji'],
        'manage' => ['key' => 'manage', 'name' => '效率管理', 'icon' => 'note',   'desc' => '待办清单与键值记忆（数据留在服务器）'],
        'beauty' => ['key' => 'beauty', 'name' => '美化',     'icon' => 'spark',  'desc' => '侧边栏、主题与背景等界面增强（需安装后刷新页面）'],
        'utility' => ['key' => 'utility', 'name' => '实用',   'icon' => 'tool',   'desc' => '上下文用量、记忆目录树与登录方式等增强'],
    ];
}

/** 「推荐套装」：常用且对模型最有用的 8 个 */
function market_recommended(): array {
    return ['mkt.wordcount', 'mkt.json', 'mkt.hash', 'mkt.unit', 'mkt.timestamp', 'mkt.ip', 'mkt.password', 'mkt.todo'];
}

function market_files(): array {
    $all = dsh_glob_php(DSH_MARKET_DIR);
    $out = [];
    foreach ($all as $f) {
        $b = basename($f);
        if ($b === 'index.php') continue;
        $out[] = $f;
    }
    return $out;
}

/** 读取单个市场插件的元数据（不执行 run，只构造数组与闭包） */
function market_meta(string $file): ?array {
    if (!is_file($file)) return null;
    try {
        $v = plugin_load_file($file);
    } catch (Throwable $e) {
        return [
            'id' => 'broken.' . basename($file, '.php'), 'name' => basename($file),
            'description' => '插件加载失败：' . $e->getMessage(), 'version' => '',
            'category' => 'other', 'tags' => [], 'risk' => 'unknown', 'usage' => '',
            'tools' => [], 'slash' => [], 'broken' => true, 'file' => basename($file),
        ];
    }
    if (!is_array($v)) return null;
    $id = (string) ($v['id'] ?? basename($file, '.php'));
    $toolNames = [];
    foreach ((array) ($v['tools'] ?? []) as $t) {
        if (is_array($t)) $toolNames[] = (string) ($t['name'] ?? '');
    }
    return [
        'id' => $id,
        'name' => (string) ($v['name'] ?? $id),
        'description' => (string) ($v['description'] ?? ''),
        'version' => (string) ($v['version'] ?? '1.0.0'),
        'author' => (string) ($v['author'] ?? ''),
        'icon' => (string) ($v['icon'] ?? 'puzzle'),
        'category' => (string) ($v['category'] ?? 'other'),
        'tags' => array_values(array_filter(array_map('strval', (array) ($v['tags'] ?? [])), fn($x) => $x !== '')),
        'risk' => (string) ($v['risk'] ?? 'local'),
        'usage' => (string) ($v['usage'] ?? ''),
        'tools' => array_values(array_filter($toolNames, fn($x) => $x !== '')),
        'slash' => array_keys((array) ($v['slash'] ?? [])),
        'ui' => is_array($v['ui'] ?? null) ? [
            'apiVersion' => (int) ($v['ui']['apiVersion'] ?? 1),
            'scope' => is_array($v['ui']['scope'] ?? null) ? array_values(array_map('strval', $v['ui']['scope'])) : ['app'],
            'mounts' => is_array($v['ui']['mounts'] ?? null) ? array_values(array_map('strval', $v['ui']['mounts'])) : [],
        ] : null,
        'broken' => false,
        'file' => basename($file),
        '_file' => $file,
    ];
}

function market_scan(bool $refresh = false): array {
    static $cache = null;
    if ($refresh) $cache = null;
    if ($cache !== null) return $cache;
    $out = [];
    foreach (market_files() as $f) {
        $m = market_meta($f);
        if ($m !== null) $out[$m['id']] = $m;
    }
    $cache = $out;
    return $out;
}

function market_state_store(): ?Store {
    try { return new Store(); } catch (Throwable $e) { return null; }
}

/** 已安装 id 列表；请求内缓存，安装/卸载时显式刷新 */
function market_installed_ids(?Store $store = null, bool $refresh = false): array {
    static $cache = null;
    if ($refresh) $cache = null;
    if ($store === null && $cache !== null) return $cache;
    $s = $store ?? market_state_store();
    $v = $s ? $s->kvGet('market.installed', []) : [];
    $ids = [];
    if (is_array($v)) {
        foreach ($v as $x) {
            $x = (string) $x;
            if ($x !== '' && !in_array($x, $ids, true)) $ids[] = $x;
        }
    }
    if ($store === null) $cache = $ids;
    return $ids;
}

/** 供 plugin_files() 使用：已安装插件的真实文件路径 */
function market_installed_files(?Store $store = null): array {
    $all = market_scan();
    $out = [];
    foreach (market_installed_ids($store) as $id) {
        if (isset($all[$id]) && empty($all[$id]['broken']) && is_file((string) $all[$id]['_file'])) {
            $out[] = (string) $all[$id]['_file'];
        }
    }
    return $out;
}

function market_valid_id(string $id): bool {
    return $id !== '' && (bool) preg_match('~^[A-Za-z0-9_.-]{2,64}$~', $id) && isset(market_scan()[$id]);
}

function market_set(Store $store, array $ids): array {
    $clean = [];
    foreach ($ids as $id) {
        $id = (string) $id;
        if (market_valid_id($id) && !in_array($id, $clean, true)) $clean[] = $id;
    }
    $store->kvSet('market.installed', $clean);
    market_installed_ids($store, true);
    return $clean;
}

function market_install(Store $store, string $id): array {
    if (!market_valid_id($id)) return ['ok' => false, 'error' => '插件不存在或 id 非法'];
    $ids = market_installed_ids($store, true);
    if (!in_array($id, $ids, true)) $ids[] = $id;
    market_set($store, $ids);
    return ['ok' => true, 'installed' => true];
}

function market_uninstall(Store $store, string $id): array {
    $ids = market_installed_ids($store, true);
    $ids = array_values(array_filter($ids, fn($x) => $x !== $id));
    market_set($store, $ids);
    return ['ok' => true, 'installed' => false];
}

/** 批量操作：preset = recommended | all | none；也可传 ids / category */
function market_batch(Store $store, array $req): array {
    $all = market_scan();
    $want = [];
    if (isset($req['ids']) && is_array($req['ids'])) {
        foreach ($req['ids'] as $id) if (market_valid_id((string) $id)) $want[] = (string) $id;
    } elseif (isset($req['category']) && (string) $req['category'] !== '') {
        foreach ($all as $id => $p) if ($p['category'] === (string) $req['category'] && !$p['broken']) $want[] = $id;
    } else {
        switch ((string) ($req['preset'] ?? '')) {
            case 'recommended': $want = market_recommended(); break;
            case 'all': $want = array_keys(array_filter($all, fn($p) => !$p['broken'])); break;
            case 'none': $want = []; break;
            default: return ['ok' => false, 'error' => '未知的批量操作'];
        }
    }
    $mode = (string) ($req['mode'] ?? 'replace'); // replace = 替换为该集合；merge = 追加；remove = 移除
    $cur = market_installed_ids($store, true);
    if ($mode === 'merge') $want = array_values(array_unique(array_merge($cur, $want)));
    elseif ($mode === 'remove') $want = array_values(array_filter($cur, fn($x) => !in_array($x, $want, true)));
    $final = market_set($store, $want);
    return ['ok' => true, 'installed' => $final, 'count' => count($final)];
}

function market_item_public(Store $store, array $p): array {
    $installed = in_array($p['id'], market_installed_ids($store), true);
    $enabled = null;
    if ($installed) {
        $st = function_exists('plugin_state') ? plugin_state($store) : [];
        $enabled = array_key_exists($p['id'], $st) ? (bool) $st[$p['id']] : true;
    }
    unset($p['_file']);
    $p['installed'] = $installed;
    $p['enabled'] = $enabled;
    $p['official'] = true;
    return $p;
}

function market_list(Store $store): array {
    $all = market_scan();
    $items = array_map(fn($p) => market_item_public($store, $p), array_values($all));
    usort($items, fn($a, $b) => strcmp($a['id'], $b['id']));
    $cats = [];
    foreach (market_categories() as $k => $c) {
        $n = 0;
        foreach ($items as $it) if ($it['category'] === $k) $n++;
        $cats[] = $c + ['count' => $n];
    }
    $installed = count(market_installed_ids($store));
    $broken = 0;
    foreach ($items as $it) if (!empty($it['broken'])) $broken++;
    $r = [
        'ok' => true,
        'items' => $items,
        'categories' => $cats,
        'recommended' => market_recommended(),
        'stats' => [
            'total' => count($items), 'installed' => $installed,
            'available' => count($items) - $installed,
            'broken' => $broken,
            'tools' => count(function_exists('plugin_toolmap') ? plugin_toolmap($store)['tools'] : []),
        ],
    ];
    // 自诊断：一旦扫不到任何插件，附带排查线索（路径 / 目录是否存在 / glob 是否被禁用）
    if (count($items) === 0) {
        $r['scan'] = [
            'market_dir' => DSH_MARKET_DIR,
            'dir_exists' => is_dir(DSH_MARKET_DIR),
            'dir_readable' => is_dir(DSH_MARKET_DIR) && is_readable(DSH_MARKET_DIR),
            'glob_disabled' => is_callable('glob') ? 'no' : 'yes',
            'open_basedir' => (string) ini_get('open_basedir'),
            'php' => PHP_VERSION,
        ];
    }
    return $r;
}
