<?php
/**
 * API Users – Live view parsers (pure PHP, unit-testable, no FreePBX needed).
 *
 * Turns plain Asterisk CLI output into the data the "Live" section shows:
 *   devices()   who is signed in right now   <- "database show registrar" + "pjsip show contacts"
 *   calls()     calls in progress            <- "core show channels concise" + "group show channels"
 *   failures()  failed sign-ins / DISA PINs  <- tail of /var/log/asterisk/full
 *   presence()  sign-in / sign-out history   <- diff of devices() against the last snapshot
 *   disaLocks() DISA codes locked by 5 wrong PINs <- "database show apiusers_disa"
 *
 * Where the facts come from:
 *   - The door (vpn / lan / pub) and the phone's real IP:port are in the PJSIP contact's
 *     Path header, which Kamailio writes as <sip:DOOR@gw:5071;lr;received=sip:IP:PORT>.
 *   - For calls, the dialplan tags each API user's channel with GROUP(apiunet)=door and
 *     GROUP(apiudst)=dialed number (ConfigGen: apiusers-netcheck / apiusers-pre / DISA).
 *     GROUP() disappears by itself at hangup, so there is nothing to clean up.
 *   - Failed passwords: Asterisk logs a NOTICE "Request 'REGISTER' from '<sip:user@host>' failed
 *     for '<gateway>:5071' ... - Failed to authenticate". Every phone reaches the PBX through the
 *     gateway, so the real IP isn't there, but the From host is the server the phone typed in,
 *     which tells which door it used.
 *
 * Nothing here can change anything; the hang-up action lives in Apiusers.class.php.
 */

namespace ApiUsers;

class Live
{
    /** sip_user => user (only users that exist in the module) */
    public static function bySipUser(array $users): array
    {
        $m = [];
        foreach ($users as $u) $m[(string)$u['sip_user']] = $u;
        return $m;
    }

    /** "10.0.1.1:5070" / "pbx.example.com:5080" -> host part, lower case */
    public static function hostOf(string $addr): string
    {
        $addr = trim(strtolower($addr));
        if ($addr === '') return '';
        if (preg_match('/^\[([0-9a-f:]+)\]/', $addr, $m)) return $m[1];
        return preg_replace('/:\d+$/', '', $addr);
    }

    /**
     * Signed-in phones.
     * @param string $registrar output of "database show registrar"
     * @param string $contacts  output of "pjsip show contacts" (status + round-trip time)
     */
    public static function devices(string $registrar, string $contacts, array $users, int $now): array
    {
        $map = self::bySipUser($users);

        // hash (first 10 chars of the contact id's md5) -> [status, rtt]
        $status = [];
        foreach (preg_split('/\R/', $contacts) as $line) {
            if (preg_match('/^\s*Contact:\s+(apiu-[0-9a-f]+)\/.*?\s([0-9a-f]{10})\s+(\S+)\s+(\S+)\s*$/', $line, $m)) {
                $status[$m[1] . '|' . $m[2]] = [$m[3], is_numeric($m[4]) ? round((float)$m[4], 1) : null];
            }
        }

        $out = [];
        foreach (preg_split('/\R/', $registrar) as $line) {
            if (!preg_match('#^/registrar/contact/((apiu-[0-9a-f]+);@([0-9a-f]+))\s*:\s*(\{.*\})\s*$#', trim($line), $m)) continue;
            $aor = $m[2];
            if (!isset($map[$aor])) continue;                      // deleted user / not ours
            $c = json_decode($m[4], true);
            if (!is_array($c)) continue;
            $u = $map[$aor];
            $path = (string)($c['path'] ?? '');
            $door = preg_match('/<sip:(vpn|lan|pub)@/', $path, $pm) ? $pm[1] : '';
            $ip = ''; $port = '';
            if (preg_match('/received=sip:(\[[0-9a-fA-F:]+\]|[0-9.]+):(\d+)/', $path, $rm)) {
                $ip = trim($rm[1], '[]'); $port = $rm[2];
            } elseif (preg_match('/@([0-9.]+):(\d+)/', (string)($c['uri'] ?? ''), $rm)) {
                $ip = $rm[1]; $port = $rm[2];                        // no Path (shouldn't happen)
            }
            [$st, $rtt] = $status[$aor . '|' . substr($m[3], 0, 10)] ?? ['Unknown', null];
            $exp = (int)($c['expiration_time'] ?? 0);
            $out[] = [
                'key' => $m[1],
                'user_id' => $u['id'], 'name' => $u['name'], 'public' => !empty($u['public']), 'reach' => (string)($u['reach'] ?? ''),
                'door' => $door, 'ip' => $ip, 'port' => $port,
                'app' => mb_substr((string)($c['user_agent'] ?? ''), 0, 60),
                'status' => $st, 'rtt_ms' => $rtt,
                'expires_in' => $exp > 0 ? max(0, $exp - $now) : null,
                // A VPN account that signed in through the public door can't call (netcheck blocks it),
                // but it means its password was used from the internet. Flag it.
                'warn' => ($door === 'pub' && empty($u['public'])) ? 'VPN account signed in through the public door: calls are blocked, check who has this login' : '',
            ];
        }
        usort($out, fn($a, $b) => [$a['name'], $a['key']] <=> [$b['name'], $b['key']]);
        return $out;
    }

    /** Parse "core show channels concise" into rows (Data may itself contain '!'). */
    public static function channels(string $concise): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $concise) as $line) {
            $line = trim($line);
            if ($line === '' || substr_count($line, '!') < 13) continue;
            $p = explode('!', $line);
            $n = count($p);
            $rows[] = [
                'channel' => $p[0], 'context' => $p[1], 'exten' => $p[2], 'state' => $p[4], 'app' => $p[5],
                'data' => implode('!', array_slice($p, 6, $n - 13)),
                'cid' => $p[$n - 7], 'duration' => (int)$p[$n - 3], 'bridge' => $p[$n - 2], 'uniqueid' => $p[$n - 1],
            ];
        }
        return $rows;
    }

    /** Parse "group show channels" -> channel => [category => group] */
    public static function groups(string $out): array
    {
        $g = [];
        foreach (preg_split('/\R/', $out) as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) !== 3 || $p[0] === 'Channel' || strpos($p[0], '/') === false) continue;
            $g[$p[0]][$p[2]] = $p[1];
        }
        return $g;
    }

    /** "PJSIP/701-0000001a" -> "701", "Local/8802@house-0001;2" -> "8802@house" */
    public static function shortChan(string $ch): string
    {
        $ch = preg_replace('#^[A-Za-z]+/#', '', $ch);
        return preg_replace('/-[0-9a-f]{8}(;\d)?$/', '', $ch);
    }

    /**
     * Calls in progress that involve an API user.
     * @param array $devices output of devices() (used for the door of incoming calls)
     */
    public static function calls(string $concise, string $groupsOut, array $users, array $devices = []): array
    {
        $map = self::bySipUser($users);
        $rows = self::channels($concise);
        $groups = self::groups($groupsOut);
        $doorOf = [];
        foreach ($devices as $d) $doorOf[$d['user_id']] = $doorOf[$d['user_id']] ?? $d['door'];

        $out = [];
        foreach ($rows as $r) {
            if (!preg_match('#^PJSIP/(apiu-[0-9a-f]+)-[0-9a-f]+$#', $r['channel'], $m) || !isset($map[$m[1]])) continue;
            $u = $map[$m[1]];
            $g = $groups[$r['channel']] ?? [];
            $peers = [];
            if ($r['bridge'] !== '') {
                foreach ($rows as $o) if ($o['bridge'] === $r['bridge'] && $o['channel'] !== $r['channel']) $peers[] = $o;
            }
            $incoming = ($r['app'] === 'AppDial');
            if ($incoming) {
                // The house phone (or Local channel) running Dial(PJSIP/apiu-xxx...) is the caller.
                $caller = '';
                foreach ($rows as $o) {
                    if ($o['app'] === 'Dial' && strpos($o['data'], 'PJSIP/' . $m[1]) !== false) { $caller = $o['cid'] ?: self::shortChan($o['channel']); break; }
                }
                if ($caller === '' && $peers) $caller = $peers[0]['cid'] ?: self::shortChan($peers[0]['channel']);
                $other = $caller;
                $door = $doorOf[$u['id']] ?? '';
            } else {
                $dst = $g['apiudst'] ?? '';
                if ($dst === '' && $r['exten'] !== '' && $r['exten'] !== 's') $dst = $r['exten'];
                $other = $dst;
                $door = $g['apiunet'] ?? ($doorOf[$u['id']] ?? '');   // no tag -> door of their signed-in phone
            }
            if ($r['context'] === 'apiusers-deny') {
                $what = 'Blocked (not allowed)';
            } elseif ($r['state'] === 'Up' && $r['bridge'] !== '') {
                $what = 'Talking';
            } elseif ($r['state'] === 'Ringing') {
                $what = 'Ringing their phone';
            } elseif ($r['state'] === 'Ring' || $r['app'] === 'Dial') {
                $what = 'Dialing';
            } elseif ($r['context'] === 'apiusers-disa') {
                $what = 'DISA: entering PIN / number';
            } elseif ($r['state'] === 'Up') {
                $what = 'Answered by the PBX' . ($r['app'] !== '' ? ' (' . $r['app'] . ')' : '');   // voicemail, IVR, DISA prompt...
            } else {
                $what = $r['state'] . ($r['app'] !== '' ? ' (' . $r['app'] . ')' : '');
            }
            $out[] = [
                'channel' => $r['channel'], 'uniqueid' => $r['uniqueid'],
                'user_id' => $u['id'], 'name' => $u['name'], 'public' => !empty($u['public']), 'reach' => (string)($u['reach'] ?? ''),
                'direction' => $incoming ? 'in' : 'out',
                'other' => (string)$other,
                'via' => $peers ? self::shortChan($peers[0]['channel']) : '',
                'door' => $door, 'state' => $what, 'seconds' => $r['duration'],
            ];
        }
        usort($out, fn($a, $b) => $b['seconds'] <=> $a['seconds']);
        return $out;
    }

    /**
     * Failed sign-ins (wrong password / unknown username, only those relayed by OUR gateway)
     * and wrong DISA PINs, newest first.
     * @param array $doorHosts ['vpn'=>host, 'lan'=>host, 'pub'=>host] from the module settings
     */
    public static function failures(string $log, array $users, string $gatewayIp, array $doorHosts, int $limit = 50): array
    {
        $map = self::bySipUser($users);
        $byId = [];
        foreach ($users as $u) $byId[$u['id']] = $u;
        $hostDoor = [];
        foreach ($doorHosts as $door => $h) { $h = self::hostOf((string)$h); if ($h !== '') $hostDoor[$h] = $door; }

        $seen = []; $out = [];
        foreach (preg_split('/\R/', $log) as $line) {
            if (strpos($line, 'failed for') !== false &&
                preg_match("/^\[([^\]]+)\]\s+NOTICE\[\d+\](?:\[[^\]]*\])?\s+\S+:\s+Request '(\w+)' from '([^']*)' failed for '([^']+)' \(callid: ([^)]*)\) - (.+)$/", trim($line), $m)) {
                [, $ts, $method, $from, $src, $callid, $reason] = $m;
                if ($gatewayIp !== '' && strpos($src, $gatewayIp . ':') !== 0) continue;   // not through our gateway
                if (!preg_match('/sip:([^@>;]+)@([^:;>]+)/', $from, $fm)) continue;
                $user = $fm[1]; $host = strtolower($fm[2]);
                $k = $callid . '|' . $user;
                if (isset($seen[$k])) continue;                                            // retransmits / 2nd log line
                $seen[$k] = true;
                $u = $map[$user] ?? null;
                if (!$u && strpos($user, 'apiu-') !== 0 && stripos($reason, 'No matching endpoint') === false) continue; // a real extension, not ours
                $out[] = [
                    'id' => substr(md5($ts . '|' . $k), 0, 12),
                    't' => self::logTime($ts), 'time' => $ts, 'kind' => $u ? 'wrong password' : 'unknown username',
                    'user_id' => $u['id'] ?? null, 'name' => $u['name'] ?? null,
                    'username' => mb_substr($user, 0, 40), 'door' => $hostDoor[$host] ?? '', 'server' => mb_substr($host, 0, 60),
                    'method' => $method,
                ];
            } elseif (strpos($line, 'DISA wrong PIN for') !== false &&
                preg_match('/^\[([^\]]+)\].*DISA wrong PIN for (u[0-9a-f]+): (\d+) in a row/', trim($line), $m)) {
                $k = 'disa|' . $m[1] . '|' . $m[2] . '|' . $m[3];
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $u = $byId[$m[2]] ?? null;
                $out[] = [
                    'id' => substr(md5($k), 0, 12),
                    't' => self::logTime($m[1]), 'time' => $m[1], 'kind' => 'wrong DISA PIN (' . (int)$m[3] . ' in a row)',
                    'user_id' => $m[2], 'name' => $u['name'] ?? $m[2], 'username' => $u['sip_user'] ?? '',
                    'door' => '', 'server' => '', 'method' => 'DISA',
                ];
            }
        }
        usort($out, fn($a, $b) => $b['t'] <=> $a['t']);
        return array_slice($out, 0, $limit);
    }

    /** "[2026-10-01 09:20:01]" (FreePBX) or "[Oct  1 16:38:04]" (Asterisk default) -> epoch, local time */
    public static function logTime(string $ts): int
    {
        $ts = preg_replace('/\.\d+$/', '', trim($ts));
        $t = strtotime($ts);
        return $t === false ? 0 : $t;
    }

    /** DISA codes currently locked: user_id => unlock epoch */
    public static function disaLocks(string $dbShow, int $now): array
    {
        $out = [];
        foreach (preg_split('/\R/', $dbShow) as $line) {
            if (preg_match('#^/apiusers_disa/(u[0-9a-f]+)_lock\s*:\s*(\d+)#', trim($line), $m) && (int)$m[2] > $now) {
                $out[$m[1]] = (int)$m[2];
            }
        }
        return $out;
    }

    /**
     * Compare the devices signed in now with the previous snapshot.
     * $prev = ['init'=>bool, 'devices'=>[key => [since,user_id,name,door,ip,app]]]
     * Returns [$next, $events]. Events: in | out | moved | seen (already on when tracking started).
     */
    public static function presence(array $prev, array $devices, int $now): array
    {
        $old = is_array($prev['devices'] ?? null) ? $prev['devices'] : [];
        $init = !empty($prev['init']);
        $next = ['init' => true, 'devices' => []];
        $ins = []; $outs = []; $events = [];
        foreach ($devices as $d) {
            $k = $d['key'];
            $row = ['since' => $now, 'user_id' => $d['user_id'], 'name' => $d['name'], 'door' => $d['door'],
                    'ip' => $d['ip'], 'app' => $d['app']];
            $ev = ['t' => $now, 'user_id' => $d['user_id'], 'name' => $d['name'], 'door' => $d['door'],
                   'ip' => $d['ip'], 'app' => $d['app']];
            if (!empty($d['warn'])) $ev['flag'] = 'pub-door';      // VPN account came in through the public door
            if (isset($old[$k])) {
                $row['since'] = (int)$old[$k]['since'];
                if ($old[$k]['door'] !== $d['door'] || $old[$k]['ip'] !== $d['ip']) {
                    $events[] = $ev + ['ev' => 'moved', 'from' => trim($old[$k]['door'] . ' ' . $old[$k]['ip'])];
                    $row['since'] = $now;
                }
            } else {
                $ins[$k] = $ev + ['ev' => $init ? 'in' : 'seen'];
            }
            $next['devices'][$k] = $row;
        }
        foreach ($old as $k => $o) {
            if (!isset($next['devices'][$k])) {
                $outs[$k] = ['t' => $now, 'ev' => 'out', 'user_id' => $o['user_id'], 'name' => $o['name'],
                             'door' => $o['door'], 'ip' => $o['ip'], 'app' => $o['app'],
                             'dur' => max(0, $now - (int)$o['since']), 'since' => (int)$o['since']];
            }
        }
        // A phone that re-registered with a new contact (new NAT port, app restart) shows up as one
        // device gone + one new device for the same user in the same tick: that's one session, not two events.
        foreach ($ins as $ki => $in) {
            foreach ($outs as $ko => $out) {
                if ($out['user_id'] !== $in['user_id']) continue;
                if ($out['door'] !== $in['door'] || $out['ip'] !== $in['ip']) {
                    $in = ['ev' => 'moved', 'from' => trim($out['door'] . ' ' . $out['ip'])] + $in;
                    $events[] = $in;
                } else {
                    $next['devices'][$ki]['since'] = $out['since'];   // same place: keep the session going
                }
                unset($ins[$ki], $outs[$ko]);
                continue 2;
            }
        }
        foreach ($ins as $in) $events[] = $in;
        foreach ($outs as $out) { unset($out['since']); $events[] = $out; }
        foreach ($events as &$e) $e['id'] = bin2hex(random_bytes(6));
        unset($e);
        return [$next, $events];
    }
}
