/* dshphp 2.4.0 — 插件 GUI 运行时：window.DSH
 * 在 app.js 之前加载（defer 顺序），app.js 通过 DSH._attach / _setState / emit 埋点。
 * 插件 bundle（index.php?r=plugin/ui.js）最后加载，通过 DSH._bundle 注入。
 * 每个插件代码在独立函数作用域内执行，单个插件报错不会影响其它插件。
 */
(function () {
  'use strict';
  if (window.DSH) return;
  var boot = window.DSH_BOOT || {};
  var uiBoot = boot.ui || { enabled: false, scope: 'app', plugins: [], slots: [] };

  var listeners = Object.create(null);
  var history = [];
  var state = {};
  var renderers = Object.create(null);
  var readyState = false;
  var readyQueue = [];

  var SLOTS = {
    'sidebar-top': '#dsh-slot-sidebar-top',
    'sidebar-foot': '#dsh-slot-sidebar-foot',
    'topbar': '#dsh-slot-topbar',
    'composer': '#dsh-slot-composer',
    'status': '#dsh-slot-status',
    'overlay': '#dsh-slot-overlay',
    'login': '#dsh-slot-login',
    'toasts': '#dsh-toasts'
  };

  function safeKey(s) { return String(s == null ? '' : s).replace(/[^A-Za-z0-9_-]/g, '_'); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === 'class') n.className = String(v);
      else if (k === 'text') n.textContent = String(v);
      else if (k === 'html') n.innerHTML = String(v);
      else if (k.slice(0, 2) === 'on' && typeof v === 'function') n.addEventListener(k.slice(2), v);
      else n.setAttribute(k, String(v));
    });
    (children || []).forEach(function (c) {
      if (c == null) return;
      n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return n;
  }
  function getState() { return Object.assign({}, state); }

  function on(name, fn, opts) {
    if (typeof name !== 'string' || typeof fn !== 'function') return null;
    var o = opts || {};
    var h = { name: name, fn: fn, owner: o.owner || '', once: !!o.once };
    (listeners[name] || (listeners[name] = [])).push(h);
    if (o.replay) history.forEach(function (r) {
      if (r.name === name) { try { fn(r.data, r); } catch (e) { console.warn('[dsh-ui] replay', name, e); } }
    });
    return h;
  }
  function off(h) {
    if (!h) return;
    var arr = listeners[h.name];
    if (!arr) return;
    var i = arr.indexOf(h);
    if (i >= 0) arr.splice(i, 1);
  }
  function emit(name, data) {
    var rec = { name: name, data: data, ts: Date.now() };
    history.push(rec);
    if (history.length > 60) history.shift();
    [[name], ['*']].forEach(function (pair) {
      (listeners[pair[0]] || []).slice().forEach(function (h) {
        if (pair[0] === '*' && name === '*') return;
        try { h.fn(data, rec); } catch (e) { console.warn('[dsh-ui] listener ' + name + ' -> ' + h.owner, e); }
        if (h.once) off(h);
      });
    });
    return rec;
  }
  function ready(fn) {
    if (typeof fn !== 'function') return;
    if (readyState) { try { fn(DSH); } catch (e) { console.warn('[dsh-ui] ready', e); } }
    else readyQueue.push(fn);
  }
  function setReady() {
    if (readyState) return;
    readyState = true;
    emit('app:ready', { scope: uiBoot.scope, plugins: DSH.plugins.slice() });
    readyQueue.splice(0).forEach(function (fn) { try { fn(DSH); } catch (e) { console.warn('[dsh-ui] ready', e); } });
  }

  function makeStorage(ns) {
    var p = 'dsh.ui.' + safeKey(ns) + '.';
    return {
      get: function (k, d) {
        try { var v = localStorage.getItem(p + safeKey(k)); return v === null ? d : JSON.parse(v); }
        catch (e) { return d; }
      },
      set: function (k, v) {
        try {
          var s = JSON.stringify(v);
          if (s.length > 65536) return false;
          localStorage.setItem(p + safeKey(k), s); return true;
        } catch (e) { return false; }
      },
      del: function (k) { try { localStorage.removeItem(p + safeKey(k)); return true; } catch (e) { return false; } },
      keys: function () {
        var out = [];
        try {
          for (var i = 0; i < localStorage.length; i++) {
            var k = localStorage.key(i);
            if (k && k.indexOf(p) === 0) out.push(k.slice(p.length));
          }
        } catch (e) { /* 隐私模式 */ }
        return out;
      }
    };
  }

  function resolveSlot(s) {
    if (!s) return null;
    if (typeof s !== 'string') return s.nodeType ? s : null;
    return document.querySelector(SLOTS[s] || s);
  }
  function mount(slot, node, opts) {
    var host = resolveSlot(slot);
    if (!host || !node) return null;
    var o = opts || {};
    if (o.id) {
      var key = safeKey(o.id);
      var old = host.querySelector('[data-dsh-mount="' + key + '"]');
      if (old) old.remove();
      node.setAttribute('data-dsh-mount', key);
    }
    if (o.mode === 'replace') host.innerHTML = '';
    if (o.mode === 'prepend') host.insertBefore(node, host.firstChild);
    else host.appendChild(node);
    return node;
  }
  function css(text, id) {
    var key = 'dsh-ui-style-' + safeKey(id || 'inline');
    var n = document.getElementById(key);
    if (!n) { n = el('style', { id: key }); document.head.appendChild(n); }
    n.textContent = String(text || '');
    return n;
  }
  function toast(msg, type) {
    var host = document.getElementById('dsh-toasts');
    if (!host) { console.log('[dsh-ui]', msg); return; }
    var n = el('div', { class: 'dsh-toast ' + (type || 'info'), text: String(msg) });
    host.appendChild(n);
    setTimeout(function () {
      n.classList.add('out');
      setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, 320);
    }, 3200);
  }
  /** 通用浮层面板，插件用它做设置界面，返回 { root, body, close, toggle } */
  function panel(opts) {
    var o = opts || {};
    var host = resolveSlot(o.slot || 'overlay');
    if (!host) return null;
    var key = safeKey(o.id || 'panel');
    var old = host.querySelector('[data-dsh-mount="' + key + '"]');
    if (old) old.remove();
    var body = el('div', { class: 'dsh-panel-body' });
    var root = el('div', { class: 'dsh-panel', 'data-dsh-mount': key }, [
      el('div', { class: 'dsh-panel-head' }, [
        el('strong', { text: o.title || '插件面板' }),
        el('button', { type: 'button', class: 'btn ghost sm', text: '✕', title: '关闭', onclick: function () { close(); } })
      ]),
      body
    ]);
    host.appendChild(root);
    function close() { if (root.parentNode) root.parentNode.removeChild(root); }
    function toggle() { return root.parentNode ? (close(), false) : (host.appendChild(root), true); }
    if (o.content) (Array.isArray(o.content) ? o.content : [o.content]).forEach(function (c) {
      if (c) body.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return { root: root, body: body, close: close, toggle: toggle };
  }

  async function api(route, data, opt) {
    if (uiBoot.scope !== 'app') throw new Error('登录页插件不允许调用受保护的 API');
    var o = opt || {};
    var res = await fetch('index.php?r=' + encodeURIComponent(route), {
      method: o.method || 'POST',
      headers: Object.assign({ 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrf || '' }, o.headers || {}),
      body: o.method === 'GET' ? undefined : JSON.stringify(data || {}),
      signal: o.signal
    });
    var j;
    try { j = await res.json(); } catch (e) { throw new Error('HTTP ' + res.status + '：响应不是 JSON'); }
    if (!res.ok && !j.ok) throw new Error(j.error || ('HTTP ' + res.status));
    return j;
  }

  var DSH = {
    version: 1,
    boot: boot,
    uiBoot: uiBoot,
    scope: uiBoot.scope || 'app',
    plugins: [],
    core: {},
    state: state,
    storage: makeStorage('_global'),
    on: on, off: off, emit: emit, replay: function (name, fn) { return on(name, fn, { replay: true }); },
    ready: ready, _setReady: setReady,
    getState: getState,
    _setState: function (patch) {
      if (!patch || typeof patch !== 'object') return;
      Object.assign(state, patch);
      emit('state:change', { patch: patch, state: getState() });
    },
    _attach: function (o) { Object.assign(DSH.core, o || {}); emit('core:attach', { keys: Object.keys(o || {}) }); },
    api: api,
    esc: esc, el: el,
    ui: {
      slots: SLOTS,
      slot: resolveSlot,
      mount: mount,
      css: css,
      toast: toast,
      panel: panel,
      btn: function (label, fn, cls) { return el('button', { type: 'button', class: 'btn ' + (cls || 'ghost sm'), text: label, onclick: fn }); },
      registerRenderer: function (name, fn) { if (typeof fn === 'function') renderers[name] = fn; return renderers[name] || null; },
      renderer: function (name, fn) { return DSH.ui.registerRenderer(name, fn); },
      getRenderer: function (name) { return renderers[name] || null; },
      renderSlot: function (name, host) { emit('render:slot', { slot: name, host: host || resolveSlot(name) }); }
    },
    history: function () { return history.slice(); }
  };

  function forPlugin(id, meta) {
    var api = Object.create(DSH);
    api.id = id;
    api.name = (meta && meta.name) || id;
    api.meta = meta || {};
    api.permissions = (meta && meta.permissions) || [];
    // 插件专属存储；兼容 2.4.0 早期版本：ready 回调曾误拿到全局 DSH，
    // 设置都落在 dsh.ui._global.*，这里首次读取时自动迁移，避免用户设置丢失。
    var scoped = makeStorage(id);
    var legacy = DSH.storage;
    var scopedKey = function (k) { return 'dsh.ui.' + safeKey(id) + '.' + safeKey(k); };
    api.storage = {
      get: function (k, d) {
        var v = scoped.get(k, undefined);
        if (v !== undefined) return v;
        try { if (localStorage.getItem(scopedKey(k)) !== null) return v; } catch (e) { /* 隐私模式 */ }
        var old = legacy.get(k, undefined);
        if (old !== undefined) { try { scoped.set(k, old); } catch (e) { /* 忽略写入失败 */ } return old; }
        return d;
      },
      set: function (k, v) { return scoped.set(k, v); },
      del: function (k) { return scoped.del(k); },
      keys: function () { return scoped.keys(); }
    };
    api.hasPermission = function (p) { return api.permissions.indexOf(p) >= 0; };
    api.on = function (name, fn, opts) { return on(name, fn, Object.assign({ owner: id }, opts || {})); };
    // ready 回调必须拿到插件专属 API（含上面隔离好的 storage / owner 标记），不能给全局 DSH
    api.ready = function (fn) {
      if (typeof fn !== 'function') return;
      ready(function () { fn(api); });
    };
    return api;
  }

  DSH._bundle = function (items) {
    (Array.isArray(items) ? items : []).forEach(function (it) {
      var entry = {
        id: it.id, name: it.name, scope: it.scope, mounts: it.mounts,
        permissions: it.permissions, actions: it.actions, ok: false, error: ''
      };
      try {
        var api = forPlugin(it.id, it);
        // 单插件隔离：语法/运行错误只影响它自己；插件 JS 视同服务端可信代码。
        var fn = new Function('DSH', 'window', 'document', it.code || '');
        fn.call(api, api, window, document);
        entry.ok = true;
      } catch (e) {
        entry.error = String((e && e.message) || e);
        console.warn('[dsh-ui] 插件 ' + it.id + ' 初始化失败：' + entry.error);
      }
      DSH.plugins.push(entry);
    });
    emit('plugins:ready', { plugins: DSH.plugins.slice() });
    if (DSH.scope !== 'app') setReady(); // 登录页没有 app.js，bundle 完成即就绪
  };

  window.DSH = DSH;

  // 兜底：主界面若 app.js 的 init 意外中断，也要让插件 ready（一次性，不是保活轮询）
  setTimeout(setReady, 2500);
  emit('runtime:boot', { scope: DSH.scope, version: DSH.version });
})();
