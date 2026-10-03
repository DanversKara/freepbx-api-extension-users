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
require_once __DIR__ . '/lib/Alerts.php';
require_once __DIR__ . '/lib/Mailer.php';

use ApiUsers\Engine;
use ApiUsers\ConfigGen;
use ApiUsers\Live;
use ApiUsers\Alerts;
use ApiUsers\Mailer;

class Apiusers extends \FreePBX_Helpers implements \BMO
{
    public const TABLE = 'apiusers_kv';
    public const PJSIP_FILE = 'pjsip_apiusers.conf';
    public const DIALPLAN_FILE = 'extensions_apiusers.conf';

    /** ops the Docker panel may call. Engine::remoteGuard() enforces the safety lock on top. */
    public const REMOTE_OPS = ['list', 'get', 'create', 'update', 'delete', 'rotate', 'kill', 'audit', 'calls', 'live', 'hangup', 'history', 'history_delete'];

    /** How many sign-in / sign-out events to keep (table apiusers_kv, key 'signins'). */
    public const SIGNIN_KEEP = 300;
    /** Out + back in through the same door within this many seconds = one session (no history lines). */
    public const BLIP_SECONDS = 300;
    /** How many dismissed failed-sign-in ids to remember. */
    public const FAILED_HIDE_KEEP = 2000;

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
        return in_array($req, ['live', 'history'], true);
    }

    public function ajaxHandler()
    {
        $cmd = $_REQUEST['command'] ?? '';
        if ($cmd === 'live') return $this->live();
        if ($cmd === 'history') return $this->history();
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

    /**
     * Conference rooms and enabled feature codes, read from FreePBX's own tables
     * (conferences module: `meetme`; framework: `featurecodes`). null = couldn't read.
     */
    public function directory(): array
    {
        $confs = null; $codes = null;
        try {
            $confs = [];
            foreach ($this->db->query('SELECT exten, description FROM meetme')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $confs[(string)$r['exten']] = (string)$r['description'];
            }
            ksort($confs, SORT_NATURAL);
        } catch (\Throwable $e) { $confs = null; }
        try {
            $codes = [];
            $q = $this->db->query('SELECT featurename, description, defaultcode, customcode, enabled FROM featurecodes');
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if ((string)$r['enabled'] !== '1') continue;
                $code = trim((string)(($r['customcode'] ?? '') !== '' ? $r['customcode'] : $r['defaultcode']));
                if ($code === '' || !preg_match('/^[0-9*#]{2,10}$/', $code)) continue;
                $codes[$code] = trim((string)($r['description'] ?: $r['featurename']));
            }
            ksort($codes, SORT_NATURAL);
        } catch (\Throwable $e) { $codes = null; }
        return ['confs' => $confs, 'features' => $codes];
    }

    // --------------------------------------------------------------- core

    /**
     * Run an Engine op; on change, persist + regenerate + reload + hang up as needed.
     */
    public function op(string $op, array $args, string $source, string $actor): array
    {
        $before = $this->loadState();
        $eng = new Engine($before, array_keys($this->localExtensions()), $this->directory());
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

    /** Last ~2 MB of the Asterisk "full" log (for failed sign-ins). */
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
            $now = time();
            [$next, $events] = Live::presence($this->kvGet('presence'), $devices, $now);
            if ($events) {
                $log = $this->kvGet('signins');
                $kept = [];
                foreach ($events as $ev) {
                    // Blip: signed out and back in through the same door within 5 minutes (phone asleep,
                    // network hiccup). Drop the "signed out" line and don't add a new "signed in" one.
                    if ($ev['ev'] === 'in') {
                        for ($i = count($log) - 1; $i >= 0; $i--) {
                            if (($log[$i]['user_id'] ?? '') !== $ev['user_id']) continue;
                            $p = $log[$i];
                            if (($p['ev'] ?? '') === 'out' && $now - (int)$p['t'] <= self::BLIP_SECONDS && ($p['door'] ?? '') === $ev['door']) {
                                array_splice($log, $i, 1);
                                foreach ($next['devices'] as &$nd) {
                                    if ($nd['user_id'] === $ev['user_id'] && $nd['since'] === $now) $nd['since'] = (int)$p['t'] - (int)($p['dur'] ?? 0);
                                }
                                unset($nd);
                                continue 2;
                            }
                            break;
                        }
                    }
                    $kept[] = $ev;
                }
                foreach ($kept as $ev) $log[] = $ev;
                if (count($log) > self::SIGNIN_KEEP) $log = array_slice($log, -self::SIGNIN_KEEP);
                $this->kvSet('signins', $log);
                $events = $kept;
            }
            $this->kvSet('presence', $next);
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
        $failed = $this->failedList($st);
        $signins = $this->signinList();
        $locks = Live::disaLocks($this->cli('database show apiusers_disa'), $now);
        $day = $now - 86400;
        return [
            'ok' => true, 'now' => $now, 'warning' => $warning,
            'devices' => $devices, 'calls' => $calls,
            'signins' => array_slice($signins, 0, 5), 'signins_total' => count($signins),
            'failed' => array_slice($failed, 0, 5), 'failed_total' => count($failed),
            'stats' => [
                'signins_24h' => count(array_filter($signins, fn($e) => $e['t'] >= $day && in_array($e['ev'], ['in', 'seen'], true))),
                'failed_24h' => count(array_filter($failed, fn($f) => $f['t'] >= $day)),
            ],
            'disa_locks' => $locks,
            'kill_switch' => !empty($s['kill_switch']),
            'users' => array_values(array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'],
                'public' => !empty($u['public']), 'enabled' => !empty($u['enabled']), 'reach' => (string)$u['reach']], $st['users'])),
        ];
    }

    /**
     * Once a minute from cron (bin/apiusers-presence): record sign-ins, then check for alerts and
     * send at most one digest e-mail. Returns a short status line.
     */
    public function cronTick(): string
    {
        $st = $this->loadState();
        $devices = $this->devices($st);
        $events = $this->presenceTick($devices, $st);
        $msg = count($events) . ' change(s)';
        $s = $st['settings'];
        if (($s['alert_email'] ?? '') === '') return $msg . ', alerts off';
        $locked = false;
        try { $q = $this->db->query("SELECT GET_LOCK('apiusers_alerts', 3)"); $locked = $q && (int)$q->fetchColumn() === 1; } catch (\Throwable $e) {}
        try {
            $now = time();
            $mem = Alerts::collect([
                'devices' => $devices, 'failed' => $this->failedList($st),
                'locks' => Live::disaLocks($this->cli('database show apiusers_disa'), $now),
                'audit' => $st['audit'], 'users' => $st['users'],
            ], $this->kvGet('alerts'), $now);
            if (Alerts::ready($mem, $now)) {
                [$subject, $body] = Alerts::compose($mem['pending']);
                $err = $this->sendMail($s, $s['alert_email'], $subject, $body);
                if ($err === null) {
                    $msg .= ', e-mailed ' . count($mem['pending']) . ' alert(s)';
                    $mem['pending'] = [];
                    $mem['last_mail'] = $now;
                    $mem['last_error'] = '';
                } else {
                    $msg .= ', e-mail FAILED: ' . $err;                  // keep them pending, retry next minute
                    $mem['last_error'] = $err;
                    $mem['last_mail'] = $now - Alerts::GAP + 60;
                }
            } elseif (!empty($mem['pending'])) {
                $msg .= ', ' . count($mem['pending']) . ' alert(s) waiting';
            }
            $this->kvSet('alerts', $mem);
        } finally {
            if ($locked) { try { $this->db->query("SELECT RELEASE_LOCK('apiusers_alerts')"); } catch (\Throwable $e) {} }
        }
        return $msg;
    }

    private function sendMail(array $s, string $to, string $subject, string $body): ?string
    {
        return Mailer::send(['host' => $s['smtp_host'], 'port' => $s['smtp_port'], 'security' => $s['smtp_security'],
            'user' => $s['smtp_user'], 'pass' => $s['smtp_pass'], 'from' => $s['smtp_from'], 'verify' => $s['smtp_verify']],
            $to, $subject, $body);
    }

    /** "Send test e-mail" button on the PBX page. */
    public function sendTestEmail(): array
    {
        $s = $this->loadState()['settings'];
        if (($s['alert_email'] ?? '') === '') return ['ok' => false, 'error' => 'Fill in and save the alert e-mail address first.'];
        $err = $this->sendMail($s, $s['alert_email'], '[PBX notice] Test e-mail from API Users',
            "This is a test from the API Users module on your PBX.\n\nIf you can read this, alert e-mails work. You'll get one when someone\n"
            . "fails to sign in, signs in from two places at once, a VPN account uses the public door,\n"
            . "a dial-out code gets locked, or the kill switch / remote panel is used in a risky way.\n");
        return $err === null ? ['ok' => true] : ['ok' => false, 'error' => 'Test e-mail failed: ' . $err];
    }

    /** Last mail problem, shown under the alert settings. */
    public function alertStatus(): array
    {
        $m = $this->kvGet('alerts');
        return ['last_mail' => (int)($m['last_mail'] ?? 0), 'last_error' => (string)($m['last_error'] ?? ''), 'pending' => count($m['pending'] ?? [])];
    }

    /** Sign-in history, newest first. Old entries without an id get a stable one. */
    private function signinList(): array
    {
        $out = [];
        foreach (array_reverse($this->kvGet('signins')) as $e) {
            if (!is_array($e)) continue;
            $e['id'] = $e['id'] ?? substr(md5(json_encode($e)), 0, 12);
            $out[] = $e;
        }
        return $out;
    }

    /** Failed sign-ins from the PBX log, newest first, minus the ones dismissed here. */
    private function failedList(?array $st = null): array
    {
        $st = $st ?? $this->loadState();
        $s = $st['settings'];
        $hide = $this->kvGet('failed_hidden');
        $before = (int)($hide['before'] ?? 0);
        $ids = array_flip((array)($hide['ids'] ?? []));
        $all = Live::failures($this->logTail(), $st['users'], (string)$s['gateway_ip'],
            ['vpn' => $s['client_vpn_addr'], 'lan' => $s['client_lan_addr'], 'pub' => $s['client_pub_addr'] ?? ''], 500);
        return array_values(array_filter($all, fn($f) => $f['t'] > $before && !isset($ids[$f['id']])));
    }

    /** Full lists for the Sign-in history and Failed sign-ins tabs. */
    public function history(): array
    {
        return ['ok' => true, 'now' => time(), 'signins' => $this->signinList(), 'failed' => $this->failedList()];
    }

    /**
     * Bulk delete. $kind = signins | failed; $ids = list of ids, or 'all'.
     * Sign-in history rows are removed. Failed sign-ins live in the Asterisk log, so they're
     * dismissed (hidden here) instead. Allowed from both pages; written to the audit log.
     */
    public function historyDelete(string $kind, $ids, string $source, string $actor): array
    {
        if (!in_array($kind, ['signins', 'failed'], true)) return ['ok' => false, 'error' => 'unknown list'];
        $all = ($ids === 'all');
        $want = $all ? [] : array_flip(array_filter(array_map('strval', (array)$ids), fn($x) => preg_match('/^[0-9a-f]{6,16}$/', $x)));
        if (!$all && !$want) return ['ok' => false, 'error' => 'nothing selected'];
        if ($kind === 'signins') {
            $rows = $this->signinList();
            $keep = $all ? [] : array_values(array_filter($rows, fn($e) => !isset($want[$e['id']])));
            $n = count($rows) - count($keep);
        } else {
            $rows = $this->failedList();
            $n = $all ? count($rows) : count(array_filter($rows, fn($f) => isset($want[$f['id']])));
        }
        $r = $this->op('history_clear', ['kind' => $kind, 'count' => $n, 'all' => $all], $source, $actor);
        if (empty($r['ok'])) return $r;
        if ($kind === 'signins') {
            $this->kvSet('signins', array_reverse($keep));
        } else {
            $hide = $this->kvGet('failed_hidden');
            if ($all) {
                $hide['before'] = max(time(), $rows ? max(array_column($rows, 't')) : 0);
                $hide['ids'] = [];
            } else {
                $hide['ids'] = array_slice(array_values(array_unique(array_merge((array)($hide['ids'] ?? []), array_keys($want)))), -self::FAILED_HIDE_KEEP);
            }
            $this->kvSet('failed_hidden', $hide);
        }
        return ['ok' => true, 'deleted' => $n];
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
        if ($op === 'history' || $op === 'history_delete') {
            if (empty($st['settings']['remote_enabled'])) return ['ok' => false, 'error' => 'remote control is disabled on the PBX'];
            if ($op === 'history') return $this->history();
            $ids = $args['ids'] ?? [];
            return $this->historyDelete((string)($args['kind'] ?? ''), $ids === 'all' ? 'all' : (array)$ids, 'remote', $actor);
        }
        if ($op === 'hangup') {
            if (empty($st['settings']['remote_enabled'])) return ['ok' => false, 'error' => 'remote control is disabled on the PBX'];
            return $this->hangupCall((string)($args['channel'] ?? ''), 'remote', $actor);
        }
        return $this->op($op, $args, 'remote', $actor);
    }

    // ------------------------------------------------------------- portal

    /**
     * Called by bin/apiusers-remote --portal (SSH forced command for the user portal container, user
     * `apiportal`). $req = {"token":"...","op":"me|rotate","login":"<Authentik user name>","id":"<account, rotate only>"}
     * One login can own SEVERAL accounts (one per phone): every account whose portal_login matches.
     * The portal can only ever see / change those accounts.
     */
    public function portalCall(array $req): array
    {
        $st = $this->loadState();
        $s = $st['settings'];
        $token = (string)($s['portal_token'] ?? '');
        if ($token === '' || !hash_equals($token, (string)($req['token'] ?? ''))) return ['ok' => false, 'error' => 'bad token'];
        if (empty($s['portal_enabled'])) return ['ok' => false, 'error' => 'The user portal is turned off on the PBX.'];
        $login = strtolower(trim((string)($req['login'] ?? '')));
        $mine = [];
        if ($login !== '') foreach ($st['users'] as $x) if (strtolower((string)($x['portal_login'] ?? '')) === $login) $mine[$x['id']] = $x;
        if (!$mine) return ['ok' => false, 'error' => 'No phone account is linked to "' . $login . '" yet. Ask the person who runs the phone system to add it.'];
        uasort($mine, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $actor = 'portal:' . preg_replace('/[^a-z0-9@._+-]/', '', $login);
        $op = (string)($req['op'] ?? '');

        if ($op === 'rotate') {
            $id = (string)($req['id'] ?? '');
            if (count($mine) === 1 && $id === '') $id = array_key_first($mine);
            if (!isset($mine[$id])) return ['ok' => false, 'error' => 'That phone is not one of yours.'];
            $r = $this->op('rotate', ['id' => $id], 'portal', $actor);
            if (empty($r['ok'])) return $r;
            return ['ok' => true, 'id' => $id, 'username' => $r['username'], 'secret' => $r['secret']];
        }
        if ($op !== 'me') return ['ok' => false, 'error' => 'unknown request'];

        $devAll = $this->devices($st);
        $callsAll = Live::calls($this->cli('core show channels concise'), $this->cli('group show channels'), $st['users'], $devAll);
        $signAll = $this->signinList();
        $failAll = $this->failedList($st);
        $accounts = []; $cdr = []; $sign = []; $fail = []; $cdrErr = '';
        foreach ($mine as $id => $u) {
            $devices = array_values(array_filter($devAll, fn($d) => $d['user_id'] === $id));
            foreach ($devices as &$d) unset($d['key']);
            unset($d);
            $accounts[] = ['id' => $id] + $this->portalProfile($u, $s) + [
                'devices' => $devices,
                'live_calls' => array_values(array_map(fn($c) => array_diff_key($c, ['channel' => 1, 'uniqueid' => 1]),
                                    array_filter($callsAll, fn($c) => $c['user_id'] === $id))),
            ];
            $c = $this->calls($id, 100);
            if ($c['ok']) foreach ($c['calls'] as $row) $cdr[] = $row + ['phone' => $u['name']];
            else $cdrErr = 'Call history is not available right now.';
            foreach ($signAll as $e) if ($e['user_id'] === $id) $sign[] = $e + ['phone' => $u['name']];
            foreach ($failAll as $f) if (($f['user_id'] ?? '') === $id) $fail[] = $f + ['phone' => $u['name']];
        }
        usort($cdr, fn($a, $b) => strcmp((string)$b['calldate'], (string)$a['calldate']));
        usort($sign, fn($a, $b) => $b['t'] <=> $a['t']);
        usort($fail, fn($a, $b) => $b['t'] <=> $a['t']);
        return ['ok' => true, 'now' => time(), 'accounts' => $accounts,
                'calls' => array_slice($cdr, 0, 150), 'calls_error' => $cdrErr,
                'signins' => array_slice($sign, 0, 150), 'failed' => array_slice($fail, 0, 150)];
    }

    /** What a user may see about their own account (never the password or anything about others). */
    private function portalProfile(array $u, array $s): array
    {
        $names = $this->localExtensions();
        $rooms = $this->directory()['confs'] ?? [];
        $byReach = [];
        foreach ($this->loadState()['users'] as $o) $byReach[(string)$o['reach']] = $o['name'];
        $pub = !empty($u['public']);
        return ['me' => [
            'name' => $u['name'], 'reach' => (string)$u['reach'], 'username' => $u['sip_user'], 'enabled' => !empty($u['enabled']),
            'public' => $pub, 'external' => !empty($u['external']), 'e911' => !empty($u['e911']), 'international' => !empty($u['international']),
            'disa' => !empty($u['disa']), 'disa_code' => (!empty($u['disa']) ? (string)($s['disa_code'] ?? '') : ''),
            'max_calls' => (int)$u['max_calls'], 'max_minutes' => (int)$u['max_minutes'],
            'can_call' => array_map(fn($x) => ['number' => (string)$x, 'name' => $names[$x] ?? ($byReach[$x] ?? '')], array_values($u['internal'] ? $u['allowed'] : [])),
            'conferences' => array_map(fn($x) => ['number' => (string)$x, 'name' => $rooms[$x] ?? ''], array_values($u['confs'] ?? [])),
            'feature_codes' => $pub ? [] : array_values($u['features'] ?? []),
            'servers' => array_filter(['VPN' => $s['client_vpn_addr'], 'Home Wi-Fi' => $s['client_lan_addr'],
                                       'Without VPN' => $pub ? $s['client_pub_addr'] : ''], fn($v) => $v !== ''),
            'kill_switch' => !empty($s['kill_switch']),
        ]];
    }

    // --------------------------------------------------------------- page

    public function showPage(): string
    {
        require_once __DIR__ . '/views/page.php';
        return \ApiUsers\renderPage($this);
    }
}
