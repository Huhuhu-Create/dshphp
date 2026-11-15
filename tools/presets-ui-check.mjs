/**
 * 真实浏览器自检（Edge CDP）：系统提示词预设 + 会话级角色下拉
 * 前置：Edge headless 9222、PHP 服务器 8097，tools/_uitest-session.php 存在（验证后删除）
 */
const CDP = 'http://127.0.0.1:9222';
const BASE = 'http://127.0.0.1:8097';
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
const requests = [];
ws.onmessage = (e) => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
  if (m.method === 'Runtime.exceptionThrown') {
    const d = m.params.exceptionDetails || {};
    errors.push((d.text || '') + ' ' + ((d.exception && d.exception.description) || ''));
  }
  if (m.method === 'Network.requestWillBeSent') requests.push(String(m.params?.request?.url || ''));
};
const cmd = (method, params = {}) => new Promise((res) => { const id = ++seq; pending.set(id, res); ws.send(JSON.stringify({ id, method, params })); });
const evaluate = async (expression) => {
  const r = await cmd('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.text);
  return r.result?.result?.value;
};
const waitFor = async (expression, timeout = 15000) => {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) { try { if (await evaluate(expression)) return true; } catch (_) {} await sleep(150); }
  return false;
};

await cmd('Page.enable'); await cmd('Runtime.enable'); await cmd('Network.enable');
await cmd('Page.navigate', { url: BASE + '/tools/_uitest-session.php' });
ok('主界面已加载（登录态生效）', await waitFor('!!document.getElementById("app") && !!window.DSH_BOOT'));
ok('app:ready 已发出', await waitFor('!!window.DSH && window.DSH.history().some(r => r.name === "app:ready")', 12000));

// 1) boot 注入 7 个预设
const presets = await evaluate('JSON.stringify((window.DSH_BOOT.settings.chat.system_presets || []).map(p => p.id))');
console.log('  DIAG presets=' + presets);
ok('boot 注入 7 个预设（含 default）', await evaluate('(() => { const a = window.DSH_BOOT.settings.chat.system_presets || []; return a.length === 7 && a.some(p => p.id === "default"); })()'), presets);

// 2) 设置页预设下拉
await evaluate('document.getElementById("btn-settings").click()');
await waitFor('!document.getElementById("modal").hidden');
await evaluate('document.querySelector(\'.tab[data-tab="chat"]\').click()');
await sleep(200);
const spOpts = await evaluate('document.getElementById("set-syspreset").options.length');
ok('设置页预设下拉含 8 个选项（自定义 + 7 角色）', spOpts === 8, spOpts);
await evaluate(`(() => { const s = document.getElementById("set-syspreset"); s.value = "coder"; s.dispatchEvent(new Event("change", {bubbles:true})); })()`);
const sysVal = await evaluate('document.getElementById("set-system").value');
ok('选「编程助手」后文本框填入对应模板', /资深软件工程师/.test(sysVal), sysVal.slice(0, 60));
await evaluate('document.dispatchEvent(new KeyboardEvent("keydown", {key:"Escape"}))');
await waitFor('document.getElementById("modal").hidden');

// 3) 顶栏角色下拉
const roleOpts = await evaluate('document.getElementById("role-select").options.length');
ok('顶栏「角色」下拉含 8 个选项', roleOpts === 8, roleOpts);

// 4) 切换角色到 coder → 写入当前会话 system
const oldSid = await evaluate('window.DSH.getState().sid');
await evaluate(`(() => { const r = document.getElementById("role-select"); r.value = "coder"; r.dispatchEvent(new Event("change", {bubbles:true})); })()`);
const sys1 = await waitFor(`(async () => {
  const r = await fetch("index.php?r=api/session/get", {method:"POST",headers:{"X-CSRF-Token":window.DSH_BOOT.csrf,"Content-Type":"application/json"},body:JSON.stringify({sid:${JSON.stringify(oldSid)}})});
  const j = await r.json();
  return !!(j.session && /资深软件工程师/.test(j.session.system || ""));
})()`, 8000);
ok('切换角色写入当前会话 system', sys1 === true);

// 5) 新建对话按所选角色带上 system
await evaluate('document.getElementById("btn-new").click()');
const sidChanged = await waitFor(`window.DSH.getState().sid && window.DSH.getState().sid !== ${JSON.stringify(oldSid)}`, 8000);
const newSid = await evaluate('window.DSH.getState().sid');
ok('点击「＋ 新对话」创建新会话', sidChanged === true && newSid !== oldSid, newSid);
const sys2 = await waitFor(`(async () => {
  const r = await fetch("index.php?r=api/session/get", {method:"POST",headers:{"X-CSRF-Token":window.DSH_BOOT.csrf,"Content-Type":"application/json"},body:JSON.stringify({sid:${JSON.stringify(newSid)}})});
  const j = await r.json();
  return !!(j.session && /资深软件工程师/.test(j.session.system || ""));
})()`, 8000);
ok('新建对话按角色带上 system', sys2 === true);

// 6) 角色切回「默认」→ system 清空
await evaluate(`(() => { const r = document.getElementById("role-select"); r.value = ""; r.dispatchEvent(new Event("change", {bubbles:true})); })()`);
const sys3 = await waitFor(`(async () => {
  const r = await fetch("index.php?r=api/session/get", {method:"POST",headers:{"X-CSRF-Token":window.DSH_BOOT.csrf,"Content-Type":"application/json"},body:JSON.stringify({sid:${JSON.stringify(newSid)}})});
  const j = await r.json();
  return !!(j.session && (j.session.system || "") === "");
})()`, 8000);
ok('角色切回默认后 system 清空', sys3 === true);

ok('无未捕获 JS 异常', errors.length === 0, errors.join(' | '));

console.log('\n结果: PASS=' + pass + ' FAIL=' + fail);
ws.close();
process.exit(fail ? 1 : 0);
