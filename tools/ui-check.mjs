/**
 * 网页端真实浏览器自检（无第三方依赖，走 Edge/Chrome 的 CDP 协议）
 *
 * 前置：
 *   1) Edge 以 --headless=new --remote-debugging-port=9222 --remote-allow-origins=* 启动
 *   2) dshphp 已安装且 PHP 服务器可通过 http://127.0.0.1:8766 访问
 * 运行：node tools/ui-check.mjs
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
ws.onmessage = (e) => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
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
await cmd('Emulation.setDeviceMetricsOverride', { width: 1200, height: 900, deviceScaleFactor: 1, mobile: false });
await cmd('Page.navigate', { url: BASE + '/ping.php' });
await sleep(500);
await evaluate(`fetch(${JSON.stringify(BASE + '/index.php?r=api/login')}, {method:'POST',
  headers:{'Content-Type':'application/json','Accept':'application/json'},
  body:JSON.stringify({password:${JSON.stringify(PW)}})}).then(r=>r.text())`);
await cmd('Page.navigate', { url: BASE + '/index.php' });
ok('工作台页面加载完成', await waitFor('!!(window.DSH_BOOT && document.querySelector("#btn-market"))'));

const boot = await evaluate('window.DSH_BOOT');
ok('首屏含市场统计', boot?.market?.total >= 40, JSON.stringify(boot?.market));
ok('首屏插件列表已渲染', (await evaluate('document.querySelectorAll("#plugin-list .item[data-id]").length')) > 0);

await evaluate('document.querySelector("#btn-market").click()');
await sleep(300);
ok('市场弹窗已打开', await evaluate('!document.querySelector("#modal").hidden'));
ok('市场页签激活', await evaluate('document.querySelector(".tab-pane[data-pane=\\"market\\"]").classList.contains("active")'));
ok('弹窗切换为宽版', await evaluate('document.querySelector("#modal-card").classList.contains("wide")'));
ok('市场卡片全部渲染', await waitFor('document.querySelectorAll("#market-grid .mkt-card").length >= 40'));
const count = await evaluate('document.querySelectorAll("#market-grid .mkt-card").length');
ok('卡片数等于插件总数', count === boot.market.total, count);
const geom = await evaluate(`(()=>{const g=document.querySelector('#market-grid').getBoundingClientRect(),
  b=document.querySelector('.modal-body').getBoundingClientRect();
  return {visible:Math.round(Math.min(g.bottom,b.bottom)-Math.max(g.top,b.top)),gridH:Math.round(g.height)};})()`);
ok('卡片区在弹窗首屏可见', geom.visible >= 180, JSON.stringify(geom));
ok('分类筛选按钮已渲染', (await evaluate('document.querySelectorAll("#market-cats .market-chip").length')) >= 9);
ok('统计条有内容', /\d+ 个插件/.test(String(await evaluate('document.querySelector("#market-stats").textContent'))));

await evaluate(`(()=>{const i=document.querySelector('#market-search');i.value='摩斯';i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
await sleep(100);
const searched = await evaluate('document.querySelectorAll("#market-grid .mkt-card").length');
ok('搜索「摩斯」命中 1 个', searched === 1, searched);
await evaluate(`(()=>{const i=document.querySelector('#market-search');i.value='';i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
await evaluate('document.querySelector("#market-cats [data-cat=\\"fun\\"]").click()');
await sleep(100);
const funCount = await evaluate('document.querySelectorAll("#market-grid .mkt-card").length');
ok('趣味分类筛选为 5 个', funCount === 5, funCount);
await evaluate('document.querySelector("#market-cats [data-cat=\\"all\\"]").click()');

const targetId = await evaluate(`(()=>{const c=[...document.querySelectorAll('#market-grid .mkt-card')].find(x=>!x.classList.contains('installed'));return c?c.dataset.id:null})()`);
ok('存在未安装插件可供测试', !!targetId, targetId);
if (targetId) {
  const before = await evaluate('document.querySelectorAll("#plugin-list .item[data-id]").length');
  await evaluate(`document.querySelector('#market-grid .mkt-card[data-id="${targetId}"] [data-act="install"]').click()`);
  await sleep(800);
  ok('安装后卡片变为已安装', await evaluate(`document.querySelector('#market-grid .mkt-card[data-id="${targetId}"]').classList.contains('installed')`));
  const after = await evaluate('document.querySelectorAll("#plugin-list .item[data-id]").length');
  ok('侧边栏插件列表同步增加', after === before + 1, `${before} → ${after}`);
  ok('操作结果有反馈', /已安装/.test(String(await evaluate('document.querySelector("#market-result").textContent'))));
  await evaluate(`document.querySelector('#market-grid .mkt-card[data-id="${targetId}"] [data-act="uninstall"]').click()`);
  await sleep(800);
  ok('卸载后恢复未安装', await evaluate(`!document.querySelector('#market-grid .mkt-card[data-id="${targetId}"]').classList.contains('installed')`));
}

await evaluate('document.querySelector(".modal-body").scrollTop = 0');
await sleep(150);
const shot = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
if (shot.result?.data) {
  const fs = await import('node:fs');
  fs.writeFileSync('tools/market-ui.png', Buffer.from(shot.result.data, 'base64'));
  console.log('  截图：tools/market-ui.png');
}
console.log('\n────────────────────────────');
console.log(`结果：${pass} 通过 / ${fail} 失败`);
ws.close();
process.exit(fail ? 1 : 0);
