<?php
/**
 * Admin page for Applications -> API Users (local source = full rights).
 * Plain PHP + Bootstrap classes that FreePBX 17 already ships.
 */

namespace ApiUsers;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Audit-log row color: red = denied / kill switch, orange = hang-up, yellow = history deleted / unlock, green = released. */
function auditClass(array $a): string
{
    $op = (string)($a['op'] ?? ''); $d = (string)($a['detail'] ?? '');
    if (strpos($d, 'DENIED') === 0 || $op === 'kill') return 'au-bad';
    if ($op === 'hangup') return 'au-warn';
    if ($op === 'history_clear' || $op === 'disa_unlock') return 'au-yel';
    if ($op === 'unkill') return 'au-ok';
    return '';
}

/** A short warning for feature codes that are more than a convenience. */
function codeRisk(string $s): string
{
    if (preg_match('/spy|barge|whisper|intercom|listen|\b555\b|\b888\b/i', $s)) return 'can listen in on calls';
    if (preg_match('/forward|follow|speed|blacklist|day.?night|toggle|queue|pause|dnd|do not disturb|wake/i', $s)) return 'changes PBX settings';
    return '';
}

function renderPage(\FreePBX\modules\Apiusers $mod): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['apiusers_csrf'])) $_SESSION['apiusers_csrf'] = bin2hex(random_bytes(16));
    $csrf = $_SESSION['apiusers_csrf'];
    $actor = 'pbx:' . ($_SESSION['AMP_user']->username ?? 'admin');

    $msg = null; $err = null; $creds = null; $calls = null; $editId = $_GET['edit'] ?? null;
    $tab = $editId ? 'users' : 'dash';   // tab to open (a #tab=... in the URL wins)

    // ------------------------------------------------------------ POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apiu_action'])) {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            $err = 'Form expired, please try again.';
        } else {
            $a = $_POST['apiu_action'];
            if (in_array($a, ['create', 'update', 'delete', 'rotate', 'reveal', 'calls'], true)) $tab = 'users';
            if ($a === 'settings') $tab = 'settings';
            $id = (string)($_POST['id'] ?? '');
            $userArgs = function () use ($id) {
                return [
                    'id' => $id,
                    'name' => $_POST['name'] ?? '',
                    'reach' => $_POST['reach'] ?? '',
                    'enabled' => isset($_POST['enabled']),
                    'internal' => isset($_POST['internal']),
                    'external' => isset($_POST['external']),
                    'e911' => isset($_POST['e911']),
                    'international' => isset($_POST['international']),
                    'allowed' => $_POST['allowed'] ?? [],
                    'portal_login' => $_POST['portal_login'] ?? '',
                    'confs' => array_merge((array)($_POST['confs'] ?? []), Engine::codeList($_POST['confs_text'] ?? '')),
                    'features' => array_merge((array)($_POST['features'] ?? []), Engine::codeList($_POST['features_text'] ?? '')),
                    'max_calls' => $_POST['max_calls'] ?? 1,
                    'max_minutes' => $_POST['max_minutes'] ?? 120,
                ];
            };
            switch ($a) {
                case 'create':
                    $r = $mod->op('create', $userArgs(), 'local', $actor);
                    if ($r['ok']) { $creds = $r; $msg = 'User created. Copy the password now.'; }
                    break;
                case 'update':
                    $r = $mod->op('update', $userArgs(), 'local', $actor);
                    if ($r['ok']) { $msg = 'Saved.'; $editId = null; }
                    break;
                case 'delete':  $r = $mod->op('delete', ['id' => $id], 'local', $actor); if ($r['ok']) $msg = 'Deleted.'; break;
                case 'rotate':  $r = $mod->op('rotate', ['id' => $id], 'local', $actor); if ($r['ok']) { $creds = $r; $msg = 'New password generated – update Zoiper.'; } break;
                case 'reveal':  $r = $mod->op('reveal', ['id' => $id], 'local', $actor); if ($r['ok']) $creds = $r; break;
                case 'kill':    $r = $mod->op('kill', [], 'local', $actor); if ($r['ok']) $msg = 'KILL SWITCH ENGAGED – all API users disconnected.'; break;
                case 'unkill':  $r = $mod->op('unkill', [], 'local', $actor); if ($r['ok']) $msg = 'Kill switch released.'; break;
                case 'calls':   $r = $mod->calls($id); if ($r['ok']) $calls = ['id' => $id, 'rows' => $r['calls']]; break;
                case 'history_delete':
                    $kind = (string)($_POST['kind'] ?? '');
                    $r = $mod->historyDelete($kind, !empty($_POST['all']) ? 'all' : (array)($_POST['ids'] ?? []), 'local', $actor);
                    if ($r['ok']) $msg = 'Deleted ' . (int)$r['deleted'] . ' entr' . ((int)$r['deleted'] === 1 ? 'y' : 'ies') . '.';
                    $tab = $kind === 'failed' ? 'failed' : 'history';
                    break;
                case 'test_email':
                    $r = $mod->sendTestEmail(); $tab = 'settings';
                    if ($r['ok']) $msg = 'Test e-mail sent to ' . ($mod->loadState()['settings']['alert_email']) . '. Check the inbox (and spam folder).';
                    break;
                case 'hangup':  $r = $mod->hangupCall((string)($_POST['channel'] ?? ''), 'local', $actor); if ($r['ok']) $msg = 'Call ended.'; break;
                case 'settings':
                    $r = $mod->op('settings', [
                        'gateway_ip' => $_POST['gateway_ip'] ?? '',
                        'transport_port' => $_POST['transport_port'] ?? 5099,
                        'safety_lock' => isset($_POST['safety_lock']),
                        'remote_enabled' => isset($_POST['remote_enabled']),
                        'client_vpn_addr' => $_POST['client_vpn_addr'] ?? '',
                        'client_lan_addr' => $_POST['client_lan_addr'] ?? '',
                        'regen_token' => isset($_POST['regen_token']),
                        'portal_enabled' => isset($_POST['portal_enabled']),
                        'regen_portal_token' => isset($_POST['regen_portal_token']),
                        'alert_email' => $_POST['alert_email'] ?? '',
                        'smtp_host' => $_POST['smtp_host'] ?? '', 'smtp_port' => $_POST['smtp_port'] ?? 587,
                        'smtp_security' => $_POST['smtp_security'] ?? 'starttls',
                        'smtp_user' => $_POST['smtp_user'] ?? '', 'smtp_pass' => $_POST['smtp_pass'] ?? '',
                        'smtp_from' => $_POST['smtp_from'] ?? '', 'smtp_verify' => !isset($_POST['smtp_noverify']),
                    ], 'local', $actor);
                    if ($r['ok']) $msg = 'Settings saved.';
                    break;
                default: $r = ['ok' => false, 'error' => 'unknown action'];
            }
            if (empty($r['ok'])) $err = $r['error'] ?? 'Error';
        }
    }

    $st = $mod->loadState();
    $s = $st['settings'];
    $users = $st['users'];
    $exts = $mod->localExtensions();
    $edit = $editId && isset($users[$editId]) ? $users[$editId] : null;

    ob_start();
    $self = '?display=apiusers';
    ?>
<div class="container-fluid apiusers">
  <h1>API Users <small class="text-muted">locked-down SIP accounts for Zoiper via the gateway</small></h1>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if ($creds): $u2 = $creds['username']; ?>
  <div class="alert alert-warning">
    <b>Zoiper settings</b> (password shown once – it is not shown again remotely)<br>
    Username / Auth user: <code><?= h($u2) ?></code><br>
    Password: <code><?= h($creds['secret']) ?></code><br>
    Domain / Server (on AstroWarp): <code><?= h($s['client_vpn_addr'] ?: '— set it under Settings —') ?></code><br>
    Domain / Server (at home Wi-Fi): <code><?= h($s['client_lan_addr']) ?></code><br>
    Transport: <code>UDP</code> · Outbound proxy: <i>none</i> · STUN: <i>off</i>
    <!-- ZOIPER-PRO-TODO: add "Server (public TLS): client_tls_addr, Transport TLS, SRTP: SDES" -->
    <?php
      // Share card: QR / image / email / copy, generated in the browser (assets/share-card.js)
      $su = null;
      foreach ($users as $cand) { if ($cand['sip_user'] === $u2) { $su = $cand; break; } }
      $share = [
          'name' => $su['name'] ?? $u2, 'username' => $u2, 'password' => $creds['secret'],
          'reach' => (string)($su['reach'] ?? ''), 'allowed' => array_values($su['allowed'] ?? []),
          'vpn' => $s['client_vpn_addr'], 'lan' => $s['client_lan_addr'], 'site' => 'home phone system',
      ];
      $assets = __DIR__ . '/../assets/';
    ?>
    <div id="apiu-share"></div>
    <script><?= file_get_contents($assets . 'qrcode.js') ?></script>
    <script><?= file_get_contents($assets . 'share-card.js') ?></script>
    <script>ApiUsersShareCard(document.getElementById('apiu-share'),
      <?= json_encode($share, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);</script>
  </div>
  <?php endif; ?>

  <!-- ===================== kill switch ===================== -->
  <div class="panel panel-default card mb-3"><div class="panel-body card-body">
    <form method="post" action="<?= $self ?>" style="display:inline">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <?php if ($s['kill_switch']): ?>
        <span class="label label-danger badge badge-danger" style="font-size:1.1em">KILL SWITCH ENGAGED</span>
        All API users are offline.
        <button class="btn btn-success" name="apiu_action" value="unkill"
                onclick="return confirm('Release the kill switch? Users go back to their saved permissions.')">Release</button>
        <small class="text-muted">(Only possible from this page.)</small>
      <?php else: ?>
        <span class="label label-success badge badge-success">Active</span>
        <?= count(array_filter($users, fn($u) => $u['enabled'])) ?> enabled user(s).
        <button class="btn btn-danger" name="apiu_action" value="kill"
                onclick="return confirm('Disconnect ALL API users right now?')">Kill switch</button>
      <?php endif; ?>
    </form>
  </div></div>

  <!-- ===================== tabs ===================== -->
  <?php
    try { $live = $mod->live(); } catch (\Throwable $e) { $live = ['ok' => false, 'warning' => 'Live data unavailable: ' . $e->getMessage()]; }
  ?>
  <div class="apiu-tabs" id="apiu-tabs" data-default="<?= h($tab) ?>">
    <nav class="at-bar">
      <button type="button" data-tab="dash">Dashboard <span class="at-n at-yel" data-count="dash"></span></button>
      <button type="button" data-tab="users">Users <span class="at-n"><?= count($users) ?></span></button>
      <button type="button" data-tab="history">Sign-in history <span class="at-n" data-count="history"></span></button>
      <button type="button" data-tab="failed">Failed sign-ins <span class="at-n at-bad" data-count="failed"></span></button>
      <button type="button" data-tab="audit">Audit log <span class="at-n"><?= count($st['audit']) ?></span></button>
      <button type="button" data-tab="settings">Settings</button>
    </nav>
    <section data-panel="dash"><div id="apiu-dash"></div></section>
    <section data-panel="history"><div id="apiu-history"></div></section>
    <section data-panel="failed"><div id="apiu-failed"></div></section>

  <section data-panel="users">
  <!-- ===================== users ===================== -->
  <h3>Users</h3>
  <table class="table table-striped table-condensed table-sm">
    <thead><tr><th>Name</th><th>Reach</th><th>Zoiper username</th><th>Permissions</th><th></th></tr></thead>
    <tbody>
    <?php if (!$users): ?><tr><td colspan="5" class="text-muted">No API users yet.</td></tr><?php endif; ?>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['name']) ?></td>
        <td><?= h($u['reach']) ?></td>
        <td><code><?= h($u['sip_user']) ?></code><?php if (!empty($u['portal_login'])): ?><br><small class="text-muted">portal: <?= h($u['portal_login']) ?></small><?php endif; ?></td>
        <td><?= h(\ApiUsers\ConfigGen::summary($u)) ?>
            · <?= (int)$u['max_calls'] ?> call(s), <?= $u['max_minutes'] ? (int)$u['max_minutes'] . ' min' : 'no time limit' ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-default btn-secondary btn-xs btn-sm" href="<?= $self ?>&edit=<?= h($u['id']) ?>">Edit</a>
          <form method="post" action="<?= $self ?>" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($u['id']) ?>">
            <button class="btn btn-default btn-secondary btn-xs btn-sm" name="apiu_action" value="calls">Calls</button>
            <button class="btn btn-default btn-secondary btn-xs btn-sm" name="apiu_action" value="reveal">Show password</button>
            <button class="btn btn-warning btn-xs btn-sm" name="apiu_action" value="rotate"
                    onclick="return confirm('Generate a new password? The old one stops working immediately.')">New password</button>
            <button class="btn btn-danger btn-xs btn-sm" name="apiu_action" value="delete"
                    onclick="return confirm('Delete <?= h($u['name']) ?>?')">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($calls !== null): ?>
    <h4>Recent calls – <?= h($users[$calls['id']]['name'] ?? '') ?></h4>
    <table class="table table-condensed table-sm">
      <tr><th>Date</th><th>From</th><th>To</th><th>Result</th><th>Seconds</th></tr>
      <?php foreach ($calls['rows'] as $c): ?>
        <tr><td><?= h($c['calldate']) ?></td><td><?= h($c['src']) ?></td><td><?= h($c['dst']) ?></td>
            <td><?= h($c['disposition']) ?></td><td><?= h($c['billsec']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$calls['rows']): ?><tr><td colspan="5" class="text-muted">No calls yet.</td></tr><?php endif; ?>
    </table>
  <?php endif; ?>

  <!-- ===================== add / edit ===================== -->
  <?php $f = $edit ?: ['id' => '', 'name' => '', 'reach' => '', 'enabled' => true, 'internal' => true,
                       'external' => false, 'e911' => false, 'international' => false, 'allowed' => [],
                       'max_calls' => 1, 'max_minutes' => 120]; ?>
  <h3><?= $edit ? 'Edit ' . h($edit['name']) : 'Add user' ?></h3>
  <form method="post" action="<?= $self ?>" class="form-horizontal">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= h($f['id']) ?>">
    <div class="row">
      <div class="col-md-4"><label>Name</label>
        <input class="form-control" name="name" maxlength="40" required value="<?= h($f['name']) ?>"
               placeholder="Brother"></div>
      <div class="col-md-2"><label>Reach extension</label>
        <input class="form-control" name="reach" value="<?= h($f['reach']) ?>" placeholder="auto (88xx)"></div>
      <div class="col-md-2"><label>Max calls at once</label>
        <input class="form-control" type="number" min="1" max="5" name="max_calls" value="<?= (int)$f['max_calls'] ?>"></div>
      <div class="col-md-2"><label>Max minutes / call</label>
        <input class="form-control" type="number" min="0" max="480" name="max_minutes" value="<?= (int)$f['max_minutes'] ?>">
        <small class="text-muted">0 = no limit</small></div>
      <div class="col-md-2"><label>&nbsp;</label><div class="checkbox">
        <label><input type="checkbox" name="enabled" <?= $f['enabled'] ? 'checked' : '' ?>> Enabled</label></div></div>
    </div>
    <div class="row">
      <div class="col-md-4"><label>User portal login <small class="text-muted">(optional)</small></label>
        <input class="form-control" name="portal_login" maxlength="100" value="<?= h($f['portal_login'] ?? '') ?>" placeholder="their Authentik user name">
        <small class="text-muted">Lets this person sign in to the user portal and see their own calls and sign-ins. Someone with several phones (one account per phone): put the <b>same</b> login on each.</small></div>
    </div>

    <h4>May call these extensions</h4>
    <label><input type="checkbox" name="internal" <?= $f['internal'] ? 'checked' : '' ?>> Allow internal calls (to the extensions ticked below)</label>
    <div class="row" style="margin-left:0">
      <?php foreach ($exts as $x => $nm): ?>
        <label class="col-md-3"><input type="checkbox" name="allowed[]" value="<?= h($x) ?>"
          <?= in_array((string)$x, $f['allowed'], true) ? 'checked' : '' ?>> <?= h($x) ?> <span class="text-muted"><?= h($nm) ?></span></label>
      <?php endforeach; ?>
      <?php foreach ($users as $o): if ($o['id'] === $f['id']) continue; ?>
        <label class="col-md-3"><input type="checkbox" name="allowed[]" value="<?= h($o['reach']) ?>"
          <?= in_array((string)$o['reach'], $f['allowed'], true) ? 'checked' : '' ?>> <?= h($o['reach']) ?>
          <span class="text-muted">API: <?= h($o['name']) ?></span></label>
      <?php endforeach; ?>
    </div>

    <?php $dir = $mod->directory(); $confRooms = $dir['confs']; $fcodes = $dir['features'];
          $fConfs = $f['confs'] ?? []; $fFeat = $f['features'] ?? []; ?>
    <h4>Conference rooms</h4>
    <?php if ($confRooms === null): ?>
      <p class="text-muted" style="margin:0">Couldn't read the conference list. Room numbers, comma separated:</p>
      <input class="form-control" name="confs_text" value="<?= h(implode(', ', $fConfs)) ?>" placeholder="8000">
    <?php elseif (!$confRooms): ?>
      <p class="text-muted">No conference rooms on this PBX yet (Applications &gt; Conferences).</p>
    <?php else: ?>
      <div class="row" style="margin-left:0">
      <?php foreach ($confRooms as $x => $nm): ?>
        <label class="col-md-3"><input type="checkbox" name="confs[]" value="<?= h($x) ?>"
          <?= in_array((string)$x, $fConfs, true) ? 'checked' : '' ?>> <?= h($x) ?> <span class="text-muted"><?= h($nm) ?></span></label>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div id="apiu-featopts">
    <h4>Feature codes <small class="text-muted">added from this page only</small></h4>
    <div class="alert alert-warning" style="margin:4px 0">A feature code works exactly as if it were dialed on a phone in your house.
      <b>ChanSpy (555) and Barge let this person listen to any call on this PBX</b>, including yours. Codes like call forward,
      follow-me or day/night change how the PBX handles calls. Only tick codes you'd let this person use on a house phone.</div>
    <div class="row" style="margin-left:0">
    <?php $custom = $fFeat;
          foreach (($fcodes ?? []) as $x => $nm):
            $x = (string)$x;
            if (Engine::checkFeatureCode($x) !== null) continue;      // e.g. 5-digit or 9-prefixed codes are never allowed
            $custom = array_values(array_diff($custom, [$x]));
            $risk = codeRisk($nm . ' ' . $x); ?>
      <label class="col-md-4"><input type="checkbox" name="features[]" value="<?= h($x) ?>"
        <?= in_array($x, $fFeat, true) ? 'checked' : '' ?>> <code><?= h($x) ?></code> <span class="text-muted"><?= h($nm) ?></span>
        <?php if ($risk): ?><span class="label label-danger badge badge-danger"><?= h($risk) ?></span><?php endif; ?></label>
    <?php endforeach; ?>
    </div>
    <label>Other codes <small class="text-muted">(exactly as dialed, comma separated, e.g. Incredible PBX's 555 ChanSpy)</small></label>
    <input class="form-control" name="features_text" value="<?= h(implode(', ', $custom)) ?>" placeholder="555, *43">
    <small class="text-muted">Digits, * and #. Digits-only codes: 2-4 digits, not starting with 0, 1 or 9. 911, 933 and N11 are never allowed.</small>
    </div>

    <h4>Outside calls</h4>
    <div class="checkbox"><label><input type="checkbox" name="external" id="apiu-ext" <?= $f['external'] ? 'checked' : '' ?>>
      <b>External</b> – US/Canada numbers through your trunk (costs money). Premium 900/976 always blocked.</label></div>
    <div class="checkbox" style="margin-left:2em"><label><input type="checkbox" name="e911" class="apiu-dep" <?= $f['e911'] ? 'checked' : '' ?>>
      <b>911 / E911</b></label>
      <div class="alert alert-danger" style="margin:4px 0">911 from an API user goes out with <b>this PBX's E911 address</b>
        (your house), not the caller's location. Only enable for someone physically at this address.</div></div>
    <div class="checkbox" style="margin-left:2em"><label><input type="checkbox" name="international" class="apiu-dep" <?= $f['international'] ? 'checked' : '' ?>>
      <b>International</b> – 011 + Caribbean area codes (876, 809, 284 …) that look domestic but bill international.</label></div>
    <?php if ($s['safety_lock']): ?><p class="text-muted">Safety lock is ON: the remote panel can't turn External, 911 or International on – only this page can.</p><?php endif; ?>

    <button class="btn btn-primary" name="apiu_action" value="<?= $edit ? 'update' : 'create' ?>"><?= $edit ? 'Save' : 'Create user' ?></button>
    <?php if ($edit): ?><a class="btn btn-default btn-secondary" href="<?= $self ?>">Cancel</a><?php endif; ?>
  </form>

  </section>

  <!-- ===================== settings ===================== -->
  <section data-panel="settings">
  <h3>Settings <small>(this page only)</small></h3>
  <form method="post" action="<?= $self ?>">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <div class="row">
      <div class="col-md-3"><label>Gateway IP (Kamailio)</label>
        <input class="form-control" name="gateway_ip" value="<?= h($s['gateway_ip']) ?>">
        <small class="text-muted">Only this IP may use API accounts.</small></div>
      <div class="col-md-2"><label>PBX gateway port</label>
        <input class="form-control" name="transport_port" value="<?= (int)$s['transport_port'] ?>">
        <small class="text-muted">Changing needs <code>fwconsole restart</code>.</small></div>
      <div class="col-md-3"><label>Zoiper server on AstroWarp</label>
        <input class="form-control" name="client_vpn_addr" value="<?= h($s['client_vpn_addr']) ?>" placeholder="10.x.x.x:5070"></div>
      <div class="col-md-3"><label>Zoiper server at home</label>
        <input class="form-control" name="client_lan_addr" value="<?= h($s['client_lan_addr']) ?>"></div>
    </div>
    <div class="checkbox"><label><input type="checkbox" name="safety_lock" <?= $s['safety_lock'] ? 'checked' : '' ?>>
      Safety lock (remote panel can't enable External / 911 / International)</label></div>
    <div class="checkbox"><label><input type="checkbox" name="remote_enabled" <?= $s['remote_enabled'] ? 'checked' : '' ?>>
      Allow the remote panel (Docker) to manage users</label></div>
    <p>Remote token (goes in the Docker <code>.env</code> as <code>PBX_REMOTE_TOKEN</code>):
      <code><?= h($s['remote_token']) ?></code>
      <label style="margin-left:1em"><input type="checkbox" name="regen_token"> generate a new one</label></p>
    <h4 style="margin-top:1.5em">User portal</h4>
    <p class="text-muted" style="margin-top:0">A separate page (container <code>portal</code> on the gateway, behind NPM + Authentik)
      where each person sees only their own account: who they can call, signed-in phones, call history, sign-ins and failed
      attempts, and a button for a new Zoiper password. Link people on their Edit form (User portal login).</p>
    <div class="checkbox"><label><input type="checkbox" name="portal_enabled" <?= !empty($s['portal_enabled']) ? 'checked' : '' ?>>
      Turn the user portal on</label></div>
    <p>Portal token (goes in the Docker <code>.env</code> as <code>PORTAL_TOKEN</code>):
      <code><?= h($s['portal_token'] ?? '') ?></code>
      <label style="margin-left:1em"><input type="checkbox" name="regen_portal_token"> generate a new one</label></p>

    <h4 style="margin-top:1.5em">E-mail alerts</h4>
    <p class="text-muted" style="margin-top:0">Checked once a minute. You get one combined e-mail (at most every 5 minutes, or 1 minute
      for urgent ones) for: failed sign-ins, wrong DISA PINs, the same account signed in from two places at once, a VPN account
      on the public door, a locked dial-out code, the kill switch, and denied or risky remote-panel actions.
      Leave the alert address empty to turn alerts off.</p>
    <?php $as = $mod->alertStatus(); ?>
    <div class="row">
      <div class="col-md-4"><label>Send alerts to</label>
        <input class="form-control" name="alert_email" value="<?= h($s['alert_email']) ?>" placeholder="admin@example.com"></div>
      <div class="col-md-4"><label>Sender address</label>
        <input class="form-control" name="smtp_from" value="<?= h($s['smtp_from']) ?>" placeholder="pbx-alerts@example.com">
        <small class="text-muted">A real mailbox on your mail server is best.</small></div>
    </div>
    <div class="row">
      <div class="col-md-4"><label>Mail server (SMTP)</label>
        <input class="form-control" name="smtp_host" value="<?= h($s['smtp_host']) ?>" placeholder="mail.example.com"></div>
      <div class="col-md-2"><label>Port</label>
        <input class="form-control" name="smtp_port" value="<?= (int)$s['smtp_port'] ?>"></div>
      <div class="col-md-2"><label>Security</label>
        <select class="form-control" name="smtp_security">
          <?php foreach (['starttls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None (LAN only)'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $s['smtp_security'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="row">
      <div class="col-md-4"><label>Login (user name)</label>
        <input class="form-control" name="smtp_user" value="<?= h($s['smtp_user']) ?>" autocomplete="off"></div>
      <div class="col-md-4"><label>Password</label>
        <input class="form-control" type="password" name="smtp_pass" autocomplete="new-password"
               placeholder="<?= $s['smtp_pass'] !== '' ? 'saved (leave empty to keep it)' : '' ?>"></div>
    </div>
    <div class="checkbox"><label><input type="checkbox" name="smtp_noverify" <?= $s['smtp_verify'] ? '' : 'checked' ?>>
      Don't check the mail server's certificate (only for a server on your LAN reached by IP)</label></div>
    <p class="text-muted">
      <?php if ($as['last_mail']): ?>Last alert e-mail: <?= h(date('M j H:i', $as['last_mail'])) ?>.<?php endif; ?>
      <?php if ($as['pending']): ?> <?= (int)$as['pending'] ?> alert(s) waiting to be sent.<?php endif; ?>
      <?php if ($as['last_error']): ?> <span class="text-danger">Last problem: <?= h($as['last_error']) ?></span><?php endif; ?>
    </p>

    <button class="btn btn-primary" name="apiu_action" value="settings">Save settings</button>
    <button class="btn btn-default btn-secondary" name="apiu_action" value="test_email">Send test e-mail</button>
  </form>

  </section>

  <!-- ===================== audit ===================== -->
  <section data-panel="audit">
  <p class="text-muted">Every change, including denied remote attempts and deleted history (newest first, last 300 kept). The audit log can't be deleted.</p>
  <table class="table table-condensed table-sm">
    <tr><th>Time (UTC)</th><th>From</th><th>Action</th><th>User</th><th>Detail</th></tr>
    <?php foreach (array_reverse($st['audit']) as $a): ?>
      <tr class="<?= auditClass($a) ?>"><td><?= h($a['t']) ?></td><td><?= h($a['src'] . ' ' . $a['actor']) ?></td><td><?= h($a['op']) ?></td>
          <td><?= h($users[$a['id']]['name'] ?? $a['id']) ?></td><td><?= h($a['detail']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  </section>
  </div><!-- /tabs -->
</div>
<script><?= file_get_contents(__DIR__ . '/../assets/live-view.js') ?></script>
<script>(function () {
  var tabs = ApiUsersTabs(document.getElementById('apiu-tabs'));
  var csrf = <?= json_encode($csrf) ?>;
  ApiUsersLive({
    tabs: tabs, light: true, disa: false,
    dash: document.getElementById('apiu-dash'), history: document.getElementById('apiu-history'), failed: document.getElementById('apiu-failed'),
    initial: <?= json_encode($live, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>,
    url: 'ajax.php?module=apiusers&command=live', historyUrl: 'ajax.php?module=apiusers&command=history', interval: 5000,
    hangup: { url: '<?= $self ?>', fields: { csrf: csrf, apiu_action: 'hangup' } },
    del: { url: '<?= $self ?>', fields: { csrf: csrf, apiu_action: 'history_delete' }, idsName: 'ids[]' }
  });
})();</script>
<script>
(function () {
  var ext = document.getElementById('apiu-ext');
  function sync() { document.querySelectorAll('.apiu-dep').forEach(function (c) {
      c.disabled = !ext.checked; if (!ext.checked) c.checked = false; }); }
  if (ext) { ext.addEventListener('change', sync); sync(); }
})();
</script>
<?php
    return ob_get_clean();
}
