/**
 * 自动化任务面板 · 真实浏览器自检（Edge CDP，无第三方依赖）
 *
 * 前置：
 *   1) Edge 以 --headless=new --remote-debugging-port=9222 --remote-allow-origins=* 启动
 *   2) dshphp 已安装，PHP 服务器可通过 http://127.0.0.1:8766 访问
 * 运行：node tools/auto-ui-check.mjs
 * 环境变量：CDP / BASE / DSH_PW
 */
const CDP = process.env.CDP || 'http://127.0.0.1:9222';
const BASE = process.env.BASE || 'http://127.0.0.1:8766';
const PW = process.env.DSH_PW || 'testpass123';
let pass = 0, fail = 0;
const ok = (n, c, x = '') => {
  if (c) { pass++; console.log('  PASS  ' + n); }
  else { fail++; console.log('  FAIL  ' + n + (x ? '  → ' + String(x).slice(0, 300) : '')); }
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const list = await (await fetch(CDP + '/json/list')).json();
let tab = list.find((t) => t.type === 'page');
if (!tab) tab = await (await fetch(CDP + '/json/new?about:blank', { method: 'PUT' })).json();
const ws = new WebSocket(tab.webSocketDebuggerUrl);
await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
let seq = 0;
const pending = new Map();
const errors = [];
const netReqs = [];
ws.onmessage = (e) => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
  if (m.method === 'Runtime.exceptionThrown') {
    const d = m.params.exceptionDetails || {};
    errors.push((d.text || '') + ' ' + ((d.exception && d.exception.description) || ''));
  }
  if (m.method === 'Network.requestWillBeSent') netReqs.push(String(m.params?.request?.url || ''));
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
    await sleep(150);
  }
  return false;
};

await cmd('Page.enable');
await cmd('Runtime.enable');
await cmd('Network.enable');
await cmd('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
const PREVIEW = process.env.DSH_PREVIEW || '';
if (PREVIEW) {
  // 开发期：用临时预览页建立已登录会话，避免改动 config.php 里的密码哈希
  await cmd('Page.navigate', { url: BASE + '/' + PREVIEW });
  ok('预览页已建立会话', await waitFor('!!window.DSH_BOOT', 15000));
} else {
  await cmd('Page.navigate', { url: BASE + '/ping.php' });
  await sleep(500);
  const login = await evaluate(`fetch(${JSON.stringify(BASE + '/index.php?r=api/login')}, {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify({ password: ${JSON.stringify(PW)} }), credentials: 'same-origin'
  }).then(r => r.json())`);
  ok('登录成功', !!(login && login.ok), JSON.stringify(login));
  await cmd('Page.navigate', { url: BASE + '/index.php' });
}
ok('工作台加载完成', await waitFor('!!window.DSH_BOOT && !!document.querySelector("#auto-grid")', 15000));
ok('首屏注入 10 个自动化任务', (await evaluate('(window.DSH_BOOT.automation.tasks || []).length')) === 10);
ok('面板 DOM 存在', await evaluate('!!document.querySelector("#auto-grid")'));

await evaluate(`document.querySelector('.tab[data-tab="automation"]').click()`);
ok('页签切换到自动化', await evaluate('document.querySelector(".tab-pane[data-pane=\\"automation\\"]").classList.contains("active")'));
ok('渲染 10 张任务卡片',
  await waitFor('document.querySelectorAll("#auto-grid .auto-card").length >= 10', 10000),
  await evaluate('document.querySelectorAll("#auto-grid .auto-card").length'));
ok('驱动说明显示「仅访问时执行」',
  /仅访问时执行/.test(String(await evaluate('document.querySelector(".auto-driver") && document.querySelector(".auto-driver").textContent'))));
ok('面板不再提供外部心跳 URL / 密钥',
  await evaluate('!document.querySelector("#auto-url") && !document.querySelector("#btn-auto-copy")'));
ok('首屏标记为访问驱动且无保活',
  await evaluate('window.DSH_BOOT.automation.stats.driver === "access-only" && window.DSH_BOOT.automation.stats.keepalive === false && !window.DSH_BOOT.automation.stats.token'),
  JSON.stringify(await evaluate('window.DSH_BOOT.automation.stats')));
ok('存储信息显示访问驱动',
  /仅访问时推进/.test(String(await evaluate('document.querySelector("#auto-hint").textContent'))));
ok('统计条已渲染', (await evaluate('document.querySelectorAll("#auto-stats .auto-stat-item").length')) >= 5);
ok('运行日志已渲染', (await evaluate('document.querySelectorAll("#auto-logs .auto-log").length')) > 0);
ok('动作类型下拉已填充', (await evaluate('document.querySelector("#auto-f-type").options.length')) >= 7);

// 静置 4.2 秒：旧版前端 3 秒后会打第一次心跳，新版应完全没有后台请求
netReqs.length = 0;
await sleep(4200);
ok('页面静置时不发起任何后台心跳',
  !netReqs.some((u) => u.indexOf('api/automation/tick') >= 0),
  netReqs.join(' | ').slice(0, 300));

await evaluate(`document.querySelector('#auto-grid [data-act="edit"]').click()`);
ok('编辑表单回填任务 ID', await evaluate(
  'document.querySelector("#auto-f-id").readOnly && document.querySelector("#auto-f-id").value.indexOf("auto.") === 0'));
await evaluate(`document.querySelector('#btn-auto-reset').click()`);
ok('清空表单解除 ID 只读', await evaluate('!document.querySelector("#auto-f-id").readOnly'));

await evaluate(`document.querySelector('#btn-auto-tick').click()`);
await sleep(1400);
ok('立即推进成功', String(await evaluate('document.querySelector("#auto-result").textContent')).indexOf('已推进') >= 0,
  await evaluate('document.querySelector("#auto-result").textContent'));

ok('无 JS 运行时异常', errors.length === 0, errors.join(' | ').slice(0, 400));

console.log('\n' + pass + ' 通过 / ' + fail + ' 失败');
ws.close();
process.exit(fail ? 1 : 0);
