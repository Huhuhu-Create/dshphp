/**
 * 真实浏览器自检（Edge CDP）：透明玻璃插件是否覆盖 .main 中间聊天区
 * 前置：Edge headless 9222、PHP 服务器 8097、tools/_uitest-session.php 临时存在，
 *       且本机已安装 mkt.glass（验证后必须删除临时会话文件并恢复安装状态）。
 */
import { writeFileSync } from 'node:fs';
const CDP = 'http://127.0.0.1:9222';
const BASE = 'http://127.0.0.1:8097';
const SHOT = 'D:/workbuddyfile/2026-11-11-17-45-31/glass-clear-preview.png';
let pass = 0, fail = 0;
const ok = (n, c, x = '') => { if (c) { pass++; console.log('  PASS  ' + n); } else { fail++; console.log('  FAIL  ' + n + (x ? '  → ' + String(x).slice(0, 240) : '')); } };
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
const waitFor = async (expression, timeout = 15000) => {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) {
    try { if (await evaluate(expression)) return true; } catch (_) {}
    await sleep(150);
  }
  return false;
};

await cmd('Page.enable'); await cmd('Runtime.enable');
await cmd('Emulation.setDeviceMetricsOverride', { width: 1000, height: 720, deviceScaleFactor: 1, mobile: false });

// 清掉旧设置，保证使用插件默认（背景图完全清晰 + 面板轻微毛玻璃）
await cmd('Page.navigate', { url: BASE + '/tools/_uitest-session.php' });
ok('主界面已加载（不是登录页）', await waitFor('!!document.getElementById("app") && !!window.DSH_BOOT && !document.body.classList.contains("login-body")'));
await evaluate(`['dsh.ui.mkt_glass.cfg','dsh.ui._global.cfg'].forEach(k => localStorage.removeItem(k))`);
await cmd('Page.navigate', { url: BASE + '/index.php' });
ok('玻璃插件已初始化', await waitFor('!!window.DSH && window.DSH.plugins.some(p => p.id === "mkt.glass" && p.ok)', 15000));
// DSH.ready 回调要等 app:ready（init 的 api/status 或 2.5s 兜底）才执行，必须等它
ok('app:ready 已发出（插件 apply 已跑）', await waitFor('!!window.DSH && window.DSH.history().some(r => r.name === "app:ready") && document.body.classList.contains("dsh-glass-on")', 12000));

const base = await evaluate(`JSON.stringify({
  bodyClass: document.body.classList.contains('dsh-glass-on'),
  mainBg: getComputedStyle(document.querySelector('.main')).backgroundColor,
  rootVar: getComputedStyle(document.documentElement).getPropertyValue('--dsh-glass-main').trim(),
  messagesBg: getComputedStyle(document.querySelector('.messages')).backgroundColor,
  beforeBg: getComputedStyle(document.body, '::before').backgroundImage,
  beforeFilter: getComputedStyle(document.body, '::before').filter,
  beforeTransform: getComputedStyle(document.body, '::before').transform,
  blurVar: getComputedStyle(document.documentElement).getPropertyValue('--dsh-glass-blur').trim(),
  panelVar: getComputedStyle(document.documentElement).getPropertyValue('--dsh-glass-panel-blur').trim(),
  sideBackdrop: getComputedStyle(document.querySelector('.sidebar')).backdropFilter,
  cfg: localStorage.getItem('dsh.ui.mkt_glass.cfg'),
  keys: Object.keys(localStorage)
})`);
console.log('  DIAG  ' + base);
const b = JSON.parse(base);
ok('body 已挂 dsh-glass-on', b.bodyClass === true);
ok('.main 背景为半透明（不再是不透明 var(--bg)）', /^rgba\(/.test(b.mainBg) && !/, ?1\)$/.test(b.mainBg) && /0\.38/.test(b.mainBg), b.mainBg);
ok('CSS 变量 --dsh-glass-main 已注入', b.rootVar === 'rgba(16,21,29,0.38)', b.rootVar);
ok('.messages 保持全透明', b.messagesBg === 'rgba(0, 0, 0, 0)', b.messagesBg);
ok('背景图默认完全清晰（--dsh-glass-blur = 0px）', b.blurVar === '0px' && /blur\(0px\)/.test(b.beforeFilter), b.blurVar + ' / ' + b.beforeFilter);
ok('面板毛玻璃降到 4px（不再是 14px 双重模糊）', b.panelVar === '4px' && /blur\(4px\)/.test(b.sideBackdrop), b.panelVar + ' / ' + b.sideBackdrop);
ok('清晰时不再放大裁掉图片边缘', /matrix\(1, 0, 0, 1,/.test(b.beforeTransform) || b.beforeTransform === 'none', b.beforeTransform);

// 打开「◐ 外观」，验证新增滑杆并调到 0%（完全透出背景）
const opened = await evaluate(`(() => {
  const btn = Array.from(document.querySelectorAll('#dsh-slot-sidebar-foot button')).find(x => x.textContent.includes('外观'));
  if (!btn) return false; btn.click(); return !!document.querySelector('.dsh-panel');
})()`);
ok('外观面板可打开', opened === true);
const hasSlider = await evaluate(`(() => {
  const row = Array.from(document.querySelectorAll('.dsh-panel .dsh-glass-row')).find(x => x.textContent.includes('主区浓度'));
  return !!row && !!row.querySelector('input[type=range]');
})()`);
ok('新增「主区浓度」滑杆', hasSlider === true);
const after = await evaluate(`(() => {
  const row = Array.from(document.querySelectorAll('.dsh-panel .dsh-glass-row')).find(x => x.textContent.includes('主区浓度'));
  const inp = row.querySelector('input[type=range]');
  inp.value = '0'; inp.dispatchEvent(new Event('input', { bubbles: true }));
  return JSON.stringify({ bg: getComputedStyle(document.querySelector('.main')).backgroundColor,
    label: row.querySelector('b').textContent, cfg: localStorage.getItem('dsh.ui.mkt_glass.cfg'),
    keys: Object.keys(localStorage) });
})()`);
console.log('  DIAG0 ' + after);
const a0 = JSON.parse(after);
ok('滑杆调到 0% 后 .main 完全透明', /, ?0\)$/.test(a0.bg), a0.bg);
ok('滑杆数值带 % 单位', a0.label === '0%', a0.label);
ok('新设置写入 localStorage（含 main 字段）', /"main":0/.test(a0.cfg), a0.cfg);

// 「玻璃模糊」与「背景模糊」必须互不干扰
const panelSlider = await evaluate(`(() => {
  const row = Array.from(document.querySelectorAll('.dsh-panel .dsh-glass-row')).find(x => x.textContent.includes('玻璃模糊'));
  if (!row || !row.querySelector('input[type=range]')) return 'missing';
  const inp = row.querySelector('input[type=range]');
  inp.value = '10'; inp.dispatchEvent(new Event('input', { bubbles: true }));
  return JSON.stringify({
    panelVar: getComputedStyle(document.documentElement).getPropertyValue('--dsh-glass-panel-blur').trim(),
    blurVar: getComputedStyle(document.documentElement).getPropertyValue('--dsh-glass-blur').trim(),
    label: row.querySelector('b').textContent
  });
})()`);
console.log('  DIAGP ' + panelSlider);
const ps = panelSlider === 'missing' ? {} : JSON.parse(panelSlider);
ok('新增独立「玻璃模糊」滑杆', panelSlider !== 'missing' && ps.label === '10px', panelSlider);
ok('调面板模糊不会把背景图也糊掉', ps.panelVar === '10px' && ps.blurVar === '0px', panelSlider);
// 还原面板模糊，恢复默认后再继续
await evaluate(`(() => {
  const row = Array.from(document.querySelectorAll('.dsh-panel .dsh-glass-row')).find(x => x.textContent.includes('玻璃模糊'));
  const inp = row.querySelector('input[type=range]'); inp.value = '4'; inp.dispatchEvent(new Event('input', { bubbles: true }));
})()`);

// 1) 旧配置（无 main 字段）重载后应自动补 38%，不能出现 NaN
await evaluate(`(() => { const k='dsh.ui.mkt_glass.cfg'; const c=JSON.parse(localStorage.getItem(k)||'{}'); delete c.main; localStorage.setItem(k, JSON.stringify(c)); })()`);
await cmd('Page.navigate', { url: BASE + '/index.php' });
ok('无 main 字段的旧配置重载后不炸', await waitFor('!!window.DSH && window.DSH.plugins.some(p => p.id === "mkt.glass" && p.ok) && window.DSH.history().some(r => r.name === "app:ready") && document.body.classList.contains("dsh-glass-on")', 15000));
const legacy = await evaluate(`JSON.stringify({ bg: getComputedStyle(document.querySelector('.main')).backgroundColor,
  varVal: getComputedStyle(document.documentElement).getPropertyValue('--dsh-glass-main').trim() })`);
console.log('  DIAGL ' + legacy);
const lg = JSON.parse(legacy);
ok('旧配置自动补默认 38%', /0\.38/.test(lg.bg) && !/NaN/.test(lg.varVal), legacy);

// 2) 2.4.0 早期版本把设置写在 dsh.ui._global.cfg，升级后必须自动迁移，不能丢背景图
await evaluate(`(() => {
  localStorage.removeItem('dsh.ui.mkt_glass.cfg');
  localStorage.setItem('dsh.ui._global.cfg', JSON.stringify({ on:true, blur:12, bright:0.85, main:70, image:'https://example.com/wall.png' }));
})()`);
await cmd('Page.navigate', { url: BASE + '/index.php' });
ok('旧全局设置迁移后插件正常', await waitFor('!!window.DSH && window.DSH.plugins.some(p => p.id === "mkt.glass" && p.ok) && window.DSH.history().some(r => r.name === "app:ready")', 15000));
const migrated = await evaluate(`JSON.stringify({
  varVal: getComputedStyle(document.documentElement).getPropertyValue('--dsh-glass-main').trim(),
  blurVar: getComputedStyle(document.documentElement).getPropertyValue('--dsh-glass-blur').trim(),
  panelVar: getComputedStyle(document.documentElement).getPropertyValue('--dsh-glass-panel-blur').trim(),
  scoped: localStorage.getItem('dsh.ui.mkt_glass.cfg'),
  beforeBg: getComputedStyle(document.body, '::before').backgroundImage
})`);
console.log('  DIAGM ' + migrated);
const mg = JSON.parse(migrated);
ok('旧全局设置自动迁移到插件专属存储', /"main":70/.test(mg.scoped || '') && /example\.com/.test(mg.beforeBg), migrated);
ok('迁移后主区浓度沿用 70%', /0\.70/.test(mg.varVal), mg.varVal);
ok('旧版 12px 全图模糊迁移为 0px（刷新即清晰）', mg.blurVar === '0px' && /"blur":0/.test(mg.scoped || ''), migrated);
ok('迁移后面板毛玻璃为 4px', mg.panelVar === '4px', mg.panelVar);
// 清掉测试用的外链，避免残留
await evaluate(`localStorage.removeItem('dsh.ui._global.cfg'); localStorage.removeItem('dsh.ui.mkt_glass.cfg');`);

// 视觉验证：塞高对比细条纹 + 彩色渐变，若被模糊条纹会糊成一片
await evaluate(`(() => {
  const s = document.createElement('style');
  s.id = '__glass_visual_test';
  s.textContent = 'body.dsh-glass-on::before{background-image:repeating-linear-gradient(90deg,rgba(255,255,255,.16) 0 2px,transparent 2px 26px),linear-gradient(120deg,#184a8c 0%,#6c3fa2 45%,#c26a3c 100%)!important;filter:brightness(.9)!important}';
  document.head.appendChild(s);
  const p = document.querySelector('.dsh-panel'); if (p) p.remove();
})()`);
await sleep(400);
const shot = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
if (shot.result?.data) { writeFileSync(SHOT, Buffer.from(shot.result.data, 'base64')); console.log('  SHOT  ' + SHOT); }
ok('截图已生成', !!shot.result?.data);
ok('无未捕获 JS 异常', errors.length === 0, errors.join(' | '));

console.log('\n结果: PASS=' + pass + ' FAIL=' + fail);
ws.close();
process.exit(fail ? 1 : 0);
