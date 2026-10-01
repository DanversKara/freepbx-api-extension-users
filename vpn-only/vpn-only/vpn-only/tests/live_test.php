<?php
/**
 * Live view tests:   php tests/live_test.php   (must print ALL PASSED)
 * Fixtures in tests/fixtures/live/ are real Asterisk 20 output captured in the sandbox
 * (two API phones through Kamailio, one inbound call from a house phone, one outbound call).
 */
require __DIR__ . '/../pbx-module/apiusers/lib/Engine.php';
require __DIR__ . '/../pbx-module/apiusers/lib/Live.php';

use ApiUsers\Engine;
use ApiUsers\Live;

$fails = 0;
function ok($cond, $name) { global $fails; if ($cond) { echo "ok   $name\n"; } else { echo "FAIL $name\n"; $fails++; } }
$fx = fn($n) => file_get_contents(__DIR__ . "/fixtures/live/$n.txt");

$users = [
    'ue83fbe' => ['id' => 'ue83fbe', 'name' => 'Pub', 'sip_user' => 'apiu-e72fd50408', 'public' => true, 'reach' => 8801],
    'u8375de' => ['id' => 'u8375de', 'name' => 'NoPub', 'sip_user' => 'apiu-025dfd2d93', 'public' => false, 'reach' => 8802],
];

// ---- devices ------------------------------------------------------------------
$dev = Live::devices($fx('registrar'), $fx('contacts'), $users, 1790873300);
ok(count($dev) === 3, 'three API contacts (real extension 701 ignored)');
$np = array_values(array_filter($dev, fn($d) => $d['user_id'] === 'u8375de'))[0];
ok($np['door'] === 'lan' && $np['ip'] === '127.0.0.1' && $np['port'] === '6001', 'door + real address from Path received=');
ok($np['status'] === 'Avail' && abs($np['rtt_ms'] - 460.7) < 0.01, 'status + round trip joined by contact hash');
ok($np['app'] === 'Zoiper-sim' && $np['expires_in'] === 44, 'app + expiry');
$pubs = array_values(array_filter($dev, fn($d) => $d['user_id'] === 'ue83fbe'));
ok(count($pubs) === 2 && $pubs[0]['door'] === 'pub' && $pubs[0]['warn'] === '', 'public account on public door: two devices, no warning');
$users2 = $users; $users2['ue83fbe']['public'] = false;
$dev2 = Live::devices($fx('registrar'), $fx('contacts'), $users2, 1790873300);
ok(strpos($dev2[1]['warn'] . $dev2[2]['warn'], 'public door') !== false, 'VPN account on public door is flagged');
unset($users2['u8375de']);
ok(count(Live::devices($fx('registrar'), $fx('contacts'), $users2, 0)) === 2, 'deleted user is not shown');
ok(Live::devices('', '', $users, 0) === [], 'empty Asterisk output -> no devices');

// ---- calls ------------------------------------------------------------------------
$calls = Live::calls($fx('concise'), $fx('groups'), $users, $dev);
ok(count($calls) === 2, 'two API calls (Local channels ignored)');
$in = array_values(array_filter($calls, fn($c) => $c['direction'] === 'in'))[0];
ok($in['name'] === 'NoPub' && $in['other'] === '701' && $in['state'] === 'Talking' && $in['door'] === 'lan', 'incoming: caller 701, talking, door from device');
$out = array_values(array_filter($calls, fn($c) => $c['direction'] === 'out'))[0];
ok($out['name'] === 'Pub' && $out['other'] === '701' && $out['door'] === 'pub', 'outgoing: dialed number + door from GROUP tags');
ok($out['channel'] === 'PJSIP/apiu-e72fd50408-00000004', 'channel name kept for hang-up');
$deny = "PJSIP/apiu-025dfd2d93-0000000a!apiusers-deny!s!3!Up!Playback!ss-noservice!8802!!!3!1!!1790873400.40\n";
$c2 = Live::calls($deny, '', $users);
ok($c2[0]['state'] === 'Blocked (not allowed)', 'call in apiusers-deny shows as blocked');
$bang = "PJSIP/apiu-025dfd2d93-0000000b!macro-dial-one!s!5!Ring!Dial!PJSIP/701/sip:701@x!y,,tr!8802!!!3!7!!1790873400.41\n";
$c3 = Live::calls($bang, '', $users);
ok(count($c3) === 1 && $c3[0]['seconds'] === 7 && $c3[0]['state'] === 'Dialing', "Data containing '!' still parses");

// ---- failures -----------------------------------------------------------------------
$doors = ['vpn' => '10.0.1.1:5070', 'lan' => '192.168.8.100:5072', 'pub' => '198.51.100.20:5080'];
$f = Live::failures($fx('log'), $users, '127.0.0.1', $doors);
$kinds = array_count_values(array_map(fn($x) => $x['kind'], $f));
ok(($kinds['unknown username'] ?? 0) === 2, 'unknown usernames, one per Call-ID (retransmits merged)');
$wp = array_values(array_filter($f, fn($x) => $x['kind'] === 'wrong password'));
ok(count($wp) === 1 && $wp[0]['name'] === 'Pub' && $wp[0]['door'] === 'pub', 'wrong password: user + door from the server the phone typed');
ok(!array_filter($f, fn($x) => strpos($x['time'], '09:21:07') !== false), 'failure NOT relayed by our gateway is ignored');
ok(count(array_filter($f, fn($x) => strpos($x['kind'], 'wrong DISA PIN (2') === 0)) === 1, 'wrong DISA PIN counted');
ok(Live::logTime('2026-10-01 09:20:01') === strtotime('2026-10-01 09:20:01') && Live::logTime('Oct  1 16:38:04') > 0, 'both log date formats');

// ---- DISA locks -----------------------------------------------------------------------
$db = "/apiusers_disa/ue83fbe_fails : 0\n/apiusers_disa/ue83fbe_lock : 2000\n/apiusers_disa/u59ab23_lock : 900\n";
ok(Live::disaLocks($db, 1000) === ['ue83fbe' => 2000], 'only locks still in the future');

// ---- presence history -------------------------------------------------------------------
[$s1, $e1] = Live::presence([], $dev, 1000);
ok(count($e1) === 3 && $e1[0]['ev'] === 'seen', 'first snapshot: "already signed in", not fake sign-ins');
[$s2, $e2] = Live::presence($s1, $dev, 1030);
ok($e2 === [], 'nothing changed -> no events');
[$s3, $e3] = Live::presence($s2, array_slice($dev, 1), 1090);
ok(count($e3) === 1 && $e3[0]['ev'] === 'out' && $e3[0]['dur'] === 90, 'sign-out with how long they were on');
[$s4, $e4] = Live::presence($s3, $dev, 1100);
ok(count($e4) === 1 && $e4[0]['ev'] === 'in' && $s4['devices'][$dev[0]['key']]['since'] === 1100, 'sign-in after tracking started');
$moved = $dev; $moved[0]['door'] = 'vpn'; $moved[0]['ip'] = '10.0.1.9';
[$s5, $e5] = Live::presence($s4, $moved, 1200);
ok(count($e5) === 1 && $e5[0]['ev'] === 'moved' && $e5[0]['from'] === 'lan 127.0.0.1', 'network change');

// ---- Engine: hangup + DISA unlock rules ----------------------------------------------------
$e = new Engine(Engine::normalizeState([]), ['701']);
$r = $e->run('create', ['name' => 'Bro', 'allowed' => ['701'], 'internal' => true], 'local', 't');
$sip = $r['user']['sip_user'] ?? $e->state()['users'][array_key_first($e->state()['users'])]['sip_user'];
ok($e->run('hangup', ['channel' => "PJSIP/$sip-0000001a"], 'remote', 'ash')['ok'] === true, 'remote panel may hang up an API call');
ok($e->run('hangup', ['channel' => 'PJSIP/701-0000001a'], 'local', 't')['ok'] === false, 'cannot hang up a real extension');
ok($e->run('hangup', ['channel' => 'PJSIP/apiu-ffffffffff-0000001a'], 'local', 't')['ok'] === false, 'cannot hang up unknown apiu channel');
ok($e->run('hangup', ['channel' => "PJSIP/$sip-1;rm -rf /"], 'local', 't')['ok'] === false, 'junk channel names refused');
ok(strpos(json_encode($e->state()['audit']), 'hangup') !== false, 'hang-ups are audited');
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
