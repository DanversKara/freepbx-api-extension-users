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


// ---- public (no-VPN) door ----
$e2 = new Engine([], ['701', '702']); $e2->ensureToken();
$r = $e2->run('create', ['name' => 'Pub', 'allowed' => ['701'], 'public' => true], 'remote');
ok(!$r['ok'], 'remote cannot create a public user (safety lock)');
$r = $e2->run('create', ['name' => 'Pub', 'allowed' => ['701'], 'public' => true], 'local');
ok($r['ok'] && $r['user']['public'] === true, 'local can create public internal-only user');
$pid = $r['user']['id'];
ok(!$e2->run('update', ['id' => $pid, 'external' => true], 'local')['ok'], 'public + external rejected');
ok(!$e2->run('create', ['name' => 'X', 'external' => true, 'public' => true], 'local')['ok'], 'create public+external rejected');
ok(!$e2->run('update', ['id' => $pid, 'public' => false], 'remote')['ok'], 'account type cannot change (remote)');
ok(!$e2->run('update', ['id' => $pid, 'public' => false], 'local')['ok'], 'account type cannot change (local/admin)');
$dp2 = ConfigGen::dialplan($e2->state());
ok(strpos($dp2, '[apiusers-netcheck]') !== false, 'netcheck context generated');
ok(strpos($dp2, "Gosub(apiusers-pre,s,1($pid,1,7200,1))") !== false, 'public user passes ARG4=1');
$r3 = $e2->run('create', ['name' => 'NoPub', 'allowed' => ['701']], 'local');
ok(strpos(ConfigGen::dialplan($e2->state()), "Gosub(apiusers-pre,s,1({$r3['user']['id']},1,7200,0))") !== false, 'non-public user passes ARG4=0');

// ---- public accounts are locked down for good; DISA is the only way out ----
ok(!$e2->run('update', ['id' => $pid, 'external' => true], 'local')['ok'], 'admin cannot give a public account External');
ok(!$e2->run('update', ['id' => $pid, 'e911' => true], 'local')['ok'], 'admin cannot give a public account 911');
ok(!$e2->run('update', ['id' => $pid, 'international' => true], 'local')['ok'], 'admin cannot give a public account International');
ok(!$e2->run('update', ['id' => $pid, 'disa' => true], 'local')['ok'], 'DISA needs a PIN first');
foreach (['12345', '111111', '123456', '987654', '12a456'] as $bad) ok(!$e2->run('update', ['id' => $pid, 'disa_pin' => $bad], 'local')['ok'], "weak/invalid PIN rejected ($bad)");
ok(!$e2->run('update', ['id' => $pid, 'disa_pin' => '582913', 'disa' => true], 'remote')['ok'], 'remote cannot set a PIN');
ok($e2->run('update', ['id' => $pid, 'disa_pin' => '582913', 'disa' => true], 'local')['ok'], 'admin sets PIN + enables DISA');
$pu = $e2->users()[$pid];
ok($pu['disa_hash'] === sha1($pu['disa_salt'] . '582913') && strpos(json_encode($e2->state()), '582913') === false, 'PIN stored only as salted hash');
$view = $e2->run('get', ['id' => $pid], 'local')['user'];
ok(!isset($view['disa_hash']) && !isset($view['disa_salt']) && $view['disa_pin_set'] === true, 'hash/salt never leave the PBX');
ok($e2->run('update', ['id' => $pid, 'disa' => false], 'remote')['ok'], 'remote may turn DISA OFF');
ok(!$e2->run('update', ['id' => $pid, 'disa' => true], 'remote')['ok'], 'remote may not turn DISA ON');
$e2->run('update', ['id' => $pid, 'disa' => true], 'local');
$vpnU = $e2->run('create', ['name' => 'Vpn'], 'local')['user']['id'];
ok(!$e2->run('update', ['id' => $vpnU, 'disa_pin' => '582913'], 'local')['ok'], 'VPN accounts cannot use DISA');
ok($e2->run('update', ['id' => $vpnU, 'external' => true, 'e911' => true], 'local')['ok'], 'VPN account can get External + 911 (admin)');
$dp3 = ConfigGen::dialplan($e2->state());
$ctx = substr($dp3, strpos($dp3, "[apiusers-$pid]")); $ctx = substr($ctx, 0, strpos($ctx, "\n\n"));
ok(strpos($ctx, "exten => *3472,1,Gosub(apiusers-pre") !== false && strpos($ctx, 'Goto(apiusers-disa,s,1)') !== false, 'public user gets DISA entry');
ok(strpos($ctx, '_NXXNXXXXXX') === false, 'public user has no direct outside patterns');
ok(strpos($ctx, 'exten => 911,1,Gosub(apiusers-deny') !== false, 'public user: 911 denied');
$disaOut = substr($dp3, strpos($dp3, '[apiusers-disa-out]')); $disaOut = substr($disaOut, 0, strpos($disaOut, "\n\n"));
ok(strpos($disaOut, 'exten => 911,1,Gosub(apiusers-deny') !== false && strpos($disaOut, '_1876NXXXXXX,1,Gosub(apiusers-deny') !== false
   && strpos($disaOut, '_1900XXXXXXX,1,Gosub(apiusers-deny') !== false && strpos($disaOut, '_011.,1,Gosub(apiusers-deny') !== false, 'DISA out: 911/Caribbean/premium/intl denied');
$vctx = substr($dp3, strpos($dp3, "[apiusers-$vpnU]")); $vctx = substr($vctx, 0, strpos($vctx, "\n\n"));
ok(strpos($vctx, '*3472') === false, 'VPN user has no DISA entry');
ok(!$e2->run('settings', ['disa_code' => '911'], 'local')['ok'] && !$e2->run('settings', ['disa_code' => '8850'], 'local')['ok'], 'bad DISA codes rejected');
file_put_contents(sys_get_temp_dir() . '/apiusers_disa_state.json', json_encode($e2->state()));
// legacy users (saved before the field existed) default to not public
$legacy = ['settings' => [], 'users' => ['uold' => ['id' => 'uold', 'name' => 'Old', 'sip_user' => 'apiu-old', 'secret' => 'x', 'reach' => 8850,
           'enabled' => true, 'internal' => true, 'external' => false, 'e911' => false, 'international' => false,
           'allowed' => ['701'], 'max_calls' => 1, 'max_minutes' => 60]]];
ok(strpos(ConfigGen::dialplan(Engine::normalizeState($legacy)), "Gosub(apiusers-pre,s,1(uold,1,3600,0))") !== false, 'legacy user treated as not public');

file_put_contents(sys_get_temp_dir() . '/apiusers_out_pjsip.conf', ConfigGen::pjsip(array_merge($e->state(), ['settings' => array_merge($e->settings(), ['kill_switch' => false])])));
file_put_contents(sys_get_temp_dir() . '/apiusers_out_extensions.conf', ConfigGen::dialplan(array_merge($e->state(), ['settings' => array_merge($e->settings(), ['kill_switch' => false])])));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
