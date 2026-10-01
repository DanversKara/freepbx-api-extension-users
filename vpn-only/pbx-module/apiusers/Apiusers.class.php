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

use ApiUsers\Engine;
use ApiUsers\ConfigGen;

class Apiusers extends \FreePBX_Helpers implements \BMO
{
    public const TABLE = 'apiusers_kv';
    public const PJSIP_FILE = 'pjsip_apiusers.conf';
    public const DIALPLAN_FILE = 'extensions_apiusers.conf';

    /** ops the Docker panel may call. Engine::remoteGuard() enforces the safety lock on top. */
    public const REMOTE_OPS = ['list', 'get', 'create', 'update', 'delete', 'rotate', 'kill', 'audit', 'calls'];

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
        $this->ensureTable();
        $stmt = $this->db->prepare('REPLACE INTO ' . self::TABLE . ' (k, v) VALUES (?, ?)');
        $stmt->execute(['state', json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]);
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
        return $this->op($op, $args, 'remote', $actor);
    }

    // --------------------------------------------------------------- page

    public function showPage(): string
    {
        require_once __DIR__ . '/views/page.php';
        return \ApiUsers\renderPage($this);
    }
}
