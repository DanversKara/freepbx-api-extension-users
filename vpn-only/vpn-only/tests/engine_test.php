<?php
// Run: php tests/engine_test.php   (pure PHP, no FreePBX needed)
require __DIR__ . '/../pbx-module/apiusers/lib/Engine.php';
require __DIR__ . '/../pbx-module/apiusers/lib/ConfigGen.php';
use ApiUsers\Engine; use ApiUsers\ConfigGen;

$fail = 0;
function ok($c, $m) { global $fail; echo ($c ? "PASS " : "FAIL ") . "$m\n"; if (!$c) $fail++; }

$e = new Engine([], ['701', '702', '703', '704', '705']);
$e->ensureToken();

// remote create of internal-only user works
$r = $e->run('create', ['name' => 'Brother', 'allowed' => ['701', '702']], 'remote', 'paul');
ok($r['ok'] && $r['user']['reach'] === 8801 && strlen($r['secret']) === 24, 'remote create internal-only');
$bro = $r['user']['id'];

// safety lock: remote cannot enable external / 911 / intl
ok(!$e->run('create', ['name' => 'X', 'external' => true], 'remote')['ok'], 'remote create external denied');
ok(!$e->run('update', ['id' => $bro, 'external' => true], 'remote')['ok'], 'remote enable external denied');
ok(!$e->run('reveal', ['id' => $bro], 'remote')['ok'], 'remote reveal denied');
ok(!$e->run('unkill', [], 'remote')['ok'], 'remote unkill denied');
ok(!$e->run('settings', ['safety_lock' => false], 'remote')['ok'], 'remote settings denied');

// local can
ok($e->run('update', ['id' => $bro, 'external' => true, 'e911' => true], 'local')['ok'], 'local enable external+911');
$u = $e->users()[$bro];
ok($u['external'] && $u['e911'] && !$u['international'], 'flags stored');
// remote may turn things OFF
ok($e->run('update', ['id' => $bro, 'e911' => false], 'remote')['ok'], 'remote disable 911 allowed');
// remote may keep external on (unchanged) while editing other fields
ok($e->run('update', ['id' => $bro, 'external' => true, 'name' => 'Bro'], 'remote')['ok'], 'remote edit with unchanged external');
ok($e->run('update', ['id' => $bro, 'reach' => '', 'name' => 'Bro'], 'remote')['ok'], 'empty reach on update = unchanged');
// external off forces 911/intl off
$e->run('update', ['id' => $bro, 'external' => false], 'local');
ok(!$e->users()[$bro]['e911'], 'external off clears 911');

// validation
ok(!$e->run('update', ['id' => $bro, 'allowed' => ['999']], 'local')['ok'], 'unknown extension rejected');
ok(!$e->run('update', ['id' => $bro, 'allowed' => ['911']], 'local')['ok'], '911 as extension rejected');
ok(!$e->run('create', ['name' => 'Y', 'reach' => 701], 'local')['ok'], 'reach outside 88xx rejected');
ok(Engine::cleanName('Bob"<script>;') === 'Bobscript', 'name sanitized');

// second user can be whitelisted by reach number
$r2 = $e->run('create', ['name' => 'Mom', 'allowed' => ['8801']], 'local');
ok($r2['ok'] && $r2['user']['allowed'] === ['8801'], 'API-to-API whitelist');

// generated config
$st = $e->state();
$pj = ConfigGen::pjsip($st);
$dp = ConfigGen::dialplan($st);
$su = $st['users'][$bro]['sip_user'];
ok(strpos($pj, "[$su]\ntype=endpoint") !== false, 'endpoint generated');
ok(strpos($pj, 'permit=192.168.8.100/255.255.255.255') !== false, 'gateway permit');
ok(strpos($pj, 'allow_transfer=no') !== false, 'transfers off');
ok(strpos($pj, 'bind=0.0.0.0:5099') !== false, 'transport 5099');
ok(strpos($dp, "[apiusers-$bro]") !== false, 'user context');
ok(strpos($dp, 'exten => 701,1,Gosub(apiusers-pre') !== false, '701 allowed');
ok(strpos($dp, 'exten => 703,') === false, '703 not allowed');
$ctx = substr($dp, strpos($dp, "[apiusers-$bro]"));
$ctx = substr($ctx, 0, strpos($ctx, "\n\n"));
ok(strpos($ctx, '_NXXNXXXXXX') === false, 'internal-only has no outside patterns');
ok(strpos($ctx, 'exten => 911,1,Gosub(apiusers-deny') !== false, '911 denied for brother');
ok(strpos($ctx, '_1876NXXXXXX,1,Gosub(apiusers-deny') !== false, 'Jamaica denied');
ok(strpos($ctx, '_011.,1,Gosub(apiusers-deny') !== false, 'intl denied');
ok(strpos($dp, 'Set(DIAL_OPTIONS=tr)') !== false, 'no DTMF transfer');

// external user context
$e->run('update', ['id' => $bro, 'external' => true, 'international' => false], 'local');
$dp = ConfigGen::dialplan($e->state());
$ctx = substr($dp, strpos($dp, "[apiusers-$bro]")); $ctx = substr($ctx, 0, strpos($ctx, "\n\n"));
ok(strpos($ctx, "exten => _1NXXNXXXXXX,1,Gosub") !== false, 'external pattern present');
ok(strpos($ctx, '_1900XXXXXXX,1,Gosub(apiusers-deny') !== false, 'premium still denied');
ok(strpos($ctx, 'exten => 911,1,Gosub(apiusers-deny') !== false, 'external without 911 still denies 911');

// kill switch
$e->run('kill', [], 'remote');
$pj = ConfigGen::pjsip($e->state());
ok(strpos($pj, 'type=endpoint') === false && strpos($pj, 'KILL SWITCH') !== false, 'kill removes endpoints');
ok(count(array_filter($e->state()['audit'], fn($a) => strpos($a['detail'], 'DENIED') === 0)) >= 5, 'denials audited');

// example text in the address fields never reaches a share card
$px = new Engine(['settings' => ['client_vpn_addr' => '10.x.x.x:5070']]);
ok($px->settings()['client_vpn_addr'] === '', 'saved "10.x.x.x:5070" is dropped');
$r = $px->run('settings', ['client_vpn_addr' => '10.x.x.x:5070'], 'local', 't');
ok(!$r['ok'] && strpos($r['error'], 'example text') !== false, 'typing the example text is refused');
ok($px->run('settings', ['client_vpn_addr' => '10.0.1.1:5070'], 'local', 't')['ok'], 'a real VPN address is accepted');

file_put_contents(sys_get_temp_dir() . '/apiusers_out_pjsip.conf', ConfigGen::pjsip(array_merge($e->state(), ['settings' => array_merge($e->settings(), ['kill_switch' => false])])));
file_put_contents(sys_get_temp_dir() . '/apiusers_out_extensions.conf', ConfigGen::dialplan(array_merge($e->state(), ['settings' => array_merge($e->settings(), ['kill_switch' => false])])));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
