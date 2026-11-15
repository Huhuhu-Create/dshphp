/* dshphp 2.3.1 — 自动化任务面板（独立模块，零外部依赖；主 app.js 无需改动）
 * 纯访问驱动：本模块不发起任何定时请求，只在用户点击时产生一次访问。 */
(() => {
  'use strict';
  const B = window.DSH_BOOT || {};
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => Array.from(document.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  const A = { tasks: [], runs: [], stats: {}, types: [], busy: false };

  async function api(route, data, opt = {}) {
    const res = await fetch('index.php?r=' + encodeURIComponent(route), {
      method: opt.method || 'POST',
      headers: Object.assign({ 'Content-Type': 'application/json', 'X-CSRF-Token': B.csrf || '' }, opt.headers || {}),
      body: opt.method === 'GET' ? undefined : JSON.stringify(data || {}),
    });
    let j;
    try { j = await res.json(); } catch (e) { throw new Error('HTTP ' + res.status + '：响应不是 JSON'); }
    if (!res.ok && !j.ok) throw new Error(j.error || ('HTTP ' + res.status));
    return j;
  }

  function result(t) { const el = $('#auto-result'); if (el) el.textContent = t || ''; }
  function formResult(t) { const el = $('#auto-form-result'); if (el) el.textContent = t || ''; }
  function fmtTs(sec) {
    if (!sec) return '—';
    const d = new Date(sec * 1000), p = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  const HINTS = {
    log: '参数：{"message":"打点 {time}"}；占位符 {time} {date} {ts} {week}',
    plugin: '参数：{"plugin":"core.time","tool":"get_time","args":{"format":"Y-m-d H:i:s"}}，插件需已启用',
    health: '参数：{"detail":true}（可选）；检查存储、目录权限、任务数',
    digest: '参数：{"scope":"today"} 或 {"scope":"week"}；汇总运行日志',
    prune: '无需参数；日志由调度器自动裁剪到上限',
    snapshot: '无需参数；把任务表结构写进运行日志',
    http: '参数：{"url":"https://example.com/","method":"GET"}；带内网 SSRF 防护',
  };
  const FALLBACK_TYPES = [
    { key: 'log', label: '记录打点' }, { key: 'plugin', label: '调用插件' },
    { key: 'health', label: '健康检查' }, { key: 'digest', label: '统计汇总' },
    { key: 'prune', label: '日志清理' }, { key: 'snapshot', label: '配置快照' },
    { key: 'http', label: '访问 URL' },
  ];

  /* ---------- 渲染 ---------- */
  function applyAuto(j) {
    if (!j || !j.ok) { result('加载失败：' + ((j && j.error) || '未知错误')); return; }
    A.tasks = j.tasks || []; A.runs = j.runs || [];
    A.stats = j.stats || {}; A.types = j.types || A.types;
    renderStats(); renderInfo(); renderTasks(); renderLogs(); fillTypes();
  }

  async function loadAuto() {
    try { applyAuto(await api('api/automation/list', {})); }
    catch (e) { result('加载失败：' + e.message); }
  }

  function renderStats() {
    const s = A.stats;
    const items = [
      ['调度器', s.enabled ? '运行中' : '已暂停'],
      ['任务', (s.tasks || 0) + ' 个（启用 ' + (s.enabled_tasks || 0) + '）'],
      ['累计执行', (s.total_runs || 0) + ' 次'],
      ['失败', (s.fail_runs || 0) + ' 次'],
      ['累计推进', (s.ticks || 0) + ' 次'],
      ['上次推进', s.last_tick_at || '—'],
    ];
    $('#auto-stats').innerHTML = items.map(([k, v]) =>
      '<span class="auto-stat-item"><span>' + esc(k) + '</span><b>' + esc(v) + '</b></span>').join('');
    $('#auto-count').textContent = A.tasks.length;
    $('#btn-auto-power').textContent = s.enabled ? '暂停调度' : '启用调度';
  }

  function renderInfo() {
    const s = A.stats;
    $('#auto-hint').textContent = '配置与日志：' + (s.file_hint || 'data/ 下的 JSON 文件')
      + ' · 存储格式：' + (s.storage || 'json').toUpperCase()
      + ' · 驱动方式：' + (s.driver === 'access-only' ? '仅访问时推进（无保活）' : '未知')
      + ' · 服务器时间 ' + (s.server_time || '')
      + ' · 目录可写：' + (s.writable ? '是' : '否');
  }

  function renderTasks() {
    const box = $('#auto-grid');
    if (!A.tasks.length) { box.innerHTML = '<div class="mkt-empty">暂无任务，可在下方新增，或导出后手工编辑 JSON</div>'; return; }
    box.innerHTML = A.tasks.map((t) =>
      '<div class="auto-card' + (t.enabled ? '' : ' off') + '" data-id="' + esc(t.id) + '">' +
        '<div class="auto-head">' +
          '<span class="auto-state' + (t.enabled ? ' on' : '') + '"></span>' +
          '<div class="auto-title"><div class="auto-name">' + esc(t.name) + '</div>' +
          '<div class="auto-sub">' + esc(t.id) + ' · ' + esc(t.type_label || t.type) + '</div></div>' +
          '<span class="auto-badge' + (t.enabled ? '' : ' off') + '">' + (t.enabled ? '运行中' : '已停用') + '</span>' +
        '</div>' +
        '<div class="auto-meta">' +
          '<span>调度 <b>' + esc(t.schedule_label || '') + '</b></span>' +
          '<span>下次 <b>' + esc(t.next_at || '—') + '</b></span>' +
          '<span>上次 <b>' + esc(t.last_at || '—') + '</b></span>' +
          '<span>累计 <b>' + Number(t.run_count || 0) + '</b> 次 / 失败 <b>' + Number(t.fail_count || 0) + '</b></span>' +
        '</div>' +
        (t.note ? '<div class="auto-note">' + esc(t.note) + '</div>' : '') +
        '<div class="auto-foot">' +
          '<button class="btn sm" data-act="run">立即运行</button>' +
          '<button class="btn ghost sm" data-act="edit">编辑</button>' +
          '<button class="btn ghost sm" data-act="toggle">' + (t.enabled ? '停用' : '启用') + '</button>' +
          '<button class="btn ghost sm danger-text" data-act="delete">删除</button>' +
          '<span class="auto-last' + (t.last_ok === false ? ' bad' : '') + '">' +
            (t.last_ok === false ? '上次失败' : (t.last_ok === true ? '上次成功' : '未运行')) + '</span>' +
        '</div>' +
      '</div>').join('');
  }

  function renderLogs() {
    const box = $('#auto-logs');
    if (!A.runs.length) { box.innerHTML = '<div class="mkt-empty">暂无运行记录，点「立即推进」或等下一次访问</div>'; return; }
    box.innerHTML = A.runs.slice(0, 30).map((r) =>
      '<div class="auto-log' + (r.ok ? '' : ' fail') + '">' +
        '<span class="auto-log-at">' + esc(fmtTs(r.at)) + '</span>' +
        '<span class="auto-log-ok">' + (r.ok ? 'OK' : 'FAIL') + '</span>' +
        '<span class="auto-log-name">' + esc(r.name || r.task) + (r.manual ? '（手动）' : '') + '</span>' +
        '<span class="auto-log-ms">' + Number(r.ms || 0) + 'ms</span>' +
        '<div class="auto-log-text">' + esc(r.text || '') + '</div>' +
      '</div>').join('');
  }

  /* ---------- 表单 ---------- */
  function fillTypes() {
    const sel = $('#auto-f-type');
    if (!sel || sel.options.length) return;
    const list = A.types.length ? A.types : FALLBACK_TYPES;
    sel.innerHTML = list.map((t) => '<option value="' + esc(t.key) + '">' + esc(t.label) + ' · ' + esc(t.key) + '</option>').join('');
  }

  function updateFormHints() {
    if (!$('#auto-f-type')) return;
    const type = $('#auto-f-type').value;
    const cron = $('#auto-f-mode').value === 'cron';
    $('#auto-f-every').disabled = cron;
    $('#auto-f-expr').disabled = !cron;
    $('#auto-args-hint').textContent = HINTS[type] || '';
  }

  function resetForm() {
    $('#auto-f-id').value = ''; $('#auto-f-id').readOnly = false;
    $('#auto-f-name').value = ''; $('#auto-f-note').value = '';
    $('#auto-f-type').value = 'log';
    $('#auto-f-mode').value = 'interval';
    $('#auto-f-every').value = '300'; $('#auto-f-expr').value = '0 9 * * *';
    $('#auto-f-args').value = '{"message":"打点 {time}"}';
    $('#auto-f-enabled').checked = true;
    formResult(''); updateFormHints();
  }

  function fillForm(t) {
    $('#auto-editor').open = true;
    $('#auto-f-id').value = t.id; $('#auto-f-id').readOnly = true;
    $('#auto-f-name').value = t.name || ''; $('#auto-f-note').value = t.note || '';
    $('#auto-f-type').value = t.type || 'log';
    const sch = t.schedule || { mode: 'interval', every: 300 };
    $('#auto-f-mode').value = sch.mode === 'cron' ? 'cron' : 'interval';
    $('#auto-f-every').value = String(sch.every || 300);
    $('#auto-f-expr').value = sch.expr || '0 9 * * *';
    $('#auto-f-args').value = JSON.stringify(t.args || {}, null, 2);
    $('#auto-f-enabled').checked = !!t.enabled;
    formResult('正在编辑：' + t.id + '（任务 ID 不可修改）');
    updateFormHints();
  }

  function collectForm() {
    const id = $('#auto-f-id').value.trim();
    const name = $('#auto-f-name').value.trim() || id;
    if (!/^[A-Za-z0-9_.-]{1,64}$/.test(id)) throw new Error('任务 ID 只允许字母、数字、_ . -，且不超过 64 位');
    const raw = $('#auto-f-args').value.trim();
    let args = {};
    if (raw) {
      try { args = JSON.parse(raw); } catch (e) { throw new Error('动作参数不是合法 JSON：' + e.message); }
      if (!args || typeof args !== 'object' || Array.isArray(args)) throw new Error('动作参数必须是 JSON 对象');
    }
    const mode = $('#auto-f-mode').value;
    const schedule = mode === 'cron'
      ? { mode: 'cron', expr: $('#auto-f-expr').value.trim() || '0 9 * * *' }
      : { mode: 'interval', every: Math.max(30, Number($('#auto-f-every').value) || 300) };
    return {
      id, name, note: $('#auto-f-note').value.trim(), type: $('#auto-f-type').value,
      schedule, args, enabled: $('#auto-f-enabled').checked,
    };
  }

  /* ---------- 交互 ---------- */
  async function post(route, body, okText) {
    if (A.busy) return null;
    A.busy = true; result('处理中…');
    try {
      const j = await api(route, body);
      applyAuto(j);
      result(okText || '完成');
      return j;
    } catch (e) { result('失败：' + e.message); return null; }
    finally { A.busy = false; }
  }

  function bindAuto() {
    // app.js 负责切换页签；这里只额外挂一个监听来拉数据，互不干扰
    $$('.tab[data-tab="automation"]').forEach((t) => t.addEventListener('click', () => loadAuto()));
    $('#btn-auto-refresh').addEventListener('click', () => loadAuto());
    $('#btn-auto-tick').addEventListener('click', () => post('api/automation/tick', {}, '已推进一次调度'));
    $('#btn-auto-power').addEventListener('click', () =>
      post('api/automation/save', { enabled: !A.stats.enabled }, A.stats.enabled ? '调度器已暂停' : '调度器已启用'));
    $('#btn-auto-logs').addEventListener('click', async () => {
      try { const j = await api('api/automation/logs', { limit: 60 }); A.runs = j.runs || []; renderLogs(); result('日志已刷新'); }
      catch (e) { result('刷新失败：' + e.message); }
    });
    $('#auto-grid').addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-act]');
      const card = btn && btn.closest('[data-id]');
      if (!btn || !card) return;
      const id = card.dataset.id, act = btn.dataset.act;
      const t = A.tasks.find((x) => x.id === id);
      if (!t) return;
      if (act === 'edit') { fillForm(t); return; }
      if (act === 'delete') {
        if (!confirm('确定删除任务「' + t.name + '」？运行日志会保留。')) return;
        await post('api/automation/delete', { id }, '已删除：' + id); return;
      }
      if (act === 'toggle') { await post('api/automation/toggle', { id, enabled: !t.enabled }, '已' + (t.enabled ? '停用' : '启用') + '：' + id); return; }
      if (act === 'run') { await post('api/automation/run', { id }, '已立即执行：' + id); return; }
    });

    $('#auto-f-mode').addEventListener('change', updateFormHints);
    $('#auto-f-type').addEventListener('change', updateFormHints);
    $('#btn-auto-reset').addEventListener('click', resetForm);
    $('#btn-auto-save').addEventListener('click', async () => {
      let task;
      try { task = collectForm(); } catch (e) { formResult(e.message); return; }
      const j = await post('api/automation/upsert', { task }, '任务已保存：' + task.id);
      if (j && j.ok) { resetForm(); $('#auto-editor').open = false; }
    });

    $('#btn-auto-export').addEventListener('click', async () => {
      try {
        const j = await api('api/automation/export', {});
        const url = URL.createObjectURL(new Blob([j.json || '{}'], { type: 'application/json;charset=utf-8' }));
        const a = document.createElement('a');
        a.href = url; a.download = 'dshphp-automation.json';
        document.body.appendChild(a); a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 1000);
        result('已导出 JSON（含任务表与运行日志，不含任何密钥，请妥善保管）');
      } catch (e) { result('导出失败：' + e.message); }
    });
    $('#btn-auto-import').addEventListener('click', () => $('#auto-file').click());
    $('#auto-file').addEventListener('change', async (e) => {
      const f = e.target.files && e.target.files[0];
      if (!f) return;
      try {
        const text = await f.text();
        if (!confirm('导入会覆盖当前任务表（运行日志与推进计数保留），确定继续？')) return;
        const j = await api('api/automation/import', { json: text });
        applyAuto(j);
        result(j.ok ? ('导入成功，共 ' + (j.imported || 0) + ' 个任务') : ('导入失败：' + (j.error || '')));
      } catch (err) { result('导入失败：' + err.message); }
      e.target.value = '';
    });

    resetForm();
  }

  /* 没有定时器：本模块不发起任何后台请求，只有用户点击才会访问 index.php。
   * 页面开着但无人操作时，调度器不会自己醒来 —— 这是刻意的「访问驱动」语义。 */
  function init() {
    if (!$('#auto-grid')) return;
    applyAuto(B.automation || { ok: false, error: '没有首屏数据' });
    bindAuto();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
