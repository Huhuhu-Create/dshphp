/**
 * dshphp 2.0 端到端自检
 * 前置：php -n -S 127.0.0.1:8766 -t .  与  node tools/mock-llm.mjs 均已启动
 * 运行：node tools/selfcheck.mjs
 */
import { createHash } from 'node:crypto';
const BASE = process.env.BASE || 'http://127.0.0.1:8766';
const MOCK = process.env.MOCK || 'http://127.0.0.1:18765';
let cookie = '', csrf = '';
let pass = 0, fail = 0;

function ok(name, cond, extra = '') {
  if (cond) { pass++; console.log('  PASS  ' + name); }
  else { fail++; console.log('  FAIL  ' + name + (extra ? '  → ' + String(extra).slice(0, 400) : '')); }
}
function section(t) { console.log('\n=== ' + t + ' ==='); }

async function request(path, opt = {}) {
  const headers = Object.assign({}, opt.headers || {});
  const explicit = (k) => Object.prototype.hasOwnProperty.call(opt.headers || {}, k);
  if (cookie && !explicit('Cookie')) headers.Cookie = cookie;
  if (csrf && opt.csrf !== false && !explicit('X-CSRF-Token')) headers['X-CSRF-Token'] = csrf;
  let body;
  if (opt.form) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(opt.form).toString(); }
  else if (opt.body !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(opt.body); }
  const r = await fetch(BASE + '/' + path, {
    method: opt.method || (body ? 'POST' : 'GET'),
    headers, body, redirect: opt.redirect || 'follow',
  });
  const sc = r.headers.get('set-cookie');
  // 显式指定 Cookie（含空串）的匿名请求，不能用它的匿名 session 覆盖已登录的 cookie
  if (sc && !explicit('Cookie')) { const m = /DSHPHP2=([^;]+)/.exec(sc); if (m) cookie = 'DSHPHP2=' + m[1]; }
  const text = await r.text();
  let json = null;
  try { json = JSON.parse(text); } catch {}
  return { status: r.status, text, json, headers: r.headers };
}
const api = (route, body = {}, opt = {}) => request('index.php?r=' + encodeURIComponent(route), Object.assign({ body }, opt));

async function main() {
  await fetch(MOCK + '/_reset');

  section('安装流程');
  let r = await request('ping.php');
  ok('ping.php 返回 PING-OK', r.status === 200 && r.text.includes('PING-OK'));
  ok('ping.php 不泄露随机数据文件名', !/vq_[0-9a-f]{20,}/.test(r.text));
  r = await request('install.php');
  ok('未安装时显示安装向导', r.text.includes('安装向导') && r.text.includes('环境检查'));

  r = await request('install.php', {
    form: {
      admin_password: 'testpass123', api_key: 'mykey123456', route: 'custom',
      base_url: MOCK + '/v1', model: 'mock-model', auth_mode: 'bearer',
      llm_key: 'sk-mock', driver: 'json', system_prompt: '你是测试用助手，请简洁回答。',
    },
  });
  ok('提交安装表单成功', r.text.includes('安装完成'), r.text.slice(0, 200));
  ok('安装结果不打印数据文件名', !/vq_[0-9a-f]{20,}/.test(r.text));
  const fs = await import('node:fs');
  ok('config.php 已生成', fs.existsSync('config.php'));
  ok('app/storage.php 已生成', fs.existsSync('app/storage.php'));
  r = await request('install.php');
  ok('已安装后拒绝重复安装', r.text.includes('已经安装过了'));

  section('登录与页面');
  r = await request('index.php');
  ok('未登录显示登录页', r.text.includes('dshphp · 登录') && r.text.includes('管理密码'));
  r = await request('index.php?r=api/login', { body: { password: 'wrongpass' }, headers: { Accept: 'application/json' } });
  ok('错误密码返回 401', r.status === 401 && r.json && r.json.ok === false);
  r = await request('index.php?r=api/login', { body: { password: 'testpass123' }, headers: { Accept: 'application/json' } });
  ok('正确密码登录成功', r.status === 200 && r.json && r.json.ok === true);
  ok('下发了会话 Cookie', cookie.startsWith('DSHPHP2='));
  r = await request('index.php');
  ok('登录后渲染工作台', r.text.includes('DSH · 服务器版') && r.text.includes('window.DSH_BOOT'));
  const m = /window\.DSH_BOOT = (.*?);<\/script>/s.exec(r.text);
  ok('首屏内嵌配置可解析', !!m);
  let boot = {};
  if (m) { try { boot = JSON.parse(m[1]); } catch (e) {} }
  csrf = boot.csrf || '';
  ok('CSRF 令牌存在', csrf.length >= 32);
  ok('页面不泄露数据文件名', !/vq_[0-9a-f]{20,}/.test(r.text));
  r = await request('assets/app.js');
  ok('app.js 200', r.status === 200 && r.text.includes('dshphp 2.0'));
  r = await request('assets/style.css');
  ok('style.css 200', r.status === 200 && r.text.includes('--accent'));

  section('状态与权限');
  r = await api('api/status');
  ok('api/status 成功', r.json && r.json.ok === true);
  ok('存储后端为 json（无 pdo_sqlite 降级）', r.json?.data?.driver === 'json', r.json?.data?.driver);
  ok('内置插件数 >= 7', (r.json?.data?.plugins || 0) >= 7, r.json?.data?.plugins);
  ok('工具 schema 已生成', (r.json?.data?.tools || 0) > 0, r.json?.data?.tools);
  const anon = await request('index.php?r=api/status', { csrf: false, headers: { Cookie: '' } });
  ok('未登录访问 API 返回 401', anon.status === 401);
  const badCsrf = await request('index.php?r=api/session/create', { body: {}, headers: { 'X-CSRF-Token': 'bad' } });
  ok('CSRF 校验失败返回 403', badCsrf.status === 403);

  // 自检请求较多，先把限流调高
  const raise = await api('api/settings/save', { security: { requests_per_minute: 600 } });
  ok('放宽限流以便自检', raise.json?.ok === true, raise.text.slice(0, 200));

  section('会话');
  r = await api('api/session/create', { title: '自检会话' });
  const sid = r.json?.session?.id || '';
  ok('创建会话', !!sid);
  r = await api('api/session/list');
  ok('会话列表含新会话', (r.json?.sessions || []).some((s) => s.id === sid));
  r = await api('api/session/get', { sid });
  ok('新会话消息为空', Array.isArray(r.json?.messages) && r.json.messages.length === 0);
  r = await api('api/session/rename', { sid, title: '改名成功' });
  ok('重命名会话', r.json?.session?.title === '改名成功');

  section('对话 · 普通回复');
  r = await api('api/chat/send', { sid, text: '你好，介绍一下你自己' });
  ok('普通对话成功', r.json?.ok === true && /mock/.test(r.json?.message?.content || ''), r.text.slice(0, 300));
  ok('返回 usage 统计', !!r.json?.usage);
  r = await api('api/session/get', { sid });
  ok('消息已落库（user + assistant）', r.json?.messages?.length === 2, r.json?.messages?.length);
  ok('首条消息自动生成标题', r.json?.session?.title?.includes('你好') || r.json?.session?.title === '改名成功');
  const lastReq = await (await fetch(MOCK + '/_last')).json();
  ok('上游收到 Bearer 凭据', lastReq.authorization === 'Bearer sk-mock', lastReq.authorization);
  ok('上游带 temperature', lastReq.hasTemperature === true);

  section('对话 · 插件工具循环');
  await fetch(MOCK + '/_reset');
  r = await api('api/chat/send', { sid, text: '现在几点了？用工具查一下' });
  ok('工具循环完成', r.json?.ok === true, r.text.slice(0, 300));
  ok('返回工具调用日志', Array.isArray(r.json?.tool_log) && r.json.tool_log.length >= 1, JSON.stringify(r.json?.tool_log));
  ok('工具结果并入最终回复', /根据工具结果/.test(r.json?.message?.content || ''), r.json?.message?.content);
  const log = await (await fetch(MOCK + '/_log')).json();
  ok('模型至少被请求 2 轮', log.length >= 2, log.length);
  ok('第 1 轮携带 tools', log[0]?.hasTools === true);
  ok('第 2 轮含 role:tool 消息', (log[1]?.messages || []).some((x) => x.role === 'tool'));

  section('插件 · 直调与开关');
  r = await api('api/plugin/run', { id: 'core.calc', args: { expression: '(12+3)*4' } });
  ok('计算器 (12+3)*4 = 60', r.json?.ok === true && /60/.test(r.json?.text || ''), r.json?.text);
  r = await api('api/plugin/run', { id: 'core.calc', args: { expression: 'sqrt(16)+2^3' } });
  ok('计算器 sqrt(16)+2^3 = 12', /12/.test(r.json?.text || ''), r.json?.text);
  r = await api('api/plugin/run', { id: 'core.calc', args: { expression: 'system("dir")' } });
  ok('计算器拒绝非法字符（不执行 eval）', r.json?.ok === false, r.json?.text);
  r = await api('api/plugin/run', { id: 'core.time', args: {} });
  ok('时间插件可用', r.json?.ok === true && /\d{4}-\d{2}-\d{2}/.test(r.json?.text || ''), r.json?.text);
  r = await api('api/plugin/run', { id: 'core.system', args: {} });
  ok('系统自检插件返回 PONG', r.json?.ok === true && r.json.text.includes('PONG'));
  ok('系统自检不泄露数据文件名', !/vq_[0-9a-f]{20,}/.test(r.json?.text || ''));
  r = await api('api/plugin/run', { id: 'core.text', args: { action: 'sha256', text: 'abc' } });
  ok('文本插件 sha256 正确', r.json?.text === hashExpect('abc'), r.json?.text);
  r = await api('api/plugin/run', { id: 'core.note', args: { action: 'add', content: '自检笔记' }, });
  ok('笔记插件写入成功', r.json?.ok === true && /自检笔记/.test(r.json?.text || ''));
  r = await api('api/plugin/run', { id: 'core.note', args: { action: 'list' } });
  ok('笔记插件可读取', /自检笔记/.test(r.json?.text || ''));
  r = await api('api/plugin/list');
  const before = (r.json?.plugins || []).filter((p) => p.enabled).length;
  const target = (r.json?.plugins || []).find((p) => p.id === 'core.time');
  r = await api('api/plugin/toggle', { id: 'core.time', enabled: false });
  const after = await api('api/plugin/list');
  const afterCount = (after.json?.plugins || []).filter((p) => p.enabled).length;
  ok('插件开关生效', r.json?.ok === true && afterCount === before - 1, `${before} → ${afterCount}`);
  await api('api/plugin/toggle', { id: 'core.time', enabled: true });

  section('技能');
  r = await api('api/skill/list');
  ok('技能列表含 16 个内置技能', (r.json?.skills || []).length >= 16, r.json?.skills?.length);
  ok('技能解析 frontmatter 名称与按需模式', (r.json?.skills || []).some((s) => s.name.includes('DSH')) &&
    (r.json?.skills || []).some((s) => s.mode === 'auto' && s.when));
  r = await api('api/skill/toggle', { id: 'code-review', enabled: false });
  ok('技能开关生效', r.json?.ok === true);
  await api('api/skill/toggle', { id: 'code-review', enabled: true });

  section('斜杠命令 / 文本工具协议 / 降级');
  r = await api('api/chat/send', { sid, text: '/ping' });
  ok('/ping 直调插件不消耗模型', r.json?.slash === true && r.json.message.content.includes('PONG'), r.text.slice(0, 250));
  r = await api('api/chat/send', { sid, text: 'textmode 现在几点了', text_tool_mode: true });
  ok('文本工具协议可解析 TOOL_CALL', (r.json?.tool_log || []).length >= 1, JSON.stringify(r.json?.tool_log));
  await fetch(MOCK + '/_reset');
  r = await api('api/chat/send', { sid, text: 'fail400 工具 几点了' });
  ok('上游 400 时自动瘦身重试成功', r.json?.ok === true, r.text.slice(0, 300));
  const log2 = await (await fetch(MOCK + '/_log')).json();
  ok('重试请求确实去掉了 tools', log2.length >= 2 && log2[0].hasTools === true && log2[1].hasTools === false, JSON.stringify(log2.map((x) => x.hasTools)));

  section('设置');
  r = await api('api/settings/get');
  ok('读取设置（API Key 已打码）', r.json?.settings?.llm?.api_key === '••••••••' && r.json.settings.llm.api_key_set === true);
  ok('设置含 6 条预置路由', Object.keys(r.json?.settings?.presets || {}).length >= 6);
  r = await api('api/settings/test', { base_url: MOCK + '/v1', model: 'mock-model', auth_mode: 'bearer' });
  ok('测试模型连通性', r.json?.ok === true && r.json.reply === 'OK', r.text.slice(0, 250));
  r = await api('api/settings/save', { chat: { system_prompt: '更新后的系统提示词', temperature: 0.3 } });
  ok('保存设置成功', r.json?.ok === true);
  r = await api('api/settings/get');
  ok('系统提示词已更新', r.json?.settings?.chat?.system_prompt === '更新后的系统提示词');
  ok('温度已更新', r.json?.settings?.chat?.temperature === 0.3);
  ok('config.php 仍是合法 PHP', fs.readFileSync('config.php', 'utf8').startsWith('<?php'));

  section('SSE 流式');
  r = await request('index.php?r=api/chat/stream', { body: { sid, text: '你好，流式测试' } });
  ok('SSE 返回 200', r.status === 200);
  ok('SSE 含 delta 事件', r.text.includes('event: delta'), r.text.slice(0, 250));
  ok('SSE 含 done 事件', r.text.includes('event: done'));
  ok('SSE 的 done 数据可解析', /event: done\ndata: (\{[^\n]*\})/.test(r.text));

  section('OpenAI 兼容接口（外部程序用 API Key）');
  r = await request('index.php?r=v1/models', { headers: { Cookie: '', 'X-API-Key': 'mykey123456' }, csrf: false });
  ok('v1/models 标准格式', r.json?.object === 'list' && Array.isArray(r.json?.data));
  r = await request('index.php?r=v1/chat/completions', {
    headers: { Cookie: '', 'X-API-Key': 'mykey123456' }, csrf: false,
    body: { model: 'mock-model', messages: [{ role: 'user', content: '你好' }] },
  });
  ok('v1/chat/completions 标准格式', r.json?.object === 'chat.completion' && r.json?.choices?.[0]?.message?.content, r.text.slice(0, 250));
  ok('v1 返回 usage 字段', !!r.json?.usage);
  r = await request('index.php?r=v1/chat/completions', {
    headers: { Cookie: '', Authorization: 'Bearer mykey123456' }, csrf: false,
    body: { model: 'mock-model', messages: [{ role: 'user', content: '你好' }], stream: true },
  });
  ok('v1 流式返回 SSE chunk 与 [DONE]', r.text.includes('chat.completion.chunk') && r.text.includes('[DONE]'), r.text.slice(0, 200));
  r = await request('index.php?r=v1/chat/completions', {
    headers: { Cookie: '', 'X-API-Key': 'wrong-key' }, csrf: false,
    body: { model: 'mock-model', messages: [{ role: 'user', content: 'hi' }] },
  });
  ok('错误 API Key 返回 401', r.status === 401);

  section('多线路故障转移（429 限流）');
  r = await api('api/settings/save', {
    llm: { base_url: MOCK + '/limited/v1', model: 'mock-model', auth_mode: 'bearer' },
    routing: {
      mode: 'auto', cooldown: 60, max_switches: 2, local_fallback: true,
      candidates: [
        { key: 'mock-backup', name: 'mock 备用线路', base_url: MOCK + '/v1', model: 'mock-model', auth_mode: 'bearer', api_key: 'sk-mock', enabled: true },
      ],
    },
  });
  ok('保存故障转移配置', r.json?.ok === true, r.text.slice(0, 200));
  r = await api('api/routes/reset');
  ok('清除线路冷却', r.json?.ok === true);
  r = await api('api/routes/list');
  ok('线路清单含主路由与备用', (r.json?.routes || []).length >= 2, JSON.stringify(r.json?.routes));
  ok('线路清单不泄露 API Key', !JSON.stringify(r.json?.routes || []).includes('sk-mock'));
  ok('线路清单标注自动模式', r.json?.routing?.mode === 'auto');
  r = await api('api/routes/test');
  const rr = r.json?.results || [];
  ok('测试全部线路返回逐条结果', rr.length >= 2, JSON.stringify(rr));
  ok('限流线路被识别为 429', rr.some((x) => x.status === 429 && x.ok === false), JSON.stringify(rr));
  ok('备用线路测试通过', rr.some((x) => x.ok === true), JSON.stringify(rr));
  await api('api/routes/reset');

  await fetch(MOCK + '/_reset');
  r = await api('api/chat/send', { sid, text: '你好，故障转移测试' });
  ok('429 自动切换后仍成功回复', r.json?.ok === true && /mock/.test(r.json?.message?.content || ''), r.text.slice(0, 300));
  ok('返回实际使用的线路', r.json?.route_used?.key === 'mock-backup', JSON.stringify(r.json?.route_used));
  ok('尝试记录含 2 条且首条 429', (r.json?.tried || []).length === 2 && r.json.tried[0].status === 429, JSON.stringify(r.json?.tried));
  const flog = await (await fetch(MOCK + '/_log')).json();
  ok('确实先打限流线路再打备用', flog.length >= 2 && /limited/.test(flog[0].url) && !/limited/.test(flog[1].url), JSON.stringify(flog.map((x) => x.url)));

  await fetch(MOCK + '/_reset');
  r = await api('api/chat/send', { sid, text: '再问一次' });
  ok('冷却期内直接走备用线路', (r.json?.tried || []).length === 1 && r.json?.route_used?.key === 'mock-backup', JSON.stringify(r.json?.tried));

  r = await api('api/settings/save', { routing: { mode: 'fixed', local_fallback: true } });
  ok('切换为固定单线路', r.json?.ok === true);
  r = await api('api/chat/send', { sid, text: '1+1=？' });
  ok('模型全挂时本地计算器兜底 1+1=2', r.json?.ok === true && /2/.test(r.json?.message?.content || ''), r.text.slice(0, 300));
  ok('兜底回答标明离线来源', r.json?.offline === 'plugin' && /离线兜底/.test(r.json?.message?.content || ''), r.json?.offline);
  r = await api('api/chat/send', { sid, text: '请写一篇关于秋天的散文' });
  ok('非确定性问题给中文排障提示', r.json?.ok === false && /模型线路暂时无法使用/.test(r.json?.message?.content || ''), r.text.slice(0, 300));
  ok('不再把上游原始 JSON 甩给用户', !/request_id/.test(r.json?.message?.content || ''), r.text.slice(0, 300));

  r = await api('api/settings/save', { llm: { base_url: MOCK + '/v1' }, routing: { mode: 'auto', candidates: [] } });
  ok('恢复原始配置', r.json?.ok === true);
  await api('api/routes/reset');
  r = await api('api/chat/send', { sid, text: '恢复后测试' });
  ok('恢复后主线路直接可用', r.json?.ok === true && (r.json?.tried || []).length === 1, JSON.stringify(r.json?.tried));

  section('插件市场（48 个内置插件）');
  r = await api('api/market/list');
  let mk = r.json || {};
  ok('市场列表接口成功', r.json?.ok === true, r.text.slice(0, 200));
  ok('内置插件数 >= 40', (mk.items || []).length >= 40, (mk.items || []).length);
  ok('分类共 10 类', (mk.categories || []).length === 10, (mk.categories || []).length);
  // 2.4 起纯界面插件没有 tools / slash，改为允许「有工具」或「有 ui 清单」
  ok('每项元数据完整', (mk.items || []).every((p) => p.id && p.name && p.description && p.category &&
    p.usage && Array.isArray(p.tools) && Array.isArray(p.slash) && (p.tools.length || p.ui)));
  ok('每项带安装状态', (mk.items || []).every((p) => typeof p.installed === 'boolean'));
  ok('初始未安装任何市场插件', mk.stats?.installed === 0, mk.stats?.installed);
  ok('推荐套装为 8 个', (mk.recommended || []).length === 8);
  const mkRaw = JSON.stringify(mk.items || []);
  ok('列表不泄露文件路径', !mkRaw.includes('_file') && !mkRaw.includes('workbuddyfile'));
  const anonMk = await request('index.php?r=api/market/list', { csrf: false, headers: { Cookie: '' } });
  ok('未登录访问市场接口 401', anonMk.status === 401);
  const badMk = await request('index.php?r=api/market/install', { body: { id: 'mkt.hash' }, headers: { 'X-CSRF-Token': 'bad' } });
  ok('市场写操作 CSRF 校验 403', badMk.status === 403);

  r = await api('api/market/install', { id: 'mkt.hash' });
  ok('安装单个插件成功', r.json?.ok === true && r.json?.installed === true, r.text.slice(0, 200));
  ok('安装响应带回市场列表', (r.json?.market?.items || []).length >= 40);
  mk = r.json.market;
  ok('已安装数变为 1', mk.stats.installed === 1, mk.stats.installed);
  const hashItem = (mk.items || []).find((x) => x.id === 'mkt.hash');
  ok('该项标记为已安装且启用', hashItem?.installed === true && hashItem?.enabled === true);
  r = await api('api/plugin/list');
  ok('已安装插件进入插件列表', (r.json?.plugins || []).some((p) => p.id === 'mkt.hash' && p.source === 'market'),
    JSON.stringify((r.json?.plugins || []).map((p) => p.id)));
  r = await api('api/status');
  ok('状态接口报告市场统计', r.json?.data?.market_total >= 40 && r.json?.data?.market_installed === 1,
    JSON.stringify(r.json?.data));

  r = await api('api/market/run', { id: 'mkt.hash', args: { text: 'abc', algo: 'sha256' } });
  ok('试运行市场插件 sha256 正确', r.json?.ok === true && (r.json?.text || '').includes(hashExpect('abc')), r.json?.text);
  r = await api('api/plugin/run', { id: 'mkt.hash', args: { text: 'abc', algo: 'md5' } });
  ok('通用插件接口也能调用市场插件', r.json?.ok === true && /MD5/.test(r.json?.text || ''));
  r = await api('api/chat/send', { sid, text: '/digest {"text":"abc","algo":"sha256"}' });
  ok('市场插件 /命令 可直调', r.json?.slash === true && (r.json?.message?.content || '').includes(hashExpect('abc')),
    r.text.slice(0, 250));
  r = await api('api/market/install', { id: 'mkt.hash' });
  ok('重复安装幂等', r.json?.ok === true && r.json?.market?.stats?.installed === 1);
  r = await api('api/market/install', { id: '../../etc/passwd' });
  ok('非法 id 被拒绝', r.status === 404 && r.json?.ok === false, r.text.slice(0, 150));
  r = await api('api/market/run', { id: 'mkt.morse', args: { text: 'SOS' } });
  ok('未安装插件不可试运行', r.status === 404 && r.json?.ok === false);

  r = await api('api/market/list');
  ok('安装状态跨请求持久化', (r.json?.items || []).find((x) => x.id === 'mkt.hash')?.installed === true);
  r = await api('api/market/uninstall', { id: 'mkt.hash' });
  ok('卸载插件成功', r.json?.ok === true && r.json?.market?.stats?.installed === 0);
  r = await api('api/plugin/list');
  ok('卸载后从插件列表消失', !(r.json?.plugins || []).some((p) => p.id === 'mkt.hash'));

  r = await api('api/market/batch', { preset: 'recommended' });
  ok('一键安装推荐套装', r.json?.ok === true && r.json?.market?.stats?.installed === 8, r.json?.market?.stats?.installed);
  r = await api('api/market/batch', { category: 'fun', mode: 'merge' });
  ok('按分类追加安装 5 个趣味插件', r.json?.ok === true && r.json?.market?.stats?.installed === 13,
    r.json?.market?.stats?.installed);
  r = await api('api/market/batch', { preset: 'all' });
  ok('一键安装全部插件', r.json?.ok === true && r.json?.market?.stats?.installed >= 40,
    r.json?.market?.stats?.installed);
  r = await api('api/status');
  ok('全装后工具表 >= 42', (r.json?.data?.tools || 0) >= 42, r.json?.data?.tools);
  r = await api('api/market/batch', { ids: ['mkt.morse'], mode: 'remove' });
  ok('按 ids 移除指定插件', r.json?.ok === true && !(r.json?.installed || []).includes('mkt.morse'));
  r = await api('api/market/batch', { preset: 'none' });
  ok('一键卸载全部市场插件', r.json?.ok === true && r.json?.market?.stats?.installed === 0);
  r = await api('api/market/batch', { preset: 'recommended' });
  ok('最终恢复推荐套装', r.json?.market?.stats?.installed === 8);
  r = await request('index.php');
  ok('页面含插件市场页签', r.text.includes('data-pane="market"') && r.text.includes('id="btn-market"'));
  r = await api('api/market/list');
  ok('首屏统计与真实安装数一致', r.json?.stats?.installed === 8);
  mk = r.json;
  ok('页面不因市场插件出现非法 UTF-8', !!r.text && !/\ufffd/.test(JSON.stringify(mk.items || [])));

  section('收尾');
  r = await api('api/session/clear', { sid });
  ok('清空会话消息', r.json?.ok === true);
  r = await api('api/session/get', { sid });
  ok('清空后消息为 0', r.json?.messages?.length === 0);
  r = await api('api/session/delete', { sid });
  ok('删除会话', r.json?.ok === true);
  r = await api('api/status');
  ok('最终状态正常', r.json?.ok === true && r.json.data.stats.sessions === 0, JSON.stringify(r.json?.data?.stats));

  console.log('\n────────────────────────────');
  console.log(`结果：${pass} 通过 / ${fail} 失败`);
  process.exit(fail ? 1 : 0);
}

function hashExpect(s) {
  // node 侧计算 sha256，用于与 PHP 插件结果对比
  return createHash('sha256').update(s).digest('hex');
}

main().catch((e) => { console.error('自检脚本异常：', e); process.exit(2); });
