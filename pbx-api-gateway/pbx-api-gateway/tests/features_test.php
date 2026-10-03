<?php
/**
 * Conference rooms + feature codes:   php tests/features_test.php   (must print ALL PASSED)
 */
require __DIR__ . '/../pbx-module/apiusers/lib/Engine.php';
require __DIR__ . '/../pbx-module/apiusers/lib/ConfigGen.php';

use ApiUsers\Engine;
use ApiUsers\ConfigGen;

$fails = 0;
function ok($cond, $name) { global $fails; if ($cond) { echo "ok   $name\n"; } else { echo "FAIL $name\n"; $fails++; } }
$hasPublic = method_exists(Engine::class, 'checkPin');          // VPN + Public edition
$dir = ['confs' => ['8000' => 'Family room', '8001' => 'Work'], 'features' => ['*43' => 'Echo Test', '555' => 'ChanSpy', '*97' => 'My Voicemail']];
$e = new Engine(Engine::normalizeState(['settings' => ['remote_token' => 't']]), ['701'], $dir);
$r = $e->run('create', ['name' => 'Bro', 'allowed' => ['701'], 'internal' => true, 'confs' => ['8000'], 'features' => '555, *43'], 'local', 'pbx');
ok($r['ok'], 'PBX page: create with a conference room and feature codes');
$id = array_key_first($e->state()['users']);
$u = $e->state()['users'][$id];
ok($u['confs'] === ['8000'] && $u['features'] === ['*43', '555'], 'stored as clean, sorted lists');
ok(strpos($e->permString($u), 'conf=8000') !== false && strpos($e->permString($u), 'codes=*43/555') !== false, 'audit text shows them');

// validation
$bad = function (array $args, string $why) use ($e, $id) { $r = $e->run('update', ['id' => $id] + $args, 'local', 'pbx'); ok(!$r['ok'], $why . (isset($r['error']) ? " ({$r['error']})" : '')); };
$bad(['confs' => ['9999']], 'conference room that does not exist is refused');
$bad(['features' => ['911']], '911 refused as a feature code');
$bad(['features' => ['411']], 'N11 refused as a feature code');
$bad(['features' => ['5595551234']], 'phone number refused as a feature code');
$bad(['features' => ['95551']], '5-digit code refused');
$bad(['features' => ['9411']], 'code starting with 9 (trunk prefix) refused');
$bad(['features' => ['_X.']], 'dialplan pattern refused');
$bad(['features' => ['*72;Hangup']], 'injection refused');
ok($e->run('update', ['id' => $id, 'features' => ['*97', '#123*']], 'local', 'pbx')['ok'], 'codes with * and # accepted');

// safety lock: remote can remove but never add
$r = $e->run('update', ['id' => $id, 'features' => ['*97', '#123*', '555']], 'remote', 'ash');
ok(!$r['ok'] && strpos($r['error'], 'safety lock') !== false, 'remote panel can NOT add a feature code');
$r = $e->run('create', ['name' => 'X', 'features' => ['*43']], 'remote', 'ash');
ok(!$r['ok'], 'remote panel can NOT create a user with feature codes');
ok($e->run('update', ['id' => $id, 'features' => ['*97']], 'remote', 'ash')['ok'], 'remote panel CAN remove a feature code');
ok($e->run('update', ['id' => $id, 'confs' => ['8000', '8001']], 'remote', 'ash')['ok'], 'remote panel CAN change conference rooms');
ok($e->run('update', ['id' => $id, 'name' => 'Bro2'], 'remote', 'ash')['ok'] && $e->state()['users'][$id]['features'] === ['*97'], 'update without the lists keeps them');
$list = $e->run('list', [], 'remote', 'ash');
ok($list['conferences'][0] === ['code' => '8000', 'name' => 'Family room'] && count($list['feature_codes']) === 3, 'list op returns rooms + codes for the panel');

// dialplan
$dp = ConfigGen::dialplan($e->state());
ok(strpos($dp, "exten => 8001,1,Gosub(apiusers-pre") !== false && strpos($dp, "Goto(ext-meetme,8001,1)") !== false, 'conference goes straight into ext-meetme, after the limits');
ok(strpos($dp, "exten => *97,1,Gosub(apiusers-pre") !== false && strpos($dp, "Goto(from-internal,*97,1)") !== false, 'feature code goes to from-internal, after the limits');
ok(strpos($dp, 'Goto(from-internal,555') === false, 'removed code is gone from the dialplan');

// legacy users (saved before this version) keep working
$legacy = Engine::normalizeState(['users' => ['uold' => ['id' => 'uold', 'name' => 'Old', 'sip_user' => 'apiu-0000000001', 'secret' => 's', 'reach' => 8805,
    'enabled' => true, 'internal' => true, 'external' => false, 'e911' => false, 'international' => false, 'allowed' => ['701'], 'max_calls' => 1, 'max_minutes' => 60]]]);
ok(strpos(ConfigGen::dialplan($legacy), '[apiusers-uold]') !== false, 'users without the new fields still generate');

if ($hasPublic) {
    $r = $e->run('create', ['name' => 'Pub', 'public' => true, 'allowed' => ['701'], 'internal' => true, 'confs' => ['8000']], 'local', 'pbx');
    ok($r['ok'], 'Public account may join a conference room');
    $pid = null; foreach ($e->state()['users'] as $x) if (!empty($x['public'])) $pid = $x['id'];
    $r = $e->run('update', ['id' => $pid, 'features' => ['*43']], 'local', 'pbx');
    ok(!$r['ok'], 'Public account can NEVER get a feature code, not even from the PBX page');
    $st = $e->state(); $st['users'][$pid]['features'] = ['555'];          // tampered state
    ok(strpos(ConfigGen::dialplan($st), "Goto(from-internal,555") === false, 'tampered Public account still gets no feature code in the dialplan');
}

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
