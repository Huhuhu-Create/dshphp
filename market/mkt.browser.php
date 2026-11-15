<?php
/**
 * 实用 / 内嵌浏览器（2.4 GUI API 插件）
 *
 * 把 AI 回复里的 HTML（```html 代码块、裸 HTML 文档或片段）实时渲染进右侧停靠的 iframe。
 * 数据来源全部是浏览器内存 / 对话消息，不上传服务器、不写 data/、不新增任何后端接口。
 *
 * 安全边界（重要）：
 *   - iframe 用 srcdoc + sandbox，绝不加 allow-same-origin / allow-top-navigation /
 *     allow-popups / allow-downloads；AI 生成的脚本可以跑（小游戏 / 图表需要），
 *     但 iframe 是 opaque origin，读不到本站 Cookie、localStorage，也碰不到 parent.document。
 *   - srcdoc 通过属性赋值，不经过 innerHTML；源码视图用 textarea.value，不做 HTML 拼接。
 *   - referrerpolicy=no-referrer，外部 CDN 资源不会带走本站地址。
 *   - 不提供「新窗口打开」：Blob URL 与父页面同源，会让脚本逃出沙箱，改为下载 .html 由用户自行打开。
 */
$css = <<<'CSS'
/* 右侧停靠式内嵌浏览器：作为 #dsh-slot-overlay 的直接子元素，只占右侧，不挡聊天区 */
.dsh-brw{position:fixed;right:0;top:0;bottom:0;width:var(--dsh-brw-w,480px);z-index:60;display:flex;flex-direction:column;background:var(--panel);border-left:1px solid var(--line2);box-shadow:-14px 0 44px rgba(0,0,0,.38)}
body.dsh-brw-open #app{margin-right:var(--dsh-brw-w,480px)}
.dsh-brw-grip{position:absolute;left:-4px;top:0;bottom:0;width:8px;cursor:col-resize;background:transparent}
.dsh-brw-grip:hover{background:rgba(91,140,255,.4)}
.dsh-brw-bar{display:flex;align-items:center;gap:5px;padding:7px 9px;border-bottom:1px solid var(--line);flex:none;flex-wrap:wrap}
.dsh-brw-bar .btn{padding:4px 8px;font-size:11px;flex:none}
.dsh-brw-url{flex:1;min-width:100px;display:flex;align-items:center;gap:6px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:5px 9px;font:11px ui-monospace,SFMono-Regular,Consolas,monospace;color:var(--muted)}
.dsh-brw-url b{color:var(--accent2);font-weight:600;flex:none}
.dsh-brw-frame{flex:1;min-height:0;width:100%;border:0;background:#fff;display:block}
.dsh-brw-src{flex:1;min-height:0;width:100%;box-sizing:border-box;border:0;background:var(--bg);color:var(--text);font:12px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace;padding:12px;resize:none;outline:none}
.dsh-brw-empty{flex:1;min-height:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:24px;text-align:center;color:var(--muted);font-size:12px;line-height:1.9}
.dsh-brw-empty strong{color:var(--text);font-size:14px}
.dsh-brw-foot{flex:none;display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:6px 9px;border-top:1px solid var(--line);font-size:10.5px;color:var(--muted)}
.dsh-brw-foot label{display:inline-flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap}
.dsh-brw-foot input[type=checkbox]{margin:0}
.dsh-brw-live{display:inline-flex;align-items:center;gap:4px;color:var(--amber);white-space:nowrap}
.dsh-brw-live::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--amber);animation:dsh-brw-pulse 1s infinite}
@keyframes dsh-brw-pulse{50%{opacity:.2}}
.dsh-brw-nav{min-width:42px;text-align:center;font-variant-numeric:tabular-nums}
@media(max-width:900px){body.dsh-brw-open #app{margin-right:0}.dsh-brw{width:100vw!important}.dsh-brw-grip{display:none}}
CSS;

$js = <<<'JS'
DSH.ready(function (D) {
  if (D.scope !== 'app') return;                 // 登录页不加载预览器
  var ID = 'mkt.browser';
  var DEFAULTS = { w: 480, scripts: true, follow: true, open: false, v: 1 };
  var cfg = D.storage.get('cfg', DEFAULTS);
  if (!cfg || typeof cfg !== 'object') cfg = {};
  if (typeof cfg.w !== 'number' || !isFinite(cfg.w)) cfg.w = DEFAULTS.w;
  cfg.w = Math.min(900, Math.max(320, Math.round(cfg.w)));
  if (typeof cfg.scripts !== 'boolean') cfg.scripts = DEFAULTS.scripts;
  if (typeof cfg.follow !== 'boolean') cfg.follow = DEFAULTS.follow;
  if (typeof cfg.open !== 'boolean') cfg.open = DEFAULTS.open;
  cfg.v = 1;

  var hist = [];                 // [{ ts, html, label }] 最近 12 个版本
  var idx = -1;
  var live = { text: '', streaming: false };
  var timer = null, lastHtml = '', srcMode = false;
  var root, frame, srcBox, urlBox, emptyBox, footStat, navInfo, prevBtn, nextBtn, followBox, scriptBox, fileInput, opener;

  /* ---------- HTML 提取：优先 ```html 代码块，其次裸文档 / 片段 ---------- */
  function extract(text) {
    text = String(text || '');
    if (!text) return '';
    var found = [], m;
    // 允许代码块未闭合（AI 还在流式输出时就是这样）
    var re = /```([A-Za-z0-9_+-]*)[ \t]*\r?\n([\s\S]*?)(?:```|$)/g;
    while ((m = re.exec(text)) !== null) {
      var lang = (m[1] || '').toLowerCase(), code = m[2] || '';
      if (lang === 'html' || lang === 'htm' || lang === 'xml' || lang === 'svg') { found.push(code); continue; }
      if (!lang && /^\s*(<!doctype|<html|<head|<body|<div|<style|<script|<svg|<section|<main|<table)/i.test(code)) found.push(code);
    }
    if (found.length) return found[found.length - 1].replace(/\s+$/, '');
    var t = text.trim();
    if (/^(<!doctype|<html[\s>])/i.test(t)) return t;
    var doc = /(<html[\s\S]*?<\/html>)/i.exec(t);
    return doc ? doc[1] : '';
  }

  /* 片段包成完整文档，保证中文字体与白底可读 */
  function wrap(h) {
    h = String(h || '').trim();
    if (!h) return '';
    if (/^(<!doctype|<html[\s>])/i.test(h)) return h;
    return '<!doctype html><html><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<style>html,body{margin:0;padding:16px;background:#fff;color:#1c2128;'
      + 'font:14px/1.6 -apple-system,"Segoe UI","Microsoft YaHei",sans-serif}</style>'
      + '</head><body>' + h + '</body></html>';
  }

  function save() { D.storage.set('cfg', cfg); }
  function applyWidth() { document.documentElement.style.setProperty('--dsh-brw-w', cfg.w + 'px'); }
  function fmtTime(ts) {
    var d = new Date(ts);
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
  }

  function setUrl(label, doc) {
    if (!urlBox) return;
    urlBox.textContent = '';
    urlBox.appendChild(D.el('b', { text: 'about:ai-preview' }));
    urlBox.appendChild(document.createTextNode(' · ' + (label || '空白页') + ' · '
      + (doc ? (Math.round(doc.length / 102.4) / 10) + ' KB' : '—')));
  }

  function updateNav() {
    if (!navInfo) return;
    navInfo.textContent = hist.length ? (idx + 1) + '/' + hist.length : '0/0';
    if (prevBtn) prevBtn.disabled = idx <= 0;
    if (nextBtn) nextBtn.disabled = idx < 0 || idx >= hist.length - 1;
    if (footStat) footStat.textContent = hist.length ? ('最近渲染 ' + fmtTime(hist[idx >= 0 ? idx : 0].ts)) : '尚无渲染记录';
  }

  function pushHistory(doc, label) {
    if (!doc) return;
    if (hist.length && hist[hist.length - 1].html === doc) { idx = hist.length - 1; updateNav(); return; }
    hist.push({ ts: Date.now(), html: doc, label: label || 'AI 回复' });
    if (hist.length > 12) hist.shift();
    idx = hist.length - 1;
    updateNav();
  }

  function syncView() {
    if (!frame || !srcBox || !emptyBox) return;
    var has = !!lastHtml;
    emptyBox.style.display = has ? 'none' : 'flex';
    frame.style.display = has && !srcMode ? 'block' : 'none';
    srcBox.style.display = has && srcMode ? 'block' : 'none';
    if (has && srcMode) srcBox.value = lastHtml;
  }

  function applyDoc(html, label, remember) {
    if (!frame || !srcBox || !emptyBox || !urlBox) return;   // 界面尚未构建
    var doc = wrap(html);
    if (remember !== false) pushHistory(doc, label);
    if (doc === lastHtml && frame && frame.getAttribute('srcdoc') === doc) { setUrl(label, doc); return; }
    lastHtml = doc;
    if (!doc) { if (frame) frame.removeAttribute('srcdoc'); setUrl('空白页', ''); syncView(); return; }
    srcBox.value = doc;
    // 沙箱：脚本可跑，但 opaque origin，拿不到 parent / cookie / localStorage；
    // 不含 allow-same-origin / allow-top-navigation / allow-popups / allow-downloads。
    frame.setAttribute('sandbox', cfg.scripts ? 'allow-scripts allow-forms allow-modals' : '');
    frame.setAttribute('srcdoc', doc);          // 属性赋值，绝不走 innerHTML
    setUrl(label, doc);
    syncView();
  }

  function showVersion(n) {
    if (n < 0 || n >= hist.length) return;
    idx = n;
    var it = hist[idx];
    lastHtml = '';                              // 强制重新赋值 srcdoc
    applyDoc(it.html, it.label + ' · ' + fmtTime(it.ts), false);
    updateNav();                                // 刷新页码与 ◀/▶ 禁用状态（remember=false 时 applyDoc 不会调）
  }

  /* ---------- 实时跟随 ---------- */
  function flush(final) {
    if (timer) { clearTimeout(timer); timer = null; }
    var h = extract(live.text);
    if (!h) return;
    var label = final ? 'AI 回复 · 完成' : 'AI 回复 · 生成中';
    if (!cfg.follow) { pushHistory(wrap(h), label); return; }  // 不自动切换，但仍留历史
    applyDoc(h, label, true);
  }
  function schedule() {
    if (timer || !cfg.follow) return;
    timer = setTimeout(function () { timer = null; flush(false); }, 500);
  }
  function forceLatest() {
    var h = extract(live.text);
    if (!h) { D.ui.toast('当前回复里没有找到 HTML', 'warn'); return; }
    applyDoc(h, 'AI 回复 · 手动跟随', true);
  }

  /* ---------- 工具栏动作 ---------- */
  function refresh() { if (lastHtml) applyDoc(lastHtml, '手动刷新', false); }
  function download() {
    if (!lastHtml) { D.ui.toast('还没有可下载的 HTML', 'warn'); return; }
    var u = URL.createObjectURL(new Blob([lastHtml], { type: 'text/html;charset=utf-8' }));
    var a = D.el('a', { href: u, download: 'ai-preview-' + Date.now() + '.html' });
    document.body.appendChild(a); a.click(); if (a.parentNode) a.parentNode.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(u); }, 4000);
    D.ui.toast('已下载 .html 文件', 'ok');
  }
  function copySrc() {
    if (!lastHtml) { D.ui.toast('还没有源码', 'warn'); return; }
    function fallback() {
      srcMode = true; syncView(); srcBox.select();
      try { document.execCommand('copy'); D.ui.toast('源码已复制', 'ok'); }
      catch (e) { D.ui.toast('复制失败，请在源码视图里手动选中', 'err'); }
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(lastHtml).then(
        function () { D.ui.toast('源码已复制', 'ok'); }, fallback);
    } else fallback();
  }

  /* ---------- 构建界面 ---------- */
  function build() {
    if (root) return;
    prevBtn = D.ui.btn('◀', function () { showVersion(idx - 1); }, 'ghost sm');
    prevBtn.title = '上一个版本';
    nextBtn = D.ui.btn('▶', function () { showVersion(idx + 1); }, 'ghost sm');
    nextBtn.title = '下一个版本';
    navInfo = D.el('span', { class: 'dsh-brw-nav muted tiny', text: '0/0' });
    urlBox = D.el('div', { class: 'dsh-brw-url' });

    var bar = D.el('div', { class: 'dsh-brw-bar' }, [
      prevBtn, nextBtn, navInfo, urlBox,
      D.ui.btn('↻', refresh, 'ghost sm'),
      D.ui.btn('⟳', forceLatest, 'ghost sm'),
      D.ui.btn('</>', function () { srcMode = !srcMode; syncView(); }, 'ghost sm'),
      D.ui.btn('⧉', copySrc, 'ghost sm'),
      D.ui.btn('⤓', download, 'ghost sm'),
      D.ui.btn('▤', function () { fileInput.click(); }, 'ghost sm'),
      D.ui.btn('✕', close, 'ghost sm')
    ]);
    bar.children[4].title = '刷新'; bar.children[5].title = '跟随最新回复';
    bar.children[6].title = '切换源码视图'; bar.children[7].title = '复制源码';
    bar.children[8].title = '下载 .html'; bar.children[9].title = '打开本地 .html';
    bar.children[10].title = '关闭内嵌浏览器';

    frame = D.el('iframe', { class: 'dsh-brw-frame', title: 'AI HTML 预览' });
    frame.setAttribute('sandbox', cfg.scripts ? 'allow-scripts allow-forms allow-modals' : '');
    frame.setAttribute('referrerpolicy', 'no-referrer');
    srcBox = D.el('textarea', { class: 'dsh-brw-src', readonly: 'readonly', spellcheck: 'false' });
    srcBox.style.display = 'none';
    emptyBox = D.el('div', { class: 'dsh-brw-empty' }, [
      D.el('strong', { text: '内嵌浏览器' }),
      D.el('div', { text: '让 AI 生成一段 HTML（```html 代码块），这里会边生成边渲染。' }),
      D.el('div', { text: '也可以点工具栏「▤」打开本机 .html 文件，只在浏览器里读，不上传服务器。' }),
      D.el('div', { class: 'dsh-hint', text: '页面运行在沙箱 iframe 中：脚本可执行，但读不到本站数据与 Cookie。' })
    ]);

    scriptBox = D.el('input', { type: 'checkbox' });
    scriptBox.checked = cfg.scripts;
    scriptBox.addEventListener('change', function () { cfg.scripts = scriptBox.checked; save(); refresh(); });
    followBox = D.el('input', { type: 'checkbox' });
    followBox.checked = cfg.follow;
    followBox.addEventListener('change', function () { cfg.follow = followBox.checked; save(); });
    footStat = D.el('span', { class: 'muted tiny', text: '尚无渲染记录' });
    var foot = D.el('div', { class: 'dsh-brw-foot' }, [
      D.el('label', {}, [scriptBox, document.createTextNode('允许脚本')]),
      D.el('label', {}, [followBox, document.createTextNode('自动跟随')]),
      footStat,
      D.el('span', { class: 'dsh-hint', text: 'sandbox: 无同源 / 无跳转 / 无弹窗' })
    ]);

    var grip = D.el('div', { class: 'dsh-brw-grip', title: '拖动调整宽度' });
    grip.addEventListener('mousedown', function (e) {
      e.preventDefault();
      var x0 = e.clientX, w0 = cfg.w;
      function move(ev) { cfg.w = Math.min(900, Math.max(320, w0 + (x0 - ev.clientX))); applyWidth(); }
      function up() {
        document.removeEventListener('mousemove', move);
        document.removeEventListener('mouseup', up);
        save();
      }
      document.addEventListener('mousemove', move);
      document.addEventListener('mouseup', up);
    });

    fileInput = D.el('input', { type: 'file', accept: '.html,.htm,text/html' });
    fileInput.style.display = 'none';
    fileInput.addEventListener('change', function () {
      var f = fileInput.files && fileInput.files[0];
      if (!f) return;
      if (f.size > 1048576) { D.ui.toast('文件超过 1MB，已忽略', 'err'); fileInput.value = ''; return; }
      var fr = new FileReader();
      fr.onload = function () { open(); applyDoc(String(fr.result || ''), '本地 · ' + f.name, true); };
      fr.onerror = function () { D.ui.toast('读取失败', 'err'); };
      fr.readAsText(f);
      fileInput.value = '';
    });

    root = D.el('div', { class: 'dsh-brw' }, [grip, bar, frame, srcBox, emptyBox, foot, fileInput]);
    setUrl('空白页', '');
    syncView();
  }

  function open() {
    cfg.open = true; save();
    build();
    D.ui.mount('overlay', root, { id: ID + '.root' });
    document.body.classList.add('dsh-brw-open');
    applyWidth();
    if (opener) opener.classList.add('active');
    // iframe 插入文档后再写一次 srcdoc，确保打开时一定重新加载一次
    if (lastHtml && frame) frame.setAttribute('srcdoc', lastHtml);
    updateNav(); syncView();
  }
  function close() {
    cfg.open = false; save();
    if (root && root.parentNode) root.parentNode.removeChild(root);
    document.body.classList.remove('dsh-brw-open');
    if (opener) opener.classList.remove('active');
  }
  function toggle() { return document.body.classList.contains('dsh-brw-open') ? (close(), false) : (open(), true); }

  /* ---------- 生命周期订阅 ---------- */
  // 先把界面构建好（但不挂到 DOM），这样 replay / 首屏扫描拿到的 HTML 能立刻写进 iframe，
  // 等用户点开时 srcdoc 已经在，不会出现「ready 回调早于界面」的空指针。
  build();

  function scanMessages(list) {
    if (!Array.isArray(list) || !list.length) return;
    for (var i = list.length - 1; i >= 0; i--) {
      var m = list[i];
      if (!m || m.role !== 'assistant') continue;
      var h = extract(m.content || '');
      if (h) { live.text = m.content; applyDoc(h, '历史回复', true); return; }
    }
  }

  D.on('chat:send', function () { live.text = ''; live.streaming = true; });
  D.on('stream:event', function (p) {
    var ev = p && p.event, d = (p && p.data) || {};
    if (ev === 'delta') { live.text += String(d.text || ''); schedule(); }
    else if (ev === 'done') {
      live.streaming = false;
      if (d.message && d.message.content) live.text = d.message.content;
      flush(true);
    } else if (ev === 'error') { live.streaming = false; flush(true); }
  });
  D.on('chat:done', function (d) {
    live.streaming = false;
    if (d && d.message && d.message.content) live.text = d.message.content;
    flush(true);
  });
  D.on('chat:error', function () { live.streaming = false; flush(true); });
  D.on('busy:change', function (p) { if (p && !p.busy) { live.streaming = false; flush(true); } });
  // replay:true 补上插件 ready 之前就已发出的首屏事件（init / selectSession 早于 app:ready）
  D.on('session:change', function (p) { if (!live.streaming) scanMessages(p && p.messages); }, { replay: true });
  D.on('messages:render', function (p) { if (!live.streaming) scanMessages(p && p.messages); }, { replay: true });

  // 首屏：从 app.js 同步过来的状态里恢复最后一次 HTML
  scanMessages(D.getState().messages);

  /* ---------- 入口 ---------- */
  opener = D.ui.btn('▣ 预览', function () { toggle(); }, 'ghost sm');
  opener.title = '内嵌浏览器 · 实时渲染 AI 生成的 HTML（Alt+P）';
  D.ui.mount('topbar', opener, { id: ID + '.opener' });
  document.addEventListener('keydown', function (e) {
    if (e.altKey && (e.key === 'p' || e.key === 'P')) { e.preventDefault(); toggle(); }
  });
  if (cfg.open) open();
  D.emit('ui:browser:ready', { id: ID, open: cfg.open });
});
JS;

return [
    'id' => 'mkt.browser',
    'name' => '内嵌浏览器 · HTML 实时预览',
    'description' => '右侧停靠的沙箱浏览器：自动提取 AI 回复里的 HTML 代码块并边生成边渲染，支持历史版本、源码视图、下载、打开本地 .html、拖拽调宽。iframe 严格沙箱隔离，脚本读不到本站数据；不需要任何后端接口。',
    'version' => '1.0.0',
    'author' => 'dshphp 官方市场',
    'icon' => 'browser',
    'category' => 'utility',
    'tags' => ['HTML', '预览', '浏览器', '实时', 'GUI'],
    'risk' => 'local',
    'usage' => '安装并刷新页面 → 顶栏「▣ 预览」或 Alt+P 打开；让 AI 输出 ```html 代码块即可实时渲染，也可用「▤」打开本地 .html。',
    'enabled' => true,
    'tools' => [],
    'slash' => [],
    'ui' => [
        'apiVersion' => 1,
        'scope' => ['app'],
        'mounts' => ['topbar', 'overlay'],
        'permissions' => ['storage', 'ui', 'state'],
        'actions' => ['ui.browser.open', 'ui.browser.render'],
        'css' => $css,
        'js' => $js,
    ],
];
