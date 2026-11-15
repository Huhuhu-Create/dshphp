<?php
/**
 * 首次安装向导：生成 config.php（密钥）、app/storage.php（随机数据文件名）、data/ 目录。
 * 只允许在未安装时运行；装完请删除本文件。
 */
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$done = false;
$err = '';
$created = ['password' => '', 'api_key' => '', 'driver' => ''];

if (is_installed() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $err = 'already';
} elseif (is_installed()) {
    $err = 'already';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string) ($_POST['admin_password'] ?? '');
    $key = trim((string) ($_POST['api_key'] ?? ''));
    $route = (string) ($_POST['route'] ?? 'ovh-free');
    $base = trim((string) ($_POST['base_url'] ?? ''));
    $model = trim((string) ($_POST['model'] ?? ''));
    $llmKey = (string) ($_POST['llm_key'] ?? '');
    $auth = (string) ($_POST['auth_mode'] ?? 'bearer');
    $driver = (string) ($_POST['driver'] ?? 'auto');

    if (strlen($pw) < 6) $err = '管理密码至少 6 位';
    elseif ($key === '' || strlen($key) < 8) $err = 'API Key 至少 8 位';
    elseif ($base === '' || $model === '') $err = '请填写 API Base URL 与模型 ID';
    elseif (!preg_match('~^https?://~i', $base)) $err = 'API Base URL 必须以 http:// 或 https:// 开头';
    elseif (!preg_match('/^[A-Za-z0-9_.\/:-]+$/', $model)) $err = '模型 ID 含非法字符';
    elseif (!is_dir(DSH_DATA_DIR) && !@mkdir(DSH_DATA_DIR, 0775, true)) $err = '无法创建 data/ 目录，请手动建好并设为可写';
    elseif (!is_writable(DSH_DATA_DIR)) $err = 'data/ 目录不可写，请把权限设为 PHP 运行用户可写';
    else {
        $sqliteOk = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
        $useDriver = ($driver === 'sqlite' && $sqliteOk) ? 'sqlite' : 'json';
        if ($driver === 'sqlite' && !$sqliteOk) $useDriver = 'json';
        $dirName = 's_' . rand_hex(12);
        $fileName = 'vq_' . rand_hex(20) . ($useDriver === 'sqlite' ? '.sqlite' : '.json');
        $sub = DSH_DATA_DIR . '/' . $dirName;
        if (!is_dir($sub) && !@mkdir($sub, 0775, true)) {
            $err = '无法在 data/ 下创建数据目录';
        } else {
            $c = default_config();
            $c['admin_password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            $c['api_key'] = $key;
            $c['route'] = $route;
            $c['llm']['base_url'] = rtrim($base, '/');
            $c['llm']['model'] = $model;
            $c['llm']['api_key'] = $llmKey;
            $c['llm']['auth_mode'] = in_array($auth, ['bearer', 'bearer-empty', 'x-api-key', 'none'], true) ? $auth : 'bearer';
            $c['llm']['models'] = [$model];
            $c['chat']['system_prompt'] = trim((string) ($_POST['system_prompt'] ?? '')) ?: $c['chat']['system_prompt'];
            $c['installed_at'] = time();
            $st = "<?php\n// 自动生成：数据文件名随机化，切勿公开此文件内容\nreturn " . var_export([
                'driver' => $useDriver, 'dir' => $dirName, 'file' => $fileName,
            ], true) . ";\n";
            $cfgPhp = "<?php\n// dshphp 2.0 配置（.php 会被执行，不会以静态文件形式泄露）\nreturn " . var_export($c, true) . ";\n";
            if (@file_put_contents(DSH_APP . '/storage.php', $st, LOCK_EX) === false) {
                $err = '无法写入 app/storage.php，请检查 app/ 目录权限';
            } elseif (@file_put_contents(DSH_ROOT . '/config.php', $cfgPhp, LOCK_EX) === false) {
                $err = '无法写入 config.php，请检查项目根目录权限';
            } else {
                try {
                    require_once DSH_APP . '/store.php';
                    new Store(); // 触发建表 / 初始化数据文件
                    $done = true;
                    $created = ['password' => $pw, 'api_key' => $key, 'driver' => $useDriver];
                } catch (Throwable $e) {
                    $err = '数据初始化失败：' . $e->getMessage();
                }
            }
        }
    }
}

function inst_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$presets = preset_routes();
$sqliteOk = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
$dataWritable = is_dir(DSH_DATA_DIR) ? is_writable(DSH_DATA_DIR) : is_writable(DSH_ROOT);
$rootWritable = is_writable(DSH_ROOT);
$appWritable = is_writable(DSH_APP);
$autoKey = bin2hex(random_bytes(12));
?><!doctype html>
<html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>dshphp · 安装</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.install-wrap{max-width:720px;margin:36px auto;padding:0 16px 60px}
.install-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:28px;box-shadow:var(--shadow)}
.install-card h1{font-size:21px;margin:0 0 4px}.install-card h2{font-size:14px;margin:26px 0 12px;color:var(--accent2);border-bottom:1px solid var(--line);padding-bottom:7px}
.install-card label{display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--muted);margin-bottom:13px}
.install-card input,.install-card select,.install-card textarea{background:var(--bg);border:1px solid var(--line2);border-radius:7px;padding:9px 11px;outline:none;font-size:13px;width:100%}
.install-card input:focus,.install-card select:focus{border-color:var(--accent)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.checks{display:grid;grid-template-columns:1fr 1fr;gap:6px 18px;font-size:12px;margin:0 0 10px}
.checks div{display:flex;justify-content:space-between;border-bottom:1px dashed var(--line);padding:4px 0}
.ok{color:var(--green)}.bad{color:var(--red)}.warn{color:var(--amber)}
.result-box{background:rgba(62,207,142,.08);border:1px solid rgba(62,207,142,.35);border-radius:8px;padding:14px;margin:14px 0;font-size:13px}
.result-box code{font-size:13px;user-select:all}
</style>
</head><body>
<div class="install-wrap"><div class="install-card">
  <h1>dshphp <?php echo inst_h(DSHPHP_VERSION); ?> · 安装向导</h1>
  <p class="muted tiny">独立运行的 DSH 对话服务：对话 + 插件。纯 PHP，零依赖，装完请删除本文件。</p>

<?php if ($done): ?>
  <div class="result-box"><strong class="ok">✓ 安装完成</strong><br>
    管理密码：<code><?php echo inst_h($created['password']); ?></code><br>
    API Key：<code><?php echo inst_h($created['api_key']); ?></code><br>
    存储后端：<?php echo inst_h($created['driver']); ?><br>
    自动故障转移：<strong class="ok">已开启</strong>（429 限流自动切换备用线路）<br>
    <span class="muted tiny">以上凭据只显示这一次，请现在记下来。页面不会打印数据文件的真实名称。</span>
  </div>
  <p>下一步：</p>
  <ol class="muted">
    <li>访问 <a href="ping.php">ping.php</a> 确认环境，再访问 <a href="index.php">index.php</a> 登录。</li>
    <li>在「设置 → 模型」点一次「测试连接」，确认模型路由可用。</li>
    <li>用 FTP <strong>删除 install.php</strong>，并把 config.php 设为只读。</li>
  </ol>
  <a class="btn primary" href="index.php">进入控制台</a>

<?php elseif ($err === 'already'): ?>
  <div class="result-box"><strong>已经安装过了。</strong><br>
  <span class="muted tiny">如需重装：用 FTP 删除 <code>config.php</code>、<code>app/storage.php</code> 与 <code>data/</code> 下的内容，再访问本页。删除前请自行备份对话数据。</span></div>
  <a class="btn primary" href="index.php">返回控制台</a>

<?php else: ?>
  <?php if ($err !== ''): ?><p class="err"><?php echo inst_h($err); ?></p><?php endif; ?>

  <h2>环境检查</h2>
  <div class="checks">
    <div><span>PHP 版本</span><span class="<?php echo PHP_VERSION_ID >= 70400 ? 'ok' : 'bad'; ?>"><?php echo inst_h(PHP_VERSION); ?></span></div>
    <div><span>data/ 可写</span><span class="<?php echo $dataWritable ? 'ok' : 'bad'; ?>"><?php echo $dataWritable ? '是' : '否（需处理）'; ?></span></div>
    <div><span>根目录可写</span><span class="<?php echo $rootWritable ? 'ok' : 'bad'; ?>"><?php echo $rootWritable ? '是（可生成 config.php）' : '否'; ?></span></div>
    <div><span>app/ 可写</span><span class="<?php echo $appWritable ? 'ok' : 'bad'; ?>"><?php echo $appWritable ? '是（可生成 storage.php）' : '否'; ?></span></div>
    <div><span>cURL</span><span class="<?php echo function_exists('curl_init') ? 'ok' : 'warn'; ?>"><?php echo function_exists('curl_init') ? '可用' : '无（自动降级 stream）'; ?></span></div>
    <div><span>OpenSSL / HTTPS</span><span class="<?php echo extension_loaded('openssl') ? 'ok' : 'warn'; ?>"><?php echo extension_loaded('openssl') ? '可用' : '无（https 上游可能连不上）'; ?></span></div>
    <div><span>pdo_sqlite</span><span class="<?php echo $sqliteOk ? 'ok' : 'warn'; ?>"><?php echo $sqliteOk ? '可用' : '无（自动用 JSON 文件存储）'; ?></span></div>
    <div><span>mbstring</span><span class="<?php echo extension_loaded('mbstring') ? 'ok' : 'warn'; ?>"><?php echo extension_loaded('mbstring') ? '可用' : '无（已内建 UTF-8 降级）'; ?></span></div>
  </div>
  <p class="muted tiny">缺失的扩展不会导致安装失败：HTTP 有 stream 降级，存储有 JSON 降级，UTF-8 处理有纯 PHP 实现。但 <strong>HTTPS 上游需要 openssl 或 curl</strong>，两个都没有时只能用 http:// 的本机/内网模型端点。</p>

  <form method="post" autocomplete="off">
    <h2>访问控制</h2>
    <div class="grid2">
      <label>管理密码（至少 6 位）<input type="password" name="admin_password" required minlength="6" autocomplete="new-password"></label>
      <label>对外 API Key（至少 8 位）<input type="text" name="api_key" value="<?php echo inst_h($autoKey); ?>" required minlength="8"></label>
    </div>
    <p class="muted tiny">API Key 用于 <code>X-API-Key</code> 或 <code>Authorization: Bearer</code> 调用 OpenAI 兼容接口。请妥善保管，不要写进前端页面。</p>

    <h2>模型路由</h2>
    <label>预设（选中后自动填入下方，可再改）
      <select id="preset" name="route">
        <?php foreach ($presets as $k => $p): ?>
        <option value="<?php echo inst_h($k); ?>" <?php echo $k === 'ovh-free' ? 'selected' : ''; ?>><?php echo inst_h($p['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="grid2">
      <label>API Base URL<input type="text" name="base_url" id="base_url" value="https://oai.endpoints.kepler.ai.cloud.ovh.net/v1" required spellcheck="false"></label>
      <label>模型 ID<input type="text" name="model" id="model" value="gpt-oss-20b" required spellcheck="false"></label>
    </div>
    <div class="grid2">
      <label>鉴权方式
        <select name="auth_mode" id="auth_mode">
          <option value="bearer-empty">Bearer 空值（OVH 免费端点专用）</option>
          <option value="bearer">Bearer Token</option>
          <option value="x-api-key">X-API-Key</option>
          <option value="none">不带鉴权头</option>
        </select>
      </label>
      <label>上游 API Key（可留空）<input type="password" name="llm_key" placeholder="免 Key 路由留空即可" autocomplete="off"></label>
    </div>
    <p class="muted tiny" id="preset_note">OVH / Pollinations 预置路由免注册免 Key。默认已开启「自动故障转移」：主线路被限流（HTTP 429）时自动切到下一条，无需重装或改配置。</p>

    <h2>对话与存储</h2>
    <label>系统提示词（可留空用默认）<textarea name="system_prompt" rows="3" placeholder="你是 DSH，一个运行在服务器上的中文 AI 助手…"></textarea></label>
    <label>存储后端
      <select name="driver">
        <option value="auto">自动（有 pdo_sqlite 用 SQLite，否则 JSON 文件）</option>
        <option value="json">强制 JSON 文件（兼容性最好）</option>
        <option value="sqlite">强制 SQLite<?php echo $sqliteOk ? '' : '（本机不可用，会自动回退）'; ?></option>
      </select>
    </label>
    <p class="muted tiny">数据文件名会随机生成并写进 <code>app/storage.php</code>（.php 文件会被执行而非下载），页面不会显示真实名称。</p>

    <button class="btn primary block" type="submit" style="margin-top:22px;padding:11px">保存配置并初始化</button>
  </form>
  <script>
  (function(){
    var P = <?php echo json_encode($presets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>;
    var sel=document.getElementById('preset');
    sel.addEventListener('change',function(){
      var p=P[this.value]; if(!p||!p.base_url)return;
      document.getElementById('base_url').value=p.base_url;
      document.getElementById('model').value=p.model;
      document.getElementById('auth_mode').value=p.auth_mode;
      document.getElementById('preset_note').textContent=p.note||'';
    });
  })();
  </script>
<?php endif; ?>
</div></div>
</body></html>
