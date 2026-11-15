/**
 * 真实浏览器自检（Edge CDP）：登录页 GUI 运行时 + login scope 插件执行 + 手势控件交互
 * 前置：Edge headless 9222、PHP 服务器 8097。运行：node tools/runtime-ui-check.mjs
 */
const CDP = 'http://127.0.0.1:9222';
const BASE = 'http://127.0.0.1:8097';
let pass = 0, fail = 0;
const ok = (n, c, x = '') => { if (c) { pass++; console.log('  PASS  ' + n); } else { fail++; console.log('  FAIL  ' + n + (x ? '  → ' + String(x).slice(0, 200) : '')); } };
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
const cmd = (method, params = {}) => new Promise((res) => {
  const id = ++seq; pending.set(id, res);
  ws.send(JSON.stringify({ id, method, params }));
});
const evaluate = async (expression) => {
  const r = await cmd('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.text);
  return r.result?.result?.value;
};
const waitFor = async (expression, timeout = 10000) => {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) {
    try { if (await evaluate(expression)) return true; } catch (_) {}
    await sleep(120);
  }
  return false;
};

await cmd('Page.enable'); await cmd('Runtime.enable'); await cmd('Network.enable');
await cmd('Page.navigate', { url: BASE + '/index.php?r=logout' });
await sleep(300);
await cmd('Page.navigate', { url: BASE + '/index.php' });
ok('页面加载出 DSH 运行时', await waitFor('!!window.DSH && !!window.DSH_BOOT'));
ok('插件 bundle 执行完毕', await waitFor('!!window.DSH && window.DSH.plugins.length >= 2', 15000));

const plugins = await evaluate('window.DSH.plugins.map(p => p.id + ":" + (p.ok ? "ok" : "ERR " + p.error))');
ok('login scope 插件 = 2 个', plugins.length === 2, JSON.stringify(plugins));
ok('两个插件都初始化成功', plugins.length === 2 && plugins.every((p) => p.endsWith(':ok')), JSON.stringify(plugins));
ok('手势点阵渲染 9 个点', (await evaluate('document.querySelectorAll("#dsh-slot-login .dsh-gesture-dot").length')) === 9);
ok('玻璃样式已应用', (await evaluate('document.body.classList.contains("dsh-glass-on")')) === true);
ok('动态 CSS 已注入', (await evaluate('!!document.querySelector("style[id^=dsh-ui-style-]")')) === true);
ok('匿名 login bundle 被请求', requests.some((u) => u.includes('r=plugin/ui.js') && u.includes('s=login')), requests.filter((u) => u.includes('plugin/ui')).join(','));
ok('匿名 login css 被请求', requests.some((u) => u.includes('r=plugin/ui.css') && u.includes('s=login')));

// 模拟画 4 个点的手势，验证写入 name=password 的轨迹
const gestureValue = await evaluate(`(() => {
  const dots = document.querySelectorAll('#dsh-slot-login .dsh-gesture-dot');
  dots[0].dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
  for (let i = 1; i < 4; i++) dots[i].dispatchEvent(new PointerEvent('pointerover', { bubbles: true }));
  document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
  return document.getElementById('pw').value;
})()`);
ok('手势轨迹写入密码字段', typeof gestureValue === 'string' && gestureValue.startsWith('g:') && gestureValue.split('-').length >= 4, String(gestureValue));
ok('手势高亮 4 个点', (await evaluate('document.querySelectorAll("#dsh-slot-login .dsh-gesture-dot.on").length')) === 4);

ok('无未捕获 JS 异常', errors.length === 0, errors.join(' | '));
console.log('\n结果: PASS=' + pass + ' FAIL=' + fail);
ws.close();
process.exit(fail ? 1 : 0);
