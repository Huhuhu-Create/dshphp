<?php
/** 页面渲染：登录页 + 主工作台。所有资源都是相对路径，子目录部署可用。 */
declare(strict_types=1);

function render_login(string $err = ''): void {
    $csrf = csrf_token();
    // 登录页也加载 GUI 运行时，但只聚合声明了 login scope 的插件（如手势密码）。
    $ui = function_exists('plugin_ui_boot')
        ? plugin_ui_boot(null, 'login')
        : ['enabled' => false, 'cssUrl' => '', 'jsUrl' => '', 'scope' => 'login', 'plugins' => []];
    $loginBoot = [
        'csrf' => $csrf, 'version' => DSHPHP_VERSION, 'scope' => 'login', 'ui' => $ui,
        'sessions' => [], 'plugins' => [], 'skills' => [], 'settings' => [],
    ];
    $loginJson = json_encode($loginBoot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($loginJson === false || $loginJson === '') $loginJson = '{}';
    ?><!doctype html>
<html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>dshphp · 登录</title>
<link rel="stylesheet" href="assets/style.css"><?php if (!empty($ui['enabled']) && $ui['cssUrl'] !== '') echo "\n" . '<link rel="stylesheet" href="' . h($ui['cssUrl']) . '">'; ?>
<script>window.DSH_BOOT = <?php echo $loginJson; ?>;</script>
<script defer src="assets/dsh-ui.js"></script><?php if (!empty($ui['enabled']) && $ui['jsUrl'] !== '') echo "\n" . '<script defer src="' . h($ui['jsUrl']) . '"></script>'; ?>
</head><body class="login-body">
<form class="login-card" method="post" action="index.php?r=api/login">
  <div class="login-logo">DSH<span>2</span></div>
  <h1>dshphp · 服务器版</h1>
  <p class="muted">独立运行的 DSH 对话服务 · 对话 + 插件</p>
  <?php if ($err !== '') echo '<p class="err">' . h($err) . '</p>'; ?>
  <label for="pw">管理密码</label>
  <input id="pw" name="password" type="password" autocomplete="current-password" autofocus required>
  <div id="dsh-slot-login" class="dsh-slot"></div>
  <button class="btn primary" type="submit">进入控制台</button>
  <p class="muted tiny">忘记密码：用 FTP 删除 config.php，重新访问 install.php。</p>
</form>
<div id="dsh-toasts" class="dsh-toasts"></div>
</body></html><?php
}

function render_page(Store $store): void {
    $c = cfg();
    $ui = function_exists('plugin_ui_boot')
        ? plugin_ui_boot($store, 'app')
        : ['enabled' => false, 'cssUrl' => '', 'jsUrl' => '', 'scope' => 'app', 'plugins' => []];
    $boot = [
        'csrf' => csrf_token(),
        'ui' => $ui,
        'version' => DSHPHP_VERSION,
        'php' => PHP_VERSION,
        'driver' => $store->driver(),
        'sessions' => $store->sessions(),
        'plugins' => plugin_list($store),
        'skills' => skill_list($store),
        'market' => [
            'total' => count(market_scan()),
            'installed' => count(market_installed_ids($store)),
        ],
        'automation' => automation_boot(),
        'settings' => [
            'route' => (string) $c['route'],
            'llm' => [
                'base_url' => (string) $c['llm']['base_url'],
                'model' => (string) $c['llm']['model'],
                'api_key_set' => (string) $c['llm']['api_key'] !== '',
                'auth_mode' => (string) $c['llm']['auth_mode'],
                'timeout' => (int) $c['llm']['timeout'],
                'models' => $c['llm']['models'],
            ],
            'chat' => $c['chat'],
            'security' => $c['security'],
            'routing' => llm_routing(),
            'routes' => llm_routes_public(),
            'presets' => preset_routes(),
        ],
    ];
    $json = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || $json === '') $json = '{}';
    ?><!doctype html>
<html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DSH · 服务器版</title>
<link rel="stylesheet" href="assets/style.css"><?php if (!empty($ui['enabled']) && $ui['cssUrl'] !== '') echo "\n" . '<link rel="stylesheet" href="' . h($ui['cssUrl']) . '">'; ?>
<script>window.DSH_BOOT = <?php echo $json; ?>;</script>
<script defer src="assets/dsh-ui.js"></script>
<script defer src="assets/app.js"></script>
<script defer src="assets/automation.js"></script><?php if (!empty($ui['enabled']) && $ui['jsUrl'] !== '') echo "\n" . '<script defer src="' . h($ui['jsUrl']) . '"></script>'; ?>
</head><body>
<div id="app">
  <aside class="sidebar">
    <div class="brand"><span class="brand-mark">DSH</span><small>服务器版 <?php echo h(DSHPHP_VERSION); ?></small></div>
    <button id="btn-new" class="btn primary block">＋ 新对话</button>

    <div class="side-scroll">
      <div id="dsh-slot-sidebar-top" class="dsh-slot"></div>
      <div class="side-title"><span>对话</span><span id="session-count" class="badge">0</span></div>
      <div id="session-list" class="list"></div>

      <div class="side-title"><span>插件</span><span class="side-title-right"><span id="plugin-count" class="badge">0</span><button id="btn-market" class="side-link" title="打开插件市场">＋ 市场</button></span></div>
      <div id="plugin-list" class="list"></div>

      <div class="side-title"><span>技能</span><span id="skill-count" class="badge">0</span></div>
      <div id="skill-list" class="list"></div>
    </div>

    <div class="side-foot">
      <div id="status-line" class="status-line">连接中…</div>
      <div id="dsh-slot-status" class="dsh-slot"></div>
      <div class="foot-actions">
        <button id="btn-settings" class="btn ghost sm">设置</button>
        <a href="ping.php" target="_blank" class="btn ghost sm">自检</a>
        <a href="index.php?r=logout" class="btn ghost sm">退出</a>
      </div>
      <div id="dsh-slot-sidebar-foot" class="dsh-slot"></div>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button id="btn-menu" class="btn ghost sm menu-btn">☰</button>
        <div>
          <h1 id="chat-title">新对话</h1>
          <div id="chat-sub" class="muted tiny">选择一个对话，或点「＋ 新对话」开始</div>
        </div>
      </div>
      <div class="topbar-right">
        <label class="inline-label">模型
          <select id="model-select" title="本次请求使用的模型"></select>
        </label>
        <label class="inline-label">角色
          <select id="role-select" title="为该对话指定角色（系统提示词）；切换会应用到当前对话与新建对话"></select>
        </label>
        <label class="inline-label" title="允许模型自动调用插件">
          <input type="checkbox" id="tools-toggle" checked> 工具
        </label>
        <label class="inline-label" title="流式输出；部分 PHP 主机会缓冲，失败会自动降级">
          <input type="checkbox" id="stream-toggle"> 流式
        </label>
        <button id="btn-clear" class="btn ghost sm" title="清空当前对话消息">清空</button>
        <div id="dsh-slot-topbar" class="dsh-slot"></div>
      </div>
    </header>

    <div id="messages" class="messages">
      <div class="welcome">
        <div class="welcome-mark">DSH</div>
        <h2>独立运行在服务器上的对话服务</h2>
        <p class="muted">纯 PHP · 零依赖 · 数据留在自己的服务器 · 插件可热插拔</p>
        <div class="welcome-chips">
          <button class="chip" data-prompt="介绍一下你自己，以及你能调用哪些工具">你能做什么？</button>
          <button class="chip" data-prompt="/ping">/ping 自检</button>
          <button class="chip" data-prompt="现在几点了？顺便算一下 (12+3)*4">试试工具调用</button>
        </div>
      </div>
    </div>

    <div class="composer-wrap">
      <div id="tool-hint" class="tool-hint" hidden></div>
      <div class="composer">
        <textarea id="input" rows="1" placeholder="输入消息…  Enter 发送 · Shift+Enter 换行 · /命令 直调插件"></textarea>
        <button id="btn-send" class="btn primary send-btn">发送</button>
        <button id="btn-stop" class="btn danger send-btn" hidden>停止</button>
      </div>
      <div class="composer-foot">
        <span id="composer-status" class="muted tiny"></span>
        <div id="dsh-slot-composer" class="dsh-slot"></div>
        <span class="muted tiny">插件以 PHP 文件形式放在 <code>plugins/</code>，刷新即生效</span>
      </div>
    </div>
  </main>
</div>

<div id="modal" class="modal" hidden>
  <div class="modal-card" id="modal-card">
    <div class="modal-head"><h2>设置</h2><button id="btn-modal-close" class="btn ghost sm">✕</button></div>
    <div class="modal-body">
      <div class="tabs">
        <button class="tab active" data-tab="model">模型</button>
        <button class="tab" data-tab="chat">对话</button>
        <button class="tab" data-tab="routes">线路</button>
        <button class="tab" data-tab="market">插件市场</button>
        <button class="tab" data-tab="automation">自动化</button>
        <button class="tab" data-tab="about">关于</button>
      </div>

      <section class="tab-pane active" data-pane="model">
        <label>预设路由
          <select id="set-preset"></select>
        </label>
        <label>API Base URL
          <input id="set-base" type="text" placeholder="https://api.deepseek.com/v1" spellcheck="false">
        </label>
        <div class="row2">
          <label>模型 ID<input id="set-model" type="text" placeholder="deepseek-chat" spellcheck="false"></label>
          <label>鉴权方式
            <select id="set-auth">
              <option value="bearer">Bearer Token</option>
              <option value="bearer-empty">Bearer（空值，OVH 免费端点）</option>
              <option value="x-api-key">X-API-Key</option>
              <option value="none">不带鉴权头</option>
            </select>
          </label>
        </div>
        <label>API Key <span class="muted tiny">（留空表示不修改；免 Key 路由无需填写）</span>
          <input id="set-key" type="password" placeholder="留空 = 保持原值" autocomplete="off">
        </label>
        <label>超时（秒）<input id="set-timeout" type="number" min="10" max="300"></label>
        <label>可选模型（顶栏下拉里的备选；可逐个添加 / 删除）
          <span class="muted tiny">留空则顶栏只用上方「模型 ID」；填好上方 Base URL 与鉴权方式后，可点下方「获取可用模型」从上游自动拉取</span>
        </label>
        <div id="set-models-list" class="model-rows"></div>
        <div class="form-actions">
          <button id="btn-add-model" class="btn ghost sm" type="button">＋ 添加模型</button>
          <button id="btn-fetch-models" class="btn ghost sm" type="button">获取可用模型</button>
          <span id="fetch-models-result" class="muted tiny"></span>
        </div>
        <div class="form-actions">
          <button id="btn-test" class="btn ghost">测试连接</button>
          <span id="test-result" class="muted tiny"></span>
        </div>
        <p class="muted tiny">上游只需兼容 OpenAI <code>/chat/completions</code>。OVH / Pollinations 两条预置路由免注册免 Key，可直接用；被限流时换一条或填自己的 Key。</p>
      </section>

      <section class="tab-pane" data-pane="chat">
        <label>系统提示词预设
          <select id="set-syspreset"></select>
        </label>
        <label>系统提示词<textarea id="set-system" rows="5" placeholder="留空 = 使用上面的预设或站点默认值"></textarea></label>
        <div class="row2">
          <label>温度 <input id="set-temp" type="number" step="0.1" min="0" max="2"></label>
          <label>最大 Token <input id="set-maxtok" type="number" min="64" max="32000"></label>
        </div>
        <div class="row2">
          <label>携带历史条数 <input id="set-history" type="number" min="2" max="100"></label>
          <label>工具最大轮数 <input id="set-rounds" type="number" min="1" max="8"></label>
        </div>
        <label class="check"><input type="checkbox" id="set-plugins"> 允许模型自动调用插件（function calling）</label>
        <label class="check"><input type="checkbox" id="set-stream"> 默认使用流式输出</label>
        <label class="check"><input type="checkbox" id="set-textmode"> 强制文本工具协议（模型不支持 tools 时勾选）</label>
        <p class="muted tiny">上游返回 400 且提示不支持 tools / temperature 时，系统会自动去掉这些参数重试，通常不必手动开启。</p>
      </section>

      <section class="tab-pane" data-pane="routes">
        <p class="muted tiny">免费模型常按 IP 限流（HTTP 429）。开启自动故障转移后，主线路被限流会自动切换备用线路，并在冷却期内跳过故障线路。</p>
        <label class="check"><input type="checkbox" id="set-routing-auto"> 自动故障转移（关闭则只用主路由）</label>
        <label class="check"><input type="checkbox" id="set-local-fallback"> 全部线路失败时，用本地插件回答算式 / 时间类问题</label>
        <div class="row2">
          <label>故障线路冷却（秒）<input id="set-cooldown" type="number" min="0" max="3600"></label>
          <label>单次最多切换次数<input id="set-switches" type="number" min="0" max="6"></label>
        </div>
        <div class="side-title"><span>候选线路（按顺序尝试）</span></div>
        <div id="route-list" class="route-list"></div>
        <div class="form-actions">
          <button id="btn-routes-test" class="btn ghost">测试全部线路</button>
          <button id="btn-routes-reset" class="btn ghost">清除冷却</button>
          <span id="route-test-result" class="muted tiny route-results"></span>
        </div>
        <p class="muted tiny">主路由在「模型」页设置；这里只管备用线路。停用某条可避免每次都去撞它。测试会向每条线路发一句「只回复 OK」，只消耗极少量免费额度。</p>
      </section>

      <section class="tab-pane" data-pane="market">
        <div class="market-top">
          <input id="market-search" type="search" placeholder="搜索插件名、说明、标签或 /命令…" spellcheck="false">
          <span id="market-stats" class="muted tiny"></span>
        </div>
        <div id="market-cats" class="market-cats"></div>
        <div class="market-actions">
          <button id="btn-mkt-recommend" class="btn primary sm">安装推荐套装</button>
          <button id="btn-mkt-all" class="btn sm">全部安装</button>
          <button id="btn-mkt-none" class="btn ghost sm">全部卸载</button>
          <button id="btn-mkt-refresh" class="btn ghost sm">刷新</button>
          <span id="market-result" class="muted tiny"></span>
        </div>
        <div id="market-grid" class="market-grid"></div>
        <p class="muted tiny">安装即时生效，模型可自动调用；安装状态存在数据库，<strong>不需要 plugins/ 写权限</strong>。工具过多会拖慢免费模型，建议只装常用插件，或一键回到推荐套装。带「存储」标记的插件会把数据留在服务器。</p>
      </section>

      <section class="tab-pane" data-pane="automation">
        <div class="auto-bar">
          <div id="auto-stats" class="auto-stats">载入中…</div>
          <div class="auto-bar-actions">
            <button id="btn-auto-tick" class="btn sm" title="立即推进一次调度，只执行已到期的任务">立即推进</button>
            <button id="btn-auto-power" class="btn sm">暂停调度</button>
            <button id="btn-auto-refresh" class="btn ghost sm">刷新</button>
          </div>
        </div>

        <div class="auto-driver">
          <strong>运行方式：仅访问时执行</strong>
          <span>有请求访问 <code>index.php</code> 时推进一次调度，到期的任务才会执行；
          页面关闭、无人访问时不执行。<b>没有保活进程、没有前端定时心跳、没有外部 cron。</b></span>
          <p class="muted tiny" id="auto-hint"></p>
        </div>

        <div class="side-title"><span>已上线任务</span><span id="auto-count" class="badge">0</span></div>
        <div id="auto-grid" class="auto-grid"></div>

        <details class="auto-editor" id="auto-editor">
          <summary>＋ 新增 / 编辑任务</summary>
          <div class="auto-form">
            <div class="row2">
              <label>任务 ID <input id="auto-f-id" type="text" placeholder="auto.mytask" spellcheck="false"></label>
              <label>名称 <input id="auto-f-name" type="text" placeholder="我的定时任务"></label>
            </div>
            <div class="row2">
              <label>动作类型 <select id="auto-f-type"></select></label>
              <label>调度模式
                <select id="auto-f-mode">
                  <option value="interval">固定间隔（秒）</option>
                  <option value="cron">Cron 表达式</option>
                </select>
              </label>
            </div>
            <div class="row2">
              <label>间隔秒数（最小 30）<input id="auto-f-every" type="number" min="30" value="300"></label>
              <label>Cron 表达式（分 时 日 月 周）<input id="auto-f-expr" type="text" placeholder="0 9 * * *" spellcheck="false"></label>
            </div>
            <label>备注 <input id="auto-f-note" type="text" placeholder="这个任务做什么"></label>
            <label>动作参数（JSON）<textarea id="auto-f-args" rows="3" spellcheck="false" placeholder='{"message":"打点 {time}"}'></textarea></label>
            <p class="muted tiny" id="auto-args-hint"></p>
            <label class="check"><input type="checkbox" id="auto-f-enabled" checked> 启用</label>
            <div class="form-actions">
              <button id="btn-auto-save" class="btn primary sm">保存任务</button>
              <button id="btn-auto-reset" class="btn ghost sm">清空表单</button>
              <span id="auto-form-result" class="muted tiny"></span>
            </div>
          </div>
        </details>

        <div class="auto-io">
          <button id="btn-auto-export" class="btn ghost sm">导出 JSON</button>
          <button id="btn-auto-import" class="btn ghost sm">导入 JSON</button>
          <input id="auto-file" type="file" accept=".json,application/json" hidden>
          <span id="auto-result" class="muted tiny"></span>
        </div>

        <div class="side-title"><span>运行日志</span><button id="btn-auto-logs" class="side-link">刷新日志</button></div>
        <div id="auto-logs" class="auto-logs"></div>

        <p class="muted tiny">配置与日志保存在服务器 <code>data/</code> 下的一个 JSON 文件（文件名随机，页面不公开真实名）。<b>只有 <code>index.php</code> 被访问时才推进调度</b>：页面开着不操作不会自动打点，关掉页面、无人访问就完全不执行；也没有外部定时或常驻进程。动作参数示例：<code>log</code> 用 <code>{"message":"{time}"}</code>，<code>plugin</code> 用 <code>{"plugin":"core.time","tool":"get_time","args":{}}</code>，<code>http</code> 用 <code>{"url":"https://...","method":"GET"}</code>。</p>
      </section>

      <section class="tab-pane" data-pane="about">
        <div class="about-grid">
          <div><span>版本</span><strong>dshphp <?php echo h(DSHPHP_VERSION); ?></strong></div>
          <div><span>PHP</span><strong><?php echo h(PHP_VERSION); ?></strong></div>
          <div><span>存储</span><strong id="about-driver">—</strong></div>
          <div><span>HTTP</span><strong id="about-http">—</strong></div>
        </div>
        <p class="muted tiny">这是纯 PHP 实现的独立 DSH 对话服务，不依赖 Node / Electron / QQ / SnowLuma。对话数据保存在本站 <code>data/</code>，密钥保存在 <code>config.php</code>。</p>
        <p class="muted tiny">内置 <strong>48 个官方市场插件</strong>（含 6 个界面增强插件），在「插件市场」页签一键安装；界面插件装完刷新页面生效。自定义插件可放进 <code>plugins/</code>，带 <code>ui</code> 清单还能扩展界面（见 README 第十二节）。</p>
        <p class="muted tiny">对外接口：<code>index.php?r=v1/chat/completions</code>（OpenAI 兼容，带 API Key）、<code>index.php?r=api/chat/send</code>。详见 README.md。</p>
      </section>
    </div>
    <div class="modal-foot">
      <span id="save-result" class="muted tiny"></span>
      <button id="btn-save" class="btn primary">保存设置</button>
    </div>
  </div>
</div>
<div id="dsh-slot-overlay" class="dsh-slot"></div>
<div id="dsh-toasts" class="dsh-toasts"></div>
</body></html><?php
}
