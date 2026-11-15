/* dshphp 2.0 — 前端：会话、对话（含 SSE）、插件、技能、设置。零外部依赖 */
(() => {
  'use strict';
  const B = window.DSH_BOOT;
  // ── 2.4 插件 GUI API：正式扩展点（有侵入）。dsh-ui.js 未加载时全部静默降级 ──
  const DSH = window.DSH || null;
  const emit = (n, d) => { if (DSH && DSH.emit) { try { DSH.emit(n, d); } catch (_) {} } };
  const syncState = () => {
    if (!DSH || !DSH._setState) return;
    DSH._setState({
      sid: S.sid, sessions: S.sessions.slice(), messages: S.messages.slice(), busy: S.busy,
      model: S.model, plugins: S.plugins.slice(), skills: S.skills.slice(), settings: S.settings,
      market: S.market, marketCat: S.marketCat, textMode: S.textMode,
    });
  };
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => Array.from(document.querySelectorAll(s));
  const S = {
    sessions: B.sessions || [], plugins: B.plugins || [], skills: B.skills || [],
    settings: B.settings || {}, sid: null, messages: [], busy: false,
    market: null, marketCat: 'all', marketQuery: '',
    controller: null, textMode: localStorage.getItem('dsh.textmode') === '1',
    model: localStorage.getItem('dsh.model') || '',
  };
  const esc = (s) => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  /* ---------- 极简 Markdown（代码块占位保护） ---------- */
  function md(text) {
    const blocks = [];
    let s = esc(text).replace(/```([A-Za-z0-9_+-]*)\n?([\s\S]*?)```/g, (m, lang, code) => {
      blocks.push('<pre><code data-lang="' + lang + '">' + code.replace(/\n$/, '') + '</code></pre>');
      return '\u0000BLK' + (blocks.length - 1) + '\u0000';
    });
    const inline = (t) => t
      .replace(/`([^`\n]+)`/g, '<code>$1</code>')
      .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    const lines = s.split('\n');
    let out = [], list = null;
    const closeList = () => { if (list) { out.push('</' + list + '>'); list = null; } };
    for (let raw of lines) {
      const line = raw.replace(/\s+$/, '');
      const blk = /^\u0000BLK(\d+)\u0000$/.exec(line);
      if (blk) { closeList(); out.push(blocks[+blk[1]]); continue; }
      const ul = /^\s*[-*]\s+(.+)$/.exec(line);
      const ol = /^\s*\d+[.)]\s+(.+)$/.exec(line);
      if (ul || ol) {
        const tag = ul ? 'ul' : 'ol';
        if (list !== tag) { closeList(); out.push('<' + tag + '>'); list = tag; }
        out.push('<li>' + inline((ul || ol)[1]) + '</li>');
        continue;
      }
      closeList();
      if (/^\s*$/.test(line)) { out.push('<p></p>'); continue; }
      const h = /^(#{1,4})\s+(.+)$/.exec(line);
      if (h) { out.push('<h4>' + inline(h[2]) + '</h4>'); continue; }
      if (/^>\s?/.test(line)) { out.push('<blockquote>' + inline(line.replace(/^>\s?/, '')) + '</blockquote>'); continue; }
      if (/^(---|\*\*\*|___)\s*$/.test(line)) { out.push('<hr>'); continue; }
      out.push('<p>' + inline(line) + '</p>');
    }
    closeList();
    return out.join('\n').replace(/<p><\/p>(\n<p><\/p>)+/g, '<p></p>');
  }

  /* ---------- API ---------- */
  async function api(route, data, opt = {}) {
    const res = await fetch('index.php?r=' + encodeURIComponent(route), {
      method: opt.method || 'POST',
      headers: Object.assign({ 'Content-Type': 'application/json', 'X-CSRF-Token': B.csrf }, opt.headers || {}),
      body: opt.method === 'GET' ? undefined : JSON.stringify(data || {}),
      signal: opt.signal,
    });
    let j;
    try { j = await res.json(); } catch (e) { throw new Error('HTTP ' + res.status + '：响应不是 JSON'); }
    if (!res.ok && !j.ok) throw new Error(j.error || ('HTTP ' + res.status));
    return j;
  }

  function setStatus(t) { $('#composer-status').textContent = t || ''; emit('status:set', { target: 'composer', text: t || '' }); }
  function setLine(t) { $('#status-line').textContent = t || ''; emit('status:set', { target: 'sidebar', text: t || '' }); }

  /* ---------- 会话列表 ---------- */
  function renderSessions() {
    const box = $('#session-list');
    $('#session-count').textContent = S.sessions.length;
    const finish = () => {
      emit('sessions:render', { sessions: S.sessions.slice(), sid: S.sid, host: box });
      if (DSH && DSH.ui) DSH.ui.renderSlot('sidebar-top', $('#dsh-slot-sidebar-top'));
      syncState();
    };
    // 插件可整体接管会话列表渲染（DSH.ui.registerRenderer('sessions', fn)）
    const custom = DSH && DSH.ui && DSH.ui.getRenderer('sessions');
    if (custom) {
      try {
        custom({
          host: box, sessions: S.sessions.slice(), sid: S.sid, esc: esc, emit: emit,
          select: (id) => selectSession(id),
          remove: async (id) => {
            await api('api/session/delete', { sid: id });
            if (S.sid === id) { S.sid = null; S.messages = []; renderMessages(); updateTitle(); }
            await refreshSessions();
          },
        });
        finish();
        return;
      } catch (e) {
        emit('ui:error', { where: 'sessions-renderer', error: String(e) });
        box.innerHTML = ''; // 回退到原生渲染
      }
    }
    if (!S.sessions.length) { box.innerHTML = '<div class="item disabled"><span class="txt muted">暂无对话</span></div>'; finish(); return; }
    box.innerHTML = S.sessions.map((s) =>
      '<div class="item' + (s.id === S.sid ? ' active' : '') + '" data-id="' + esc(s.id) + '">' +
      '<span class="dot' + (s.id === S.sid ? ' on' : '') + '"></span>' +
      '<span class="txt">' + esc(s.title || '新对话') + '</span>' +
      '<button class="del" data-del="' + esc(s.id) + '" title="删除对话">×</button></div>'
    ).join('');
    box.querySelectorAll('.item[data-id]').forEach((el) => el.addEventListener('click', (e) => {
      if (e.target.dataset.del) return;
      selectSession(el.dataset.id);
    }));
    box.querySelectorAll('[data-del]').forEach((el) => el.addEventListener('click', async (e) => {
      e.stopPropagation();
      if (!confirm('确定删除这个对话？消息将一起删除。')) return;
      await api('api/session/delete', { sid: el.dataset.del });
      if (S.sid === el.dataset.del) { S.sid = null; S.messages = []; renderMessages(); updateTitle(); }
      await refreshSessions();
    }));
    finish();
  }

  async function refreshSessions() {
    const j = await api('api/session/list', {});
    S.sessions = j.sessions || [];
    renderSessions();
  }

  async function selectSession(sid) {
    if (S.busy) return;
    S.sid = sid;
    const j = await api('api/session/get', { sid });
    S.messages = j.messages || [];
    renderSessions(); renderMessages(); updateTitle();
    $('#messages').scrollTop = $('#messages').scrollHeight;
    emit('session:change', { sid: S.sid, session: S.sessions.find((x) => x.id === S.sid) || null, messages: S.messages.slice() });
    syncRole();
  }

  function sysPresets() { return (S.settings && S.settings.chat && S.settings.chat.system_presets) || []; }

  // 填充顶栏「角色」下拉并反映当前会话已设定的系统提示词
  function syncRole() {
    const r = $('#role-select'); if (!r) return;
    const list = sysPresets();
    r.innerHTML = '<option value="">默认（站点设置）</option>' +
      list.map((p) => '<option value="' + esc(p.id) + '">' + esc(p.name) + '</option>').join('');
    const s = S.sessions.find((x) => x.id === S.sid);
    const sys = s ? (s.system || '') : '';
    const m = list.find((p) => p.prompt === sys);
    r.value = m ? m.id : '';
  }

  async function newSession() {
    const id = $('#role-select') ? $('#role-select').value : '';
    const p = sysPresets().find((x) => x.id === id);
    const sys = (id && p) ? p.prompt : '';
    const j = await api('api/session/create', sys ? { system: sys } : {});
    S.sid = j.session.id; S.messages = [];
    await refreshSessions();
    renderSessions(); renderMessages(); updateTitle();
    $('#input').focus();
    emit('session:create', { sid: S.sid });
    emit('session:change', { sid: S.sid, session: j.session, messages: [] });
  }

  function updateTitle() {
    const s = S.sessions.find((x) => x.id === S.sid);
    $('#chat-title').textContent = s ? (s.title || '新对话') : '新对话';
    $('#chat-sub').textContent = s
      ? (S.messages.length + ' 条消息 · 模型 ' + (S.model || S.settings.llm.model || '默认'))
      : '选择一个对话，或点「＋ 新对话」开始';
    $('#btn-clear').disabled = !s;
  }

  /* ---------- 消息 ---------- */
  function msgNode(m, pending) {
    const row = document.createElement('div');
    row.className = 'msg-row ' + (m.role === 'user' ? 'user' : 'assistant');
    const avatar = document.createElement('div');
    avatar.className = 'avatar'; avatar.textContent = m.role === 'user' ? 'U' : 'DSH';
    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    let html = md(m.content || '');
    if (pending) html += '<span class="caret"></span>';
    if (Array.isArray(m.tools) && m.tools.length) {
      html += '<details class="tool-log" open><summary>工具调用 · ' + m.tools.length + ' 次</summary>' +
        m.tools.map((t) => '<div class="tool-item"><b>' + esc(t.name || 'tool') + '</b> ' +
          (t.ok === false ? '<span class="fail">失败</span>' : '') + '\n' +
          esc(typeof t.arguments === 'string' ? t.arguments : JSON.stringify(t.arguments || {})) +
          '\n→ ' + esc(String(t.result || '')) + '</div>').join('') + '</details>';
    }
    bubble.innerHTML = html;
    row.appendChild(avatar); row.appendChild(bubble);
    return row;
  }

  function renderMessages() {
    const box = $('#messages');
    const done = () => emit('messages:render', {
      count: S.messages.length, host: box, messages: S.messages.slice(), sid: S.sid,
    });
    if (!S.messages.length) {
      box.innerHTML = '<div class="welcome"><div class="welcome-mark">DSH</div>' +
        '<h2>独立运行在服务器上的对话服务</h2>' +
        '<p class="muted">纯 PHP · 零依赖 · 数据留在自己的服务器 · 插件可热插拔</p>' +
        '<div class="welcome-chips">' +
        '<button class="chip" data-prompt="介绍一下你自己，以及你能调用哪些工具">你能做什么？</button>' +
        '<button class="chip" data-prompt="/ping">/ping 自检</button>' +
        '<button class="chip" data-prompt="现在几点了？顺便算一下 (12+3)*4">试试工具调用</button>' +
        '</div></div>';
      bindChips();
      done();
      return;
    }
    box.innerHTML = '';
    S.messages.forEach((m) => box.appendChild(msgNode(m, false)));
    done();
  }

  function bindChips() {
    $$('.chip[data-prompt]').forEach((c) => c.addEventListener('click', () => {
      $('#input').value = c.dataset.prompt; send();
    }));
  }

  /* ---------- 插件 / 技能 ---------- */
  function renderPlugins() {
    $('#plugin-count').textContent = S.plugins.length;
    $('#plugin-list').innerHTML = S.plugins.map((p) =>
      '<div class="item" data-id="' + esc(p.id) + '" title="' + esc(p.description || '') +
      (p.source === 'market' ? ' · 来自插件市场' : '') +
      (p.tools && p.tools.length ? ' · 工具: ' + esc(p.tools.join(', ')) : '') + '">' +
      '<span class="dot ' + (p.enabled ? 'on' : 'off') + '"></span>' +
      '<span class="txt">' + esc(p.name) + '</span>' +
      '<span class="sub">' + (p.broken ? '加载失败' : (p.enabled ? '启用' : '关闭')) + '</span></div>'
    ).join('') || '<div class="item disabled"><span class="txt muted">plugins/ 目录为空</span></div>';
    $$('#plugin-list .item[data-id]').forEach((el) => el.addEventListener('click', async () => {
      const p = S.plugins.find((x) => x.id === el.dataset.id);
      if (!p || p.broken) return;
      const j = await api('api/plugin/toggle', { id: p.id, enabled: !p.enabled });
      if (j.ok) {
        p.enabled = !p.enabled; renderPlugins();
        emit('plugin:toggle', { id: p.id, enabled: p.enabled });
        if (S.market) {
          const m = (S.market.items || []).find((x) => x.id === p.id);
          if (m) { m.enabled = p.enabled; renderMarket(); }
        }
      }
    }));
  }

  async function refreshPlugins() {
    try { const j = await api('api/plugin/list', {}); S.plugins = j.plugins || []; renderPlugins(); syncState(); }
    catch (_) { /* 保留当前列表 */ }
  }

  function renderSkills() {
    $('#skill-count').textContent = S.skills.length;
    const MODE_TEXT = { always: '常驻', auto: '按需', off: '关闭' };
    $('#skill-list').innerHTML = S.skills.map((s) => {
      const mode = s.mode || (s.enabled ? 'always' : 'off');
      const tip = (s.description || '') + (s.when ? ' · 触发词：' + s.when : '') + ' · 点击切换状态';
      return '<div class="item" data-id="' + esc(s.id) + '" title="' + esc(tip) + '">' +
        '<span class="dot ' + (mode === 'always' ? 'on' : mode) + '"></span>' +
        '<span class="txt">' + esc(s.name) + '</span>' +
        '<span class="sub">' + (MODE_TEXT[mode] || mode) + '</span></div>';
    }).join('') || '<div class="item disabled"><span class="txt muted">skills/ 目录为空</span></div>';
    $$('#skill-list .item[data-id]').forEach((el) => el.addEventListener('click', async () => {
      const s = S.skills.find((x) => x.id === el.dataset.id);
      if (!s) return;
      const mode = s.mode || (s.enabled ? 'always' : 'off');
      // 三态循环：关闭 → 常驻 → 按需（仅带触发词的技能）→ 关闭
      const next = mode === 'off' ? 'always' : (mode === 'always' ? (s.auto ? 'auto' : 'off') : 'off');
      const payload = next === 'auto' ? { id: s.id, mode: 'auto' } : { id: s.id, enabled: next === 'always' };
      const j = await api('api/skill/toggle', payload);
      if (j.ok) { s.mode = next; s.enabled = next !== 'off'; renderSkills(); }
    }));
  }

  /* ---------- 发送 / 流式 ---------- */
  function busy(on) {
    S.busy = on;
    $('#btn-send').disabled = on;
    $('#btn-stop').hidden = !on;
    $('#input').disabled = on;
    emit('busy:change', { busy: on });
    syncState();
  }

  async function parseSSE(res, onEvent) {
    if (!res.body || !res.body.getReader) throw new Error('浏览器不支持流式读取');
    const reader = res.body.getReader(), dec = new TextDecoder();
    let buf = '', got = false;
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      buf += dec.decode(value, { stream: true });
      let idx;
      while ((idx = buf.indexOf('\n\n')) >= 0) {
        const block = buf.slice(0, idx); buf = buf.slice(idx + 2);
        let ev = 'message', data = '';
        for (const line of block.split('\n')) {
          if (line.startsWith('event:')) ev = line.slice(6).trim();
          else if (line.startsWith('data:')) data += line.slice(5).trim();
        }
        if (!data || data[0] === ':') continue;
        try { got = true; onEvent(ev, JSON.parse(data)); } catch (e) { /* 忽略坏帧 */ }
      }
    }
    if (!got) throw new Error('未收到任何流式事件');
  }

  async function send() {
    const input = $('#input');
    const text = (input.value || '').trim();
    if (!text || S.busy) return;
    emit('chat:send', { sid: S.sid, text: text, model: S.model });
    if (!S.sid) await newSession();
    input.value = ''; input.style.height = 'auto';
    S.messages.push({ role: 'user', content: text });
    renderMessages();
    const box = $('#messages');
    const pending = msgNode({ role: 'assistant', content: '思考中…' }, true);
    box.appendChild(pending);
    box.scrollTop = box.scrollHeight;
    const bubble = pending.querySelector('.bubble');
    busy(true); setStatus('正在请求模型…');
    $('#tool-hint').hidden = true;

    const payload = {
      sid: S.sid, text,
      tools: $('#tools-toggle').checked,
      text_tool_mode: S.textMode,
      model: S.model || undefined,
    };
    const useStream = $('#stream-toggle').checked;
    const ctl = new AbortController(); S.controller = ctl;
    let acc = '', tools = [], final = null, gotEvent = false;

    const renderAcc = () => {
      let extra = '';
      if (tools.length) extra = '<details class="tool-log" open><summary>工具调用 · ' + tools.length + ' 次</summary>' +
        tools.map((t) => '<div class="tool-item"><b>' + esc(t.name) + '</b> ' + (t.ok === false ? '<span class="fail">失败</span>' : '') +
          '\n→ ' + esc(String(t.result || '')) + '</div>').join('') + '</details>';
      bubble.innerHTML = md(acc || '思考中…') + (final ? '' : '<span class="caret"></span>') + extra;
      box.scrollTop = box.scrollHeight;
    };

    const onEvent = (ev, d) => {
      gotEvent = true;
      emit('stream:event', { event: ev, data: d, sid: S.sid });
      if (ev === 'stage') {
        if (d.stage === 'switch' || d.stage === 'switching') setStatus(d.note || '正在切换备用线路…');
        else setStatus(d.stage === 'fallback' ? (d.note || '已降级') : ('模型思考中 · 第 ' + (d.round || 1) + ' 轮'));
      }
      else if (ev === 'tool' && d.stage === 'done') {
        tools.push({ name: d.name, ok: d.ok, result: d.result });
        $('#tool-hint').hidden = false;
        $('#tool-hint').textContent = '调用插件：' + d.name + (d.ok ? ' ✓' : ' ✗');
        renderAcc();
      } else if (ev === 'delta') { acc += d.text || ''; renderAcc(); }
      else if (ev === 'done') {
        final = d; acc = (d.message && d.message.content) || acc;
        tools = (d.tool_log && d.tool_log.length) ? d.tool_log : tools;
        const via = d.offline ? '离线兜底' : ((d.route_used && d.route_used.name ? d.route_used.name + ' / ' : '') + (d.model || ''));
        setStatus(d.slash ? '插件直调完成' : ('完成 · ' + via + (d.usage && d.usage.total_tokens ? ' · ' + d.usage.total_tokens + ' tokens' : '')));
        renderAcc();
        emit('chat:done', d);
        if (DSH && DSH._setState) DSH._setState({ usage: d.usage || null, model: d.model || S.model, lastReplyAt: Date.now() });
      } else if (ev === 'error') {
        final = d; acc = '❌ ' + (d.error || (d.message && d.message.content) || '生成失败');
        setStatus('生成失败'); renderAcc();
        emit('chat:error', d);
      }
    };

    try {
      if (useStream) {
        const res = await fetch('index.php?r=api/chat/stream', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': B.csrf },
          body: JSON.stringify(payload), signal: ctl.signal,
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        try { await parseSSE(res, onEvent); }
        catch (e) {
          if (gotEvent) throw new Error('流提前结束：' + e.message);
          throw e; // 一个事件都没收到 → 允许回退到普通请求（服务端可能还没落库）
        }
      } else {
        const j = await api('api/chat/send', payload, { signal: ctl.signal });
        onEvent(j.ok ? 'done' : 'error', j);
      }
    } catch (e) {
      if (e.name === 'AbortError') {
        setStatus('已停止（服务端可能仍会完成本次生成并保存结果）');
        bubble.innerHTML = md(acc || '已停止') + '<p class="muted tiny">前端已断开；服务端可能仍在生成，刷新后可看到结果。</p>';
        busy(false); return;
      }
      if (useStream && !gotEvent) {
        // 流式整体不可用（Nginx 缓冲 / 无 ReadableStream）→ 自动降级一次
        setStatus('流式不可用，已自动降级为普通请求…');
        try {
          const j = await api('api/chat/send', payload, { signal: ctl.signal });
          onEvent(j.ok ? 'done' : 'error', j);
        } catch (e2) {
          bubble.innerHTML = md('❌ 请求失败：' + e2.message); setStatus('请求失败');
        }
      } else {
        bubble.innerHTML = md(acc || ('❌ 请求失败：' + e.message)); setStatus('请求失败');
      }
    } finally {
      if (S.controller === ctl) S.controller = null;
      busy(false);
      $('#input').focus();
      try { await selectSession(S.sid); } catch (_) { /* 保留当前视图 */ }
    }
  }

  /* ---------- 设置 ---------- */
  function fillSettings() {
    const st = S.settings, l = st.llm || {}, ch = st.chat || {}, sec = st.security || {};
    const sel = $('#set-preset');
    sel.innerHTML = Object.entries(st.presets || {}).map(([k, v]) =>
      '<option value="' + esc(k) + '"' + (k === st.route ? ' selected' : '') + '>' + esc(v.name) + '</option>').join('');
    $('#set-base').value = l.base_url || '';
    $('#set-model').value = l.model || '';
    $('#set-auth').value = l.auth_mode || 'bearer';
    $('#set-key').value = '';
    $('#set-timeout').value = l.timeout || 90;
    renderModelRows(l.models);
    $('#set-system').value = ch.system_prompt || '';
    const sp = $('#set-syspreset');
    const spList = sysPresets();
    sp.innerHTML = '<option value="">自定义 / 留空（用站点默认）</option>' +
      spList.map((p) => '<option value="' + esc(p.id) + '">' + esc(p.name) + '</option>').join('');
    const spCur = ch.system_prompt || '';
    const spMatch = spList.find((p) => p.prompt === spCur);
    sp.value = spMatch ? spMatch.id : '';
    $('#set-temp').value = ch.temperature ?? 0.7;
    $('#set-maxtok').value = ch.max_tokens || 2048;
    $('#set-history').value = ch.history_limit || 20;
    $('#set-rounds').value = ch.max_tool_rounds || 4;
    $('#set-plugins').checked = sec.allow_plugins !== false;
    $('#set-stream').checked = !!ch.stream_default;
    $('#set-textmode').checked = S.textMode;
    const ro = st.routing || {};
    $('#set-routing-auto').checked = ro.mode !== 'fixed';
    $('#set-local-fallback').checked = ro.local_fallback !== false;
    $('#set-cooldown').value = ro.cooldown ?? 120;
    $('#set-switches').value = ro.max_switches ?? 3;
    renderRoutes();
    $('#about-driver').textContent = B.driver || '—';
    $('#about-http').textContent = $('#status-line').textContent.includes('cURL') ? 'cURL' : (window.DSH_HTTP || '自动');
  }

  /* ---------- 线路（自动故障转移） ---------- */
  function renderRoutes() {
    const box = $('#route-list');
    const list = S.settings.routes || [];
    if (!list.length) { box.innerHTML = '<div class="item disabled"><span class="txt muted">暂无线路</span></div>'; return; }
    box.innerHTML = list.map((r, i) => {
      const tag = !r.enabled ? '<span class="route-tag tag-off">已停用</span>'
        : r.cooldown_left > 0 ? '<span class="route-tag tag-cool">冷却 ' + r.cooldown_left + 's</span>'
          : '<span class="route-tag tag-ok">正常</span>';
      return '<div class="route-row">' +
        '<input type="checkbox" data-act="toggle" data-i="' + i + '"' + (r.enabled ? ' checked' : '') + (r.primary ? ' disabled' : '') + '>' +
        '<div class="route-main"><div class="route-name">' + esc(r.name) + (r.primary ? '（主路由）' : '') + '</div>' +
        '<div class="route-meta">' + esc(r.model) + ' · ' + esc(r.base_url) +
        (r.last_error ? ' · ' + esc(r.last_error) : '') + '</div></div>' + tag +
        (r.primary ? '' : '<button class="route-up" data-act="up" data-i="' + i + '" title="上移一位">↑</button>') +
        '</div>';
    }).join('');
  }

  function routeCandidates() {
    const presets = S.settings.presets || {};
    return (S.settings.routes || []).filter((r) => !r.primary).map((r) => {
      const p = presets[r.key] || {};
      return {
        key: r.key, name: r.name, base_url: r.base_url, model: r.model,
        auth_mode: r.auth_mode,
        api_key: typeof p.api_key === 'string' ? p.api_key : (r.has_key ? '••••••••' : ''),
        enabled: !!r.enabled,
      };
    });
  }

  async function refreshRoutes() {
    try {
      const j = await api('api/routes/list');
      if (j.ok) { S.settings.routes = j.routes; S.settings.routing = j.routing; renderRoutes(); }
    } catch (_) { /* 保留当前列表 */ }
  }

  async function testRoutes() {
    const el = $('#route-test-result');
    el.textContent = '正在逐条测试…（每条只发一句「只回复 OK」）';
    try {
      const j = await api('api/routes/test');
      el.textContent = (j.results || []).map((r) =>
        (r.ok ? '✓ ' : '✗ ') + r.name + ' — ' + (r.ok ? ('HTTP ' + r.status + ' ' + (r.reply || '')) : (r.error || '失败'))
      ).join('\n');
      await refreshRoutes();
    } catch (e) { el.textContent = '测试失败：' + e.message; }
  }

  async function resetCooldown() {
    const el = $('#route-test-result');
    try {
      const j = await api('api/routes/reset');
      await refreshRoutes();
      el.textContent = j.note || '已清除冷却';
    } catch (e) { el.textContent = '清除失败：' + e.message; }
  }

  function openModal() { fillSettings(); $('#modal').hidden = false; emit('modal:open', {}); }
  function closeModal() { $('#modal').hidden = true; $('#modal-card').classList.remove('wide'); emit('modal:close', {}); }

  async function saveSettings() {
    $('#save-result').textContent = '保存中…';
    const body = {
      route: $('#set-preset').value,
      llm: {
        base_url: $('#set-base').value.trim(), model: $('#set-model').value.trim(),
        auth_mode: $('#set-auth').value, timeout: +$('#set-timeout').value || 90,
        models: collectModelRows(),
      },
      chat: {
        system_prompt: $('#set-system').value, temperature: +$('#set-temp').value,
        max_tokens: +$('#set-maxtok').value, history_limit: +$('#set-history').value,
        max_tool_rounds: +$('#set-rounds').value, stream_default: $('#set-stream').checked,
      },
      security: { allow_plugins: $('#set-plugins').checked },
      routing: {
        mode: $('#set-routing-auto').checked ? 'auto' : 'fixed',
        cooldown: +$('#set-cooldown').value || 0,
        max_switches: +$('#set-switches').value || 3,
        local_fallback: $('#set-local-fallback').checked,
        candidates: routeCandidates(),
      },
    };
    const key = $('#set-key').value;
    if (key) body.llm.api_key = key;
    try {
      const j = await api('api/settings/save', body);
      $('#save-result').textContent = j.ok ? '已保存，后续对话立即生效' : (j.error || '保存失败');
      if (j.ok) {
        S.settings.llm = Object.assign(S.settings.llm, body.llm, { api_key_set: S.settings.llm.api_key_set || !!key });
        S.settings.chat = Object.assign(S.settings.chat, body.chat);
        S.settings.security = Object.assign(S.settings.security, body.security);
        S.settings.routing = Object.assign(S.settings.routing || {}, body.routing);
        S.settings.route = body.route;
        S.textMode = $('#set-textmode').checked;
        localStorage.setItem('dsh.textmode', S.textMode ? '1' : '0');
        $('#stream-toggle').checked = !!body.chat.stream_default;
        $('#tools-toggle').checked = !!body.security.allow_plugins;
        fillModels();
        emit('settings:save', body);
      }
    } catch (e) { $('#save-result').textContent = '保存失败：' + e.message; }
  }

  function fillModels() {
    const l = S.settings.llm || {};
    const list = (l.models && l.models.length) ? l.models : [l.model || 'default'];
    if (!list.includes(l.model) && l.model) list.unshift(l.model);
    const sel = $('#model-select');
    sel.innerHTML = list.map((m) => '<option value="' + esc(m) + '">' + esc(m) + '</option>').join('');
    const want = S.model && list.includes(S.model) ? S.model : (l.model || list[0]);
    sel.value = want; S.model = want;
    updateTitle();
    emit('model:change', { model: S.model, models: list.slice() });
    syncState();
  }

  /* ---------- 模型多行编辑（设置页） ---------- */
  function modelRowHtml(val) {
    return '<div class="model-row">' +
      '<input class="model-input" type="text" value="' + esc(val) + '" placeholder="模型 ID，如 deepseek-chat" spellcheck="false">' +
      '<button class="model-del btn ghost sm" type="button" title="删除该模型">×</button>' +
      '</div>';
  }

  function renderModelRows(list) {
    const box = $('#set-models-list');
    if (!box) return;
    const arr = (list && list.length) ? list : [];
    box.innerHTML = arr.map((m) => modelRowHtml(m)).join('');
    box.querySelectorAll('.model-del').forEach((b) => b.addEventListener('click', () => {
      const row = b.closest('.model-row');
      if (row) row.remove();
    }));
  }

  function collectModelRows() {
    const box = $('#set-models-list');
    if (!box) return [];
    return Array.from(box.querySelectorAll('.model-input'))
      .map((i) => i.value.trim()).filter(Boolean);
  }

  function addModelRow(val) {
    const box = $('#set-models-list');
    if (!box) return;
    const row = document.createElement('div');
    row.className = 'model-row';
    row.innerHTML = '<input class="model-input" type="text" placeholder="模型 ID，如 deepseek-chat" spellcheck="false">' +
      '<button class="model-del btn ghost sm" type="button" title="删除该模型">×</button>';
    if (val) row.querySelector('.model-input').value = val;
    row.querySelector('.model-del').addEventListener('click', () => row.remove());
    box.appendChild(row);
    row.querySelector('.model-input').focus();
  }

  async function fetchModels() {
    const el = $('#fetch-models-result');
    const base = $('#set-base').value.trim();
    const auth = $('#set-auth').value;
    const key = $('#set-key').value; // 仅当已输入才发送，避免把半截 Key 发到后端
    if (!base) { el.textContent = '请先填写 API Base URL'; return; }
    el.textContent = '正在从上游获取模型列表…';
    try {
      const j = await api('api/settings/models', {
        base_url: base, auth_mode: auth, api_key: key || undefined,
      });
      if (!j.ok) { el.textContent = '✗ ' + (j.error || '获取失败'); return; }
      const models = (j.models || []).filter(Boolean);
      renderModelRows(models);
      if (!$('#set-model').value.trim() && models[0]) $('#set-model').value = models[0];
      el.textContent = '✓ 已获取 ' + models.length + ' 个模型' + (models[0] ? '，已将首个设为默认' : '');
    } catch (e) { el.textContent = '✗ ' + e.message; }
  }

  /* ---------- 插件市场 ---------- */
  function refreshLine() {
    const tools = S.market ? S.market.stats.tools : '?';
    setLine('v' + B.version + ' · ' + B.driver + ' · ' + (S.settings.llm?.model || '') +
      ' · 插件 ' + S.plugins.length + ' / 工具 ' + tools + ' · PHP ' + B.php);
  }

  function applyMarket(j) {
    if (j.market) S.market = j.market;
    renderMarket();
    B.market = S.market ? { total: S.market.stats.total, installed: S.market.stats.installed } : B.market;
    refreshPlugins();
    refreshLine();
  }

  async function loadMarket(force) {
    if (S.market && !force) { renderMarket(); return; }
    $('#market-grid').innerHTML = '<div class="mkt-empty">正在读取插件仓库…</div>';
    try {
      const j = await api('api/market/list', {});
      S.market = j;
      renderMarket();
      refreshLine();
    } catch (e) {
      $('#market-grid').innerHTML = '<div class="mkt-empty">加载失败：' + esc(e.message) + '</div>';
    }
  }

  function renderMarket() {
    const m = S.market;
    if (!m) return;
    $('#market-stats').textContent = m.stats.total + ' 个插件 · 已装 ' + m.stats.installed + ' · 工具 ' + m.stats.tools;
    const cats = m.categories || [];
    const catNames = {};
    cats.forEach((c) => { catNames[c.key] = c.name; });
    $('#market-cats').innerHTML = '<button class="market-chip' + (S.marketCat === 'all' ? ' active' : '') +
      '" data-cat="all">全部<span class="n">' + m.stats.total + '</span></button>' +
      cats.map((c) => '<button class="market-chip' + (S.marketCat === c.key ? ' active' : '') +
        '" data-cat="' + esc(c.key) + '" title="' + esc(c.desc) + '">' + esc(c.name) +
        '<span class="n">' + c.count + '</span></button>').join('');

    const q = S.marketQuery.trim().toLowerCase();
    const list = (m.items || []).filter((p) => {
      if (S.marketCat !== 'all' && p.category !== S.marketCat) return false;
      if (!q) return true;
      const hay = [p.id, p.name, p.description, p.usage, (p.tags || []).join(' '),
        (p.slash || []).join(' '), (p.tools || []).join(' ')].join(' ').toLowerCase();
      return hay.includes(q);
    });
    if (!list.length) {
      if (m.items && m.items.length === 0 && m.scan) {
        const s = m.scan;
        $('#market-grid').innerHTML =
          '<div class="mkt-empty">插件仓库为空，已自动诊断：<br>' +
          '· market 目录：<code>' + esc(s.market_dir) + '</code><br>' +
          '· 目录存在：' + (s.dir_exists ? '是' : '否') + ' · 可读：' + (s.dir_readable ? '是' : '否') + '<br>' +
          '· PHP：' + esc(s.php) + '<br><br>' +
          '如果 <b>market 目录存在且有文件、但仍为空</b>，最常见的原因是：服务器上的核心文件还是旧版' +
          '（旧版用 <code>glob()</code> 扫描，在部分主机上会返回空）。请<b>重新上传整个包</b>，' +
          '至少覆盖 <code>app/bootstrap.php</code>、<code>app/market.php</code>、<code>app/plugins.php</code> 与 <code>index.php</code>。' +
          '上传后访问 <code>ping.php</code> 看 <code>dshphp=</code> 是否为 <b>2.2.2</b>，' +
          '不是就说明旧核心文件没被覆盖。</div>';
      } else {
        $('#market-grid').innerHTML = '<div class="mkt-empty">没有匹配的插件，换个关键词试试。</div>';
      }
      emit('market:render', { count: 0, category: S.marketCat, host: $('#market-grid') });
      return;
    }
    $('#market-grid').innerHTML = list.map((p) => {
      const inst = !!p.installed;
      const tags = (p.tags || []).slice(0, 3).map((t) => '<span class="mkt-tag">' + esc(t) + '</span>').join('');
      const cmds = (p.slash || []).slice(0, 2).map((s) => '<span class="mkt-tag cmd">/' + esc(s) + '</span>').join('');
      const store = p.risk === 'store' ? '<span class="mkt-tag store">存储</span>' :
        (p.risk === 'read' ? '<span class="mkt-tag store">读环境</span>' : '');
      return '<div class="mkt-card' + (inst ? ' installed' : '') + '" data-id="' + esc(p.id) + '">' +
        '<div class="mkt-head"><div class="mkt-icon">' + esc(String(p.name || '?').slice(0, 1)) + '</div>' +
        '<div class="mkt-title"><div class="mkt-name">' + esc(p.name) + (p.broken ? ' ⚠' : '') + '</div>' +
        '<div class="mkt-cat">' + esc(catNames[p.category] || p.category) + ' · v' + esc(p.version) + '</div></div></div>' +
        '<div class="mkt-desc">' + esc(p.description) + '</div>' +
        '<div class="mkt-meta">' + tags + cmds + store + '</div>' +
        '<div class="mkt-usage">' + esc(p.usage) + '</div>' +
        '<div class="mkt-foot"><span class="mkt-state' + (inst ? '' : ' off') + '">' +
        (inst ? '● 已安装' : '○ 未安装') + '</span><span style="flex:1"></span>' +
        (inst
          ? '<button class="btn ghost sm" data-act="toggle">' + (p.enabled === false ? '启用' : '停用') +
            '</button><button class="btn ghost sm" data-act="uninstall">卸载</button>'
          : '<button class="btn primary sm" data-act="install">安装</button>') +
        '</div></div>';
    }).join('');
    emit('market:render', { count: list.length, category: S.marketCat, host: $('#market-grid') });
  }

  async function marketPost(route, body, successText) {
    const el = $('#market-result');
    el.textContent = '处理中…';
    try {
      const j = await api('api/market/' + route, body);
      if (!j.ok) { el.textContent = '失败：' + (j.error || '未知错误'); return; }
      applyMarket(j);
      el.textContent = successText || '操作完成';
    } catch (e) { el.textContent = '失败：' + e.message; }
  }

  function openMarket() {
    openModal();
    const tab = $('.tab[data-tab="market"]');
    if (tab) tab.click();
  }

  /* ---------- 事件绑定 ---------- */
  function bind() {
    $('#btn-new').addEventListener('click', () => { if (!S.busy) newSession(); });
    $('#btn-send').addEventListener('click', send);
    $('#btn-stop').addEventListener('click', () => { if (S.controller) S.controller.abort(); });
    $('#btn-clear').addEventListener('click', async () => {
      if (!S.sid || !confirm('清空当前对话的所有消息？')) return;
      await api('api/session/clear', { sid: S.sid });
      S.messages = []; renderMessages(); updateTitle();
    });
    const input = $('#input');
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
    });
    input.addEventListener('input', () => {
      input.style.height = 'auto'; input.style.height = Math.min(180, input.scrollHeight) + 'px';
      const v = input.value.trim();
      const hint = $('#tool-hint');
      if (v.startsWith('/')) {
        const cmds = S.plugins.flatMap((p) => Object.keys(p.slash || {}));
        hint.hidden = false; hint.textContent = '插件命令：' + (cmds.length ? cmds.map((c) => '/' + c).join('  ') : '（暂无）');
      } else if (!S.busy) hint.hidden = true;
    });
    $('#btn-settings').addEventListener('click', openModal);
    $('#btn-market').addEventListener('click', openMarket);
    $('#btn-modal-close').addEventListener('click', closeModal);
    $('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
    $$('.tab').forEach((t) => t.addEventListener('click', () => {
      $$('.tab').forEach((x) => x.classList.remove('active'));
      $$('.tab-pane').forEach((x) => x.classList.remove('active'));
      t.classList.add('active');
      $('.tab-pane[data-pane="' + t.dataset.tab + '"]').classList.add('active');
      $('#modal-card').classList.toggle('wide', t.dataset.tab === 'market');
      if (t.dataset.tab === 'market') loadMarket();
    }));
    $('#set-preset').addEventListener('change', () => {
      const p = (S.settings.presets || {})[$('#set-preset').value];
      if (!p || !p.base_url) return;
      $('#set-base').value = p.base_url; $('#set-model').value = p.model;
      $('#set-auth').value = p.auth_mode;
      $('#test-result').textContent = p.note || '';
    });
    $('#btn-test').addEventListener('click', async () => {
      $('#test-result').textContent = '测试中…（只发一句「只回复 OK」）';
      try {
        const j = await api('api/settings/test', {
          base_url: $('#set-base').value.trim(), model: $('#set-model').value.trim(),
          auth_mode: $('#set-auth').value,
        });
        $('#test-result').textContent = j.ok ? ('✓ 连通，模型回复：' + j.reply) : ('✗ ' + (j.error || '失败') + '（HTTP ' + j.status + '）');
      } catch (e) { $('#test-result').textContent = '✗ ' + e.message; }
    });
    $('#btn-save').addEventListener('click', saveSettings);
    $('#btn-add-model').addEventListener('click', () => addModelRow(''));
    $('#btn-fetch-models').addEventListener('click', fetchModels);
    $('#route-list').addEventListener('change', (e) => {
      if (e.target.dataset.act !== 'toggle') return;
      const i = +e.target.dataset.i, arr = S.settings.routes || [];
      if (!arr[i] || arr[i].primary) return;
      arr[i].enabled = e.target.checked;
      renderRoutes();
    });
    $('#route-list').addEventListener('click', (e) => {
      if (e.target.dataset.act !== 'up') return;
      const i = +e.target.dataset.i, arr = S.settings.routes || [];
      if (i <= 1 || !arr[i]) return; // 0 是主路由，不能上移
      [arr[i - 1], arr[i]] = [arr[i], arr[i - 1]];
      renderRoutes();
    });
    $('#btn-routes-test').addEventListener('click', testRoutes);
    $('#btn-routes-reset').addEventListener('click', resetCooldown);

    $('#market-cats').addEventListener('click', (e) => {
      const b = e.target.closest('[data-cat]');
      if (!b) return;
      S.marketCat = b.dataset.cat;
      renderMarket();
    });
    $('#market-search').addEventListener('input', (e) => { S.marketQuery = e.target.value; renderMarket(); });
    $('#market-grid').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-act]');
      const card = b && b.closest('[data-id]');
      if (!b || !card) return;
      const id = card.dataset.id, act = b.dataset.act;
      if (act === 'install') await marketPost('install', { id }, '已安装并启用：' + id);
      else if (act === 'uninstall') await marketPost('uninstall', { id }, '已卸载：' + id);
      else if (act === 'toggle') {
        const p = (S.market?.items || []).find((x) => x.id === id);
        if (!p) return;
        try {
          await api('api/plugin/toggle', { id, enabled: p.enabled === false });
          await loadMarket(true);
          await refreshPlugins();
          $('#market-result').textContent = '已' + (p.enabled === false ? '启用' : '停用') + '：' + id;
        } catch (err) { $('#market-result').textContent = '失败：' + err.message; }
      }
    });
    $('#btn-mkt-recommend').addEventListener('click', () =>
      marketPost('batch', { preset: 'recommended' }, '已切换到推荐套装（8 个常用插件）'));
    $('#btn-mkt-all').addEventListener('click', () => {
      if (!confirm('全部安装会把所有工具的 schema 都带上，免费模型可能变慢、也更容易触发参数降级。确定继续？')) return;
      marketPost('batch', { preset: 'all' }, '已安装全部插件');
    });
    $('#btn-mkt-none').addEventListener('click', () => {
      if (!confirm('卸载全部市场插件？你自己上传到 plugins/ 的插件不会受影响。')) return;
      marketPost('batch', { preset: 'none' }, '已卸载全部市场插件');
    });
    $('#btn-mkt-refresh').addEventListener('click', async () => {
      await loadMarket(true);
      $('#market-result').textContent = '已刷新';
    });
    $('#model-select').addEventListener('change', () => {
      S.model = $('#model-select').value; localStorage.setItem('dsh.model', S.model); updateTitle();
    });
    $('#set-syspreset').addEventListener('change', () => {
      const id = $('#set-syspreset').value;
      if (!id) return;               // 自定义：保留文本框内容，让用户继续编辑
      const p = sysPresets().find((x) => x.id === id);
      if (p) $('#set-system').value = p.prompt;
    });
    $('#role-select').addEventListener('change', async () => {
      const id = $('#role-select').value;
      const p = sysPresets().find((x) => x.id === id);
      const sys = (id && p) ? p.prompt : '';   // 空 = 用站点默认系统提示词
      if (S.sid) { try { await api('api/session/rename', { sid: S.sid, system: sys }); } catch (_) {} }
    });
    $('#btn-menu').addEventListener('click', () => $('.sidebar').classList.toggle('open'));
    bindChips();
  }

  async function init() {
    // 把核心能力交给插件运行时（只读为主，动作受核心自身的登录/CSRF 约束）
    if (DSH && DSH._attach) DSH._attach({
      api: api, esc: esc, md: md, emit: emit,
      renderSessions: renderSessions, refreshSessions: refreshSessions,
      selectSession: selectSession, newSession: newSession, renderMessages: renderMessages,
      renderPlugins: renderPlugins, refreshPlugins: refreshPlugins, renderSkills: renderSkills,
      setStatus: setStatus, setLine: setLine, openModal: openModal, closeModal: closeModal,
      loadMarket: loadMarket, fillModels: fillModels, saveSettings: saveSettings, send: send,
      getState: () => ({
        sid: S.sid, sessions: S.sessions.slice(), messages: S.messages.slice(),
        model: S.model, settings: S.settings, busy: S.busy,
      }),
    });
    emit('app:boot', { version: B.version, driver: B.driver, ui: B.ui || null });
    renderSessions(); renderPlugins(); renderSkills(); renderMessages(); fillModels(); bind(); syncRole();
    emit('bind:done', {});
    if (S.sessions.length) { try { await selectSession(S.sessions[0].id); } catch (_) {} }
    try {
      const j = await api('api/status', {});
      window.DSH_HTTP = j.data.http === 'curl' ? 'cURL' : 'stream';
      setLine('v' + j.data.version + ' · ' + j.data.driver + ' · ' + j.data.model + ' · 插件 ' + j.data.plugins + ' / 工具 ' + j.data.tools + ' · PHP ' + j.data.php);
      emit('status:ready', j.data);
    } catch (e) { setLine('状态获取失败：' + e.message); }
    $('#input').focus();
    syncState();
    if (DSH && DSH._setReady) DSH._setReady();
  }
  init();
})();
