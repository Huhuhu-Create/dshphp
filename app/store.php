<?php
/**
 * 存储层：SQLite（PDO）优先，无 pdo_sqlite 时自动降级 JSON 文件。
 * 两种后端对上层接口完全一致；数据文件名由 install.php 随机生成并写入 app/storage.php。
 */
declare(strict_types=1);

class Store {
    private string $path;
    private string $driver = 'json';
    private ?PDO $pdo = null;

    public function __construct() {
        $info = storage_info();
        $dir = DSH_DATA_DIR . ($info['dir'] !== '' ? '/' . $info['dir'] : '');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $this->path = $dir . '/' . $info['file'];
        $sqliteOk = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
        $this->driver = ($info['driver'] === 'sqlite' && $sqliteOk) ? 'sqlite' : 'json';
        $this->init();
    }

    public function driver(): string { return $this->driver; }

    private function init(): void {
        if ($this->driver === 'sqlite') {
            $this->pdo = new PDO('sqlite:' . $this->path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY, title TEXT NOT NULL DEFAULT "", created INTEGER NOT NULL,
                updated INTEGER NOT NULL, model TEXT NOT NULL DEFAULT "", system TEXT NOT NULL DEFAULT ""
            )');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages (
                id TEXT PRIMARY KEY, sid TEXT NOT NULL, role TEXT NOT NULL, content TEXT NOT NULL,
                ts INTEGER NOT NULL, meta TEXT NOT NULL DEFAULT "{}"
            )');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_msg_sid ON messages(sid, ts)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
            return;
        }
        if (!is_file($this->path)) {
            $this->writeRaw(self::emptyData());
        }
    }

    private static function emptyData(): array {
        return ['sessions' => [], 'messages' => [], 'kv' => []];
    }

    private function readRaw(): array {
        $fh = @fopen($this->path, 'rb');
        if (!$fh) return self::emptyData();
        @flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        @flock($fh, LOCK_UN);
        fclose($fh);
        $d = json_decode((string) $raw, true);
        if (!is_array($d)) return self::emptyData();
        $d['sessions'] = is_array($d['sessions'] ?? null) ? $d['sessions'] : [];
        $d['messages'] = is_array($d['messages'] ?? null) ? $d['messages'] : [];
        $d['kv'] = is_array($d['kv'] ?? null) ? $d['kv'] : [];
        return $d;
    }

    private function writeRaw(array $d): void {
        $tmp = $this->path . '.tmp' . rand_hex(4);
        $fh = @fopen($tmp, 'wb');
        if (!$fh) throw new RuntimeException('数据目录不可写，请检查 data/ 权限');
        @flock($fh, LOCK_EX);
        fwrite($fh, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        fflush($fh);
        @flock($fh, LOCK_UN);
        fclose($fh);
        if (!@rename($tmp, $this->path)) {
            // Windows 上 rename 到已存在文件会失败：退回覆盖写
            $fh2 = @fopen($this->path, 'wb');
            if (!$fh2) { @unlink($tmp); throw new RuntimeException('数据目录不可写'); }
            @flock($fh2, LOCK_EX);
            fwrite($fh2, (string) file_get_contents($tmp));
            @flock($fh2, LOCK_UN);
            fclose($fh2);
            @unlink($tmp);
        }
    }

    /** 在独占锁内读取并修改整份 JSON，保证并发写不互相覆盖 */
    private function mutate(callable $fn): void {
        $fh = @fopen($this->path, 'c+b');
        if (!$fh) throw new RuntimeException('数据目录不可写，请检查 data/ 权限');
        @flock($fh, LOCK_EX);
        rewind($fh);
        $raw = stream_get_contents($fh);
        $d = json_decode((string) $raw, true);
        if (!is_array($d)) $d = self::emptyData();
        foreach (self::emptyData() as $k => $v) if (!isset($d[$k]) || !is_array($d[$k])) $d[$k] = $v;
        $d = $fn($d);
        rewind($fh);
        @ftruncate($fh, 0);
        fwrite($fh, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        fflush($fh);
        @flock($fh, LOCK_UN);
        fclose($fh);
    }

    // ── 会话 ──────────────────────────────────────────────────────────────
    public function sessions(): array {
        if ($this->driver === 'sqlite') {
            $rows = $this->pdo->query('SELECT * FROM sessions ORDER BY updated DESC')->fetchAll(PDO::FETCH_ASSOC);
            return array_map(fn($r) => [
                'id' => (string) $r['id'], 'title' => (string) $r['title'],
                'created' => (int) $r['created'], 'updated' => (int) $r['updated'],
                'model' => (string) $r['model'], 'system' => (string) $r['system'],
            ], $rows);
        }
        $d = $this->readRaw();
        $list = array_values($d['sessions']);
        usort($list, fn($a, $b) => (int) ($b['updated'] ?? 0) <=> (int) ($a['updated'] ?? 0));
        return $list;
    }

    public function session(string $id): ?array {
        if ($id === '') return null;
        if ($this->driver === 'sqlite') {
            $st = $this->pdo->prepare('SELECT * FROM sessions WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return [
                'id' => (string) $r['id'], 'title' => (string) $r['title'],
                'created' => (int) $r['created'], 'updated' => (int) $r['updated'],
                'model' => (string) $r['model'], 'system' => (string) $r['system'],
            ];
        }
        $d = $this->readRaw();
        return isset($d['sessions'][$id]) && is_array($d['sessions'][$id]) ? $d['sessions'][$id] : null;
    }

    public function saveSession(array $s): void {
        $s['created'] = (int) ($s['created'] ?? now_ms());
        $s['updated'] = (int) ($s['updated'] ?? now_ms());
        if ($this->driver === 'sqlite') {
            $st = $this->pdo->prepare('INSERT INTO sessions(id,title,created,updated,model,system) VALUES(?,?,?,?,?,?)
                ON CONFLICT(id) DO UPDATE SET title=excluded.title, updated=excluded.updated, model=excluded.model, system=excluded.system');
            $st->execute([$s['id'], $s['title'] ?? '', $s['created'], $s['updated'], $s['model'] ?? '', $s['system'] ?? '']);
            return;
        }
        $this->mutate(function ($d) use ($s) { $d['sessions'][$s['id']] = $s; return $d; });
    }

    public function deleteSession(string $id): void {
        if ($this->driver === 'sqlite') {
            $st = $this->pdo->prepare('DELETE FROM sessions WHERE id = ?'); $st->execute([$id]);
            $st = $this->pdo->prepare('DELETE FROM messages WHERE sid = ?'); $st->execute([$id]);
            return;
        }
        $this->mutate(function ($d) use ($id) { unset($d['sessions'][$id], $d['messages'][$id]); return $d; });
    }

    // ── 消息 ──────────────────────────────────────────────────────────────
    public function messages(string $sid, int $limit = 0): array {
        if ($this->driver === 'sqlite') {
            $sql = 'SELECT * FROM messages WHERE sid = ? ORDER BY ts ASC, rowid ASC';
            $st = $this->pdo->prepare($sql); $st->execute([$sid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) $out[] = self::msgFromRow($r);
            if ($limit > 0 && count($out) > $limit) $out = array_slice($out, -$limit);
            return $out;
        }
        $d = $this->readRaw();
        $list = isset($d['messages'][$sid]) && is_array($d['messages'][$sid]) ? array_values($d['messages'][$sid]) : [];
        if ($limit > 0 && count($list) > $limit) $list = array_slice($list, -$limit);
        return $list;
    }

    private static function msgFromRow(array $r): array {
        $m = [
            'id' => (string) $r['id'], 'role' => (string) $r['role'],
            'content' => (string) $r['content'], 'ts' => (int) $r['ts'],
        ];
        $meta = json_decode((string) $r['meta'], true);
        if (is_array($meta)) $m = array_merge($m, $meta);
        return $m;
    }

    public function addMessage(string $sid, array $m): void {
        $m['id'] = (string) ($m['id'] ?? ('msg_' . rand_hex(8)));
        $m['ts'] = (int) ($m['ts'] ?? now_ms());
        $m['content'] = (string) ($m['content'] ?? '');
        $m['role'] = (string) ($m['role'] ?? 'user');
        $meta = $m;
        unset($meta['id'], $meta['ts'], $meta['content'], $meta['role']);
        if ($this->driver === 'sqlite') {
            $st = $this->pdo->prepare('INSERT INTO messages(id,sid,role,content,ts,meta) VALUES(?,?,?,?,?,?)');
            $st->execute([$m['id'], $sid, $m['role'], $m['content'], $m['ts'], json_encode($meta, JSON_UNESCAPED_UNICODE)]);
            return;
        }
        $this->mutate(function ($d) use ($sid, $m) {
            if (!isset($d['messages'][$sid]) || !is_array($d['messages'][$sid])) $d['messages'][$sid] = [];
            $d['messages'][$sid][] = $m;
            return $d;
        });
    }

    public function clearMessages(string $sid): void {
        if ($this->driver === 'sqlite') {
            $st = $this->pdo->prepare('DELETE FROM messages WHERE sid = ?'); $st->execute([$sid]);
            return;
        }
        $this->mutate(function ($d) use ($sid) { $d['messages'][$sid] = []; return $d; });
    }

    // ── 键值（插件开关、技能开关、设置）───────────────────────────────────
    public function kvGet(string $k, $default = null) {
        if ($this->driver === 'sqlite') {
            $st = $this->pdo->prepare('SELECT v FROM kv WHERE k = ?'); $st->execute([$k]);
            $v = $st->fetchColumn();
            return $v === false ? $default : json_decode((string) $v, true);
        }
        $d = $this->readRaw();
        return array_key_exists($k, $d['kv']) ? $d['kv'][$k] : $default;
    }

    public function kvSet(string $k, $v): void {
        if ($this->driver === 'sqlite') {
            $st = $this->pdo->prepare('INSERT INTO kv(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v');
            $st->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            return;
        }
        $this->mutate(function ($d) use ($k, $v) { $d['kv'][$k] = $v; return $d; });
    }

    public function stats(): array {
        $nS = count($this->sessions());
        $nM = 0;
        if ($this->driver === 'sqlite') {
            $nM = (int) $this->pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
        } else {
            foreach ($this->readRaw()['messages'] as $list) $nM += is_array($list) ? count($list) : 0;
        }
        // 刻意不返回真实文件名/路径
        return [
            'driver' => $this->driver,
            'sessions' => $nS,
            'messages' => $nM,
            'bytes' => is_file($this->path) ? (int) filesize($this->path) : 0,
            'writable' => is_writable(dirname($this->path)),
        ];
    }
}
