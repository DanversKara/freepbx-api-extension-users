<?php
/**
 * API Users – keeping an eye on the home's public IP (pure PHP, unit-testable).
 *
 * The public door only works while phones are told the right address. Home internet IPs change
 * now and then, so the once-a-minute cron job (Apiusers::cronTick) asks "what is my IP?" every
 * 5 minutes and compares it with Settings > "Zoiper server without VPN":
 *
 *   - the IP changed                                   -> notice e-mail (orange)
 *   - Settings has a fixed IP and it is now wrong       -> alert (red) + a "Use the new IP" button on the page
 *   - Settings has a name (phone.example.com) that
 *     still points at the old IP after 15 minutes      -> alert (red): the Cloudflare updater isn't working
 *
 * Memory lives in the kv row `pubip`.
 */

namespace ApiUsers;

class PublicIp
{
    public const EVERY = 300;        // seconds between lookups
    public const DNS_GRACE = 900;    // a name may lag behind the real IP this long before we complain
    public const DNS_REPEAT = 3600;  // ... and then at most once an hour

    /** "phone.example.com:5080" -> ["phone.example.com", 5080]; no port -> 5080. */
    public static function split(string $addr): array
    {
        $addr = trim($addr);
        if (preg_match('/^(.*):(\d{1,5})$/', $addr, $m)) return [strtolower($m[1]), (int)$m[2]];
        return [strtolower($addr), 5080];
    }

    public static function isIp(string $h): bool
    {
        return (bool)filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }

    /** A real internet address (not 192.168.x, 10.x, 100.64.x CGNAT, ...). */
    public static function isPublic(?string $ip): bool
    {
        if ($ip === null || !self::isIp($ip)) return false;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        return !preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip);   // carrier-grade NAT
    }

    public static function due(array $mem, int $now): bool
    {
        return $now - (int)($mem['checked'] ?? 0) >= self::EVERY;
    }

    /**
     * One check. $detected = what the internet says our IP is (null = lookup failed),
     * $pubAddr = Settings > Zoiper server without VPN, $resolved = IPs that name points at now ([] for a fixed IP).
     * Returns [$mem, $alerts] with $alerts = list of [kind, severity 1 red / 2 orange, text].
     */
    public static function check(array $mem, ?string $detected, string $pubAddr, array $resolved, int $now): array
    {
        $alerts = [];
        $mem['checked'] = $now;
        if (!self::isPublic($detected)) {
            $mem['fails'] = (int)($mem['fails'] ?? 0) + 1;
            return [$mem, $alerts];
        }
        $mem['fails'] = 0;
        [$host, $port] = self::split($pubAddr);
        $named = $pubAddr !== '' && !self::isIp($host);
        $old = (string)($mem['ip'] ?? '');

        if ($old === '') {
            $mem['ip'] = $detected; $mem['since'] = $now;
        } elseif ($old !== $detected) {
            $mem['prev'] = $old; $mem['ip'] = $detected; $mem['since'] = $now;
            $text = "Your home's public internet address changed: $old -> $detected.";
            if ($pubAddr !== '' && !$named && $host !== $detected) {
                $alerts[] = ['pubip', 1, $text . " Settings still tells phones to use $host:$port, so Public (no-VPN) phones can't"
                    . " connect. On the PBX page: Settings > \"Use $detected:$port\", then send each Public person their new card."
                    . ' Tip: a name like phone.example.com with the Cloudflare updater makes this automatic.'];
            } elseif ($named) {
                $alerts[] = ['pubip', 2, $text . " Phones use the name $host, which the Cloudflare updater should move to the new"
                    . ' address within a few minutes. The gateway follows by itself if GW_PUBLIC_IP is "auto" or that name.'];
            } else {
                $alerts[] = ['pubip', 2, $text];
            }
        }

        // A name that keeps pointing somewhere else = the updater isn't doing its job.
        if ($named && !in_array($detected, $resolved, true)) {
            $mem['dns_bad_since'] = (int)($mem['dns_bad_since'] ?? 0) ?: $now;
            if ($now - $mem['dns_bad_since'] >= self::DNS_GRACE && $now - (int)($mem['dns_sent'] ?? 0) >= self::DNS_REPEAT) {
                $mem['dns_sent'] = $now;
                $alerts[] = ['pubip', 1, "$host points to " . ($resolved ? implode(', ', $resolved) : 'nothing')
                    . " but your public IP is $detected, so Public (no-VPN) phones can't connect. Check the Cloudflare updater"
                    . ' on the gateway: docker compose logs ddns'];
            }
        } else {
            $mem['dns_bad_since'] = 0;
        }
        return [$mem, $alerts];
    }

    /**
     * Problem to show at the top of the PBX page, or null.
     * ['text' => ..., 'fix' => 'ip:port' (only when a button can fix it)]
     */
    public static function problem(array $mem, string $pubAddr, int $now): ?array
    {
        $ip = (string)($mem['ip'] ?? '');
        if ($ip === '' || $pubAddr === '') return null;
        [$host, $port] = self::split($pubAddr);
        if (self::isIp($host)) {
            if ($host === $ip) return null;
            return ['text' => "Your public IP is now $ip, but phones are told to use $host:$port for the no-VPN door.", 'fix' => "$ip:$port"];
        }
        $bad = (int)($mem['dns_bad_since'] ?? 0);
        if ($bad && $now - $bad >= 300) {
            return ['text' => "$host does not point to your public IP ($ip) yet. If this lasts, check the Cloudflare updater (docker compose logs ddns).", 'fix' => ''];
        }
        return null;
    }
}
