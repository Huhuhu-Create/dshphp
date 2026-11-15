<?php
/**
 * dshphp 2.3 —— 自动化任务的动作执行器
 *
 * 所有内置动作都是纯本地、零外部依赖的（http 类型除外，且默认不作为内置任务）。
 * 统一返回 ['ok'=>bool, 'text'=>string]，text 会写进运行日志。
 * 约定：绝不打印真实数据文件名、绝对路径或任何密钥。
 */
declare(strict_types=1);

function automation_type_label(string $type): string {
    $labels = [
        'log' => '记录打点', 'plugin' => '调用插件', 'health' => '健康检查',
        'digest' => '统计汇总', 'prune' => '日志清理', 'snapshot' => '配置快照',
        'http' => '访问 URL',
    ];
    return $labels[$type] ?? $type;
}

function automation_execute_task(Store $store, array $t, array $config, int $now): array {
    $args = is_array($t['args'] ?? null) ? $t['args'] : [];
    switch ((string) $t['type']) {
        case 'log':      return automation_act_log($now, $args);
        case 'plugin':   return automation_act_plugin($store, $args);
        case 'health':   return automation_act_health($store, $config, $now, $args);
        case 'digest':   return automation_act_digest($config, $now, $args);
        case 'prune':    return automation_act_prune($config);
        case 'snapshot': return automation_act_snapshot($config, $now);
        case 'http':     return automation_act_http($args);
        default:         return ['ok' => false, 'text' => '未知任务类型：' . (string) $t['type']];
    }
}

/** 最简单的打点动作，支持占位符 */
function automation_act_log(int $now, array $args): array {
    $msg = trim((string) ($args['message'] ?? '自动化任务已执行'));
    if ($msg === '') $msg = '自动化任务已执行';
    $msg = str_replace(
        ['{time}', '{date}', '{ts}', '{week}'],
        [date('H:i:s', $now), date('Y-m-d', $now), (string) $now,
            '星期' . ['日', '一', '二', '三', '四', '五', '六'][(int) date('w', $now)]],
        $msg
    );
    return ['ok' => true, 'text' => str_cut($msg, 300)];
}

/** 调用任意已安装插件的工具；不需要模型、不消耗额度 */
function automation_act_plugin(Store $store, array $args): array {
    $id = trim((string) ($args['plugin'] ?? ''));
    $tool = trim((string) ($args['tool'] ?? ''));
    if ($id === '') return ['ok' => false, 'text' => '缺少 plugin 参数'];
    $inner = is_array($args['args'] ?? null) ? $args['args'] : [];
    if ($tool === '') {
        foreach (plugin_scan() as $p) {
            if ($p['id'] !== $id) continue;
            $tool = (string) ($p['tools'][0]['name'] ?? '');
            break;
        }
    }
    if ($tool === '') return ['ok' => false, 'text' => '插件 ' . $id . ' 没有可用工具'];
    $r = plugin_invoke($store, $id, $tool, $inner, ['session_id' => '', 'automation' => true]);
    $text = trim((string) ($r['text'] ?? ''));
    if ($text === '') $text = $r['ok'] ? '插件执行完成（无输出）' : '插件未返回内容';
    return ['ok' => (bool) $r['ok'], 'text' => str_cut(($r['ok'] ? '' : '失败：') . $text, 600)];
}

/** 环境 / 存储健康检查：只报状态，不报路径 */
function automation_act_health(Store $store, array $config, int $now, array $args): array {
    $st = $store->stats();
    $writable = (bool) ($st['writable'] ?? false) && automation_ensure_dir() && is_writable(automation_info()['dir']);
    $enabled = 0;
    foreach ($config['tasks'] as $x) if (!empty($x['enabled'])) $enabled++;
    $lines = [
        '存储后端 ' . (string) ($st['driver'] ?? '?') . '，' . (int) ($st['sessions'] ?? 0) . ' 个会话 / ' . (int) ($st['messages'] ?? 0) . ' 条消息',
        '数据目录可写：' . ($writable ? '是' : '否'),
        '任务 ' . count($config['tasks']) . ' 个（启用 ' . $enabled . '），累计推进 ' . (int) ($config['meta']['ticks'] ?? 0) . ' 次',
        'PHP ' . PHP_VERSION . ' · dshphp ' . DSHPHP_VERSION . ' · ' . date('Y-m-d H:i:s', $now),
    ];
    if (!empty($args['detail'])) {
        $lines[] = 'HTTP 通道 ' . (function_exists('curl_init') ? 'cURL' : 'stream') . ' · 时区 ' . date_default_timezone_get();
        $lines[] = '运行日志 ' . count($config['runs']) . ' 条 / 上限 ' . (int) ($config['max_log'] ?? 0) . ' 条';
    }
    return ['ok' => $writable, 'text' => implode("\n", $lines)];
}

/** 按范围统计运行日志，输出每个任务的成功 / 失败次数 */
function automation_act_digest(array $config, int $now, array $args): array {
    $scope = (string) ($args['scope'] ?? 'today');
    $from = $scope === 'week' ? strtotime('-7 days', $now) : strtotime('today 00:00:00', $now);
    $label = $scope === 'week' ? '最近 7 天' : '今日';
    $stat = [];
    $total = 0; $fail = 0;
    foreach (($config['runs'] ?? []) as $r) {
        if (!is_array($r) || (int) ($r['at'] ?? 0) < (int) $from) continue;
        $id = (string) ($r['task'] ?? '?');
        if (!isset($stat[$id])) $stat[$id] = ['name' => (string) ($r['name'] ?? $id), 'ok' => 0, 'fail' => 0];
        $total++;
        if (!empty($r['ok'])) $stat[$id]['ok']++; else { $stat[$id]['fail']++; $fail++; }
    }
    $lines = [$label . '自动化任务共执行 ' . $total . ' 次，成功 ' . ($total - $fail) . ' 次，失败 ' . $fail . ' 次'];
    foreach ($stat as $s) $lines[] = '· ' . $s['name'] . '：成功 ' . $s['ok'] . ' / 失败 ' . $s['fail'];
    if (!$stat) $lines[] = '（该时间范围内暂无执行记录）';
    return ['ok' => true, 'text' => str_cut(implode("\n", $lines), 600)];
}

/** 日志压缩由 tick 统一做，这里输出可核对的结果 */
function automation_act_prune(array $config): array {
    $max = (int) ($config['max_log'] ?? AUTOMATION_LOG_LIMIT);
    $now = count($config['runs'] ?? []);
    return ['ok' => true, 'text' => '运行日志当前 ' . $now . ' 条，调度器保留上限 ' . $max . ' 条；超出部分会在每次推进末尾自动裁掉'];
}

/** 把任务表结构写进日志，方便回溯「谁在什么时候改过任务」 */
function automation_act_snapshot(array $config, int $now): array {
    $lines = ['配置快照 · ' . date('Y-m-d H:i:s', $now) . ' · 共 ' . count($config['tasks']) . ' 个任务'];
    foreach ($config['tasks'] as $t) {
        $lines[] = ($t['enabled'] ? '[开] ' : '[关] ') . $t['id'] . ' · ' . $t['type'] . ' · ' . automation_schedule_label($t['schedule']);
    }
    return ['ok' => true, 'text' => str_cut(implode("\n", $lines), 600)];
}

/** 访问公开 URL（webhook / 自定义回调），带与 core.http 一致的 SSRF 防护 */
function automation_act_http(array $args): array {
    $url = trim((string) ($args['url'] ?? ''));
    if ($url === '') return ['ok' => false, 'text' => '缺少 url 参数'];
    if (!preg_match('~^https?://~i', $url)) return ['ok' => false, 'text' => '只允许 http/https'];
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host'])) return ['ok' => false, 'text' => 'URL 无法解析'];
    $host = strtolower((string) $p['host']);
    $port = (int) ($p['port'] ?? (stripos($url, 'https:') === 0 ? 443 : 80));
    if (!in_array($port, [80, 443, 8080, 8443], true)) return ['ok' => false, 'text' => '端口不允许：' . $port];
    $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]', '169.254.169.254'];
    if (in_array($host, $blocked, true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return ['ok' => false, 'text' => '禁止访问本机 / 内网地址'];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['ok' => false, 'text' => '禁止访问私有 / 保留 IP'];
        }
    } else {
        $ip = @gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)
            && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['ok' => false, 'text' => '域名解析到内网地址，已拒绝'];
        }
    }
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) $method = 'GET';
    $opt = ['method' => $method, 'timeout' => 8, 'headers' => ['User-Agent: dshphp-automation/' . DSHPHP_VERSION, 'Accept: */*']];
    if ($method === 'POST') $opt['body'] = (string) ($args['body'] ?? '');
    $r = http_request($url, $opt);
    if (!$r['ok'] && (int) $r['status'] === 0) return ['ok' => false, 'text' => '请求失败：' . $r['error']];
    return ['ok' => (bool) $r['ok'], 'text' => str_cut('HTTP ' . (int) $r['status'] . ' · ' . $host . ' · 响应 ' . strlen((string) $r['body']) . ' 字节', 300)];
}
