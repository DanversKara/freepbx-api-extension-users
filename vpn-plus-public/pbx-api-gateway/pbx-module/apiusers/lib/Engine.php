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

    /** @var array|null conference rooms on this PBX (room => name); null = couldn't be read (any number accepted) */
    private ?array $confRooms;
    /** @var array|null enabled FreePBX feature codes (code => description); null = couldn't be read */
    private ?array $featureCodes;

    /** $directory = ['confs' => [room => name] | null, 'features' => [code => description] | null] */
    public function __construct(array $state, array $localExtensions = [], array $directory = [])
    {
        $this->state = self::normalizeState($state);
        $this->localExtensions = array_values(array_map('strval', $localExtensions));
        $this->confRooms = $directory['confs'] ?? null;
        $this->featureCodes = $directory['features'] ?? null;
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
            'client_pub_addr' => '',                    // public UDP door, e.g. pbx.example.com:5080 (no VPN)
            'disa_code'      => '*3472',                // what PUBLIC users dial to reach the PIN-protected dial-out
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
     * $op: list|get|create|update|delete|rotate|reveal|kill|unkill|settings|audit|hangup|disa_unlock
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
                        'client_pub_addr' => $this->state['settings']['client_pub_addr'],
                        'disa_code' => $this->state['settings']['disa_code'],
                        'local_extensions' => $this->localExtensions,
                        'conferences' => self::pairs($this->confRooms ?? []),
                        'feature_codes' => self::pairs($this->featureCodes ?? [])];
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
            case 'hangup': // end one API user's call (Live view). Allowed remotely: it only stops things.
                $ch = (string)($args['channel'] ?? '');
                if (!preg_match('#^PJSIP/(apiu-[0-9a-f]+)-[0-9a-f]+$#', $ch, $m)) return self::err('not an API user call');
                foreach ($this->state['users'] as $u) {
                    if ($u['sip_user'] === $m[1]) {
                        $this->audit($source, $actor, 'hangup', $u['id'], $ch);
                        return ['ok' => true, 'channel' => $ch];
                    }
                }
                return self::err('not an API user call');
            case 'disa_unlock': // local only (guarded above): clear a 5-wrong-PINs lockout early
                $id = (string)($args['id'] ?? '');
                if (empty($this->state['users'][$id]['public'])) return self::err('no such Public account');
                $this->audit($source, $actor, 'disa_unlock', $id, 'DISA lockout cleared');
                return ['ok' => true, 'id' => $id];
        }
        return self::err('unknown op');
    }

    /** Returns an error string if a remote call is not allowed, else null. */
    private function remoteGuard(string $op, array $args): ?string
    {
        $s = $this->state['settings'];
        if (!$s['remote_enabled']) return 'remote control is disabled on the PBX';
        if (in_array($op, ['reveal', 'unkill', 'settings', 'disa_unlock'], true)) {
            return "'$op' is only allowed from the PBX page (VPN/LAN)";
        }
        if (!$s['safety_lock']) return null;
        // Feature codes run with a house phone's powers (ChanSpy can listen to any call): add them on the PBX page only.
        if (($op === 'create' || $op === 'update') && array_key_exists('features', $args)) {
            $cur = $op === 'update' ? ($this->state['users'][$args['id'] ?? '']['features'] ?? []) : [];
            foreach (self::codeList($args['features']) as $c) {
                if (!in_array($c, $cur, true)) return 'safety lock: feature codes can only be added from the PBX page';
            }
        }
        if (($op === 'create' || $op === 'update') && isset($args['disa_pin']) && $args['disa_pin'] !== '') {
            return 'safety lock: DISA PINs can only be set from the PBX page';
        }
        $risky = ['external', 'e911', 'international', 'public', 'disa'];
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
            'confs'         => [],      // conference rooms they may join (FreePBX Conferences)
            'features'      => [],      // exact feature codes they may dial (VPN accounts only)
            'public'        => false,   // ACCOUNT TYPE, fixed at creation: false = VPN account, true = Public account
            'disa'          => false,   // public accounts only: PIN-protected dial-out code (never 911)
            'disa_salt'     => '',
            'disa_hash'     => '',      // sha1(salt . pin); the PIN itself is never stored
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
        // users saved before these fields existed
        $u += ['public' => false, 'disa' => false, 'disa_salt' => '', 'disa_hash' => ''];

        // ---- ACCOUNT TYPE is fixed at creation ------------------------------
        if (array_key_exists('public', $a)) {
            $want = self::bool($a['public']);
            if (!$isNew && $want !== (bool)$u['public']) {
                return 'The account type (VPN / Public) is fixed when the account is created. Create a new user instead.';
            }
            $u['public'] = $want;
        }
        // ---- PUBLIC accounts can NEVER have outside calling or 911 -----------
        if ($u['public']) {
            foreach (['external', 'e911', 'international'] as $k) {
                if (array_key_exists($k, $a) && self::bool($a[$k])) {
                    return 'Public (no-VPN) accounts can never have outside calls, 911 or international. '
                         . 'Use the DISA dial-out code for outside calls (911 is never possible).';
                }
            }
        }
        foreach (['enabled', 'internal', 'external', 'e911', 'international', 'disa'] as $k) {
            if (array_key_exists($k, $a)) $u[$k] = self::bool($a[$k]);
        }
        // ---- DISA PIN (public accounts only) ---------------------------------
        if (isset($a['disa_pin']) && $a['disa_pin'] !== '') {
            if (!$u['public']) return 'DISA is only for Public accounts (VPN accounts use the External switch).';
            $pin = (string)$a['disa_pin'];
            $e = self::checkPin($pin);
            if ($e) return $e;
            $u['disa_salt'] = bin2hex(random_bytes(8));
            $u['disa_hash'] = sha1($u['disa_salt'] . $pin);
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
        // ---- conference rooms + feature codes ---------------------------------
        $u += ['confs' => [], 'features' => []];
        if (array_key_exists('confs', $a)) {
            $clean = [];
            foreach (self::codeList($a['confs']) as $c) {
                if (!preg_match('/^\d{2,8}$/', $c)) return "conference room '$c' is not a number";
                if ($this->confRooms !== null && !array_key_exists($c, $this->confRooms)) {
                    return "conference room $c does not exist on this PBX (Applications > Conferences)";
                }
                $clean[$c] = true;
            }
            $u['confs'] = array_map('strval', array_keys($clean));
            sort($u['confs'], SORT_STRING);
        }
        if (array_key_exists('features', $a)) {
            $clean = [];
            foreach (self::codeList($a['features']) as $c) {
                $e = self::checkFeatureCode($c);
                if ($e) return $e;
                $clean[$c] = true;
            }
            if ($clean && !empty($u['public'])) {
                return 'Public (no-VPN) accounts can never use feature codes: a feature code can do anything a house phone can.';
            }
            $u['features'] = array_map('strval', array_keys($clean));
            sort($u['features'], SORT_STRING);
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
        // Belt and braces: a public account is ALWAYS internal-only.
        if ($u['public']) { $u['external'] = $u['e911'] = $u['international'] = false; $u['features'] = []; }
        if (!$u['public']) { $u['disa'] = false; }
        if ($u['disa'] && $u['disa_hash'] === '') return 'Set a DISA PIN before turning the dial-out code on.';
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
        if (array_key_exists('disa_code', $a)) {
            $c = trim((string)$a['disa_code']);
            if (!preg_match('/^\*?\d{3,8}$/', $c)) return self::err('DISA code must be 3-8 digits, optionally starting with *');
            if (preg_match('/^(911|933|112|999|[2-9]11)$/', $c)) return self::err('DISA code cannot be a service/emergency number');
            if (in_array($c, $this->localExtensions, true)) return self::err("DISA code $c is a real extension");
            if (ctype_digit($c) && (int)$c >= self::REACH_MIN && (int)$c <= self::REACH_MAX) return self::err('DISA code cannot be in the 88xx reach range');
            $s['disa_code'] = $c;
        }
        foreach (['client_vpn_addr', 'client_lan_addr', 'client_pub_addr'] as $k) {
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
        $u['disa_pin_set'] = !empty($u['disa_hash']);
        unset($u['secret'], $u['disa_hash'], $u['disa_salt']);
        $u += ['public' => false, 'disa' => false];
        return $u;
    }

    /** DISA PIN rules: 6-12 digits, not one repeated digit, not a straight run. */
    public static function checkPin(string $pin): ?string
    {
        if (!preg_match('/^\d{6,12}$/', $pin)) return 'DISA PIN must be 6-12 digits';
        if (preg_match('/^(\d)\1+$/', $pin)) return 'DISA PIN is too easy (all the same digit)';
        if (strpos('01234567890123456789', $pin) !== false || strpos('98765432109876543210', $pin) !== false) {
            return 'DISA PIN is too easy (a straight run like 123456)';
        }
        return null;
    }

    /** "555, *97" or ['555', '*97'] -> ['555', '*97'] (trimmed, no blanks, no duplicates) */
    public static function codeList($v): array
    {
        $list = is_array($v) ? $v : preg_split('/[\s,]+/', (string)$v);
        $out = [];
        foreach ($list as $x) { $x = trim((string)$x); if ($x !== '') $out[$x] = true; }
        return array_map('strval', array_keys($out));
    }

    /**
     * Feature codes are dialed exactly as typed and sent to from-internal, so they must never be
     * able to look like a phone number, a trunk prefix or an emergency number.
     */
    public static function checkFeatureCode(string $c): ?string
    {
        if (!preg_match('/^[0-9*#]{2,10}$/', $c)) return "feature code '$c' may only contain digits, * and # (2-10 characters)";
        if (ctype_digit($c)) {
            if (strlen($c) > 4) return "feature code '$c' looks like a phone number (digits-only codes: 2-4 digits)";
            if (preg_match('/^([2-9]11|933|112)$/', $c)) return "$c is a service/emergency number, not a feature code";
            if (preg_match('/^[019]/', $c)) return "feature code '$c' starts with 0, 1 or 9 (trunk / operator prefixes); not allowed";
        }
        return null;
    }

    /** [code => name] -> [['code' => ..., 'name' => ...], ...] (keeps codes as strings in JSON) */
    public static function pairs(array $m): array
    {
        $o = [];
        foreach ($m as $k => $v) $o[] = ['code' => (string)$k, 'name' => (string)$v];
        return $o;
    }

    public function permString(array $u): string
    {
        $p = [];
        if (!$u['enabled']) $p[] = 'DISABLED';
        $p[] = $u['external'] ? 'external' : 'internal-only';
        if ($u['e911']) $p[] = '911';
        if ($u['international']) $p[] = 'intl';
        $p[] = !empty($u['public']) ? 'PUBLIC' : 'VPN';
        if (!empty($u['disa'])) $p[] = 'DISA';
        $p[] = 'exts=' . (implode('/', $u['allowed']) ?: 'none');
        if (!empty($u['confs'])) $p[] = 'conf=' . implode('/', $u['confs']);
        if (!empty($u['features'])) $p[] = 'codes=' . implode('/', $u['features']);
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
