<?php
/**
 * 实用 / 上下文用量显示（2.4 GUI API 示范插件）
 * 演示能力：后端新增 api/ui/context + 核心在 chat:done / session:change 埋点
 *           + 插件监听事件后调用 DSH.api() 拿服务端真实 usage 与确定性估算
 */
$css = <<<'CSS'
.dsh-ctx{padding:6px 8px;background:var(--panel2);border:1px solid var(--line);border-radius:8px;margin-top:6px}
.dsh-ctx-top{display:flex;justify-content:space-between;gap:6px;align-items:center;font-size:11px;color:var(--text)}
.dsh-ctx-top b{color:var(--accent2);font-variant-numeric:tabular-nums}
.dsh-ctx-bar{height:5px;border-radius:99px;background:var(--panel3);overflow:hidden;margin:6px 0 5px}
.dsh-ctx-bar>i{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--accent),var(--green));transition:width .35s}
.dsh-ctx-bar.hot>i{background:linear-gradient(90deg,var(--amber),var(--red))}
.dsh-ctx-foot{font-size:10px;color:var(--muted);line-height:1.5;font-variant-numeric:tabular-nums}
.dsh-ctx.err .dsh-ctx-foot{color:var(--red)}
CSS;

$js = <<<'JS'
if (DSH.scope !== 'app') return;

DSH.ready(function (D) {
  var title = D.el('div', { class: 'dsh-ctx-top' }, [
    D.el('span', { text: '上下文' }), D.el('b', { text: '读取中…' })
  ]);
  var fill = D.el('i');
  var bar = D.el('div', { class: 'dsh-ctx-bar' }, [fill]);
  var foot = D.el('div', { class: 'dsh-ctx-foot', text: '等待数据…' });
  var root = D.el('div', { class: 'dsh-ctx' }, [title, bar, foot]);
  D.ui.mount('status', root, { id: 'mkt.context.meter' });

  function k(n) {
    n = Number(n) || 0;
    return n >= 1000 ? (n / 1000).toFixed(1) + 'k' : String(n);
  }
  function paint(c) {
    var ratio = Math.max(0, Math.min(1, Number(c.ratio) || 0));
    title.querySelector('b').textContent = k(c.est_tokens) + ' / ' + k(c.context_limit) + ' · ' + Math.round(ratio * 100) + '%';
    fill.style.width = (ratio * 100).toFixed(1) + '%';
    bar.classList.toggle('hot', ratio >= 0.75);
    root.classList.remove('err');
    var bits = [];
    bits.push(c.messages + ' 条消息');
    var last = c.usage_last;
    if (last && last.total_tokens) bits.push('本次 ' + k(last.total_tokens) + ' tok');
    else if (!c.usage_last) bits.push('本次无用量（上游未返回）');
    bits.push('累计 ' + k(c.usage_total) + ' tok / ' + (c.calls || 0) + ' 次');
    bits.push('上限 ' + k(c.max_tokens) + ' tok · 历史 ' + c.history_limit + ' 条');
    foot.textContent = bits.join(' · ');
  }

  async function load() {
    var sid = '';
    try { sid = (D.core.getState ? D.core.getState().sid : '') || ''; } catch (e) { /* noop */ }
    try {
      var j = await D.api('api/ui/context', { sid: sid });
      paint(j);
    } catch (e) {
      root.classList.add('err');
      foot.textContent = '读取失败：' + e.message;
    }
  }

  D.on('chat:done', load);
  D.on('session:change', load);
  D.on('settings:save', load);
  D.on('app:ready', load);
  load();
});
JS;

return [
    'id' => 'mkt.context',
    'name' => '上下文用量显示',
    'description' => '在侧栏显示上下文占用比例、本次与累计 token、历史条数与模型上限；数据来自服务端，不猜测、不调用模型。',
    'version' => '1.0.0',
    'author' => 'dshphp 官方市场',
    'icon' => 'gauge',
    'category' => 'utility',
    'tags' => ['上下文', 'token', '用量', 'GUI'],
    'risk' => 'local',
    'usage' => '安装并刷新页面，侧栏底部出现用量条；每次对话结束与切换会话时自动更新。',
    'enabled' => true,
    'tools' => [],
    'slash' => [],
    'ui' => [
        'apiVersion' => 1,
        'scope' => ['app'],
        'mounts' => ['status'],
        'permissions' => ['api', 'ui'],
        'actions' => ['ui.context.read'],
        'css' => $css,
        'js' => $js,
    ],
];
