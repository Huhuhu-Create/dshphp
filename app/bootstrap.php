<?php
/**
 * dshphp 2.0 —— 独立运行的 DSH 网页服务端（纯 PHP）
 *
 * 功能边界：对话 + 插件。不依赖 Node / Electron / QQ / SnowLuma。
 * 部署约定（适配无重写规则的 Nginx + PHP-FPM 主机）：
 *   · 所有请求走真实文件 index.php，用 ?r=xxx 查询参数路由
 *   · 页面一律 .php 后缀；不依赖默认文档、不依赖 rewrite
 *   · PHP 8.4：参数显式可空；display_errors 关闭，避免泄露绝对路径
 *   · config.php / storage.php 都是 .php，会被执行而非下载
 *   · 数据文件名随机化，公开页面绝不显示真实文件名
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

define('DSH_ROOT', dirname(__DIR__));
define('DSH_APP', DSH_ROOT . '/app');
define('DSH_PLUGIN_DIR', DSH_ROOT . '/plugins');
define('DSH_MARKET_DIR', DSH_ROOT . '/market');
define('DSH_SKILL_DIR', DSH_ROOT . '/skills');
define('DSH_DATA_DIR', DSH_ROOT . '/data');
define('DSHPHP_VERSION', '2.4.3');

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool { return $needle === '' || strpos($haystack, $needle) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool { return $needle === '' || substr($haystack, -strlen($needle)) === $needle; }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool { return $needle === '' || strpos($haystack, $needle) !== false; }
}

/** UTF-8 安全截断（按字符数），不依赖 mbstring；绝不切出半个汉字 */
function str_cut(string $s, int $len): string {
    if ($len <= 0) return '';
    if (function_exists('mb_substr')) return mb_substr($s, 0, $len, 'UTF-8');
    $n = strlen($s);
    $out = '';
    $count = 0;
    for ($i = 0; $i < $n;) {
        $c = ord($s[$i]);
        if ($c < 0x80) $cl = 1;
        elseif ($c >= 0xC2 && $c <= 0xDF) $cl = 2;
        elseif ($c >= 0xE0 && $c <= 0xEF) $cl = 3;
        elseif ($c >= 0xF0 && $c <= 0xF4) $cl = 4;
        else { $i++; continue; } // 孤立续字节，跳过
        if ($i + $cl > $n) break;
        for ($k = 1; $k < $cl; $k++) {
            if ((ord($s[$i + $k]) & 0xC0) !== 0x80) { $cl = 1; break; } // 非法序列，按单字节跳过
        }
        if ($count >= $len) break;
        $out .= substr($s, $i, $cl);
        $i += $cl;
        $count++;
    }
    return $out;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rand_hex(int $bytes = 24): string { return bin2hex(random_bytes($bytes)); }
function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
    $s = bin2hex($b);
    return substr($s, 0, 8) . '-' . substr($s, 8, 4) . '-' . substr($s, 12, 4) . '-' . substr($s, 16, 4) . '-' . substr($s, 20);
}
function now_ms(): int { return (int) round(microtime(true) * 1000); }

/**
 * 列出目录下的某扩展名文件（不依赖 glob，兼容 open_basedir / glob 被禁用的主机）。
 * 返回「真实路径」数组（已排序）；目录不存在或读不到返回 []。
 */
function dsh_glob_ext(string $dir, string $ext): array {
    if ($dir === '' || !is_dir($dir) || !is_readable($dir)) return [];
    $entries = @scandir($dir);
    if (!is_array($entries)) return [];
    $out = [];
    $suf = '.' . ltrim($ext, '.');
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        if ($e[0] === '.') continue;
        if (substr($e, -strlen($suf)) !== $suf) continue;
        $p = $dir . DIRECTORY_SEPARATOR . $e;
        if (is_file($p)) $out[] = $p;
    }
    sort($out);
    return $out;
}

function dsh_glob_php(string $dir): array { return dsh_glob_ext($dir, 'php'); }
function dsh_glob_md(string $dir): array { return dsh_glob_ext($dir, 'md'); }

/**
 * 安全加载插件/市场文件：首次 require 捕获其 return 的数组并缓存，
 * 之后即使被再次 require（返回 1）也能复用缓存，避免「已安装的市场插件失效」。
 */
function plugin_load_file(string $file): ?array {
    static $cache = [];
    $real = realpath($file);
    $key = $real !== false ? $real : $file;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $v = require $file; // 首次包含返回文件里的 array
    $arr = is_array($v) ? $v : null;
    $cache[$key] = $arr;
    return $arr;
}

function safe_eq(?string $a, ?string $b): bool {
    if (!is_string($a) || !is_string($b) || $a === '' || strlen($a) !== strlen($b)) return false;
    return hash_equals($a, $b);
}

function json_out($data, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        // 兼容表单提交
        return is_array($_POST) ? $_POST : [];
    }
    $v = json_decode($raw, true);
    return is_array($v) ? $v : (is_array($_POST) ? $_POST : []);
}

function req_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) return (string) $_SERVER[$key];
    if (strtolower($name) === 'content-type' && isset($_SERVER['CONTENT_TYPE'])) return (string) $_SERVER['CONTENT_TYPE'];
    return '';
}

/** 预置模型路由：老板 ~/.dsh/FREE-API-README.md 里实测过免 Key 的两条 + 常见 OpenAI 兼容端点 */
function preset_routes(): array {
    return [
        'ovh-free' => [
            'name' => 'OVHcloud 免费（免 Key）',
            'base_url' => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1',
            'model' => 'gpt-oss-20b',
            'api_key' => '',
            'auth_mode' => 'bearer-empty',
            'keyless' => true,
            'note' => '免注册免 Key，按 IP 限流；被限流时会自动切到其它免 Key 线路。Authorization 只能是 "Bearer"',
        ],
        'pollinations-free' => [
            'name' => 'Pollinations 免费（免 Key）',
            'base_url' => 'https://text.pollinations.ai/openai',
            'model' => 'openai-fast',
            'api_key' => 'free',
            'auth_mode' => 'bearer',
            'keyless' => true,
            'note' => '匿名可用，额度较紧；与 OVH 不同厂商，是首选备用线路',
        ],
        'pollinations-openai' => [
            'name' => 'Pollinations 备用模型（免 Key）',
            'base_url' => 'https://text.pollinations.ai/openai',
            'model' => 'openai',
            'api_key' => 'free',
            'auth_mode' => 'bearer',
            'keyless' => true,
            'note' => '同端点不同模型，独立额度池；作为第三顺位备用',
        ],
        'deepseek' => [
            'name' => 'DeepSeek 官方',
            'base_url' => 'https://api.deepseek.com/v1',
            'model' => 'deepseek-chat',
            'api_key' => '',
            'auth_mode' => 'bearer',
            'keyless' => false,
            'note' => '需要在平台申请 API Key',
        ],
        'openrouter' => [
            'name' => 'OpenRouter',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'openai/gpt-oss-20b:free',
            'api_key' => '',
            'auth_mode' => 'bearer',
            'keyless' => false,
            'note' => ':free 后缀模型免费，需要 Key',
        ],
        'ollama' => [
            'name' => '本机 Ollama',
            'base_url' => 'http://127.0.0.1:11434/v1',
            'model' => 'qwen2.5:7b',
            'api_key' => 'ollama',
            'auth_mode' => 'bearer',
            'keyless' => false,
            'note' => '适合自建服务器本机跑模型',
        ],
        'custom' => [
            'name' => '自定义 OpenAI 兼容端点',
            'base_url' => '',
            'model' => '',
            'api_key' => '',
            'auth_mode' => 'bearer',
            'keyless' => false,
            'note' => '任意 /v1/chat/completions 兼容服务',
        ],
    ];
}

/**
 * 内置系统提示词预设（2.4.2）。
 * 供设置页「系统提示词」一键切换，以及顶栏「角色」下拉为单个会话指定角色。
 * 每个预设 = [ 'id' => string, 'name' => string, 'prompt' => string ]。
 * 这些是只读的内置模板：用户修改后只写 chat.system_prompt（与预设解耦），不会被覆盖。
 * 单条 prompt 控制在 200 字以内，避免拖慢免费模型。
 */
function system_presets(): array {
    return [
        [
            'id' => 'default', 'name' => '通用助手（默认）',
            'prompt' => '你是 DSH，一个运行在服务器上的中文 AI 助手。回答简洁、准确、有用；不确定时诚实说明，不编造。',
        ],
        [
            'id' => 'coder', 'name' => '编程助手',
            'prompt' => '你是一名资深软件工程师，擅长多种编程语言。回答代码问题时：先给出可直接运行的完整代码，再简要说明关键点；遵守最小改动原则；指出潜在坑与边界情况。',
        ],
        [
            'id' => 'writer', 'name' => '中文文案写手',
            'prompt' => '你是一名中文文案写手。根据用户意图产出有感染力、口语化、不堆砌形容词的文案；默认给一版精炼版和一版活泼版供挑选；除非要求，不要加解释。',
        ],
        [
            'id' => 'translator', 'name' => '中英翻译专家',
            'prompt' => '你是一名中英互译专家。翻译时准确传达原意与语气，专有名词保留原文（首次附中文）；只输出译文与必要的一行说明，不要额外发挥。',
        ],
        [
            'id' => 'analyst', 'name' => '严谨分析顾问',
            'prompt' => '你是一名严谨的分析顾问。面对问题先拆解维度、列出假设与证据，再给结论与风险；明确区分事实与观点；对不确定项显式标注。',
        ],
        [
            'id' => 'teacher', 'name' => '耐心讲解老师',
            'prompt' => '你是一名耐心的老师，善用比喻与循序渐进的方式讲解。先给直观理解，再补细节；遇到陌生概念主动定义；鼓励追问。',
        ],
        [
            'id' => 'creative', 'name' => '创意脑暴伙伴',
            'prompt' => '你是一名创意脑暴伙伴。面对开放问题先发散给出 8–10 个不重样的点子（标序号），再挑 2 个展开；鼓励非常规想法，但标注可行性与成本。',
        ],
    ];
}

function default_config(): array {
    return [
        'admin_password_hash' => '',
        // 2.4 多凭据：label => ['type'=>'password|gesture','hash'=>bcrypt,'created'=>int]
        // 留空时 auth_verify() 只认 admin_password_hash，行为与 2.3.x 完全一致
        'credentials' => [],
        'api_key' => '',
        // 2.4 插件 GUI API 总开关：改成 false 可一键停用整套 UI 扩展点（工具插件不受影响）
        'ui' => ['enabled' => true, 'allow_login_scope' => true],
        'route' => 'ovh-free',
        'llm' => [
            'base_url' => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1',
            'model' => 'gpt-oss-20b',
            'api_key' => '',
            'auth_mode' => 'bearer-empty',
            'timeout' => 90,
            'extra_headers' => [],
            'models' => [],
        ],
        'chat' => [
            'system_prompt' => '你是 DSH，一个运行在服务器上的中文 AI 助手。回答简洁、准确、有用。需要时用工具完成任务。',
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'history_limit' => 20,
            'max_tool_rounds' => 4,
            'tools_enabled' => true,
            'stream_default' => false,
            'system_presets' => system_presets(),
        ],
        'security' => [
            'allow_plugins' => true,
            'allow_custom_base_url' => true,
            'requests_per_minute' => 30,
        ],
        // 2.1：免费端点常按 IP 限流（HTTP 429），自动换线路 + 冷却
        'routing' => [
            'mode' => 'auto',          // auto = 自动故障转移；fixed = 只用主路由
            'cooldown' => 120,         // 失败线路冷却秒数（期间不再尝试）
            'max_switches' => 3,       // 单次请求最多额外尝试几条线路
            'local_fallback' => true,  // 全部线路失败时用本地插件回答确定性问题
            'candidates' => [],        // 空 = 自动使用所有 keyless 预设
        ],
        'installed_at' => 0,
    ];
}

function is_installed(): bool { return is_file(DSH_ROOT . '/config.php'); }

function cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $d = default_config();
    if (!is_installed()) {
        if (str_starts_with((string) ($_GET['r'] ?? ''), 'api/') || str_starts_with((string) ($_GET['r'] ?? ''), 'v1/')) {
            json_out(['ok' => false, 'error' => '尚未安装，请先访问 install.php'], 503);
        }
        header('Location: install.php');
        exit;
    }
    $u = require DSH_ROOT . '/config.php';
    if (!is_array($u)) $u = [];
    foreach ($d as $k => $v) {
        if (!array_key_exists($k, $u)) $u[$k] = $v;
        elseif (is_array($v) && is_array($u[$k])) $u[$k] = array_merge($v, $u[$k]);
    }
    $c = $u;
    return $c;
}

/** 数据文件随机名：写在 app/storage.php（.php 会被执行，外部读不到内容） */
function storage_info(): array {
    $f = DSH_APP . '/storage.php';
    if (is_file($f)) {
        $v = require $f;
        if (is_array($v) && !empty($v['file'])) return $v;
    }
    return ['driver' => 'json', 'dir' => '', 'file' => 'dshphp-fallback.json'];
}

function storage_path(): string {
    $i = storage_info();
    $dir = DSH_DATA_DIR . ($i['dir'] !== '' ? '/' . $i['dir'] : '');
    return $dir . '/' . $i['file'];
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('DSHPHP2');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '', 'domain' => '',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    @session_start();
}

function auth_check(): bool { return !empty($_SESSION['dshphp_ok']); }

/**
 * 2.4 附加凭据：label => ['type'=>'password|gesture','hash'=>bcrypt,'created'=>int]
 * 主密码仍是 admin_password_hash；credentials 为空时行为与 2.3.x 完全一致。
 */
function auth_credentials(): array {
    $v = cfg()['credentials'] ?? [];
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $label => $c) {
        if (!is_array($c)) continue;
        $hash = (string) ($c['hash'] ?? '');
        if ($hash === '') continue;
        $type = (string) ($c['type'] ?? 'password');
        $out[(string) $label] = [
            'type' => in_array($type, ['password', 'gesture'], true) ? $type : 'password',
            'hash' => $hash,
            'created' => (int) ($c['created'] ?? 0),
        ];
        if (count($out) >= 8) break;
    }
    return $out;
}

/** 给前端的凭据列表（绝不下发 hash） */
function auth_credentials_public(): array {
    $out = [];
    foreach (auth_credentials() as $label => $c) {
        $out[] = ['label' => $label, 'type' => $c['type'], 'created' => $c['created']];
    }
    return $out;
}

function auth_verify(string $password): bool {
    $until = (int) ($_SESSION['until'] ?? 0);
    if ($until > time()) return false;
    $hashes = [];
    $main = (string) cfg()['admin_password_hash'];
    if ($main !== '') $hashes[] = $main;
    foreach (auth_credentials() as $c) $hashes[] = $c['hash'];
    if (!$hashes) return false;

    $ok = false;
    foreach ($hashes as $h) {
        if (password_verify($password, $h)) { $ok = true; break; }
    }
    if (!$ok) {
        $fail = (int) ($_SESSION['fail'] ?? 0) + 1;
        $_SESSION['fail'] = $fail;
        if ($fail >= 8) { $_SESSION['until'] = time() + 300; $_SESSION['fail'] = 0; }
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['dshphp_ok'] = true;
    $_SESSION['fail'] = 0;
    $_SESSION['until'] = 0;
    return true;
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, (string) ($p['path'] ?? '/'), (string) ($p['domain'] ?? ''), (bool) ($p['secure'] ?? false), true);
    }
    @session_destroy();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = rand_hex(24);
    return (string) $_SESSION['csrf'];
}

function csrf_check(): bool {
    $t = req_header('x-csrf-token');
    if ($t === '') $t = (string) ($_POST['csrf'] ?? '');
    return safe_eq($t, (string) ($_SESSION['csrf'] ?? ''));
}

/** 浏览器：会话 + CSRF；外部程序：X-API-Key 或 Bearer（OpenAI 兼容接口用后者） */
function api_guard(bool $write = true): void {
    $c = cfg();
    $key = req_header('x-api-key');
    if ($key === '') {
        $auth = req_header('authorization');
        if (stripos($auth, 'Bearer ') === 0) $key = trim(substr($auth, 7));
    }
    if ($key !== '' && (string) $c['api_key'] !== '' && safe_eq($key, (string) $c['api_key'])) return;
    if (!auth_check()) json_out(['ok' => false, 'error' => '未登录，或 API Key 无效'], 401);
    if ($write && !csrf_check()) json_out(['ok' => false, 'error' => 'CSRF 校验失败，请刷新页面'], 403);
}

/** 简单限流：按 session/key 每分钟计数（存在 session 里，无需额外存储） */
function rate_limit(): void {
    $n = (int) cfg()['security']['requests_per_minute'];
    if ($n <= 0) return;
    $min = (int) floor(time() / 60);
    if (($_SESSION['rl_min'] ?? 0) !== $min) { $_SESSION['rl_min'] = $min; $_SESSION['rl_n'] = 0; }
    $_SESSION['rl_n'] = (int) ($_SESSION['rl_n'] ?? 0) + 1;
    if ($_SESSION['rl_n'] > $n) json_out(['ok' => false, 'error' => '请求过于频繁，请稍后再试'], 429);
}

/**
 * 通用 HTTP 客户端：cURL 优先，无 curl 时降级 stream wrapper。
 * $opt: method, headers(array of "K: V"), body(string|array), timeout(int), on_chunk(callable|null)
 * 返回 ['ok'=>bool,'status'=>int,'headers'=>array,'body'=>string,'error'=>string]
 */
/**
 * 把底层网络错误翻译成一句可读、不含服务器绝对路径的中文原因。
 * 原始错误保留在 http_request 的 error 里，供 ping.php?net=1 排障页使用。
 */
function http_error_brief(string $raw): string {
    $s = trim((string) preg_replace('~^fopen\([^)]*\):\s*~', '', $raw));
    if ($s === '') return '未知网络错误';
    if (stripos($s, 'socket transport') !== false || stripos($s, 'no ssl') !== false
        || (stripos($s, 'openssl') !== false && stripos($s, 'enable') !== false)) {
        return 'PHP 未启用 openssl 扩展，无法建立 https 连接';
    }
    if (stripos($s, 'certificate') !== false || stripos($s, 'issuer') !== false
        || stripos($s, 'SSL_connect') !== false || stripos($s, 'CAfile') !== false) {
        return 'TLS 证书校验失败（服务器缺 CA 根证书）';
    }
    if (stripos($s, 'resolve') !== false || stripos($s, 'getaddrinfo') !== false
        || stripos($s, 'Name or service not known') !== false) {
        return 'DNS 解析失败';
    }
    if (stripos($s, 'timed out') !== false || stripos($s, 'timeout') !== false) {
        return '连接超时（出站网络不通或被阻断）';
    }
    if (stripos($s, 'refused') !== false || stripos($s, 'unreachable') !== false
        || stripos($s, 'Connection reset') !== false) {
        return '连接被拒绝 / 网络不可达';
    }
    if (stripos($s, 'allow_url_fopen') !== false) return 'allow_url_fopen 已关闭';
    if (stripos($s, 'No such file or directory') !== false || stripos($s, 'Failed to open stream') !== false) {
        return '无法建立连接（openssl 未启用或网络不可达）';
    }
    if (stripos($s, '403') !== false || stripos($s, 'Forbidden') !== false) return '上游拒绝访问（鉴权或地区限制）';
    // 兜底：只脱敏 Windows 绝对路径，别把 https:// 里的 "s:/" 误伤成 <path>
    $s = preg_replace('~(?<![A-Za-z0-9])[A-Za-z]:[\\\\/][^\s\'"]+~', '<path>', (string) $s);
    return str_cut(trim((string) preg_replace('~\s+~', ' ', $s)), 100);
}

function http_request(string $url, array $opt = []): array {
    $method = strtoupper((string) ($opt['method'] ?? 'GET'));
    $headers = $opt['headers'] ?? [];
    $body = $opt['body'] ?? null;
    $timeout = (int) ($opt['timeout'] ?? 30);
    $onChunk = $opt['on_chunk'] ?? null;
    if (is_array($body) || is_object($body)) {
        $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers[] = 'Content-Type: application/json';
    }
    if ($body !== null && $body !== '') $headers[] = 'Content-Length: ' . strlen((string) $body);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $respHeaders = [];
        $collected = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $len = strlen($line);
                $p = strpos($line, ':');
                if ($p !== false) $respHeaders[] = trim($line);
                return $len;
            },
        ]);
        // CA 根证书兜底：精简 PHP / 共享主机常常没配 curl.cainfo，导致所有 https
        // 请求报 SSL certificate problem。优先让 libcurl 用系统证书存储
        // （Windows Schannel / macOS Keychain / Linux 系统 CA），否则退到常见 bundle。
        if ((string) ini_get('curl.cainfo') === '' && (string) ini_get('openssl.cafile') === '') {
            if (defined('CURLSSLOPT_NATIVE_CA')) {
                curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            } else {
                foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/usr/local/etc/openssl/cert.pem'] as $ca) {
                    if (is_file($ca)) { curl_setopt($ch, CURLOPT_CAINFO, $ca); break; }
                }
            }
        }

        if ($body !== null && $body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
        if ($onChunk) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $chunk) use (&$collected, $onChunk) {
                $collected .= $chunk;
                $onChunk($chunk);
                return strlen($chunk);
            });
        }
        $out = curl_exec($ch);
        $err = curl_error($ch);
        $errno = (int) curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out === false && $err !== '') {
            $raw = 'cURL #' . $errno . ': ' . $err;
            return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '',
                'error' => $raw, 'brief' => http_error_brief($raw)];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status,
            'headers' => $respHeaders, 'body' => $onChunk ? $collected : (string) $out,
            'error' => '', 'brief' => ''];
    }

    // 降级：PHP stream wrapper（需要 allow_url_fopen；https 需要 openssl）
    // 先主动判断扩展，比 fopen 失败后再猜 error_get_last() 可靠得多
    if (stripos($url, 'https://') === 0 && !extension_loaded('openssl')) {
        $raw = 'Unable to find the socket transport "ssl" - openssl 扩展未启用';
        return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '',
            'error' => $raw, 'brief' => http_error_brief($raw)];
    }
    if (!ini_get('allow_url_fopen')) {
        $raw = 'allow_url_fopen 已关闭，无法使用 stream 通道';
        return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '',
            'error' => $raw, 'brief' => http_error_brief($raw)];
    }
    $headerLines = implode("\r\n", $headers);
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headerLines,
        'content' => $body === null ? null : (string) $body,
        'timeout' => $timeout,
        'follow_location' => 0,
        'ignore_errors' => true,
    ]]);
    $fp = @fopen($url, 'rb', false, $ctx);
    if (!$fp) {
        $last = error_get_last();
        $raw = is_array($last) && !empty($last['message']) ? (string) $last['message'] : '';
        if ($raw === '' && !extension_loaded('openssl')) $raw = 'Unable to find the socket transport "ssl" - openssl 扩展未启用';
        if ($raw === '') $raw = 'allow_url_fopen 不可用或网络不可达';
        return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '',
            'error' => $raw, 'brief' => http_error_brief($raw)];
    }
    $meta = stream_get_meta_data($fp);
    $respHeaders = $meta['wrapper_data'] ?? [];
    if (!is_array($respHeaders)) $respHeaders = [];
    $status = 0;
    foreach ($respHeaders as $line) if (preg_match('~HTTP/\S+\s+(\d{3})~', (string) $line, $m)) $status = (int) $m[1];
    $collected = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) break;
        $collected .= $chunk;
        if ($onChunk) $onChunk($chunk);
    }
    fclose($fp);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status,
        'headers' => $respHeaders, 'body' => $collected, 'error' => '', 'brief' => ''];
}

start_session();
