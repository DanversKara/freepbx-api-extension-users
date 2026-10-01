<?php
/**
 * FreePBX 17 BMO class for the "API Users" module.
 *
 * Responsibilities:
 *   - Load/save state (JSON in table apiusers_kv) and run Engine operations
 *   - Write /etc/asterisk/{pjsip,extensions}_apiusers.conf via ConfigGen
 *   - Reload Asterisk (res_pjsip + dialplan) and hang up calls of users who
 *     were disabled/deleted or when the kill switch is engaged
 *   - Render the admin page (Applications -> API Users)
 *   - remoteCall(): entry point for the Docker panel (via bin/apiusers-remote)
 *
 * Map of the project: see CLAUDE.md at the project root.
 */

namespace FreePBX\modules;

require_once __DIR__ . '/lib/Engine.php';
require_once __DIR__ . '/lib/ConfigGen.php';
require_once __DIR__ . '/lib/Live.php';

use ApiUsers\Engine;
use ApiUsers\ConfigGen;
use ApiUsers\Live;

class Apiusers extends \FreePBX_Helpers implements \BMO
{
    public const TABLE = 'apiusers_kv';
    public const PJSIP_FILE = 'pjsip_apiusers.conf';
    public const DIALPLAN_FILE = 'extensions_apiusers.conf';

    /** ops the Docker panel may call. Engine::remoteGuard() enforces the safety lock on top. */
    public const REMOTE_OPS = ['list', 'get', 'create', 'update', 'delete', 'rotate', 'kill', 'audit', 'calls', 'live', 'hangup'];

    /** How many sign-in / sign-out events to keep (table apiusers_kv, key 'signins'). */
    public const SIGNIN_KEEP = 300;

    public $FreePBX;
    public $db;

    public function __construct($freepbx = null)
    {
        if ($freepbx === null) {
            throw new \Exception('Not given a FreePBX Object');
        }
        $this->FreePBX = $freepbx;
        $this->db = $freepbx->Database;
    }

    // ---------------------------------------------------------------- BMO

    public function install()
    {
        $this->ensureTable();
        $st = $this->loadState();
        $eng = new Engine($st);
        if ($eng->ensureToken()) {
            $this->saveState($eng->state());
        }
        $this->apply($eng->state(), []);
    }

    public function uninstall()
    {
        $dir = $this->astEtc();
        foreach ([self::PJSIP_FILE, self::DIALPLAN_FILE] as $f) {
            // Leave empty files so the #include lines in *_custom.conf don't break.
            @file_put_contents("$dir/$f", "; apiusers module uninstalled\n");
        }
        $this->reload();
        $this->db->query('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    /** FreePBX ajax.php?module=apiusers&command=live (read-only, used by the Live section to refresh). */
    public function ajaxRequest($req, &$setting)
    {
        return $req === 'live';
    }

    public function ajaxHandler()
    {
        if (($_REQUEST['command'] ?? '') === 'live') return $this->live();
        return ['ok' => false, 'error' => 'unknown command'];
    }

    public function backup() {}
    public function restore($backup) {}
    public function doConfigPageInit($page) {}

    // ------------------------------------------------------------- storage

    private function ensureTable(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . self::TABLE .
            ' (k VARCHAR(64) NOT NULL PRIMARY KEY, v LONGTEXT NOT NULL) DEFAULT CHARSET=utf8mb4');
    }

    public function loadState(): array
    {
        $this->ensureTable();
        $stmt = $this->db->prepare('SELECT v FROM ' . self::TABLE . ' WHERE k = ?');
        $stmt->execute(['state']);
        $v = $stmt->fetchColumn();
        $st = $v ? json_decode($v, true) : [];
        return Engine::normalizeState(is_array($st) ? $st : []);
    }

    public function saveState(array $st): void
    {
        $this->kvSet('state', $st, true);
    }

    /** Extra rows in the same table: 'presence' (who is signed in) and 'signins' (history). */
    private function kvGet(string $k): array
    {
        $this->ensureTable();
        $stmt = $this->db->prepare('SELECT v FROM ' . self::TABLE . ' WHERE k = ?');
        $stmt->execute([$k]);
        $v = $stmt->fetchColumn();
        $d = $v ? json_decode($v, true) : [];
        return is_array($d) ? $d : [];
    }

    private function kvSet(string $k, array $v, bool $pretty = false): void
    {
        $this->ensureTable();
        $stmt = $this->db->prepare('REPLACE INTO ' . self::TABLE . ' (k, v) VALUES (?, ?)');
        $stmt->execute([$k, json_encode($v, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_SLASHES)]);
    }

    /** Real FreePBX extensions (for the allowed-extension picker / validation). */
    public function localExtensions(): array
    {
        $out = [];
        try {
            foreach ((array)$this->FreePBX->Core->getAllUsers() as $u) {
                if (isset($u['extension'])) $out[(string)$u['extension']] = (string)($u['name'] ?? '');
            }
        } catch (\Throwable $e) {
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    // --------------------------------------------------------------- core

    /**
     * Run an Engine op; on change, persist + regenerate + reload + hang up as needed.
     */
    public function op(string $op, array $args, string $source, string $actor): array
    {
        $before = $this->loadState();
        $eng = new Engine($before, array_keys($this->localExtensions()));
        $res = $eng->run($op, $args, $source, $actor);

        // Audit entries are written even for denied ops, so always persist.
        $after = $eng->state();
        $this->saveState($after);
        if (!empty($res['changed'])) {
            $this->apply($after, $before);
        }
        unset($res['changed']);
        return $res;
    }

    /** Write config files, reload, and hang up channels that lost access. */
    public function apply(array $after, array $before): void
    {
        $dir = $this->astEtc();
        $this->atomicWrite("$dir/" . self::PJSIP_FILE, ConfigGen::pjsip($after));
        $this->atomicWrite("$dir/" . self::DIALPLAN_FILE, ConfigGen::dialplan($after));
        $this->reload();

        if (!empty($after['settings']['kill_switch'])) {
            $this->hangupRegex('^PJSIP/apiu-');
            return;
        }
        // Users that were active before but are now deleted or disabled
        foreach (($before['users'] ?? []) as $id => $u) {
            $now = $after['users'][$id] ?? null;
            if (!empty($u['enabled']) && (!$now || empty($now['enabled']))) {
                $this->hangupRegex('^PJSIP/' . preg_quote($u['sip_user'], '/') . '-');
            }
        }
    }

    private function atomicWrite(string $path, string $content): void
    {
        $tmp = $path . '.tmp' . getmypid();
        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException("cannot write $tmp");
        }
        @chmod($tmp, 0640);
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            @chown($tmp, 'asterisk');
            @chgrp($tmp, 'asterisk');
        }
        rename($tmp, $path);
    }

    private function reload(): void
    {
        $ast = $this->FreePBX->astman ?? null;
        if (!$ast || !$ast->connected()) return;
        $ast->send_request('Command', ['Command' => 'module reload res_pjsip.so']);
        $ast->send_request('Command', ['Command' => 'dialplan reload']);
    }

    private function hangupRegex(string $re): void
    {
        $ast = $this->FreePBX->astman ?? null;
        if (!$ast || !$ast->connected()) return;
        // AMI Hangup accepts /regex/ in Channel
        $ast->send_request('Hangup', ['Channel' => '/' . $re . '/', 'Cause' => 16]);
    }

    private function astEtc(): string
    {
        $d = '';
        try { $d = (string)$this->FreePBX->Config->get('ASTETCDIR'); } catch (\Throwable $e) {}
        return $d !== '' ? rtrim($d, '/') : '/etc/asterisk';
    }

    /**
     * Recent CDRs for one API user (both directions).
     *
     * FreePBX 17 keeps DB credentials in /etc/freepbx.conf ($amp_conf), not in the
     * Config store, so Config->get('AMPDBUSER') is empty. We first reuse FreePBX's
     * own open connection (its user has rights on asteriskcdrdb), and only fall
     * back to a separate login built from $amp_conf if that is refused.
     */
    public function calls(string $id, int $limit = 50): array
    {
        global $amp_conf;
        $st = $this->loadState();
        $u = $st['users'][$id] ?? null;
        if (!$u) return ['ok' => false, 'error' => 'no such user'];

        $conf = is_array($amp_conf ?? null) ? $amp_conf : [];
        $dbname = $conf['CDRDBNAME'] ?? '';
        if (!is_string($dbname) || !preg_match('/^[A-Za-z0-9_]+$/', $dbname)) $dbname = 'asteriskcdrdb';
        $limit = max(1, min(200, $limit));
        $like = 'PJSIP/' . $u['sip_user'] . '-%';
        $sql = "SELECT calldate, src, dst, disposition, billsec FROM `$dbname`.cdr
                WHERE channel LIKE ? OR dstchannel LIKE ?
                ORDER BY calldate DESC LIMIT $limit";

        $err = '';
        // 1) FreePBX's existing connection
        try {
            $q = $this->db->prepare($sql);
            $q->execute([$like, $like]);
            return ['ok' => true, 'calls' => $q->fetchAll(\PDO::FETCH_ASSOC)];
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        // 2) dedicated login from /etc/freepbx.conf values
        try {
            $host = ($conf['CDRDBHOST'] ?? '') ?: (($conf['AMPDBHOST'] ?? '') ?: 'localhost');
            $user = ($conf['CDRDBUSER'] ?? '') ?: ($conf['AMPDBUSER'] ?? '');
            $pass = ($conf['CDRDBPASS'] ?? '') ?: ($conf['AMPDBPASS'] ?? '');
            if ($user === '') throw new \RuntimeException('no CDR database user configured');
            $pdo = new \PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass,
                            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $q = $pdo->prepare($sql);
            $q->execute([$like, $like]);
            return ['ok' => true, 'calls' => $q->fetchAll(\PDO::FETCH_ASSOC)];
        } catch (\Throwable $e2) {
            return ['ok' => false, 'error' => 'CDR lookup failed: ' . $err . ' / ' . $e2->getMessage()];
        }
    }

    // --------------------------------------------------------------- live

    /**
     * Run one Asterisk CLI command. Uses FreePBX's AMI connection ("Command" action);
     * falls back to `asterisk -rx` (the web server, cron job and apiusers-remote all
     * run as the asterisk user, which may use the CLI socket).
     * APIUSERS_AST_CLI env var overrides the CLI binary (used by the sandbox tests).
     */
    public function cli(string $cmd): string
    {
        $bin = getenv('APIUSERS_AST_CLI');
        if (!$bin) {
            $ast = $this->FreePBX->astman ?? null;
            if ($ast && method_exists($ast, 'connected') && $ast->connected()) {
                $r = $ast->send_request('Command', ['Command' => $cmd]);
                if (is_array($r) && isset($r['data']) && trim((string)$r['data']) !== '') return (string)$r['data'];
            }
            $bin = is_executable('/usr/sbin/asterisk') ? '/usr/sbin/asterisk' : 'asterisk';
        }
        return (string)@shell_exec($bin . ' -rx ' . escapeshellarg($cmd) . ' 2>/dev/null');
    }

    /** Last ~2 MB of the Asterisk "full" log (for failed sign-ins and wrong DISA PINs). */
    private function logTail(int $bytes = 2000000): string
    {
        $f = getenv('APIUSERS_AST_LOG');
        if (!$f) {
            $dir = '';
            try { $dir = (string)$this->FreePBX->Config->get('ASTLOGDIR'); } catch (\Throwable $e) {}
            $f = rtrim($dir !== '' ? $dir : '/var/log/asterisk', '/') . '/full';
        }
        $h = @fopen($f, 'rb');
        if (!$h) return '';
        $size = (int)@filesize($f);
        if ($size > $bytes) { fseek($h, $size - $bytes); fgets($h); }   // drop the partial first line
        $data = (string)stream_get_contents($h);
        fclose($h);
        return $data;
    }

    /** Signed-in phones right now (two CLI calls). */
    public function devices(?array $st = null): array
    {
        $st = $st ?? $this->loadState();
        return Live::devices($this->cli('database show registrar'), $this->cli('pjsip show contacts'), $st['users'], time());
    }

    /**
     * Record sign-ins / sign-outs by comparing who is signed in now with the last snapshot.
     * Called by every Live refresh and once a minute by cron (bin/apiusers-presence), so the
     * history fills in even when nobody has the page open. A MySQL named lock keeps the page
     * and cron from writing the same event twice.
     */
    public function presenceTick(?array $devices = null, ?array $st = null): array
    {
        $devices = $devices ?? $this->devices($st);
        $locked = false;
        try {
            $q = $this->db->query("SELECT GET_LOCK('apiusers_presence', 3)");
            $locked = $q && (int)$q->fetchColumn() === 1;
        } catch (\Throwable $e) { /* no named locks (tests): carry on */ }
        try {
            [$next, $events] = Live::presence($this->kvGet('presence'), $devices, time());
            $this->kvSet('presence', $next);
            if ($events) {
                $log = $this->kvGet('signins');
                foreach ($events as $ev) $log[] = $ev;
                if (count($log) > self::SIGNIN_KEEP) $log = array_slice($log, -self::SIGNIN_KEEP);
                $this->kvSet('signins', $log);
            }
            return $events;
        } finally {
            if ($locked) { try { $this->db->query("SELECT RELEASE_LOCK('apiusers_presence')"); } catch (\Throwable $e) {} }
        }
    }

    /**
     * Everything the Live section shows. Read-only apart from the sign-in history.
     * Shape: {ok, now, devices[], calls[], signins[], failed[], disa_locks{}, kill_switch, warning?}
     */
    public function live(): array
    {
        $st = $this->loadState();
        $s = $st['settings'];
        $now = time();
        $registrar = $this->cli('database show registrar');
        $contacts = $this->cli('pjsip show contacts');
        $warning = '';
        if (trim($registrar . $contacts) === '') {
            $warning = 'Asterisk did not answer (is it running?). Live data is empty.';
        }
        $devices = Live::devices($registrar, $contacts, $st['users'], $now);
        $calls = Live::calls($this->cli('core show channels concise'), $this->cli('group show channels'), $st['users'], $devices);
        $this->presenceTick($devices, $st);
        $since = [];
        foreach (($this->kvGet('presence')['devices'] ?? []) as $k => $p) $since[$k] = (int)$p['since'];
        foreach ($devices as &$d) $d['since'] = $since[$d['key']] ?? null;
        unset($d);
        $failed = Live::failures($this->logTail(), $st['users'], (string)$s['gateway_ip'],
            ['vpn' => $s['client_vpn_addr'], 'lan' => $s['client_lan_addr'], 'pub' => $s['client_pub_addr']]);
        $locks = Live::disaLocks($this->cli('database show apiusers_disa'), $now);
        return [
            'ok' => true, 'now' => $now, 'warning' => $warning,
            'devices' => $devices, 'calls' => $calls,
            'signins' => array_slice(array_reverse($this->kvGet('signins')), 0, 60),
            'failed' => $failed, 'disa_locks' => $locks,
            'kill_switch' => !empty($s['kill_switch']),
            'users' => array_values(array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'],
                'public' => !empty($u['public']), 'enabled' => !empty($u['enabled'])], $st['users'])),
        ];
    }

    /**
     * Hang up one API user's call. Only channels named PJSIP/apiu-... that are up right now
     * can be hung up (checked here and in Engine). Allowed remotely: it only ends things.
     */
    public function hangupCall(string $channel, string $source, string $actor): array
    {
        if (!preg_match('#^PJSIP/apiu-[0-9a-f]+-[0-9a-f]+$#', $channel)) return ['ok' => false, 'error' => 'not an API user call'];
        $live = false;
        foreach (Live::channels($this->cli('core show channels concise')) as $r) {
            if ($r['channel'] === $channel) { $live = true; break; }
        }
        if (!$live) return ['ok' => false, 'error' => 'that call has already ended'];
        $r = $this->op('hangup', ['channel' => $channel], $source, $actor);
        if (!empty($r['ok'])) $this->cli('channel request hangup ' . $channel);
        return $r;
    }

    /** Clear a DISA lockout (5 wrong PINs) before the hour is up. PBX page only (Engine guard). */
    public function unlockDisa(string $id, string $source, string $actor): array
    {
        $r = $this->op('disa_unlock', ['id' => $id], $source, $actor);
        if (!empty($r['ok']) && preg_match('/^u[0-9a-f]+$/', $id)) {
            $this->cli("database del apiusers_disa {$id}_lock");
            $this->cli("database put apiusers_disa {$id}_fails 0");
        }
        return $r;
    }

    // ------------------------------------------------------------- remote

    /**
     * Called by bin/apiusers-remote (SSH forced command from the Docker box).
     * $req = {"token":"...","op":"...","args":{...},"actor":"authentik-username"}
     */
    public function remoteCall(array $req): array
    {
        $st = $this->loadState();
        $token = (string)$st['settings']['remote_token'];
        if ($token === '' || !hash_equals($token, (string)($req['token'] ?? ''))) {
            return ['ok' => false, 'error' => 'bad token'];
        }
        $op = (string)($req['op'] ?? '');
        if (!in_array($op, self::REMOTE_OPS, true)) {
            return ['ok' => false, 'error' => 'op not allowed remotely'];
        }
        $args = is_array($req['args'] ?? null) ? $req['args'] : [];
        $actor = 'panel:' . preg_replace('/[^A-Za-z0-9@._-]/', '', (string)($req['actor'] ?? '?'));
        if ($op === 'calls') {
            if (empty($st['settings']['remote_enabled'])) return ['ok' => false, 'error' => 'remote control is disabled on the PBX'];
            return $this->calls((string)($args['id'] ?? ''));
        }
        if ($op === 'live') {
            if (empty($st['settings']['remote_enabled'])) return ['ok' => false, 'error' => 'remote control is disabled on the PBX'];
            return $this->live();
        }
        if ($op === 'hangup') {
            if (empty($st['settings']['remote_enabled'])) return ['ok' => false, 'error' => 'remote control is disabled on the PBX'];
            return $this->hangupCall((string)($args['channel'] ?? ''), 'remote', $actor);
        }
        return $this->op($op, $args, 'remote', $actor);
    }

    // --------------------------------------------------------------- page

    public function showPage(): string
    {
        require_once __DIR__ . '/views/page.php';
        return \ApiUsers\renderPage($this);
    }
}
