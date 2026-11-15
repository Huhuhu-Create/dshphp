<?php
/**
 * 美化 / 透明玻璃背景（2.4 GUI API 示范插件）
 * 演示能力：ui.scope 同时覆盖 app 与 login、CSS 变量覆盖、DSH.storage 本地设置、
 *          theme:change 事件广播、D.ui.panel 浮层设置界面。
 * 1.1.0：玻璃层覆盖 .main（中间聊天区原本是实心 var(--bg)），新增「主区浓度」滑杆。
 * 1.2.0：背景图与面板毛玻璃拆成两个独立模糊；背景默认 0px 全清晰、面板默认 4px，
 *       旧配置（12px + 14px 双重模糊）一次性迁移，不再糊成一片。
 * 重要：背景图只存浏览器 localStorage（转小尺寸 data URL，上限 64KB），
 *      不上传服务器、不写 data/ 目录，因此不需要任何服务端写权限。
 */
$css = <<<'CSS'
body.dsh-glass-on{background:transparent!important}
body.dsh-glass-on::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background-color:#0d1117;background-image:var(--dsh-glass-image,none);background-size:cover;background-position:center;filter:blur(var(--dsh-glass-blur,0px)) brightness(var(--dsh-glass-bright,.9));transform:scale(var(--dsh-glass-scale,1));transition:filter .3s,transform .3s}
body.dsh-glass-on .sidebar,body.dsh-glass-on .topbar,body.dsh-glass-on .composer-wrap,body.dsh-glass-on .modal-card,body.dsh-glass-on .login-card,body.dsh-glass-on .dsh-panel{background:rgba(20,26,35,.5)!important;backdrop-filter:blur(var(--dsh-glass-panel-blur,4px)) saturate(1.1)}
/* 主区域：style.css 里 .main 是实心 var(--bg)，必须覆盖掉才能透出背景图 */
body.dsh-glass-on .main{background:var(--dsh-glass-main,rgba(16,21,29,.38))!important}
body.dsh-glass-on .messages{background:transparent}
body.dsh-glass-on .bubble pre{background:rgba(10,14,20,.82)!important}
body.dsh-glass-on .welcome{text-shadow:0 1px 12px rgba(0,0,0,.55)}
.dsh-glass-row{display:flex;align-items:center;gap:8px;margin:9px 0;font-size:11px;color:var(--muted)}
.dsh-glass-row>span:first-child{width:62px;flex:none}
.dsh-glass-row input[type=range]{flex:1;min-width:0}
.dsh-glass-row b{width:42px;text-align:right;color:var(--text);font-variant-numeric:tabular-nums}
.dsh-glass-url{width:100%;background:var(--panel2);border:1px solid var(--line);color:var(--text);border-radius:7px;padding:6px 8px;font-size:11px}
CSS;

$js = <<<'JS'
DSH.ready(function (D) {
  var key = 'mkt.glass';
  // blur = 背景图本身；panel = 侧栏/顶栏等面板的毛玻璃。两者分开才不会糊成一片。
  var DEFAULTS = { on: true, blur: 0, panel: 4, bright: 0.9, main: 38, image: '', v: 2 };
  var cfg = D.storage.get('cfg', DEFAULTS);
  if (!cfg || typeof cfg !== 'object') cfg = {};
  // 1.2.0 一次性迁移：1.1.0 及更早默认 12px 全图模糊 + 14px 面板模糊，反馈「太糊」。
  // 老配置直接归零背景模糊、面板降到 4px，并写回，保证刷新即变清晰（想糊可在外观里调回）。
  var migrated = false;
  if (cfg.v !== 2) {
    migrated = true;
    cfg.v = 2;
    cfg.blur = 0;
    cfg.panel = 4;
    if (Number(cfg.bright) === 0.85) cfg.bright = DEFAULTS.bright;
  }
  // 兼容缺字段 / 非法值的旧对象，避免 NaN 写进 CSS
  if (typeof cfg.main !== 'number' || !isFinite(cfg.main)) cfg.main = DEFAULTS.main;
  if (typeof cfg.blur !== 'number' || !isFinite(cfg.blur)) cfg.blur = DEFAULTS.blur;
  if (typeof cfg.panel !== 'number' || !isFinite(cfg.panel)) cfg.panel = DEFAULTS.panel;
  if (typeof cfg.bright !== 'number' || !isFinite(cfg.bright)) cfg.bright = DEFAULTS.bright;
  cfg.main = Math.min(80, Math.max(0, cfg.main));
  cfg.blur = Math.min(24, Math.max(0, cfg.blur));
  cfg.panel = Math.min(16, Math.max(0, cfg.panel));
  cfg.bright = Math.min(1.2, Math.max(0.3, cfg.bright));
  if (migrated) D.storage.set('cfg', cfg);

  function safeImage(s) {
    s = String(s || '').trim();
    if (!s) return '';
    if (/^data:image\/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$/.test(s)) return s;
    if (/^https?:\/\/[^\s"'()\\]+$/.test(s)) return s;
    return '';
  }
  function apply() {
    var img = safeImage(cfg.image);
    document.body.classList.toggle('dsh-glass-on', !!cfg.on);
    var mainAlpha = (Math.min(80, Math.max(0, Number(cfg.main) || 0)) / 100).toFixed(2);
    // 背景不模糊时不要再放大 1.08 倍（会白白裁掉图片边缘）；只有真模糊才需要遮边
    var scale = Number(cfg.blur) > 0 ? 1.08 : 1;
    var text = ':root{--dsh-glass-blur:' + Number(cfg.blur) + 'px'
      + ';--dsh-glass-panel-blur:' + Number(cfg.panel) + 'px'
      + ';--dsh-glass-scale:' + scale
      + ';--dsh-glass-bright:' + Number(cfg.bright)
      + ';--dsh-glass-main:rgba(16,21,29,' + mainAlpha + ')}\n'
      + (img ? 'body.dsh-glass-on::before{background-image:url("' + img + '")}' : 'body.dsh-glass-on::before{background-image:none}');
    D.ui.css(text, key + '.vars');
    D.emit('theme:change', { glass: !!cfg.on, blur: cfg.blur, panel: cfg.panel,
      bright: cfg.bright, main: cfg.main, clear: Number(cfg.blur) === 0, hasImage: !!img });
  }
  function persist() { D.storage.set('cfg', cfg); apply(); }
  apply();

  // 登录页只负责渲染外观，不显示需要登录态的设置按钮
  if (D.scope !== 'app') return;

  var btn = D.ui.btn('◐ 外观', function () { open(); }, 'ghost sm');
  D.ui.mount('sidebar-foot', btn, { id: key + '.opener' });

  function slider(label, min, max, step, get, set, suffix) {
    var unit = suffix || '';
    var val = D.el('b', { text: String(get()) + unit });
    var input = D.el('input', { type: 'range', min: min, max: max, step: step, value: get() });
    input.addEventListener('input', function () { set(Number(input.value)); val.textContent = input.value + unit; persist(); });
    return D.el('div', { class: 'dsh-glass-row' }, [D.el('span', { text: label }), input, val]);
  }

  function shrink(file, cb) {
    if (!/^image\//.test(file.type)) return cb('请选择图片文件');
    var fr = new FileReader();
    fr.onerror = function () { cb('读取失败'); };
    fr.onload = function () {
      var img = new Image();
      img.onerror = function () { cb('图片解码失败'); };
      img.onload = function () {
        var cv = document.createElement('canvas');
        var scale = Math.min(480 / img.width, 270 / img.height, 1);
        cv.width = Math.max(1, Math.round(img.width * scale));
        cv.height = Math.max(1, Math.round(img.height * scale));
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        var q = 0.5, url = '';
        for (var i = 0; i < 6; i++) {
          url = cv.toDataURL('image/jpeg', q);
          if (url.length <= 65536) break;
          q -= 0.07;
        }
        if (url.length > 65536) return cb('压缩后仍超过 64KB，请换一张更小的图或用外链 URL');
        cb(null, url);
      };
      img.src = fr.result;
    };
    fr.readAsDataURL(file);
  }

  function open() {
    var p = D.ui.panel({ id: key + '.panel', title: '透明玻璃背景', slot: 'overlay' });
    if (!p) return;
    var msg = D.el('div', { class: 'dsh-hint' });

    var on = D.el('input', { type: 'checkbox' });
    on.checked = !!cfg.on;
    on.addEventListener('change', function () { cfg.on = on.checked; persist(); });
    p.body.appendChild(D.el('label', { class: 'dsh-glass-row' }, [D.el('span', { text: '启用' }), on]));
    p.body.appendChild(slider('背景模糊', 0, 24, 1, function () { return cfg.blur; }, function (v) { cfg.blur = v; }, 'px'));
    p.body.appendChild(slider('玻璃模糊', 0, 16, 1, function () { return cfg.panel; }, function (v) { cfg.panel = v; }, 'px'));
    p.body.appendChild(slider('背景亮度', 30, 120, 5, function () { return Math.round(cfg.bright * 100); }, function (v) { cfg.bright = v / 100; }, '%'));
    p.body.appendChild(slider('主区浓度', 0, 80, 2, function () { return cfg.main; }, function (v) { cfg.main = v; }, '%'));
    p.body.appendChild(D.el('div', { class: 'dsh-hint', text: '背景模糊＝整张背景图，默认 0 完全清晰；玻璃模糊＝只作用于侧栏/顶栏/输入区等面板。两项分开调，不会互相叠加。' }));
    p.body.appendChild(D.el('div', { class: 'dsh-hint', text: '主区浓度＝中间聊天区域的玻璃底色深浅。0% 完全透出背景图，数值越大越暗、文字越清楚。' }));

    var url = D.el('input', { type: 'text', class: 'dsh-glass-url', placeholder: 'https://… 背景图外链（留空 = 纯色玻璃）', value: /^https?:/.test(cfg.image || '') ? cfg.image : '' });
    url.addEventListener('change', function () {
      cfg.image = url.value.trim();
      msg.textContent = cfg.image && !safeImage(cfg.image) ? '外链格式不合法，已忽略' : '已应用';
      persist();
    });
    p.body.appendChild(D.el('label', { text: '背景图 URL' }, [url]));

    var file = D.el('input', { type: 'file', accept: 'image/*' });
    file.addEventListener('change', function () {
      if (!file.files || !file.files[0]) return;
      msg.textContent = '正在压缩…';
      shrink(file.files[0], function (err, data) {
        if (err) { msg.textContent = err; return; }
        cfg.image = data; url.value = '';
        persist();
        msg.textContent = '已存到本机浏览器（' + Math.round(data.length / 1024) + 'KB），不会上传服务器';
      });
    });
    p.body.appendChild(D.el('label', { text: '或上传小图（自动压缩，仅本地）' }, [file]));
    p.body.appendChild(D.el('div', { class: 'dsh-row' }, [
      D.ui.btn('清除背景', function () { cfg.image = ''; url.value = ''; persist(); msg.textContent = '已清除'; }, 'ghost sm'),
      D.ui.btn('恢复默认', function () {
        cfg = Object.assign({}, DEFAULTS);
        on.checked = true; url.value = '';
        persist(); msg.textContent = '已恢复默认（背景清晰）';
        p.close(); open();
      }, 'ghost sm')
    ]));
    p.body.appendChild(msg);
    p.body.appendChild(D.el('div', { class: 'dsh-hint', text: '设置只存在当前浏览器，换设备/清缓存会重置；不影响服务器数据。' }));
  }
});
JS;

return [
    'id' => 'mkt.glass',
    'name' => '透明玻璃背景',
    'description' => '半透明玻璃质感覆盖全站，背景图默认完全清晰（背景模糊与面板毛玻璃分开独立可调）；可自定义背景图、模糊、亮度与主区浓度。背景图压缩后只存浏览器本地，不上传服务器。登录页同样生效。',
    'version' => '1.2.0',
    'author' => 'dshphp 官方市场',
    'icon' => 'glass',
    'category' => 'beauty',
    'tags' => ['背景', '玻璃', '主题', 'GUI'],
    'risk' => 'local',
    'usage' => '安装并刷新页面即生效（旧版 12px 双重模糊自动迁移为清晰）；侧栏「◐ 外观」可分别调整背景模糊、玻璃模糊、亮度、主区浓度与背景图。',
    'enabled' => true,
    'tools' => [],
    'slash' => [],
    'ui' => [
        'apiVersion' => 1,
        'scope' => ['app', 'login'],
        'mounts' => ['sidebar-foot', 'login', 'overlay'],
        'permissions' => ['storage', 'theme', 'ui'],
        'actions' => ['ui.theme.change'],
        'css' => $css,
        'js' => $js,
    ],
];
