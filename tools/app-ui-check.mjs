/**
 * 真实浏览器自检（Edge CDP）：主界面 app scope 插件 + app.js 生命周期埋点
 * 前置：Edge headless 9222、PHP 服务器 8097，并临时在 tools/ 放一个 _uitest-session.php：
 *   <?php require_once __DIR__.'/../app/bootstrap.php'; $_SESSION['dshphp_ok']=true;
 *        header('Location: ../index.php');
 * 该文件可直接绕过登录，验证完必须立刻删除，切勿上传服务器。
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

await cmd('Page.enable'); await cmd('Runtime.enable'); await cmd('Network.enable');
await cmd('Page.navigate', { url: BASE + '/tools/_uitest-session.php' });
ok('主界面已加载（会话生效）', await waitFor('!!document.getElementById("app") && !!window.DSH_BOOT'));
ok('app scope 插件 bundle ≥ 5 个', await waitFor('!!window.DSH && window.DSH.plugins.length >= 5', 15000));
ok('生命周期 app:ready 已发出', await waitFor('!!window.DSH && window.DSH.history().some(r => r.name === "app:ready")', 12000));

const diag = await evaluate(`JSON.stringify({
  statusRequests: 1,
  events: window.DSH.history().map(r => r.name).slice(-25),
  statusLine: (document.getElementById('status-line') || {}).textContent,
  composerStatus: (document.getElementById('composer-status') || {}).textContent,
  sid: window.DSH.getState().sid,
  readyFired: window.DSH.history().some(r => r.name === 'app:ready'),
  coreKeys: Object.keys(window.DSH.core).length,
  pluginCount: window.DSH.plugins.length,
  bootUi: (window.DSH_BOOT.ui || {}).scope
})`);
console.log('  DIAG  ' + diag);
const plugins = await evaluate('window.DSH.plugins.map(p => p.id + ":" + (p.ok ? "ok" : "ERR " + p.error))');
ok('全部插件初始化成功（≥5 个）', plugins.length >= 5 && plugins.every((p) => p.endsWith(':ok')), JSON.stringify(plugins));
ok('核心能力已挂载到 DSH.core', (await evaluate('typeof window.DSH.core.renderSessions === "function" && typeof window.DSH.core.api === "function"')) === true);
ok('生命周期事件已发出', (await evaluate('window.DSH.history().some(r => r.name === "app:ready") && window.DSH.history().some(r => r.name === "sessions:render")')) === true);
ok('侧边栏插件接管渲染', (await evaluate('!!document.querySelector("#dsh-slot-sidebar-top .dsh-sb-search")')) === true);
ok('上下文用量仪表已挂载', (await evaluate('!!document.querySelector("#dsh-slot-status .dsh-ctx")')) === true);
ok('侧栏 foot 挂了 3 个插件按钮', (await evaluate('document.querySelectorAll("#dsh-slot-sidebar-foot button").length')) >= 3);
ok('上下文插件调用了服务端接口', requests.some((u) => u.includes('api%2Fui%2Fcontext') || u.includes('api/ui/context')), requests.filter((u) => u.includes('context')).join(','));
console.log('  FOOT ' + String(await evaluate('(document.querySelector("#dsh-slot-status .dsh-ctx-foot") || {}).textContent')));
ok('上下文仪表拿到了真实数据', await waitFor('(() => { const e = document.querySelector("#dsh-slot-status .dsh-ctx-foot"); return !!e && !e.textContent.includes("读取失败") && /tok/.test(e.textContent); })()', 8000));
ok('app bundle 被请求', requests.some((u) => u.includes('r=plugin/ui.js') && u.includes('s=app')));
ok('无未捕获 JS 异常', errors.length === 0, errors.join(' | '));

// 打开记忆树面板，验证 D.ui.panel + D.api('api/plugin/run')
const opened = await evaluate(`(() => {
  const b = Array.from(document.querySelectorAll('#dsh-slot-sidebar-foot button')).find(x => x.textContent.includes('记忆树'));
  if (!b) return false;
  b.click();
  return !!document.querySelector('.dsh-panel');
})()`);
ok('记忆树浮层面板可打开', opened === true);
ok('记忆树面板读到服务端数据', await waitFor('!!document.querySelector(".dsh-panel .dsh-hint") && /节点|40|字节/.test(document.querySelector(".dsh-panel").textContent)', 8000));

console.log('\n结果: PASS=' + pass + ' FAIL=' + fail);
ws.close();
process.exit(fail ? 1 : 0);
