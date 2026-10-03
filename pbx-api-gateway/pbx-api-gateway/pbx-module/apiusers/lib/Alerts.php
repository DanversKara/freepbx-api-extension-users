<?php
/**
 * API Users – what is worth an alert e-mail (pure PHP, unit-testable).
 *
 * Called once a minute from cron (Apiusers::cronTick) with a snapshot of what's happening.
 * Keeps a small memory (kv row `alerts`) so each thing is reported once:
 *
 *   failed     every new failed sign-in / unknown username / wrong DISA PIN        (red)
 *   twice      one account signed in from 2+ different IP addresses for >= 2 min   (red)
 *   pubdoor    a VPN account signed in through the public door                     (orange)
 *   disalock   a Public account's dial-out code got locked by 5 wrong PINs         (red)
 *   pubip      the home's public IP changed / the public door address is now wrong     (orange / red)
 *   audit      denied remote attempts, kill switch on/off, history deleted,
 *              DISA unlocked, a call hung up from a page                           (red / orange / info)
 *
 * Items wait in `pending` and go out as ONE digest e-mail, at most every 5 minutes
 * (1 minute if something red is waiting), so an attack can't flood the inbox.
 */

namespace ApiUsers;

class Alerts
{
    public const TWICE_AFTER = 120;      // seconds two IPs must overlap before it counts
    public const REPEAT_EVERY = 3600;    // same "twice" / "pubdoor" alert again after an hour at most
    public const GAP = 300;              // min seconds between e-mails
    public const GAP_RED = 60;           // ... when something red is waiting
    public const KEEP_PENDING = 200;

    /**
     * $in = ['devices' => Live::devices(), 'failed' => newest-first list, 'locks' => [user_id => until],
     *        'audit' => state audit list (oldest first), 'users' => state users]
     * Returns the updated memory; new items are appended to $mem['pending'].
     */
    public static function collect(array $in, array $mem, int $now): array
    {
        $first = !isset($mem['since']);
        $mem += ['since' => $now, 'failed_seen' => [], 'twice' => [], 'sent' => [], 'locks_seen' => [],
                 'audit_since' => '', 'pending' => [], 'last_mail' => 0];
        $users = $in['users'] ?? [];
        $add = function (string $kind, int $sev, string $text) use (&$mem, $now) {
            $mem['pending'][] = ['t' => $now, 'kind' => $kind, 'sev' => $sev, 'text' => $text];
        };

        // ---- failed sign-ins (only ones newer than when alerts were switched on)
        $seen = array_flip($mem['failed_seen']);
        foreach (array_reverse($in['failed'] ?? []) as $f) {
            if (isset($seen[$f['id']])) continue;
            $mem['failed_seen'][] = $f['id'];
            if ($first || (int)$f['t'] < (int)$mem['since']) continue;
            $who = $f['name'] ? $f['name'] . ' (' . $f['username'] . ')' : 'unknown username "' . $f['username'] . '"';
            $where = $f['door'] ? self::door($f['door']) : ($f['server'] ? 'server typed: ' . $f['server'] : '');
            $add('failed', $f['kind'] === 'unknown username' ? 2 : 1,
                date('H:i', (int)$f['t']) . '  ' . ucfirst($f['kind']) . ' – ' . $who . ($where ? ' – ' . $where : ''));
        }
        $mem['failed_seen'] = array_slice($mem['failed_seen'], -2000);

        // ---- same account in two places / VPN account on the public door
        $ips = []; $names = [];
        foreach ($in['devices'] ?? [] as $d) {
            $ips[$d['user_id']][$d['ip']] = true;
            $names[$d['user_id']] = $d['name'];
            if (!empty($d['warn']) && self::due($mem, 'pubdoor:' . $d['user_id'], $now)) {
                $add('pubdoor', 2, $d['name'] . ' (a VPN account) signed in through the PUBLIC door from ' . $d['ip']
                    . '. Its calls are blocked that way, but its password was used from the internet.');
            }
        }
        foreach ($ips as $uid => $set) {
            if (count($set) < 2) { unset($mem['twice'][$uid]); continue; }
            $mem['twice'][$uid] = $mem['twice'][$uid] ?? $now;
            if ($now - $mem['twice'][$uid] >= self::TWICE_AFTER && self::due($mem, 'twice:' . $uid, $now)) {
                $add('twice', 1, $names[$uid] . ' is signed in from ' . count($set) . ' places at once: ' . implode(', ', array_keys($set))
                    . '. If that isn\'t them on two phones, give them a New password.');
            }
        }
        foreach (array_keys($mem['twice']) as $uid) if (!isset($ips[$uid])) unset($mem['twice'][$uid]);

        // ---- DISA lockouts
        foreach ($in['locks'] ?? [] as $uid => $until) {
            $k = $uid . ':' . $until;
            if (in_array($k, $mem['locks_seen'], true)) continue;
            $mem['locks_seen'][] = $k;
            $add('disalock', 1, ($users[$uid]['name'] ?? $uid) . "'s dial-out code was LOCKED after 5 wrong PINs (until " . date('H:i', (int)$until) . ').');
        }
        $mem['locks_seen'] = array_slice($mem['locks_seen'], -200);

        // ---- public IP (lib/PublicIp.php decides; we just queue)
        foreach ($in['extra'] ?? [] as $x) $add((string)$x[0], (int)$x[1], (string)$x[2]);

        // ---- audit log
        $last = (string)$mem['audit_since'];
        foreach ($in['audit'] ?? [] as $a) {
            $t = (string)($a['t'] ?? '');
            if ($t <= $last) continue;
            $mem['audit_since'] = max((string)$mem['audit_since'], $t);
            if ($first) continue;
            $who = trim(($a['src'] ?? '') . ' ' . ($a['actor'] ?? ''));
            $user = isset($a['id'], $users[$a['id']]) ? ' (' . $users[$a['id']]['name'] . ')' : '';
            $op = (string)($a['op'] ?? ''); $d = (string)($a['detail'] ?? '');
            if (strpos($d, 'DENIED') === 0)      $add('audit', 1, "DENIED $op by $who$user: " . substr($d, 8));
            elseif ($op === 'kill')              $add('audit', 1, "KILL SWITCH engaged by $who");
            elseif ($op === 'unkill')            $add('audit', 2, "Kill switch released by $who");
            elseif ($op === 'history_clear')     $add('audit', 2, ucfirst($d) . " by $who");
            elseif ($op === 'disa_unlock')       $add('audit', 2, "DISA code unlocked by $who$user");
            elseif ($op === 'hangup')            $add('audit', 3, "Call hung up by $who$user");
            elseif ($op === 'rotate' && ($a['src'] ?? '') !== 'local') $add('audit', 3, "New SIP password issued by $who$user");
        }
        if ($first && $mem['audit_since'] === '') $mem['audit_since'] = gmdate('c', $now);

        $mem['pending'] = array_slice($mem['pending'], -self::KEEP_PENDING);
        foreach ($mem['sent'] as $k => $t) if ($now - (int)$t > 86400) unset($mem['sent'][$k]);
        return $mem;
    }

    /** Is it time to send the pending items? */
    public static function ready(array $mem, int $now): bool
    {
        if (empty($mem['pending'])) return false;
        $red = (bool)array_filter($mem['pending'], fn($p) => $p['sev'] === 1);
        return $now - (int)($mem['last_mail'] ?? 0) >= ($red ? self::GAP_RED : self::GAP);
    }

    /** One digest e-mail: [subject, body]. */
    public static function compose(array $pending, string $site = 'home PBX'): array
    {
        $by = [];
        foreach ($pending as $p) $by[$p['kind']][] = $p;
        $parts = [];
        $label = ['failed' => 'failed sign-in', 'twice' => 'signed in from 2 places', 'pubdoor' => 'VPN account on the public door',
                  'disalock' => 'dial-out code locked', 'pubip' => 'public IP change', 'audit' => 'admin event'];
        foreach (['pubip', 'twice', 'disalock', 'failed', 'pubdoor', 'audit'] as $k) {
            if (empty($by[$k])) continue;
            $n = count($by[$k]);
            $parts[] = $n . ' ' . $label[$k] . ($n > 1 && in_array($k, ['failed', 'audit', 'pubip'], true) ? 's' : '');
        }
        $red = (bool)array_filter($pending, fn($p) => $p['sev'] === 1);
        $subject = '[' . ($red ? 'PBX ALERT' : 'PBX notice') . '] ' . implode(', ', $parts);
        $titles = ['pubip' => 'YOUR PUBLIC IP ADDRESS', 'twice' => 'SAME ACCOUNT IN TWO PLACES', 'disalock' => 'DIAL-OUT CODE LOCKED', 'failed' => 'FAILED SIGN-INS',
                   'pubdoor' => 'VPN ACCOUNT ON THE PUBLIC DOOR', 'audit' => 'ADMIN EVENTS'];
        $body = "API Users alert from your $site.\n";
        foreach ($titles as $k => $title) {
            if (empty($by[$k])) continue;
            $body .= "\n== $title ==\n";
            foreach ($by[$k] as $p) $body .= '- ' . $p['text'] . "\n";
        }
        $body .= "\nTimes are the PBX's local time. Open API Users on the PBX page (Applications > API Users) or the remote panel"
               . " for details. To stop these e-mails, clear the alert address in Settings.\n";
        return [$subject, $body];
    }

    private static function due(array &$mem, string $key, int $now): bool
    {
        if ($now - (int)($mem['sent'][$key] ?? 0) < self::REPEAT_EVERY) return false;
        $mem['sent'][$key] = $now;
        return true;
    }

    private static function door(string $d): string
    {
        return ['vpn' => 'via VPN', 'lan' => 'via home Wi-Fi', 'pub' => 'via the PUBLIC door'][$d] ?? $d;
    }
}
