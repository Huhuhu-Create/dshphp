<?php
/**
 * dshphp 2.3.1 —— 自动化任务引擎（访问驱动，无保活）
 *
 * 设计前提（适配 l2.ink 那台「纯 PHP-FPM + FTP、无 SSH、无常驻进程」的主机）：
 *   · 任务配置与运行日志全部保存在服务器上的一个 **JSON 文件**；
 *   · 文件名由 app/storage.php 里的随机数据名派生，外部既猜不到也无法通过 Web 下载；
 *   · 唯一的驱动方式：**有请求访问 index.php 时推进一次调度**。
 *     没有访问就不执行；没有前端定时心跳、没有外部 cron、没有常驻进程。
 *     PHP 只在被请求时运行，这是运行模型决定的，不做任何保活伪装。
 *   · 每次推进仍按各任务的 next_run 判断，只有到期的任务才执行；
 *     10 秒内的高频访问合并为一次推进，避免反复写盘。
 *   · 用 flock 独占锁保证多个请求同时推进时同一个任务只跑一次。
 *
 * 不改动现有 config.php / storage.php / 对话数据，上传即用、旧站直接升级。
 */
declare(strict_types=1);

require_once __DIR__ . '/automation.actions.php';

if (!defined('AUTOMATION_TICK_THROTTLE')) {
    define('AUTOMATION_TICK_THROTTLE', 10);   // 高频访问合并窗口（秒），不是保活间隔
    define('AUTOMATION_MIN_INTERVAL', 30);    // interval 任务最小周期（秒）
    define('AUTOMATION_MAX_PER_TICK', 12);    // 单次推进最多执行几个任务
    define('AUTOMATION_LOG_LIMIT', 200);      // 运行日志最多保留几条
}

/** 自动化 JSON 文件位置：随机子目录 + 由 storage.php 随机名派生的不可猜文件名 */
function automation_info(): array {
    $i = storage_info();
    $seed = (string) ($i['file'] ?? '') . '|' . (string) ($i['dir'] ?? '') . '|dshphp-automation-v1';
    $hash = hash('sha256', $seed);
    $dir = DSH_DATA_DIR . (($i['dir'] ?? '') !== '' ? '/' . $i['dir'] : '');
    $name = 'auto_' . substr($hash, 0, 32) . '.json';
    return [
        'dir'   => $dir,
        'file'  => $dir . '/' . $name,
        'lock'  => $dir . '/auto_' . substr($hash, 32, 16) . '.lock',
        'name'  => $name,
        'hint'  => 'data/<随机目录>/auto_<随机>.json（真实文件名不公开）',
    ];
}

function automation_ensure_dir(): bool {
    $i = automation_info();
    if (!is_dir($i['dir'])) @mkdir($i['dir'], 0775, true);
    return is_dir($i['dir']) && is_writable($i['dir']);
}

/** 单个默认任务的完整字段 */
function automation_task(array $v): array {
    $now = time();
    return array_merge([
        'id' => '', 'name' => '未命名任务', 'note' => '', 'type' => 'log',
        'schedule' => ['mode' => 'interval', 'every' => 300],
        'args' => [], 'enabled' => true, 'builtin' => true,
        'last_run' => 0, 'last_ok' => null, 'next_run' => $now,
        'run_count' => 0, 'fail_count' => 0,
    ], $v);
}

/** 上线即内置的 10 个自动化任务：全部开箱即用，不依赖市场插件是否已安装 */
function automation_default_tasks(): array {
    return [
        automation_task([
            'id' => 'auto.heartbeat', 'name' => '周期打点',
            'note' => '每 5 分钟写一条运行记录，用来确认最近有访问推进过调度',
            'type' => 'log', 'schedule' => ['mode' => 'interval', 'every' => 300],
            'args' => ['message' => '调度已推进 · {time}'],
        ]),
        automation_task([
            'id' => 'auto.watchdog', 'name' => '调度器自检',
            'note' => '每分钟检查任务表、存储驱动与 PHP 环境',
            'type' => 'health', 'schedule' => ['mode' => 'interval', 'every' => 60],
        ]),
        automation_task([
            'id' => 'auto.storage', 'name' => '存储健康检查',
            'note' => '每 10 分钟确认数据目录可写、统计会话与消息量',
            'type' => 'health', 'schedule' => ['mode' => 'interval', 'every' => 600],
            'args' => ['detail' => true],
        ]),
        automation_task([
            'id' => 'auto.hourly-time', 'name' => '整点时间快照',
            'note' => '每小时调用 core.time 插件记录服务器时间',
            'type' => 'plugin', 'schedule' => ['mode' => 'cron', 'expr' => '0 * * * *'],
            'args' => ['plugin' => 'core.time', 'tool' => 'get_time', 'args' => ['format' => 'Y-m-d H:i:s']],
        ]),
        automation_task([
            'id' => 'auto.morning', 'name' => '每日晨间自检',
            'note' => '每天 09:00 调用 core.system 输出系统状态（不泄露路径与密钥）',
            'type' => 'plugin', 'schedule' => ['mode' => 'cron', 'expr' => '0 9 * * *'],
            'args' => ['plugin' => 'core.system', 'tool' => 'system_status', 'args' => []],
        ]),
        automation_task([
            'id' => 'auto.daily-digest', 'name' => '每日执行汇总',
            'note' => '每天 23:55 统计当天成功 / 失败次数',
            'type' => 'digest', 'schedule' => ['mode' => 'cron', 'expr' => '55 23 * * *'],
            'args' => ['scope' => 'today'],
        ]),
        automation_task([
            'id' => 'auto.log-prune', 'name' => '运行日志清理',
            'note' => '每天 00:05 把运行日志压到配置上限，避免 JSON 无限膨胀',
            'type' => 'prune', 'schedule' => ['mode' => 'cron', 'expr' => '5 0 * * *'],
        ]),
        automation_task([
            'id' => 'auto.weekly', 'name' => '每周一运行周报',
            'note' => '每周一 08:30 输出最近 7 天执行统计',
            'type' => 'digest', 'schedule' => ['mode' => 'cron', 'expr' => '30 8 * * 1'],
            'args' => ['scope' => 'week'],
        ]),
        automation_task([
            'id' => 'auto.snapshot', 'name' => '配置快照备份',
            'note' => '每天 02:00 把任务表结构写入运行日志，便于回溯改动',
            'type' => 'snapshot', 'schedule' => ['mode' => 'cron', 'expr' => '0 2 * * *'],
        ]),
        automation_task([
            'id' => 'auto.midnight', 'name' => '跨日零点标记',
            'note' => '每天 00:00 记录一条跨日标记，便于按天查看运行日志',
            'type' => 'log', 'schedule' => ['mode' => 'cron', 'expr' => '0 0 * * *'],
            'args' => ['message' => '新的一天：{date}（{week}）'],
        ]),
    ];
}

function automation_defaults(): array {
    return [
        'version'   => 1,
        'enabled'   => true,
        'max_log'   => AUTOMATION_LOG_LIMIT,
        'timezone'  => date_default_timezone_get(),
        'tasks'     => automation_default_tasks(),
        'runs'      => [],
        'meta'      => [
            'token'      => '',   // 2.3.1 起不再有外部心跳密钥，字段保留仅为兼容旧 JSON
            'last_tick'  => 0,
            'ticks'      => 0,
            'created_at' => time(),
        ],
    ];
}

/** 补齐字段、清洗非法值；任何来源（文件 / 导入 / 表单）都先过这一层 */
function automation_normalize($d): array {
    $base = automation_defaults();
    if (!is_array($d)) return $base;
    $out = $base;
    $out['enabled'] = !array_key_exists('enabled', $d) || !empty($d['enabled']);
    $out['max_log'] = max(20, min(2000, (int) ($d['max_log'] ?? AUTOMATION_LOG_LIMIT)));
    $out['version'] = 1;
    // 2.3.1 起不再生成/保留任何心跳密钥：读到旧文件里的 token 一律清空，
    // 这样导出的 JSON 也不含密钥，外部已停用的 cron.php 无法再被利用。
    $out['meta']['token']      = '';
    $out['meta']['last_tick']  = (int) ($d['meta']['last_tick'] ?? 0);
    $out['meta']['ticks']      = (int) ($d['meta']['ticks'] ?? 0);
    $out['meta']['created_at'] = (int) ($d['meta']['created_at'] ?? time());

    $tasks = is_array($d['tasks'] ?? null) ? $d['tasks'] : $base['tasks'];
    $seen = [];
    $out['tasks'] = [];
    foreach ($tasks as $t) {
        if (!is_array($t)) continue;
        $n = automation_normalize_task($t);
        if ($n === null || isset($seen[$n['id']])) continue;
        $seen[$n['id']] = true;
        $out['tasks'][] = $n;
    }
    if (!$out['tasks']) $out['tasks'] = $base['tasks'];

    $runs = is_array($d['runs'] ?? null) ? $d['runs'] : [];
    foreach ($runs as $r) {
        if (!is_array($r)) continue;
        $out['runs'][] = [
            'id' => (string) ($r['id'] ?? ''), 'task' => (string) ($r['task'] ?? ''),
            'name' => (string) ($r['name'] ?? ''), 'at' => (int) ($r['at'] ?? 0),
            'ok' => !empty($r['ok']), 'ms' => (int) ($r['ms'] ?? 0),
            'text' => str_cut((string) ($r['text'] ?? ''), 600),
            'manual' => !empty($r['manual']),
        ];
    }
    if (count($out['runs']) > $out['max_log']) {
        $out['runs'] = array_slice($out['runs'], -$out['max_log']);
    }
    return $out;
}

function automation_normalize_task(array $t): ?array {
    $id = trim((string) ($t['id'] ?? ''));
    if ($id === '' || !preg_match('~^[A-Za-z0-9_.-]{1,64}$~', $id)) return null;
    $type = (string) ($t['type'] ?? 'log');
    if (!in_array($type, automation_types(), true)) $type = 'log';
    $sched = is_array($t['schedule'] ?? null) ? $t['schedule'] : [];
    $sched = automation_normalize_schedule($sched);
    $args = is_array($t['args'] ?? null) ? $t['args'] : [];
    return [
        'id' => $id,
        'name' => str_cut(trim((string) ($t['name'] ?? $id)) ?: $id, 60),
        'note' => str_cut((string) ($t['note'] ?? ''), 200),
        'type' => $type,
        'schedule' => $sched,
        'args' => $args,
        'enabled' => !array_key_exists('enabled', $t) || !empty($t['enabled']),
        'builtin' => !empty($t['builtin']),
        'last_run' => (int) ($t['last_run'] ?? 0),
        'last_ok' => array_key_exists('last_ok', $t) ? ($t['last_ok'] === null ? null : !empty($t['last_ok'])) : null,
        'next_run' => (int) ($t['next_run'] ?? 0),
        'run_count' => (int) ($t['run_count'] ?? 0),
        'fail_count' => (int) ($t['fail_count'] ?? 0),
    ];
}

function automation_normalize_schedule(array $s): array {
    if (($s['mode'] ?? '') === 'cron') {
        $expr = trim((string) ($s['expr'] ?? ''));
        if (automation_cron_sets($expr) === null) $expr = '0 * * * *';
        return ['mode' => 'cron', 'expr' => $expr];
    }
    return ['mode' => 'interval', 'every' => max(AUTOMATION_MIN_INTERVAL, min(86400 * 30, (int) ($s['every'] ?? 300)))];
}

function automation_types(): array {
    return ['log', 'plugin', 'health', 'digest', 'prune', 'snapshot', 'http'];
}

/** 5 段 cron → 每段允许值的集合；支持 * , - / ；返回 null 表示非法 */
function automation_cron_sets(string $expr): ?array {
    $parts = preg_split('/\s+/', trim($expr)) ?: [];
    if (count($parts) !== 5) return null;
    $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    $sets = [];
    foreach ($parts as $i => $seg) {
        [$lo, $hi] = $ranges[$i];
        $vals = [];
        foreach (explode(',', $seg) as $piece) {
            $piece = trim($piece);
            if ($piece === '') continue;
            $step = 1;
            if (str_contains($piece, '/')) {
                [$piece, $st] = explode('/', $piece, 2);
                $step = max(1, min(59, (int) $st));
            }
            if ($piece === '*') { $a = $lo; $b = $hi; }
            elseif (str_contains($piece, '-')) {
                [$a, $b] = array_map('intval', explode('-', $piece, 2));
            } elseif (ctype_digit($piece)) {
                $a = (int) $piece; $b = $a;
            } else { return null; }
            $a = max($lo, $a); $b = min($hi, $b);
            if ($a > $b) continue;
            for ($k = $a; $k <= $b; $k += $step) $vals[$k] = true;
        }
        if ($i === 4) {
            if (isset($vals[7])) $vals[0] = true;
            unset($vals[7]); // 统一 0=周日 … 6=周六
        }
        if (!$vals) return null;
        ksort($vals);
        $sets[$i] = $vals;
    }
    return $sets;
}

/** 计算某次执行之后的下一次执行时间戳（按天跳跃，不逐分钟暴力轮询） */
function automation_next_from(int $now, array $sched): int {
    $s = automation_normalize_schedule($sched);
    if ($s['mode'] === 'interval') return $now + (int) $s['every'];
    $sets = automation_cron_sets((string) $s['expr']);
    if ($sets === null) return $now + 3600;
    $t0 = (int) (floor(($now + 59) / 60) * 60); // 下一整分钟
    $day0 = mktime(0, 0, 0, (int) date('n', $t0), (int) date('j', $t0), (int) date('Y', $t0));
    for ($d = 0; $d < 367; $d++) {
        $day = strtotime('+' . $d . ' days', $day0);
        if ($day === false) break;
        $gd = getdate($day);
        if (!isset($sets[2][$gd['mday']]) || !isset($sets[3][$gd['mon']]) || !isset($sets[4][$gd['wday']])) continue;
        foreach (array_keys($sets[1]) as $h) {
            foreach (array_keys($sets[0]) as $m) {
                $ts = mktime((int) $h, (int) $m, 0, $gd['mon'], $gd['mday'], $gd['year']);
                if ($ts >= $t0) return $ts;
            }
        }
    }
    return $now + 86400;
}

function automation_schedule_label(array $s): string {
    $s = automation_normalize_schedule($s);
    if ($s['mode'] === 'cron') return 'cron ' . $s['expr'];
    $e = (int) $s['every'];
    if ($e < 60) return '每 ' . $e . ' 秒';
    if ($e % 3600 === 0) return '每 ' . ($e / 3600) . ' 小时';
    if ($e % 60 === 0) return '每 ' . ($e / 60) . ' 分钟';
    return '每 ' . $e . ' 秒';
}

function automation_ts(?int $t): string {
    return $t ? date('Y-m-d H:i:s', $t) : '—';
}

/** 读配置（共享锁） */
function automation_read(): array {
    $i = automation_info();
    if (!is_file($i['file'])) return automation_defaults();
    $fh = @fopen($i['file'], 'rb');
    if (!$fh) return automation_defaults();
    @flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    $d = json_decode((string) $raw, true);
    return automation_normalize(is_array($d) ? $d : null);
}

/** 在独占锁内读—改—写整份 JSON，保证并发不互相覆盖 */
function automation_mutate(callable $fn): array {
    if (!automation_ensure_dir()) throw new RuntimeException('数据目录不可写，请检查 data/ 权限');
    $i = automation_info();
    $fh = @fopen($i['file'], 'c+b');
    if (!$fh) throw new RuntimeException('无法打开自动化配置文件，请检查 data/ 权限');
    @flock($fh, LOCK_EX);
    rewind($fh);
    $raw = stream_get_contents($fh);
    $d = json_decode((string) $raw, true);
    $d = automation_normalize(is_array($d) ? $d : null);
    $d = $fn($d);
    $d = automation_normalize($d);
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    rewind($fh);
    @ftruncate($fh, 0);
    fwrite($fh, (string) $json);
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $d;
}

/** 外部可见的任务视图（含可读时间，不暴露文件路径 / token） */
function automation_task_public(array $t): array {
    $t['schedule_label'] = automation_schedule_label($t['schedule']);
    $t['next_at'] = automation_ts((int) $t['next_run']);
    $t['last_at'] = automation_ts((int) $t['last_run']);
    $t['type_label'] = automation_type_label((string) $t['type']);
    return $t;
}

function automation_stats(array $d): array {
    $ok = 0; $fail = 0; $enabled = 0;
    foreach ($d['tasks'] as $t) {
        if (!empty($t['enabled'])) $enabled++;
        $ok += (int) ($t['run_count'] ?? 0) - (int) ($t['fail_count'] ?? 0);
        $fail += (int) ($t['fail_count'] ?? 0);
    }
    $i = automation_info();
    return [
        'enabled' => !empty($d['enabled']),
        'storage' => 'json',
        'tasks' => count($d['tasks']),
        'enabled_tasks' => $enabled,
        'runs_logged' => count($d['runs']),
        'total_runs' => $ok + $fail,
        'ok_runs' => max(0, $ok),
        'fail_runs' => $fail,
        'last_tick' => (int) ($d['meta']['last_tick'] ?? 0),
        'last_tick_at' => automation_ts((int) ($d['meta']['last_tick'] ?? 0)),
        'ticks' => (int) ($d['meta']['ticks'] ?? 0),
        'created_at' => automation_ts((int) ($d['meta']['created_at'] ?? 0)),
        'driver' => 'access-only',   // 唯一驱动：index.php 被访问
        'keepalive' => false,
        'file_hint' => $i['hint'],
        'file_ready' => is_file($i['file']),
        'writable' => is_dir($i['dir']) && is_writable($i['dir']),
        'server_time' => date('Y-m-d H:i:s'),
    ];
}

function automation_list(): array {
    $d = automation_read();
    return [
        'ok' => true,
        'tasks' => array_map('automation_task_public', $d['tasks']),
        'runs' => array_slice(array_reverse($d['runs']), 0, 60),
        'stats' => automation_stats($d),
        'types' => automation_type_options(),
    ];
}

function automation_type_options(): array {
    $out = [];
    foreach (automation_types() as $t) $out[] = ['key' => $t, 'label' => automation_type_label($t)];
    return $out;
}

function automation_set_enabled(bool $on): array {
    automation_mutate(function (array $d) use ($on): array { $d['enabled'] = $on; return $d; });
    return ['ok' => true, 'scheduler_enabled' => $on];
}

function automation_toggle(string $id, bool $on): array {
    $found = false;
    automation_mutate(function (array $d) use ($id, $on, &$found): array {
        foreach ($d['tasks'] as $k => $t) {
            if ($t['id'] !== $id) continue;
            $d['tasks'][$k]['enabled'] = $on;
            $found = true;
            if ($on && (int) $d['tasks'][$k]['next_run'] < time()) {
                $d['tasks'][$k]['next_run'] = automation_next_from(time(), $t['schedule']);
            }
            break;
        }
        return $d;
    });
    return $found ? ['ok' => true] : ['ok' => false, 'error' => '任务不存在'];
}

function automation_upsert(array $raw): array {
    $t = automation_normalize_task($raw);
    if ($t === null) return ['ok' => false, 'error' => '任务 ID 非法（只允许字母数字 _ . -，最长 64 位）'];
    $isNew = true;
    automation_mutate(function (array $d) use ($t, &$isNew): array {
        foreach ($d['tasks'] as $k => $old) {
            if ($old['id'] !== $t['id']) continue;
            // 编辑保留运行统计；首次到期时间保持用户设置或立即排期
            $t['last_run'] = (int) $old['last_run'];
            $t['last_ok'] = $old['last_ok'];
            $t['run_count'] = (int) $old['run_count'];
            $t['fail_count'] = (int) $old['fail_count'];
            $t['next_run'] = (int) ($t['next_run'] ?: automation_next_from(time(), $t['schedule']));
            $d['tasks'][$k] = $t;
            $isNew = false;
            return $d;
        }
        $t['next_run'] = time(); // 新建任务：下一次访问推进时立刻跑一次，便于验证
        $d['tasks'][] = $t;
        return $d;
    });
    return ['ok' => true, 'created' => $isNew, 'task' => automation_task_public($t)];
}

function automation_delete(string $id): array {
    $found = false;
    automation_mutate(function (array $d) use ($id, &$found): array {
        $d['tasks'] = array_values(array_filter($d['tasks'], function ($t) use ($id, &$found) {
            if ($t['id'] === $id) { $found = true; return false; }
            return true;
        }));
        if (!$d['tasks']) $d['tasks'] = automation_default_tasks(); // 永不留空表，避免调度器「空转」
        return $d;
    });
    return $found ? ['ok' => true] : ['ok' => false, 'error' => '任务不存在'];
}

/** 导出原始 JSON（供备份 / FTP 手工编辑） */
function automation_export(): string {
    $d = automation_read();
    return (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

/** 导入 JSON：只替换任务表与开关，运行日志与推进计数保留 */
function automation_import($text): array {
    $v = json_decode((string) $text, true);
    if (!is_array($v) || !isset($v['tasks']) || !is_array($v['tasks'])) {
        return ['ok' => false, 'error' => 'JSON 格式不对：需要包含 tasks 数组'];
    }
    $n = automation_normalize($v);
    automation_mutate(function (array $d) use ($n): array {
        $d['tasks'] = $n['tasks'];
        $d['enabled'] = $n['enabled'];
        $d['max_log'] = $n['max_log'];
        return $d;
    });
    return ['ok' => true, 'imported' => count($n['tasks'])];
}

function automation_logs(int $limit = 100): array {
    $d = automation_read();
    $runs = array_slice(array_reverse($d['runs']), 0, max(1, min(200, $limit)));
    foreach ($runs as $k => $r) $runs[$k]['at_text'] = automation_ts((int) $r['at']);
    return ['ok' => true, 'runs' => $runs];
}

/**
 * 推进一次调度。
 * $force  = 跳过访问合并窗口（供手动「立即推进」按钮），但**不会**跳过到期判断；
 * $onlyId = 只执行指定任务（立即运行按钮），无视其 enabled 与 next_run。
 */
function automation_tick(Store $store, bool $force = false, ?string $onlyId = null): array {
    try {
        if (!automation_ensure_dir()) return ['ok' => false, 'error' => '数据目录不可写', 'executed' => 0];
        $i = automation_info();
        $lh = @fopen($i['lock'], 'c+b');
        if (!$lh) return ['ok' => false, 'error' => '无法创建调度锁文件', 'executed' => 0];
        if (!@flock($lh, LOCK_EX | LOCK_NB)) {
            fclose($lh);
            return ['ok' => true, 'skipped' => 'busy', 'executed' => 0, 'note' => '另一个请求正在调度，已跳过'];
        }
        $res = ['ok' => true, 'executed' => 0, 'results' => [], 'skipped' => ''];
        automation_mutate(function (array $d) use ($store, $force, $onlyId, &$res): array {
            $now = time();
            if ($onlyId === null && !$force && ($now - (int) $d['meta']['last_tick']) < AUTOMATION_TICK_THROTTLE) {
                $res['skipped'] = 'throttled';
                return $d;
            }
            if ($onlyId === null && !$d['enabled']) {
                $res['skipped'] = 'paused';
                return $d;
            }
            $d['meta']['last_tick'] = $now;
            $d['meta']['ticks'] = (int) $d['meta']['ticks'] + 1;

            $due = [];
            foreach ($d['tasks'] as $k => $t) {
                if ($onlyId !== null) {
                    if ($t['id'] === $onlyId) $due[] = $k;
                    continue;
                }
                if (empty($t['enabled'])) continue;
                if ($now >= (int) $t['next_run']) $due[] = $k;
            }
            $budget = min(AUTOMATION_MAX_PER_TICK, count($due));
            for ($n = 0; $n < $budget; $n++) {
                $k = $due[$n];
                $t = $d['tasks'][$k];
                $t0 = microtime(true);
                try {
                    $r = automation_execute_task($store, $t, $d, $now);
                } catch (Throwable $e) {
                    $r = ['ok' => false, 'text' => '任务异常：' . $e->getMessage()];
                }
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $d['tasks'][$k]['last_run'] = $now;
                $d['tasks'][$k]['last_ok'] = !empty($r['ok']);
                $d['tasks'][$k]['run_count'] = (int) $d['tasks'][$k]['run_count'] + 1;
                if (empty($r['ok'])) $d['tasks'][$k]['fail_count'] = (int) $d['tasks'][$k]['fail_count'] + 1;
                $d['tasks'][$k]['next_run'] = automation_next_from($now, $t['schedule']);
                $entry = [
                    'id' => 'run_' . rand_hex(6), 'task' => $t['id'], 'name' => $t['name'],
                    'at' => $now, 'ok' => !empty($r['ok']), 'ms' => $ms,
                    'text' => str_cut((string) ($r['text'] ?? ''), 600),
                    'manual' => $onlyId !== null,
                ];
                $d['runs'][] = $entry;
                $res['results'][] = $entry;
                $res['executed']++;
            }
            // 超出单次预算的到期任务顺延 60 秒，避免下一轮全部挤在一起
            for ($n = $budget; $n < count($due); $n++) {
                $d['tasks'][$due[$n]]['next_run'] = $now + 60;
            }
            if (count($d['runs']) > (int) $d['max_log']) {
                $d['runs'] = array_slice($d['runs'], -((int) $d['max_log']));
            }
            return $d;
        });
        @flock($lh, LOCK_UN);
        fclose($lh);
        $res['at'] = date('Y-m-d H:i:s');
        return $res;
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'executed' => 0];
    }
}

/** 唯一的调度驱动：有请求访问 index.php 时推进一次（静默，任何失败都不影响主请求） */
function automation_auto_tick(Store $store): void {
    try { automation_tick($store, false, null); } catch (Throwable $e) { /* 静默 */ }
}

/** 首屏注入用：与 list 相同，但只带最近 20 条日志，避免 HTML 过大 */
function automation_boot(): array {
    try {
        $l = automation_list();
        $l['runs'] = array_slice($l['runs'], 0, 20);
        return $l;
    } catch (Throwable $e) {
        return ['ok' => false, 'tasks' => [], 'runs' => [], 'stats' => [], 'types' => [], 'error' => $e->getMessage()];
    }
}
