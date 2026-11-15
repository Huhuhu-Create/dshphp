<?php
/**
 * 美化 / 更好的侧边栏（2.4 GUI API 示范插件）
 * 演示能力：ui 清单 + DSH.ui.registerRenderer('sessions') 整体接管渲染
 *           + DSH.ui.mount('sidebar-top') + 命名空间 storage + 生命周期事件
 */
$css = <<<'CSS'
.dsh-sb-tools{display:flex;gap:6px;align-items:center;margin-bottom:2px}
.dsh-sb-search{flex:1;min-width:0;background:var(--panel2);border:1px solid var(--line);color:var(--text);border-radius:8px;padding:6px 9px;font-size:12px;outline:none}
.dsh-sb-search:focus{border-color:var(--accent)}
.dsh-sb-clear{background:none;border:0;color:var(--muted);cursor:pointer;font-size:14px;padding:0 4px}
.dsh-sb-group{display:flex;align-items:center;gap:6px;padding:8px 8px 3px;color:var(--muted);font-size:10px;letter-spacing:.06em;cursor:pointer;user-select:none}
.dsh-sb-group:before{content:'▾';font-size:9px;color:var(--accent)}
.dsh-sb-group.closed:before{content:'▸'}
.dsh-sb-group .n{margin-left:auto;background:var(--panel3);border-radius:99px;padding:0 6px;font-size:9px}
.dsh-sb-item{position:relative}
.dsh-sb-pin{position:absolute;right:26px;top:50%;transform:translateY(-50%);background:none;border:0;color:var(--muted);cursor:pointer;font-size:12px;opacity:0;line-height:1}
.dsh-sb-item:hover .dsh-sb-pin,.dsh-sb-pin.on{opacity:1}
.dsh-sb-pin.on{color:var(--amber)}
.dsh-sb-empty{padding:10px 8px;color:var(--muted);font-size:11px}
CSS;

$js = <<<'JS'
if (DSH.scope !== 'app') return;

DSH.ready(function (D) {
  var pinned = D.storage.get('pinned', []);
  var collapsed = D.storage.get('collapsed', {});
  var query = '';

  var search = D.el('input', { type: 'search', class: 'dsh-sb-search', placeholder: '搜索对话…' });
  var clear = D.el('button', { class: 'dsh-sb-clear', type: 'button', text: '×', title: '清除搜索' });
  var tools = D.el('div', { class: 'dsh-sb-tools' }, [search, clear]);
  D.ui.mount('sidebar-top', tools, { id: 'mkt.sidebar.tools' });

  search.addEventListener('input', function () {
    query = search.value.trim().toLowerCase();
    if (D.core.renderSessions) D.core.renderSessions();
  });
  clear.addEventListener('click', function () {
    search.value = ''; query = '';
    if (D.core.renderSessions) D.core.renderSessions();
  });

  function groupOf(s) {
    if (pinned.indexOf(s.id) >= 0) return 'pinned';
    var t = Number(s.updated || 0);
    var day = 86400000;
    if (Date.now() - t < day) return 'today';
    if (Date.now() - t < day * 7) return 'week';
    return 'older';
  }

  // 接管核心的会话列表渲染：核心 renderSessions() 会优先调用这个渲染器
  D.ui.registerRenderer('sessions', function (ctx) {
    var host = ctx.host;
    var list = (ctx.sessions || []).filter(function (s) {
      return !query || String(s.title || '').toLowerCase().indexOf(query) >= 0;
    });
    host.innerHTML = '';
    var defs = [
      { key: 'pinned', name: '置顶' },
      { key: 'today', name: '今天' },
      { key: 'week', name: '近 7 天' },
      { key: 'older', name: '更早' }
    ];
    var total = 0;
    defs.forEach(function (g) {
      var items = list.filter(function (s) { return groupOf(s) === g.key; });
      if (!items.length) return;
      total += items.length;
      var head = D.el('div', { class: 'dsh-sb-group' + (collapsed[g.key] ? ' closed' : '') }, [
        D.el('span', { text: g.name }), D.el('span', { class: 'n', text: String(items.length) })
      ]);
      head.addEventListener('click', function () {
        collapsed[g.key] = !collapsed[g.key];
        D.storage.set('collapsed', collapsed);
        D.core.renderSessions();
      });
      host.appendChild(head);
      if (collapsed[g.key]) return;
      items.forEach(function (s) {
        var isPin = pinned.indexOf(s.id) >= 0;
        var row = D.el('div', {
          class: 'item dsh-sb-item' + (s.id === ctx.sid ? ' active' : ''),
          'data-id': s.id, title: D.esc(s.title || '新对话')
        }, [
          D.el('span', { class: 'dot' + (s.id === ctx.sid ? ' on' : '') }),
          D.el('span', { class: 'txt', text: s.title || '新对话' })
        ]);
        var pin = D.el('button', {
          class: 'dsh-sb-pin' + (isPin ? ' on' : ''), type: 'button',
          text: isPin ? '★' : '☆', title: isPin ? '取消置顶' : '置顶'
        });
        pin.addEventListener('click', function (e) {
          e.stopPropagation();
          var i = pinned.indexOf(s.id);
          if (i >= 0) pinned.splice(i, 1); else pinned.push(s.id);
          D.storage.set('pinned', pinned);
          D.core.renderSessions();
        });
        var del = D.el('button', { class: 'del', type: 'button', text: '×', title: '删除对话', 'data-del': s.id });
        del.addEventListener('click', function (e) {
          e.stopPropagation();
          if (!confirm('确定删除这个对话？消息将一起删除。')) return;
          ctx.remove(s.id);
        });
        row.appendChild(pin); row.appendChild(del);
        row.addEventListener('click', function () { ctx.select(s.id); });
        host.appendChild(row);
      });
    });
    if (!total) host.appendChild(D.el('div', { class: 'dsh-sb-empty', text: query ? '没有匹配的对话' : '暂无对话' }));
  });

  // bundle 晚于 app.js 加载，注册完渲染器后主动重绘一次
  if (D.core.renderSessions) D.core.renderSessions();
  D.on('sessions:render', function () { /* 渲染器已接管，此事件留给其它插件 */ });
  D.ui.toast('侧边栏增强已启用：搜索 / 置顶 / 日期分组', 'ok');
});
JS;

return [
    'id' => 'mkt.sidebar',
    'name' => '更好的侧边栏',
    'description' => '对话搜索、置顶、按日期分组与折叠；通过 GUI API 接管会话列表渲染，偏好存在本机浏览器。',
    'version' => '1.0.0',
    'author' => 'dshphp 官方市场',
    'icon' => 'list',
    'category' => 'beauty',
    'tags' => ['侧边栏', '搜索', '置顶', '分组', 'GUI'],
    'risk' => 'local',
    'usage' => '安装后刷新页面：侧栏顶部出现搜索框，鼠标悬停会话可★置顶，点分组标题可折叠。',
    'enabled' => true,
    'tools' => [],
    'slash' => [],
    'ui' => [
        'apiVersion' => 1,
        'scope' => ['app'],
        'mounts' => ['sidebar-top'],
        'permissions' => ['storage', 'ui'],
        'actions' => ['ui.sessions.render', 'ui.sessions.pin'],
        'css' => $css,
        'js' => $js,
    ],
];
