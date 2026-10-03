<?php
/**
 * Public IP watch + placeholder addresses:   php tests/pubip_test.php   (must print ALL PASSED)
 */
require __DIR__ . '/../pbx-module/apiusers/lib/Engine.php';
require __DIR__ . '/../pbx-module/apiusers/lib/Alerts.php';
require __DIR__ . '/../pbx-module/apiusers/lib/PublicIp.php';
use ApiUsers\Engine;
use ApiUsers\Alerts;
use ApiUsers\PublicIp;

$fails = 0;
function ok($cond, $name) { global $fails; if ($cond) { echo "ok   $name\n"; } else { echo "FAIL $name\n"; $fails++; } }
$T = 1800000000;
$A = '93.184.216.34'; $B = '93.184.216.35';

// ---- helpers
ok(PublicIp::split('phone.example.com:5080') === ['phone.example.com', 5080], 'split host:port');
ok(PublicIp::split('Phone.Example.com') === ['phone.example.com', 5080], 'split without port -> 5080');
ok(PublicIp::isPublic($A) && !PublicIp::isPublic('192.168.8.1') && !PublicIp::isPublic('10.0.1.1'), 'private IPs are not public');
ok(!PublicIp::isPublic('100.72.1.2') && !PublicIp::isPublic('') && !PublicIp::isPublic(null), 'CGNAT / empty are not public');

// ---- fixed IP in Settings
[$m, $al] = PublicIp::check([], $A, "$A:5080", [], $T);
ok($m['ip'] === $A && $al === [], 'first look: remember the IP, no alert');
ok(PublicIp::problem($m, "$A:5080", $T) === null, 'fixed IP that matches: no problem');
ok(!PublicIp::due($m, $T + 60) && PublicIp::due($m, $T + 300), 'looked up every 5 minutes');
[$m2, $al] = PublicIp::check($m, null, "$A:5080", [], $T + 300);
ok($al === [] && $m2['ip'] === $A && $m2['fails'] === 1, 'failed lookup: nothing changes, no alert');
[$m, $al] = PublicIp::check($m2, $B, "$A:5080", [], $T + 600);
ok(count($al) === 1 && $al[0][1] === 1 && strpos($al[0][2], "Use $B:5080") !== false, 'IP changed while Settings has the old fixed IP: red alert with the fix');
ok($m['prev'] === $A && $m['ip'] === $B, 'remembers old and new IP');
$p = PublicIp::problem($m, "$A:5080", $T + 600);
ok($p && $p['fix'] === "$B:5080", 'page banner offers "Use new IP"');
[$m, $al] = PublicIp::check($m, $B, "$A:5080", [], $T + 900);
ok($al === [], 'the same change is not reported again');
ok(PublicIp::problem($m, "$B:5080", $T + 900) === null, 'after the fix: no banner');

// ---- a name (Cloudflare) in Settings
$n = 'phone.example.com:5080';
[$m, $al] = PublicIp::check([], $A, $n, [$A], $T);
[$m, $al] = PublicIp::check($m, $B, $n, [$A], $T + 300);
ok(count($al) === 1 && $al[0][1] === 2 && strpos($al[0][2], 'Cloudflare updater') !== false, 'IP changed with a name: orange notice');
[$m, $al] = PublicIp::check($m, $B, $n, [$A], $T + 600);
ok($al === [] && PublicIp::problem($m, $n, $T + 600) !== null, 'name still old after 5 min: banner, no alert yet');
[$m, $al] = PublicIp::check($m, $B, $n, [$A], $T + 300 + 900);
ok(count($al) === 1 && $al[0][1] === 1 && strpos($al[0][2], 'docker compose logs ddns') !== false, 'name still old after 15 min: red alert');
[$m, $al] = PublicIp::check($m, $B, $n, [$A], $T + 300 + 1200);
ok($al === [], '... at most once an hour');
[$m, $al] = PublicIp::check($m, $B, $n, [$B], $T + 300 + 1500);
ok($al === [] && $m['dns_bad_since'] === 0 && PublicIp::problem($m, $n, $T + 1800) === null, 'name caught up: all clear');
[$m, $al] = PublicIp::check([], $A, '', [], $T);
[$m, $al] = PublicIp::check($m, $B, '', [], $T + 300);
ok(count($al) === 1 && $al[0][1] === 2, 'no address in Settings: just a notice');

// ---- into the alert e-mail
$mem = Alerts::collect(['users' => []], [], $T);
$mem = Alerts::collect(['users' => [], 'extra' => [['pubip', 1, 'Your public IP changed: x -> y']]], $mem, $T + 60);
ok(count($mem['pending']) === 1 && Alerts::ready($mem, $T + 60), 'public IP alert is queued and red goes out right away');
[$subj, $body] = Alerts::compose($mem['pending']);
ok(strpos($subj, 'PBX ALERT') !== false && strpos($subj, 'public IP change') !== false && strpos($body, 'YOUR PUBLIC IP ADDRESS') !== false, 'e-mail subject/body');

// ---- example text in the address fields
$e = new Engine(['settings' => ['client_vpn_addr' => '10.x.x.x:5070']]);
ok($e->settings()['client_vpn_addr'] === '', 'saved "10.x.x.x:5070" is dropped (never on a share card)');
$r = $e->run('settings', ['client_vpn_addr' => '10.x.x.x:5070'], 'local', 't');
ok(!$r['ok'] && strpos($r['error'], 'example text') !== false, 'typing the example text is refused with a clear message');
$r = $e->run('settings', ['client_vpn_addr' => '10.0.1.1:5070', 'client_pub_addr' => 'phone.example.com:5080'], 'local', 't');
ok($r['ok'] && $e->settings()['client_pub_addr'] === 'phone.example.com:5080', 'real addresses are accepted');
$r = $e->run('settings', ['client_pub_addr' => 'x.com:5080'], 'local', 't');
ok($r['ok'], 'a real name starting with x is fine');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
