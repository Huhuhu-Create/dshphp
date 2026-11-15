<?php
/**
 * 实用 / 更好的记忆：虚拟目录树（2.4 GUI API 示范插件）
 * 演示能力：$ctx['store'] kv 持久化 + 插件工具后端 + 前端面板通过 DSH.api 调 api/plugin/run
 * 约定：整棵树存在 kv（不碰 sessions 表，SQLite / JSON 双驱动通用），上限 40KB，
 *      全部操作是确定性的本地读写，不调用模型、不上传文件。
 */
$run = function (string $tool, array $args, array $ctx): array {
    $store = $ctx['store'] ?? null;
    if (!$store instanceof Store) return ['ok' => false, 'text' => '存储不可用'];

    $norm = function ($p): string {
        $p = str_replace('\\', '/', trim((string) $p));
        $p = '/' . trim($p, '/');
        if ($p === '//') $p = '/';
        if (strlen($p) > 200 || str_contains($p, '..')) return '';
        return $p === '' ? '/' : $p;
    };
    $load = function () use ($store): array {
        $d = $store->kvGet('plugin.vtree', null);
        if (!is_array($d) || !is_array($d['nodes'] ?? null)) {
            $d = ['nodes' => ['/' => ['type' => 'dir', 'ts' => time()]], 'updated' => time()];
        }
        if (!isset($d['nodes']['/'])) $d['nodes']['/'] = ['type' => 'dir', 'ts' => time()];
        return $d;
    };
    $save = function (array $d) use ($store): ?string {
        $raw = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($raw) || strlen($raw) > 40960) return '记忆树已达 40KB 上限，请先删除或导出清理';
        $d['updated'] = time();
        $store->kvSet('plugin.vtree', $d);
        return null;
    };
    $parents = function (string $p): array {
        if ($p === '/') return [];
        $parts = explode('/', trim($p, '/'));
        array_pop($parts);
        $out = [];
        $cur = '';
        foreach ($parts as $x) { $cur .= '/' . $x; $out[] = $cur; }
        return $out;
    };
    $bytes = function (array $d): int {
        $raw = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($raw) ? strlen($raw) : 0;
    };

    $action = (string) ($args['action'] ?? 'tree');
    $path = $norm($args['path'] ?? '/');
    if ($path === '' && $action !== 'stats' && $action !== 'export' && $action !== 'import') {
        return ['ok' => false, 'text' => '路径非法（不能包含 .. 或超过 200 字节）'];
    }
    $d = $load();

    if ($action === 'tree' || $action === 'ls' || $action === 'stats') {
        $listOnly = $action !== 'tree';
        $prefix = $listOnly && $path !== '/' ? rtrim($path, '/') . '/' : '';
        $nodes = [];
        foreach ($d['nodes'] as $k => $v) {
            if ($k === '/') continue;
            if ($prefix !== '' && strpos($k, $prefix) !== 0) continue;
            if ($listOnly && $prefix !== '' && strpos(substr($k, strlen($prefix)), '/') !== false) continue;
            $nodes[$k] = $v;
        }
        ksort($nodes);
        if ($action === 'stats') {
            $nFile = 0;
            foreach ($nodes as $v) if (($v['type'] ?? '') === 'file') $nFile++;
            return ['ok' => true, 'text' => '节点 ' . count($nodes) . ' 个 · 文件 ' . $nFile . ' 个 · ' . $bytes($d) . ' / 40960 字节',
                'data' => ['nodes' => count($nodes), 'files' => $nFile, 'bytes' => $bytes($d), 'limit' => 40960]];
        }
        $lines = [];
        foreach ($nodes as $k => $v) {
            $lines[] = ['path' => $k, 'type' => (string) ($v['type'] ?? 'file'),
                'value' => (string) ($v['value'] ?? ''), 'ts' => (int) ($v['ts'] ?? 0)];
        }
        if (!$lines) return ['ok' => true, 'text' => '（空目录）', 'data' => ['nodes' => [], 'path' => $path]];
        $text = '';
        foreach ($lines as $n) {
            $depth = substr_count(trim($n['path'], '/'), '/');
            $text .= str_repeat('  ', $depth) . ($n['type'] === 'dir' ? '📁 ' : '📄 ') . basename($n['path'])
                . ($n['type'] === 'file' ? '  (' . strlen($n['value']) . ' 字节)' : '') . "\n";
        }
        return ['ok' => true, 'text' => rtrim($text), 'data' => ['nodes' => $lines, 'path' => $path]];
    }

    if ($action === 'export') {
        $raw = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['ok' => true, 'text' => '已导出 ' . strlen((string) $raw) . ' 字节', 'data' => ['json' => (string) $raw]];
    }
    if ($action === 'import') {
        $raw = (string) ($args['json'] ?? '');
        if (strlen($raw) > 40960) return ['ok' => false, 'text' => '导入内容超过 40KB 上限'];
        $x = json_decode($raw, true);
        if (!is_array($x) || !is_array($x['nodes'] ?? null)) return ['ok' => false, 'text' => '导入失败：不是合法的记忆树 JSON'];
        $err = $save($x);
        if ($err !== null) return ['ok' => false, 'text' => $err];
        return ['ok' => true, 'text' => '已导入 ' . count($x['nodes']) . ' 个节点', 'data' => ['nodes' => count($x['nodes'])]];
    }
    if ($action === 'get') {
        if (!isset($d['nodes'][$path])) return ['ok' => false, 'text' => '节点不存在：' . $path];
        $n = $d['nodes'][$path];
        return ['ok' => true, 'text' => (string) ($n['value'] ?? ''),
            'data' => ['path' => $path, 'type' => (string) ($n['type'] ?? 'file'), 'value' => (string) ($n['value'] ?? '')]];
    }
    if ($action === 'set' || $action === 'mkdir') {
        if ($path === '/') return ['ok' => false, 'text' => '根目录不能覆盖'];
        foreach ($parents($path) as $par) {
            if (!isset($d['nodes'][$par])) $d['nodes'][$par] = ['type' => 'dir', 'ts' => time()];
        }
        $d['nodes'][$path] = $action === 'mkdir'
            ? ['type' => 'dir', 'ts' => time()]
            : ['type' => 'file', 'value' => (string) ($args['value'] ?? ''), 'ts' => time()];
        $err = $save($d);
        if ($err !== null) return ['ok' => false, 'text' => $err];
        return ['ok' => true, 'text' => ($action === 'mkdir' ? '已建目录 ' : '已保存 ') . $path,
            'data' => ['path' => $path, 'bytes' => $bytes($d)]];
    }
    if ($action === 'rm') {
        if ($path === '/') return ['ok' => false, 'text' => '不能删除根目录'];
        $n = 0;
        foreach (array_keys($d['nodes']) as $k) {
            if ($k === $path || strpos($k, rtrim($path, '/') . '/') === 0) { unset($d['nodes'][$k]); $n++; }
        }
        if (!$n) return ['ok' => false, 'text' => '节点不存在：' . $path];
        $err = $save($d);
        if ($err !== null) return ['ok' => false, 'text' => $err];
        return ['ok' => true, 'text' => '已删除 ' . $n . ' 个节点', 'data' => ['removed' => $n]];
    }
    if ($action === 'mv') {
        $to = $norm($args['to'] ?? '');
        if ($to === '' || $to === '/') return ['ok' => false, 'text' => '目标路径非法'];
        if (!isset($d['nodes'][$path])) return ['ok' => false, 'text' => '源节点不存在：' . $path];
        foreach ($parents($to) as $par) {
            if (!isset($d['nodes'][$par])) $d['nodes'][$par] = ['type' => 'dir', 'ts' => time()];
        }
        $moved = [];
        foreach ($d['nodes'] as $k => $v) {
            if ($k === $path || strpos($k, rtrim($path, '/') . '/') === 0) {
                $moved[$to . substr($k, strlen($path))] = $v;
                unset($d['nodes'][$k]);
            }
        }
        foreach ($moved as $k => $v) $d['nodes'][$k] = $v;
        $err = $save($d);
        if ($err !== null) return ['ok' => false, 'text' => $err];
        return ['ok' => true, 'text' => '已移动 ' . count($moved) . ' 个节点 → ' . $to, 'data' => ['moved' => count($moved)]];
    }
    return ['ok' => false, 'text' => '未知操作：' . $action . '（可用 tree/ls/get/set/mkdir/mv/rm/export/import/stats）'];
};

$css = <<<'CSS'
.dsh-vt-row{display:flex;align-items:center;gap:6px;padding:4px 2px;border-bottom:1px solid var(--line);font-size:11px}
.dsh-vt-row .p{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;color:var(--text)}
.dsh-vt-row .p:hover{color:var(--accent2)}
.dsh-vt-row .s{color:var(--muted);font-size:10px}
.dsh-vt-list{max-height:230px;overflow:auto;margin:6px 0}
.dsh-vt-edit{width:100%;min-height:76px;background:var(--panel2);border:1px solid var(--line);color:var(--text);border-radius:7px;padding:7px;font-size:11px;font-family:ui-monospace,Consolas,monospace;resize:vertical}
CSS;

$js = <<<'JS'
if (DSH.scope !== 'app') return;

DSH.ready(function (D) {
  var ID = 'mkt.vtree';
  function call(action, args) {
    return D.api('api/plugin/run', { id: ID, tool: 'vtree', args: Object.assign({ action: action }, args || {}) });
  }

  var btn = D.ui.btn('🗂 记忆树', function () { open(); }, 'ghost sm');
  D.ui.mount('sidebar-foot', btn, { id: ID + '.opener' });

  var stat = D.el('div', { class: 'dsh-hint', text: '读取中…' });
  var list = D.el('div', { class: 'dsh-vt-list' });
  var path = D.el('input', { type: 'text', placeholder: '/工作/方案.md', spellcheck: 'false' });
  var editor = D.el('textarea', { class: 'dsh-vt-edit', placeholder: '文件内容（纯文本）' });
  var msg = D.el('div', { class: 'dsh-hint' });

  var save = D.ui.btn('保存文件', async function () {
    var p = path.value.trim();
    if (!p) { msg.textContent = '请填写路径'; return; }
    msg.textContent = '保存中…';
    try {
      var j = await call('set', { path: p, value: editor.value });
      msg.textContent = j.text || '已保存';
      await refresh();
    } catch (e) { msg.textContent = '失败：' + e.message; }
  }, 'primary sm');
  var mkdir = D.ui.btn('建目录', async function () {
    var p = path.value.trim();
    if (!p) { msg.textContent = '请填写路径'; return; }
    try { var j = await call('mkdir', { path: p }); msg.textContent = j.text; await refresh(); }
    catch (e) { msg.textContent = '失败：' + e.message; }
  }, 'ghost sm');
  var del = D.ui.btn('删除', async function () {
    var p = path.value.trim();
    if (!p || !confirm('删除 ' + p + '（目录下所有子节点一起删除）？')) return;
    try { var j = await call('rm', { path: p }); msg.textContent = j.text; await refresh(); }
    catch (e) { msg.textContent = '失败：' + e.message; }
  }, 'ghost sm');
  var exp = D.ui.btn('导出', async function () {
    try { var j = await call('export'); editor.value = (j.data && j.data.json) || ''; msg.textContent = j.text; }
    catch (e) { msg.textContent = '失败：' + e.message; }
  }, 'ghost sm');
  var imp = D.ui.btn('导入', async function () {
    if (!confirm('导入会覆盖当前整棵记忆树，确定继续？')) return;
    try { var j = await call('import', { json: editor.value }); msg.textContent = j.text; await refresh(); }
    catch (e) { msg.textContent = '失败：' + e.message; }
  }, 'ghost sm');

  async function refresh() {
    try {
      var j = await call('tree');
      var rows = (j.data && j.data.nodes) || [];
      list.innerHTML = '';
      rows.forEach(function (n) {
        var row = D.el('div', { class: 'dsh-vt-row' }, [
          D.el('span', { class: 'p', text: (n.type === 'dir' ? '📁 ' : '📄 ') + n.path }),
          D.el('span', { class: 's', text: n.type === 'dir' ? '目录' : n.value.length + 'B' })
        ]);
        row.querySelector('.p').addEventListener('click', async function () {
          path.value = n.path;
          try {
            var g = await call('get', { path: n.path });
            editor.value = (g.data && g.data.value) || '';
            msg.textContent = n.type === 'dir' ? '目录：' + n.path : '已载入 ' + n.path;
          } catch (e) { msg.textContent = e.message; }
        });
        list.appendChild(row);
      });
      if (!rows.length) list.appendChild(D.el('div', { class: 'dsh-hint', text: '（空）用下方输入框新建一个节点' }));
      var s = await call('stats');
      stat.textContent = s.text + ' · 纯本地读写，不调用模型';
    } catch (e) { stat.textContent = '读取失败：' + e.message; }
  }

  function open() {
    var p = D.ui.panel({ id: ID + '.panel', title: '记忆 · 虚拟目录树', slot: 'overlay' });
    if (!p) return;
    p.body.appendChild(stat);
    p.body.appendChild(list);
    p.body.appendChild(D.el('label', { text: '路径' }, [path]));
    p.body.appendChild(D.el('label', { text: '内容 / 导出 JSON' }, [editor]));
    p.body.appendChild(D.el('div', { class: 'dsh-row' }, [save, mkdir, del, exp, imp]));
    p.body.appendChild(msg);
    refresh();
  }
});
JS;

return [
    'id' => 'mkt.vtree',
    'name' => '更好的记忆 · 虚拟目录树',
    'description' => '把记忆组织成虚拟目录树，支持新建/编辑/移动/删除/导入导出；整棵树存在服务器 kv，上限 40KB，纯确定性读写不消耗模型额度。',
    'version' => '1.0.0',
    'author' => 'dshphp 官方市场',
    'icon' => 'tree',
    'category' => 'utility',
    'tags' => ['记忆', '目录树', '存储', 'GUI'],
    'risk' => 'store',
    'usage' => '/vtree 查看整棵树；安装后侧栏「🗂 记忆树」按钮打开可视化面板。也可用 /vtree {"action":"set","path":"/a/b","value":"..."}。',
    'enabled' => true,
    'tools' => [[
        'name' => 'vtree',
        'description' => '虚拟目录树：tree / ls / get / set / mkdir / mv / rm / export / import / stats。path 用 / 分隔，纯本地读写，不调用模型。',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'description' => 'tree|ls|get|set|mkdir|mv|rm|export|import|stats'],
                'path' => ['type' => 'string', 'description' => '节点路径，如 /工作/方案.md'],
                'value' => ['type' => 'string', 'description' => 'set 时的文件内容'],
                'to' => ['type' => 'string', 'description' => 'mv 时的目标路径'],
                'json' => ['type' => 'string', 'description' => 'import 时的整棵树 JSON'],
            ],
        ],
    ]],
    'slash' => ['vtree' => '查看虚拟记忆目录树；/vtree {"action":"set","path":"/a/b","value":"内容"} 可写入'],
    'run' => $run,
    'ui' => [
        'apiVersion' => 1,
        'scope' => ['app'],
        'mounts' => ['sidebar-foot', 'overlay'],
        'permissions' => ['api', 'storage', 'ui'],
        'actions' => ['vtree.tree', 'vtree.set', 'vtree.export'],
        'css' => $css,
        'js' => $js,
    ],
];
