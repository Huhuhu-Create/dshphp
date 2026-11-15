<?php
/**
 * 实用 / 更多登录密码 + 3×3 手势密码（2.4 GUI API 示范插件）
 * 演示能力：ui.scope 覆盖登录页（匿名资源）、改核心鉴权后的 api/ui/credentials、
 *          登录表单免改后端（手势轨迹直接写进 name=password）。
 *
 * 后端配套（已改核心）：default_config() 增 credentials[]、auth_verify() 遍历多凭据、
 * index.php 增 api/ui/credentials。主密码 admin_password_hash 永远有效，
 * 增删凭据只改 config.php，不销毁任何已登录会话。
 */
$css = <<<'CSS'
.dsh-gesture-wrap{margin:12px 0 6px}
.dsh-gesture{display:grid;grid-template-columns:repeat(3,44px);gap:10px;justify-content:center;margin:9px 0}
.dsh-gesture-dot{width:44px;height:44px;border-radius:50%;border:1px solid var(--line2);background:var(--panel2);cursor:pointer;touch-action:none;transition:.12s}
.dsh-gesture-dot.on{background:var(--accent);border-color:var(--accent2);box-shadow:0 0 0 4px rgba(91,140,255,.18)}
.dsh-cred-row{display:flex;align-items:center;gap:6px;padding:6px 0;border-bottom:1px solid var(--line);font-size:11px}
.dsh-cred-row .n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text)}
.dsh-cred-list{margin:6px 0 10px;max-height:150px;overflow:auto}
CSS;

$js = <<<'JS'
/* 3×3 手势控件：轨迹编码成 g:0-1-2-5-8，与添加凭据时使用同一编码 */
function gesture(D, host, onChange) {
  var seq = [], drawing = false, dots = [];
  var grid = D.el('div', { class: 'dsh-gesture' });
  for (var i = 0; i < 9; i++) {
    var dot = D.el('button', { type: 'button', class: 'dsh-gesture-dot', 'data-i': String(i), title: '' });
    grid.appendChild(dot); dots.push(dot);
  }
  host.appendChild(grid);
  function mark(n) {
    if (n < 0 || n > 8 || seq.indexOf(n) >= 0) return;
    seq.push(n); dots[n].classList.add('on');
    if (onChange) onChange(seq.length >= 4 ? 'g:' + seq.join('-') : '');
  }
  function begin(e) {
    var t = e.target.closest && e.target.closest('.dsh-gesture-dot');
    if (!t) return;
    drawing = true; seq = [];
    dots.forEach(function (d) { d.classList.remove('on'); });
    mark(Number(t.getAttribute('data-i')));
    e.preventDefault();
  }
  grid.addEventListener('pointerdown', begin);
  function track(e) {
    if (!drawing) return;
    var node = document.elementFromPoint(e.clientX, e.clientY);
    var t = node && node.closest ? node.closest('.dsh-gesture-dot') : null;
    if (t && grid.contains(t)) mark(Number(t.getAttribute('data-i')));
  }
  grid.addEventListener('pointermove', track);
  grid.addEventListener('pointerover', function (e) {
    if (!drawing) return;
    var t = e.target.closest && e.target.closest('.dsh-gesture-dot');
    if (t) mark(Number(t.getAttribute('data-i')));
  });
  document.addEventListener('pointerup', function () { drawing = false; });
  return {
    value: function () { return seq.length >= 4 ? 'g:' + seq.join('-') : ''; },
    reset: function () {
      seq = []; dots.forEach(function (d) { d.classList.remove('on'); });
      if (onChange) onChange('');
    }
  };
}

DSH.ready(function (D) {
  var ID = 'mkt.passwords';

  // ── 登录页：只渲染手势控件，不调用任何 API（登录页运行时禁用了 D.api） ──
  if (D.scope === 'login') {
    var slot = D.ui.slot('login');
    var pw = document.getElementById('pw');
    if (!slot || !pw) return;
    var hint = D.el('div', { class: 'dsh-hint', text: '或直接画手势密码（至少连 4 个点）' });
    var wrap = D.el('div', { class: 'dsh-gesture-wrap' }, [hint]);
    gesture(D, wrap, function (v) {
      if (v) { pw.value = v; hint.textContent = '手势已输入 ' + v.slice(2).split('-').length + ' 个点，点「进入控制台」登录'; }
      else if (v === '') { pw.value = ''; }
    });
    var clear = D.ui.btn('重画', function () {
      pw.value = ''; hint.textContent = '或直接画手势密码（至少连 4 个点）';
      wrap.querySelectorAll('.dsh-gesture-dot').forEach(function (d) { d.classList.remove('on'); });
    }, 'ghost sm');
    wrap.appendChild(clear);
    D.ui.mount('login', wrap, { id: ID + '.gesture' });
    return;
  }

  // ── 主界面：凭据管理面板 ──
  var btn = D.ui.btn('🔑 凭据', function () { open(); }, 'ghost sm');
  D.ui.mount('sidebar-foot', btn, { id: ID + '.opener' });

  function open() {
    var p = D.ui.panel({ id: ID + '.panel', title: '登录凭据', slot: 'overlay' });
    if (!p) return;
    var msg = D.el('div', { class: 'dsh-hint' });
    var list = D.el('div', { class: 'dsh-cred-list' });

    var label = D.el('input', { type: 'text', placeholder: '名称，如「备用密码」「手机手势」' });
    var type = D.el('select', {}, [
      D.el('option', { value: 'password', text: '文本密码' }),
      D.el('option', { value: 'gesture', text: '3×3 手势密码' })
    ]);
    var secret = D.el('input', { type: 'password', placeholder: '密码（4～200 字节）' });
    var gestureBox = D.el('div', { class: 'dsh-gesture-wrap', hidden: true });
    var g = gesture(D, gestureBox, function (v) { gestureValue = v; });
    var gestureValue = '';

    type.addEventListener('change', function () {
      var isG = type.value === 'gesture';
      secret.hidden = isG; gestureBox.hidden = !isG;
      if (!isG) g.reset();
    });

    var add = D.ui.btn('添加凭据', async function () {
      var v = type.value === 'gesture' ? gestureValue : secret.value;
      if (!label.value.trim() || !v) { msg.textContent = '请填写名称与密码 / 画完手势（至少 4 个点）'; return; }
      msg.textContent = '保存中…';
      try {
        await D.api('api/ui/credentials', { action: 'add', label: label.value.trim(), type: type.value, secret: v });
        msg.textContent = '已添加：' + label.value.trim();
        label.value = ''; secret.value = ''; g.reset();
        await refresh();
      } catch (e) { msg.textContent = '失败：' + e.message; }
    }, 'primary sm');

    async function refresh() {
      try {
        var j = await D.api('api/ui/credentials', {}, { method: 'GET' });
        list.innerHTML = '';
        (j.credentials || []).forEach(function (c) {
          var row = D.el('div', { class: 'dsh-cred-row' }, [
            D.el('span', { class: 'n', text: (c.type === 'gesture' ? ' gesture ' : ' 密码 ') + ' · ' + c.label })
          ]);
          row.appendChild(D.ui.btn('删除', async function () {
            if (!confirm('删除凭据「' + c.label + '」？（主密码不受影响）')) return;
            try {
              await D.api('api/ui/credentials', { action: 'remove', label: c.label });
              msg.textContent = '已删除：' + c.label;
              await refresh();
            } catch (e) { msg.textContent = '失败：' + e.message; }
          }, 'ghost sm'));
          list.appendChild(row);
        });
        if (!(j.credentials || []).length) list.appendChild(D.el('div', { class: 'dsh-hint', text: '暂无附加凭据，当前只有主密码可用。' }));
      } catch (e) { msg.textContent = '读取失败：' + e.message; }
    }

    p.body.appendChild(D.el('div', { class: 'dsh-hint', text: '主密码始终有效；附加凭据可给不同设备/人用。增删不会把已登录的设备踢下线。' }));
    p.body.appendChild(D.el('div', { class: 'side-title' }, [D.el('span', { text: '已添加' })]));
    p.body.appendChild(list);
    p.body.appendChild(D.el('label', { text: '名称' }, [label]));
    p.body.appendChild(D.el('label', { text: '类型' }, [type]));
    p.body.appendChild(D.el('label', { text: '密码' }, [secret]));
    p.body.appendChild(gestureBox);
    p.body.appendChild(D.el('div', { class: 'dsh-row' }, [add]));
    p.body.appendChild(msg);
    refresh();
  }
});
JS;

return [
    'id' => 'mkt.passwords',
    'name' => '更多登录密码 · 手势密码',
    'description' => '除主密码外最多再加 8 个凭据，支持 3×3 手势密码；登录页可直接画手势登录。凭据用 bcrypt 存 config.php，增删不影响已登录会话。',
    'version' => '1.0.0',
    'author' => 'dshphp 官方市场',
    'icon' => 'key',
    'category' => 'utility',
    'tags' => ['登录', '多密码', '手势', '鉴权', 'GUI'],
    'risk' => 'store',
    'usage' => '安装并刷新；设置里「🔑 凭据」添加，退出后在登录页可画手势登录。主密码永远有效，不会被锁死。',
    'enabled' => true,
    'tools' => [],
    'slash' => [],
    'ui' => [
        'apiVersion' => 1,
        'scope' => ['app', 'login'],
        'mounts' => ['login', 'sidebar-foot', 'overlay'],
        'permissions' => ['api', 'ui', 'security'],
        'actions' => ['ui.credentials.add', 'ui.credentials.remove'],
        'css' => $css,
        'js' => $js,
    ],
];
