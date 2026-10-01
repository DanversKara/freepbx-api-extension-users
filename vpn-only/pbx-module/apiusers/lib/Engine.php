<?php
/**
 * API Users – core engine (pure PHP, no FreePBX dependencies, unit-testable).
 *
 * WHAT THIS IS
 *   The single source of truth for "API users": SIP accounts that are NOT real
 *   FreePBX extensions, live only behind the Docker gateway (Kamailio on
 *   192.168.8.100), and are locked into their own dialplan context.
 *
 *   Both front-ends call into this class:
 *     - local  : the FreePBX "API Users" page (reached over VPN / LAN)
 *     - remote : the Docker admin panel via SSH forced command (bin/apiusers-remote)
 *
 * SAFETY LOCK (source === 'remote')
 *   Remote callers can NEVER grant paid / emergency calling:
 *     - cannot set external / e911 / international to true (only to false)
 *     - cannot release the kill switch (can engage it)
 *     - cannot change settings, cannot reveal existing secrets
 *   See remoteGuard(). Do not weaken this without the owner asking.
 *
 * NOTES FOR FUTURE EDITS
 *   - Generated files: see ConfigGen.php. This class never touches disk.
 *   - ZOIPER-PRO-TODO: when public TLS is added, nothing changes here except
 *     possibly a per-user "allow_public_tls" flag (see CLAUDE.md, section ZOIPER-PRO).
 */

namespace ApiUsers;

class Engine
{
    public const REACH_MIN = 8800;
    public const REACH_MAX = 8899;
    public const MAX_CALLS_LIMIT = 5;
    public const MAX_MINUTES_LIMIT = 480;

    /** @var array full state: ['settings'=>[], 'users'=>[id=>user], 'audit'=>[]] */
    private array $state;
    /** @var string[] real FreePBX extensions that may be whitelisted as targets */
    private array $localExtensions;

    public function __construct(array $state, array $localExtensions = [])
    {
        $this->state = self::normalizeState($state);
        $this->localExtensions = array_values(array_map('strval', $localExtensions));
    }

    public static function defaultSettings(): array
    {
        return [
            'gateway_ip'     => '192.168.8.100', // Kamailio box; only source allowed to use API endpoints
            'transport_port' => 5099,            // PBX-side UDP port Kamailio sends to
            'safety_lock'    => true,            // remote cannot grant external/911/international
            'remote_enabled' => true,            // master switch for the Docker panel
            'remote_token'   => '',              // shared secret (defense in depth on top of SSH key)
            'kill_switch'    => false,           // true = all API users disabled + calls hung up
            'codecs'         => 'ulaw,alaw,g722',
            // Shown to users as "what to type into Zoiper". Display only.
            'client_vpn_addr' => '',                    // AstroWarp virtual IP of 192.168.8.100 + :5070
            'client_lan_addr' => '192.168.8.100:5072',  // at home on Wi-Fi
            // ZOIPER-PRO-TODO: add 'client_tls_addr' => 'pbx.yourdomain.com:5443' when public TLS exists.
        ];
    }

    public static function normalizeState(array $s): array
    {
        $s['settings'] = array_merge(self::defaultSettings(), $s['settings'] ?? []);
        $s['users'] = $s['users'] ?? [];
        $s['audit'] = $s['audit'] ?? [];
        return $s;
    }

    public function state(): array { return $this->state; }
    public function settings(): array { return $this->state['settings']; }
    public function users(): array { return $this->state['users']; }

    // ------------------------------------------------------------------ ops

    /**
     * Run one operation. Returns ['ok'=>bool, ...]. Mutates internal state on success.
     * $op: list|get|create|update|delete|rotate|reveal|kill|unkill|settings|audit
     */
    public function run(string $op, array $args, string $source, string $actor = ''): array
    {
        if (!in_array($source, ['local', 'remote'], true)) {
            return self::err('bad source');
        }
        if ($source === 'remote') {
            $g = $this->remoteGuard($op, $args);
            if ($g !== null) {
                $this->audit($source, $actor, $op, $args['id'] ?? '', 'DENIED: ' . $g);
                return self::err($g);
            }
        }

        switch ($op) {
            case 'list':
                return ['ok' => true, 'users' => array_map([$this, 'publicView'], array_values($this->state['users'])),
                        'kill_switch' => $this->state['settings']['kill_switch'],
                        'safety_lock' => $this->state['settings']['safety_lock'],
                        'client_vpn_addr' => $this->state['settings']['client_vpn_addr'],
                        'client_lan_addr' => $this->state['settings']['client_lan_addr'],
                        'local_extensions' => $this->localExtensions];
            case 'get':
                $u = $this->state['users'][$args['id'] ?? ''] ?? null;
                return $u ? ['ok' => true, 'user' => $this->publicView($u)] : self::err('no such user');
            case 'create':
                return $this->create($args, $source, $actor);
            case 'update':
                return $this->update($args, $source, $actor);
            case 'delete':
                $id = (string)($args['id'] ?? '');
                if (!isset($this->state['users'][$id])) return self::err('no such user');
                unset($this->state['users'][$id]);
                $this->audit($source, $actor, 'delete', $id);
                return ['ok' => true, 'changed' => true];
            case 'rotate':
                $id = (string)($args['id'] ?? '');
                if (!isset($this->state['users'][$id])) return self::err('no such user');
                $secret = self::randomSecret();
                $this->state['users'][$id]['secret'] = $secret;
                $this->audit($source, $actor, 'rotate', $id);
                return ['ok' => true, 'changed' => true, 'secret' => $secret,
                        'username' => $this->state['users'][$id]['sip_user']];
            case 'reveal': // local only (guarded above)
                $u = $this->state['users'][$args['id'] ?? ''] ?? null;
                return $u ? ['ok' => true, 'secret' => $u['secret'], 'username' => $u['sip_user']] : self::err('no such user');
            case 'kill':
                $this->state['settings']['kill_switch'] = true;
                $this->audit($source, $actor, 'kill', '', 'kill switch ENGAGED');
                return ['ok' => true, 'changed' => true, 'hangup' => true];
            case 'unkill': // local only
                $this->state['settings']['kill_switch'] = false;
                $this->audit($source, $actor, 'unkill', '', 'kill switch released');
                return ['ok' => true, 'changed' => true];
            case 'settings': // local only
                return $this->updateSettings($args, $source, $actor);
            case 'audit':
                return ['ok' => true, 'audit' => array_slice($this->state['audit'], -100)];
        }
        return self::err('unknown op');
    }

    /** Returns an error string if a remote call is not allowed, else null. */
    private function remoteGuard(string $op, array $args): ?string
    {
        $s = $this->state['settings'];
        if (!$s['remote_enabled']) return 'remote control is disabled on the PBX';
        if (in_array($op, ['reveal', 'unkill', 'settings'], true)) {
            return "'$op' is only allowed from the PBX page (VPN/LAN)";
        }
        if (!$s['safety_lock']) return null;
        $risky = ['external', 'e911', 'international'];
        if ($op === 'create') {
            foreach ($risky as $k) {
                if (!empty($args[$k])) return "safety lock: '$k' can only be enabled from the PBX page";
            }
        }
        if ($op === 'update') {
            $cur = $this->state['users'][$args['id'] ?? ''] ?? null;
            if ($cur) {
                foreach ($risky as $k) {
                    if (array_key_exists($k, $args) && !empty($args[$k]) && empty($cur[$k])) {
                        return "safety lock: '$k' can only be enabled from the PBX page";
                    }
                }
            }
        }
        return null;
    }

    private function create(array $a, string $source, string $actor): array
    {
        $name = self::cleanName($a['name'] ?? '');
        if ($name === '') return self::err('name is required');

        $id = self::newId(array_keys($this->state['users']));
        $reach = isset($a['reach']) && $a['reach'] !== '' ? (int)$a['reach'] : $this->nextReach();
        if ($reach === 0) return self::err('no free reach extensions left (8800-8899)');
        $e = $this->checkReach($reach, null);
        if ($e) return self::err($e);

        $u = [
            'id'            => $id,
            'name'          => $name,
            'sip_user'      => 'apiu-' . bin2hex(random_bytes(5)),
            'secret'        => self::randomSecret(),
            'reach'         => $reach,
            'enabled'       => true,
            'internal'      => true,
            'external'      => false,
            'e911'          => false,
            'international' => false,
            'allowed'       => [],
            'max_calls'     => 1,
            'max_minutes'   => 120,
            'created'       => gmdate('c'),
        ];
        $r = $this->applyFields($u, $a, true);
        if ($r !== null) return self::err($r);
        $this->state['users'][$id] = $u;
        $this->audit($source, $actor, 'create', $id, $name . ' perms=' . $this->permString($u));
        return ['ok' => true, 'changed' => true, 'user' => $this->publicView($u),
                'username' => $u['sip_user'], 'secret' => $u['secret']];
    }

    private function update(array $a, string $source, string $actor): array
    {
        $id = (string)($a['id'] ?? '');
        if (!isset($this->state['users'][$id])) return self::err('no such user');
        $u = $this->state['users'][$id];
        if (isset($a['name'])) {
            $n = self::cleanName($a['name']);
            if ($n === '') return self::err('name is required');
            $u['name'] = $n;
        }
        if (isset($a['reach']) && $a['reach'] !== '' && (int)$a['reach'] !== (int)$u['reach']) {
            $e = $this->checkReach((int)$a['reach'], $id);
            if ($e) return self::err($e);
            $u['reach'] = (int)$a['reach'];
        }
        $r = $this->applyFields($u, $a, false);
        if ($r !== null) return self::err($r);
        $before = $this->permString($this->state['users'][$id]);
        $this->state['users'][$id] = $u;
        $this->audit($source, $actor, 'update', $id, $before . ' -> ' . $this->permString($u));
        return ['ok' => true, 'changed' => true, 'user' => $this->publicView($u)];
    }

    /** Validates & copies permission / limit fields. Returns error or null. */
    private function applyFields(array &$u, array $a, bool $isNew): ?string
    {
        foreach (['enabled', 'internal', 'external', 'e911', 'international'] as $k) {
            if (array_key_exists($k, $a)) $u[$k] = self::bool($a[$k]);
        }
        if (array_key_exists('allowed', $a)) {
            $list = is_array($a['allowed']) ? $a['allowed'] : preg_split('/[\s,]+/', (string)$a['allowed']);
            $clean = [];
            foreach ($list as $x) {
                $x = trim((string)$x);
                if ($x === '') continue;
                if (!preg_match('/^\d{2,6}$/', $x)) return "allowed extension '$x' is not a number";
                if (preg_match('/^([2-9]11|933)$/', $x)) return "$x is a service/emergency number, not an extension (use the External/911 switches)";
                $isReach = (int)$x >= self::REACH_MIN && (int)$x <= self::REACH_MAX;
                if (!$isReach && $this->localExtensions && !in_array($x, $this->localExtensions, true)) {
                    return "extension $x does not exist on this PBX";
                }
                if ($isReach && (int)$x === (int)$u['reach']) continue; // can't call yourself
                $clean[$x] = true;
            }
            $u['allowed'] = array_keys($clean);
            sort($u['allowed']);
            $u['allowed'] = array_map('strval', $u['allowed']);
        }
        if (array_key_exists('max_calls', $a)) {
            $m = (int)$a['max_calls'];
            if ($m < 1 || $m > self::MAX_CALLS_LIMIT) return 'max calls must be 1-' . self::MAX_CALLS_LIMIT;
            $u['max_calls'] = $m;
        }
        if (array_key_exists('max_minutes', $a)) {
            $m = (int)$a['max_minutes'];
            if ($m < 0 || $m > self::MAX_MINUTES_LIMIT) return 'max minutes must be 0-' . self::MAX_MINUTES_LIMIT . ' (0 = no limit)';
            $u['max_minutes'] = $m;
        }
        // 911 and international only make sense on top of external
        if (!$u['external']) { $u['e911'] = false; $u['international'] = false; }
        return null;
    }

    private function updateSettings(array $a, string $source, string $actor): array
    {
        $s = &$this->state['settings'];
        if (isset($a['gateway_ip'])) {
            if (!filter_var($a['gateway_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return self::err('gateway IP invalid');
            $s['gateway_ip'] = $a['gateway_ip'];
        }
        if (isset($a['transport_port'])) {
            $p = (int)$a['transport_port'];
            if ($p < 1024 || $p > 65535 || in_array($p, [5060, 5061], true)) return self::err('transport port invalid');
            $s['transport_port'] = $p;
        }
        foreach (['safety_lock', 'remote_enabled'] as $k) {
            if (array_key_exists($k, $a)) $s[$k] = self::bool($a[$k]);
        }
        foreach (['client_vpn_addr', 'client_lan_addr'] as $k) {
            if (array_key_exists($k, $a)) {
                $v = trim((string)$a[$k]);
                if ($v !== '' && !preg_match('/^[A-Za-z0-9.-]{1,253}(:\d{1,5})?$/', $v)) return self::err("$k must look like host:port");
                $s[$k] = $v;
            }
        }
        if (!empty($a['regen_token'])) $s['remote_token'] = bin2hex(random_bytes(24));
        $this->audit($source, $actor, 'settings', '', 'settings updated');
        return ['ok' => true, 'changed' => true];
    }

    // -------------------------------------------------------------- helpers

    public function ensureToken(): bool
    {
        if ($this->state['settings']['remote_token'] === '') {
            $this->state['settings']['remote_token'] = bin2hex(random_bytes(24));
            return true;
        }
        return false;
    }

    private function nextReach(): int
    {
        $used = array_map(fn($u) => (int)$u['reach'], $this->state['users']);
        for ($i = self::REACH_MIN + 1; $i <= self::REACH_MAX; $i++) {
            if (!in_array($i, $used, true) && !in_array((string)$i, $this->localExtensions, true)) return $i;
        }
        return 0;
    }

    private function checkReach(int $r, ?string $selfId): ?string
    {
        if ($r < self::REACH_MIN || $r > self::REACH_MAX) return 'reach extension must be 8800-8899';
        if (in_array((string)$r, $this->localExtensions, true)) return "$r is already a real extension";
        foreach ($this->state['users'] as $id => $u) {
            if ($id !== $selfId && (int)$u['reach'] === $r) return "$r is used by {$u['name']}";
        }
        return null;
    }

    public function publicView(array $u): array
    {
        unset($u['secret']);
        return $u;
    }

    public function permString(array $u): string
    {
        $p = [];
        if (!$u['enabled']) $p[] = 'DISABLED';
        $p[] = $u['external'] ? 'external' : 'internal-only';
        if ($u['e911']) $p[] = '911';
        if ($u['international']) $p[] = 'intl';
        $p[] = 'exts=' . (implode('/', $u['allowed']) ?: 'none');
        return implode(',', $p);
    }

    private function audit(string $src, string $actor, string $op, string $id, string $detail = ''): void
    {
        $this->state['audit'][] = ['t' => gmdate('c'), 'src' => $src, 'actor' => substr($actor, 0, 64),
                                   'op' => $op, 'id' => $id, 'detail' => substr($detail, 0, 300)];
        if (count($this->state['audit']) > 300) $this->state['audit'] = array_slice($this->state['audit'], -300);
    }

    public static function cleanName(string $n): string
    {
        // Safe for CallerID and config files: letters, digits, space, . - _ '
        $n = preg_replace("/[^A-Za-z0-9 ._'-]/", '', $n);
        return trim(substr($n, 0, 40));
    }

    public static function randomSecret(): string
    {
        // 24 chars, no ambiguous characters, easy to type into Zoiper
        $alpha = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 24; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
        return $s;
    }

    private static function newId(array $existing): string
    {
        do { $id = 'u' . bin2hex(random_bytes(3)); } while (in_array($id, $existing, true));
        return $id;
    }

    private static function bool($v): bool
    {
        return in_array($v, [true, 1, '1', 'on', 'true', 'yes'], true);
    }

    private static function err(string $m): array { return ['ok' => false, 'error' => $m]; }
}
