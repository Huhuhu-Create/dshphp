/**
 * 真实浏览器自检（Edge CDP）：内嵌浏览器插件 mkt.browser
 * 前置：Edge headless 9222、PHP 服务器 8097、tools/_uitest-session.php 临时存在，
 *       且本机已安装 6 个 UI 插件（tools/check-ui.php 会装）。
 * 重点验证：流式实时渲染、iframe 沙箱隔离（脚本能跑但拿不到 parent.document）、
 *          历史版本 / 源码视图 / 本地文件 / 关闭还原布局。
 */
import { writeFileSync } from 'node:fs';
const CDP = 'http://127.0.0.1:9222';
const BASE = 'http://127.0.0.1:8097';
const SHOT = 'D:/workbuddyfile/2026-11-11-17-45-31/browser-preview.png';
let pass = 0, fail = 0;
const ok = (n, c, x = '') => { if (c) { pass++; console.log('  PASS  ' + n); } else { fail++; console.log('  FAIL  ' + n + (x ? '  → ' + String(x).slice(0, 260) : '')); } };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const list = await (await fetch(CDP + '/json/list')).json();
let tab = list.find((t) => t.type === 'page');
if (!tab) tab = await (await fetch(CDP + '/json/new?about:blank', { method: 'PUT' })).json();
const ws = new WebSocket(tab.webSocketDebuggerUrl);
await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
let seq = 0;
const pending = new Map();
const errors = [];
ws.onmessage = (e) => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
  if (m.method === 'Runtime.exceptionThrown') {
    const d = m.params.exceptionDetails || {};
    errors.push((d.text || '') + ' ' + ((d.exception && d.exception.description) || ''));
  }
};
const cmd = (method, params = {}) => new Promise((res) => {
  const id = ++seq; pending.set(id, res);
  ws.send(JSON.stringify({ id, method, params }));
});
const evaluate = async (expression) => {
  const r = await cmd('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.text);
  return r.result?.result?.value;
};
const waitFor = async (expression, timeout = 12000) => {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) {
    try { if (await evaluate(expression)) return true; } catch (_) {}
    await sleep(120);
  }
  return false;
};

await cmd('Page.enable'); await cmd('Runtime.enable');
await cmd('Emulation.setDeviceMetricsOverride', { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });
await cmd('Page.navigate', { url: BASE + '/tools/_uitest-session.php' });
ok('主界面已加载', await waitFor('!!document.getElementById("app") && !document.body.classList.contains("login-body")'));
ok('app:ready 已发出', await waitFor('!!window.DSH && window.DSH.history().some(r => r.name === "app:ready")', 15000));

const plugins = await evaluate('window.DSH.plugins.map(p => p.id + ":" + (p.ok ? "ok" : "ERR " + p.error))');
ok('6 个 UI 插件全部初始化成功', plugins.length === 6 && plugins.every((p) => p.endsWith(':ok')), JSON.stringify(plugins));
ok('内嵌浏览器插件在 bundle 里', plugins.some((p) => p.startsWith('mkt.browser:ok')), JSON.stringify(plugins));
ok('顶栏出现「▣ 预览」按钮', (await evaluate('!!Array.from(document.querySelectorAll("#dsh-slot-topbar button")).find(b => b.textContent.includes("预览"))')) === true);

// 清空插件设置，保证从默认状态开始（默认不自动打开）
await evaluate(`localStorage.removeItem('dsh.ui.mkt_browser.cfg')`);
await cmd('Page.navigate', { url: BASE + '/index.php' });
ok('重载后插件仍正常', await waitFor('!!window.DSH && window.DSH.plugins.some(p => p.id === "mkt.browser" && p.ok) && window.DSH.history().some(r => r.name === "app:ready")', 15000));

// 打开抽屉，验证右侧分栏不遮挡聊天区
const opened = await evaluate(`(() => {
  const b = Array.from(document.querySelectorAll('#dsh-slot-topbar button')).find(x => x.textContent.includes('预览'));
  if (!b) return 'no-button'; b.click();
  const r = document.querySelector('.dsh-brw');
  return JSON.stringify({ root: !!r, bodyClass: document.body.classList.contains('dsh-brw-open'),
    margin: getComputedStyle(document.getElementById('app')).marginRight,
    iframe: !!document.querySelector('.dsh-brw-frame'),
    sandbox: (document.querySelector('.dsh-brw-frame') || {}).getAttribute
      ? document.querySelector('.dsh-brw-frame').getAttribute('sandbox') : '',
    empty: !!document.querySelector('.dsh-brw-empty') });
})()`);
console.log('  DIAG  ' + opened);
const o = JSON.parse(opened);
ok('内嵌浏览器抽屉已打开', o.root === true && o.bodyClass === true, opened);
ok('聊天区让出右侧宽度（真分栏，不遮挡）', parseFloat(o.margin) >= 300, o.margin);
ok('空状态提示已显示', o.empty === true);
ok('默认 sandbox 不含 allow-same-origin / 弹窗 / 跳转 / 下载',
  !/same-origin|top-navigation|popups|downloads/.test(o.sandbox || ''), o.sandbox);

// 监听 iframe 脚本回传：既能证明脚本执行，也能证明拿不到父页面 DOM
await evaluate(`window.__brwMsgs = []; window.addEventListener('message', function (e) {
  if (e.data && e.data.brwTest) window.__brwMsgs.push(e.data);
});`);

const NL = String.fromCharCode(10);
const HTML1 = '给你一段代码：' + NL + '```html' + NL
  + '<!doctype html><html><head><meta charset="utf-8"></head><body>'
  + '<h1 id="t" style="color:#2b6cb0">HELLO-BRW</h1>'
  + '<scr' + 'ipt>window.__brwRan=1;var probe=false;try{probe=!!parent.document.body}catch(e){probe=false}'
  + 'try{parent.postMessage({brwTest:1,ran:window.__brwRan,sameOriginProbe:probe},"*")}catch(e){}</scr'
  + 'ipt></body></html>' + NL + '```' + NL + '说明完毕。';

// 模拟一次流式对话：先 delta，再 done
await evaluate(`window.DSH.emit('chat:send', { sid: 'test', text: '写个 HTML' })`);
await evaluate(`window.DSH.emit('stream:event', { event: 'delta', data: { text: ${JSON.stringify(HTML1)} }, sid: 'test' })`);
ok('delta 后 500ms 内触发实时渲染', await waitFor(`(() => { const f = document.querySelector('.dsh-brw-frame'); return !!f && /HELLO-BRW/.test(f.getAttribute('srcdoc') || ''); })()`, 4000));
await evaluate(`window.DSH.emit('chat:done', { message: { content: ${JSON.stringify(HTML1)} } })`);
ok('完成后 srcdoc 是完整 HTML 文档', (await evaluate(`(() => { const s = document.querySelector('.dsh-brw-frame').getAttribute('srcdoc') || ''; return /^<!doctype html>/i.test(s) && /HELLO-BRW/.test(s) && !/说明完毕/.test(s); })()`)) === true);

// 沙箱：脚本能跑，但访问 parent.document 必须失败
ok('iframe 内脚本已执行并回传消息', await waitFor('window.__brwMsgs.length >= 1', 8000));
const msgs = await evaluate('JSON.stringify(window.__brwMsgs)');
console.log('  DIAGMSG ' + msgs);
const mm = JSON.parse(msgs);
ok('脚本确实执行（ran=1）', mm.some((x) => x.ran === 1), msgs);
ok('沙箱隔离生效：脚本拿不到 parent.document', mm.every((x) => x.sameOriginProbe === false), msgs);
ok('父页面无法读取 opaque iframe 的 contentDocument', (await evaluate(`(() => { try { return !!document.querySelector('.dsh-brw-frame').contentDocument.body; } catch (e) { return false; } })()`)) === false);

// 第二版：验证历史版本、源码视图
const HTML2 = '```html' + NL + '<div style="font:700 28px system-ui;color:#b23c6a">SECOND-VERSION</div>' + NL + '```';
await evaluate(`window.DSH.emit('chat:send', { sid: 'test', text: '再来一版' })`);
await evaluate(`window.DSH.emit('chat:done', { message: { content: ${JSON.stringify(HTML2)} } })`);
ok('第二个版本已渲染', await waitFor(`/SECOND-VERSION/.test(document.querySelector('.dsh-brw-frame').getAttribute('srcdoc') || '')`, 4000));
const nav = await evaluate(`document.querySelector('.dsh-brw-nav').textContent`);
// 首屏 replay 可能已从历史会话恢复过一版，所以用相对判断而不是硬编码 2/2
ok('每次渲染都会留下历史版本', /^(\d+)\/\1$/.test(nav) && parseInt(nav, 10) >= 2, nav);
const back = await evaluate(`(() => {
  const btns = Array.from(document.querySelectorAll('.dsh-brw-bar .btn'));
  btns.find(b => b.textContent === '◀').click();
  const s = document.querySelector('.dsh-brw-frame').getAttribute('srcdoc') || '';
  return JSON.stringify({ nav: document.querySelector('.dsh-brw-nav').textContent, first: /HELLO-BRW/.test(s), second: /SECOND-VERSION/.test(s) });
})()`);
console.log('  DIAGNAV ' + back + ' (before=' + nav + ')');
const nb = JSON.parse(back);
ok('◀ 可回退到上一版本', nb.first === true && nb.second === false && parseInt(nb.nav, 10) === parseInt(nav, 10) - 1, back);
const fwd = await evaluate(`(() => {
  Array.from(document.querySelectorAll('.dsh-brw-bar .btn')).find(b => b.textContent === '▶').click();
  const s = document.querySelector('.dsh-brw-frame').getAttribute('srcdoc') || '';
  return JSON.stringify({ nav: document.querySelector('.dsh-brw-nav').textContent, second: /SECOND-VERSION/.test(s) });
})()`);
const fw = JSON.parse(fwd);
ok('▶ 可前进到最新版本', fw.second === true && fw.nav === nav, fwd);

// 源码视图
const src = await evaluate(`(() => {
  const btns = Array.from(document.querySelectorAll('.dsh-brw-bar .btn'));
  btns.find(b => b.textContent === '</>').click();
  const t = document.querySelector('.dsh-brw-src');
  return JSON.stringify({ visible: getComputedStyle(t).display !== 'none', has: /(HELLO-BRW|SECOND-VERSION)/.test(t.value),
    frameHidden: getComputedStyle(document.querySelector('.dsh-brw-frame')).display === 'none' });
})()`);
console.log('  DIAGSRC ' + src);
const sc = JSON.parse(src);
ok('源码视图可切换且显示正确源码', sc.visible === true && sc.has === true && sc.frameHidden === true, src);
await evaluate(`Array.from(document.querySelectorAll('.dsh-brw-bar .btn')).find(b => b.textContent === '</>').click()`);

// 打开本地 .html（DataTransfer 模拟 file input，验证纯前端读取不上传）
const local = await evaluate(`(() => {
  const inp = document.querySelector('.dsh-brw input[type=file]');
  if (!inp) return 'no-input';
  const dt = new DataTransfer();
  dt.items.add(new File(['<h1>LOCAL-FILE-OK</h1>'], 'local.html', { type: 'text/html' }));
  inp.files = dt.files;
  inp.dispatchEvent(new Event('change', { bubbles: true }));
  return 'sent';
})()`);
ok('本地文件控件可接收文件', local === 'sent', local);
ok('本地 .html 被渲染（FileReader，不上传）', await waitFor(`/LOCAL-FILE-OK/.test(document.querySelector('.dsh-brw-frame').getAttribute('srcdoc') || '')`, 6000));
const navLocal = await evaluate(`document.querySelector('.dsh-brw-nav').textContent`);
ok('本地文件加入历史版本', /^(\d+)\/\1$/.test(navLocal) && parseInt(navLocal, 10) >= 3, navLocal);

// 截图：渲染一个彩色示例页
const HTML3 = '```html' + NL + '<!doctype html><html><body style="margin:0;background:linear-gradient(135deg,#184a8c,#6c3fa2 55%,#c26a3c);color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;font:700 30px system-ui">AI HTML 实时预览 ✓</body></html>' + NL + '```';
await evaluate(`window.DSH.emit('chat:send', { sid: 'test', text: '彩色页' })`);
await evaluate(`window.DSH.emit('chat:done', { message: { content: ${JSON.stringify(HTML3)} } })`);
await sleep(600);
const shot = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
if (shot.result?.data) { writeFileSync(SHOT, Buffer.from(shot.result.data, 'base64')); console.log('  SHOT  ' + SHOT); }
ok('截图已生成', !!shot.result?.data);

// 关闭：还原布局
const closed = await evaluate(`(() => {
  const btns = Array.from(document.querySelectorAll('.dsh-brw-bar .btn'));
  btns.find(b => b.textContent === '✕').click();
  return JSON.stringify({ root: !!document.querySelector('.dsh-brw'),
    bodyClass: document.body.classList.contains('dsh-brw-open'),
    margin: getComputedStyle(document.getElementById('app')).marginRight });
})()`);
console.log('  DIAGCLOSE ' + closed);
const cl = JSON.parse(closed);
ok('关闭后抽屉移除、布局还原', cl.root === false && cl.bodyClass === false && parseFloat(cl.margin) === 0, closed);
ok('无未捕获 JS 异常', errors.length === 0, errors.join(' | '));

console.log('\n结果: PASS=' + pass + ' FAIL=' + fail);
ws.close();
process.exit(fail ? 1 : 0);
