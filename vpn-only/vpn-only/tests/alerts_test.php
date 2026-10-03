<?php
/**
 * Alert rules + e-mail digest:   php tests/alerts_test.php   (must print ALL PASSED)
 */
require __DIR__ . '/../pbx-module/apiusers/lib/Alerts.php';
require __DIR__ . '/../pbx-module/apiusers/lib/Mailer.php';
use ApiUsers\Alerts;
use ApiUsers\Mailer;

$fails = 0;
function ok($cond, $name) { global $fails; if ($cond) { echo "ok   $name\n"; } else { echo "FAIL $name\n"; $fails++; } }
$users = ['u1' => ['id' => 'u1', 'name' => 'Bro', 'sip_user' => 'apiu-01'], 'u2' => ['id' => 'u2', 'name' => 'Mom', 'sip_user' => 'apiu-02']];
$dev = fn($uid, $name, $ip, $warn = '') => ['user_id' => $uid, 'name' => $name, 'ip' => $ip, 'door' => 'vpn', 'warn' => $warn];
$fail = fn($id, $t, $kind = 'wrong password', $name = 'Bro') => ['id' => $id, 't' => $t, 'kind' => $kind, 'name' => $name, 'username' => 'apiu-01', 'door' => 'pub', 'server' => 'x'];
$T = 1800000000;

// first run: remember what's there, alert nothing old
$m = Alerts::collect(['failed' => [$fail('old1', $T - 50)], 'audit' => [['t' => '2027-01-01T00:00:00+00:00', 'op' => 'kill', 'src' => 'local', 'actor' => 'x']], 'users' => $users], [], $T);
ok($m['pending'] === [], 'first run: existing failures and audit entries are not e-mailed');

// new failure -> pending, red, ready after 1 minute gap
$m = Alerts::collect(['failed' => [$fail('f1', $T + 30), $fail('old1', $T - 50)], 'users' => $users], $m, $T + 60);
ok(count($m['pending']) === 1 && $m['pending'][0]['sev'] === 1 && strpos($m['pending'][0]['text'], 'Bro') !== false, 'new wrong password -> one red alert');
ok(Alerts::ready($m, $T + 60), 'red alert is ready right away');
$m2 = Alerts::collect(['failed' => [$fail('f1', $T + 30)], 'users' => $users], $m, $T + 120);
ok(count($m2['pending']) === 1, 'the same failure is never reported twice');
$m['pending'] = []; $m['last_mail'] = $T + 60;
$m = Alerts::collect(['failed' => [$fail('f2', $T + 70, 'unknown username', null)], 'users' => $users], $m, $T + 90);
ok(!Alerts::ready($m, $T + 100), 'orange-only alert waits for the 5 minute gap');
ok(Alerts::ready($m, $T + 60 + 300), '... and goes out after it');

// two places at once: needs 2 minutes of overlap, then once an hour
$m = ['since' => $T];
$both = ['devices' => [$dev('u1', 'Bro', '1.1.1.1'), $dev('u1', 'Bro', '2.2.2.2')], 'users' => $users];
$m = Alerts::collect($both, $m, $T + 10);
ok(empty($m['pending']), 'two IPs for a few seconds (NAT re-register): no alert yet');
$m = Alerts::collect($both, $m, $T + 140);
ok(count($m['pending']) === 1 && $m['pending'][0]['kind'] === 'twice', 'two IPs for 2+ minutes: alert');
$m = Alerts::collect($both, $m, $T + 600);
ok(count($m['pending']) === 1, '... not repeated within the hour');
$m = Alerts::collect(['devices' => [$dev('u1', 'Bro', '1.1.1.1'), $dev('u1', 'Bro', '1.1.1.1')], 'users' => $users], $m, $T + 700);
ok(!isset($m['twice']['u1']), 'two contacts from the SAME IP is not "two places"');

// VPN account on the public door; DISA lock; audit events
$m = ['since' => $T, 'audit_since' => '2026-01-01T00:00:00+00:00'];
$m = Alerts::collect(['devices' => [$dev('u2', 'Mom', '9.9.9.9', 'VPN account signed in through the public door')], 'locks' => ['u1' => $T + 3600],
    'audit' => [['t' => '2026-06-01T00:00:00+00:00', 'op' => 'update', 'src' => 'remote', 'actor' => 'panel:ash', 'id' => 'u1', 'detail' => "DENIED: safety lock: 'external' can only be enabled from the PBX page"],
                ['t' => '2026-06-01T00:00:01+00:00', 'op' => 'history_clear', 'src' => 'remote', 'actor' => 'panel:ash', 'id' => '', 'detail' => 'cleared ALL 9 failed sign-in entries'],
                ['t' => '2026-06-01T00:00:02+00:00', 'op' => 'create', 'src' => 'local', 'actor' => 'pbx:admin', 'id' => 'u1', 'detail' => 'x']], 'users' => $users], $m, $T + 5);
$kinds = array_count_values(array_map(fn($p) => $p['kind'], $m['pending']));
ok(($kinds['pubdoor'] ?? 0) === 1 && ($kinds['disalock'] ?? 0) === 1 && ($kinds['audit'] ?? 0) === 2, 'public door, DISA lock, denied remote attempt and history wipe are reported; a normal create is not');
$m = Alerts::collect(['locks' => ['u1' => $T + 3600], 'users' => $users], $m, $T + 65);
ok(($kinds['disalock'] ?? 0) === count(array_filter($m['pending'], fn($p) => $p['kind'] === 'disalock')), 'the same lock is reported once');

[$subj, $body] = Alerts::compose($m['pending']);
ok(strpos($subj, '[PBX ALERT]') === 0 && strpos($subj, 'dial-out code locked') !== false, 'digest subject: ' . $subj);
ok(strpos($body, '== DIAL-OUT CODE LOCKED ==') !== false && strpos($body, 'DENIED update by remote panel:ash (Bro)') !== false, 'digest body lists each item');
ok(Mailer::clean("a\r\nBcc: x") === 'a  Bcc: x', 'mail header injection stripped');
ok(Mailer::encodeHeader('Hi') === 'Hi' && strpos(Mailer::encodeHeader('⚠ x'), '=?UTF-8?B?') === 0, 'UTF-8 subjects encoded');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
