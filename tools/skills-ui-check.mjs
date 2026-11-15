/**
 * 真实浏览器自检（Edge CDP）：技能列表 16 个 + 常驻/按需/关闭三态循环 + 服务端同步
 * 前置：Edge headless 9222、PHP 服务器 8097、tools/_uitest-session.php 临时存在（验证完删除）。
 * 运行：node tools/skills-ui-check.mjs
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
await cmd('Page.navigate', { url: BASE + '/tools/_uitest-session.php' });
await sleep(700);
await cmd('Page.navigate', { url: BASE + '/index.php' });
ok('主界面已加载', await waitFor('!!document.getElementById("app") && !!window.DSH_BOOT'));
ok('技能面板渲染完成', await waitFor('document.querySelectorAll("#skill-list .item[data-id]").length >= 16'));

const read = () => evaluate(`JSON.stringify(Array.from(document.querySelectorAll('#skill-list .item[data-id]')).map(el => ({
  id: el.dataset.id, sub: (el.querySelector('.sub')||{}).textContent || '',
  dot: (el.querySelector('.dot')||{}).className || '', title: el.title || ''
})))`);
const items = JSON.parse(await read());
ok('技能卡片 = 16 个', items.length === 16, String(items.length));
ok('2 个老技能显示「常驻」', items.filter((x) => x.sub === '常驻').length === 2,
  JSON.stringify(items.filter((x) => x.sub === '常驻').map((x) => x.id)));
ok('14 个新技能显示「按需」且为琥珀圆点',
  items.filter((x) => x.sub === '按需' && /dot auto/.test(x.dot)).length === 14,
  String(items.filter((x) => x.sub === '按需').length));
const hp = items.find((x) => x.id === 'html-preview');
ok('按需技能 title 带触发词', !!hp && /触发词/.test(hp.title) && /html/.test(hp.title), hp && hp.title);
ok('boot 里技能带 mode 字段', await evaluate(`window.DSH_BOOT.skills.every(s => !!s.mode && typeof s.auto === 'boolean')`));

const stateOf = (id) => evaluate(`(() => { const el = document.querySelector('#skill-list .item[data-id="${id}"]');
  return el ? (el.querySelector('.sub').textContent + '|' + el.querySelector('.dot').className) : ''; })()`);
async function click(id) {
  await evaluate(`document.querySelector('#skill-list .item[data-id="${id}"]').click()`);
  await sleep(450);
  return stateOf(id);
}

// 三态循环：按需 → 关闭 → 常驻 → 按需
const ID = 'regex-helper';
ok('初始为按需', /^按需\|.*dot auto/.test(await stateOf(ID)), await stateOf(ID));
ok('点击 1：按需 → 关闭', /^关闭\|.*dot off/.test(await click(ID)), await stateOf(ID));
ok('关闭后服务端 mode=off', await evaluate(`(async () => {
  const j = await (await fetch('index.php?r=api/skill/list')).json();
  return (j.skills.find(s => s.id === '${ID}') || {}).mode === 'off';
})()`));
ok('点击 2：关闭 → 常驻', /^常驻\|.*dot on/.test(await click(ID)), await stateOf(ID));
ok('点击 3：常驻 → 按需（auto 清掉显式开关）', /^按需\|.*dot auto/.test(await click(ID)), await stateOf(ID));
ok('复位后服务端 mode=auto', await evaluate(`(async () => {
  const j = await (await fetch('index.php?r=api/skill/list')).json();
  return (j.skills.find(s => s.id === '${ID}') || {}).mode === 'auto';
})()`));

// 老技能只有两态，不应出现「按需」
const OLD = 'code-review';
ok('老技能点击 1：常驻 → 关闭', /^关闭\|/.test(await click(OLD)), await stateOf(OLD));
ok('老技能点击 2：关闭 → 常驻（不进按需）', /^常驻\|/.test(await click(OLD)), await stateOf(OLD));

ok('零 JS 异常', errors.length === 0, errors.join(' | ').slice(0, 200));
console.log(`\nSKILLS-UI: ${pass} 通过 / ${fail} 失败`);
ws.close();
process.exit(fail === 0 ? 0 : 1);
