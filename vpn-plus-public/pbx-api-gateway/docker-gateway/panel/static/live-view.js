/*
 * API Users – Live view (who is signed in, calls in progress, sign-in history, failed sign-ins).
 * Same file on the PBX page (inlined by views/page.php) and the remote panel (/static/live-view.js).
 * SOURCE COPY: pbx-module/apiusers/assets/live-view.js -> run scripts/sync-assets.sh after editing.
 *
 *   ApiUsersLive(container, {
 *     initial:  {...} | null      first data set (rendered immediately, no wait)
 *     url:      'ajax.php?module=apiusers&command=live' | '/live.json'
 *     interval: 5000               ms between refreshes (paused while the tab is hidden)
 *     hangup:   {url, fields:{...}} | null   POST form used by the "Hang up" buttons
 *     unlock:   {url, fields:{...}} | null   POST form for "Unlock now" on a locked DISA code (PBX page only)
 *     tableClass: 'table table-sm'           extra classes for tables (Bootstrap on the PBX)
 *   })
 *
 * All data goes into the page with textContent, never innerHTML.
 */
(function (root) {
  'use strict';

  var DOORS = { vpn: 'VPN', lan: 'Home Wi-Fi / LAN', pub: 'Public door (no VPN)' };
  var CSS = [
    '.apiu-live{margin:8px 0 18px}',
    '.apiu-live .al-top{display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 14px;margin-bottom:6px}',
    '.apiu-live .al-mute{opacity:.68;font-size:12.5px}',
    '.apiu-live h4.al-h{font-size:14px;font-weight:600;margin:16px 0 4px;display:flex;align-items:baseline;gap:8px}',
    '.apiu-live .al-n{display:inline-block;min-width:20px;padding:0 7px;border-radius:99px;font-size:12px;text-align:center;border:1px solid currentColor;opacity:.75}',
    '.apiu-live .al-tag{display:inline-block;font-size:11.5px;line-height:1.5;padding:0 7px;border-radius:99px;border:1px solid currentColor;margin:0 3px 0 0;white-space:nowrap}',
    '.apiu-live .al-ok{color:#1a7f37}.apiu-live .al-warn{color:#9a6700}.apiu-live .al-bad{color:#cf222e}.apiu-live .al-info{color:#1f6feb}',
    '@media (prefers-color-scheme:dark){.apiu-live .al-ok{color:#3fb950}.apiu-live .al-warn{color:#d29922}.apiu-live .al-bad{color:#ff7b72}.apiu-live .al-info{color:#58a6ff}}',
    '.apiu-live .al-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:currentColor;margin-right:5px;vertical-align:1px}',
    '.apiu-live table{width:100%;border-collapse:collapse;margin:0}',
    '.apiu-live td,.apiu-live th{text-align:left;padding:6px 6px;vertical-align:top;font-size:13.5px}',
    '.apiu-live th{font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;opacity:.7;font-weight:600}',
    '.apiu-live .al-scroll{overflow-x:auto}',
    '.apiu-live .al-empty{opacity:.65;font-size:13px;padding:4px 6px}',
    '.apiu-live .al-alert{border-left:4px solid #cf222e;padding:6px 10px;margin:6px 0;font-size:13.5px}',
    '.apiu-live .al-note{border-left:4px solid #9a6700;padding:6px 10px;margin:6px 0;font-size:13.5px}',
    '.apiu-live button.al-btn{font:inherit;font-size:12.5px;padding:3px 9px;border-radius:6px;cursor:pointer;border:1px solid #cf222e;color:#cf222e;background:transparent}',
    '.apiu-live button.al-ghost{border-color:currentColor;color:inherit;opacity:.8}',
    '.apiu-live code{font-size:12.5px;word-break:normal;white-space:nowrap}',
    '.apiu-live td.al-t{white-space:nowrap}',
    /* phones: each row becomes a compact wrapped block instead of a wide table */
    '@media (max-width:640px){.apiu-live .al-hide-sm{display:none}' +
      '.apiu-live thead{display:none}.apiu-live table,.apiu-live tbody{display:block;width:100%}' +
      '.apiu-live tr{display:flex;flex-wrap:wrap;align-items:baseline;gap:2px 10px;padding:7px 2px;border-bottom:1px solid rgba(128,128,128,.25)}' +
      '.apiu-live td{display:block;padding:0;border:0!important;font-size:13.5px}.apiu-live td:empty{display:none}}'
  ].join('\n');

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      if (k === 'class') e.className = attrs[k];
      else if (k === 'text') e.textContent = attrs[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
      else e.setAttribute(k, attrs[k]);
    }
    (kids || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return e;
  }
  function tag(text, cls) { return el('span', { 'class': 'al-tag ' + (cls || ''), text: text }); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function dur(s) {
    s = Math.max(0, Math.round(s));
    var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60);
    if (h) return h + 'h ' + m + 'm';
    if (m) return m + 'm ' + pad(s % 60) + 's';
    return s + 's';
  }
  function clock(s) { s = Math.max(0, Math.floor(s)); var h = Math.floor(s / 3600); return (h ? h + ':' + pad(Math.floor(s % 3600 / 60)) : Math.floor(s / 60)) + ':' + pad(s % 60); }
  function when(epoch) {
    if (!epoch) return '';
    var d = new Date(epoch * 1000), now = new Date();
    var t = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (d.toDateString() === now.toDateString()) return t;
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + t;
  }
  function door(d) { return DOORS[d] || (d ? d : '—'); }
  function doorTag(d) { return d ? tag(door(d), d === 'pub' ? 'al-warn' : 'al-info') : el('span', { 'class': 'al-mute', text: '—' }); }
  function typeTag(pub) { return tag(pub ? 'PUBLIC' : 'VPN', pub ? 'al-warn' : 'al-info'); }

  function table(cls, head, rows, emptyText) {
    if (!rows.length) return el('div', { 'class': 'al-empty', text: emptyText });
    return el('div', { 'class': 'al-scroll' }, [el('table', { 'class': cls }, [
      el('thead', null, [el('tr', null, head.map(function (h) {
        return el('th', h[1] ? { 'class': h[1], text: h[0] } : { text: h[0] });
      }))]),
      el('tbody', null, rows)
    ])]);
  }
  function section(title, count, body) {
    return el('div', null, [el('h4', { 'class': 'al-h' }, [title, count === null ? null : el('span', { 'class': 'al-n', text: String(count) })]), body]);
  }

  function ApiUsersLive(box, opts) {
    opts = opts || {};
    var tcls = opts.tableClass || '';
    var data = null, gotAt = 0, skew = 0, timer = null, ticking = null, err = '', paused = false;

    if (!document.getElementById('apiu-live-css')) {
      document.head.appendChild(el('style', { id: 'apiu-live-css', text: CSS }));
    }
    box.classList.add('apiu-live');

    function nowServer() { return Date.now() / 1000 + skew; }

    function post(cfg, extra) {
      var f = el('form', { method: 'post', action: cfg.url, style: 'display:none' });
      var fields = Object.assign({}, cfg.fields || {}, extra);
      Object.keys(fields).forEach(function (k) { f.appendChild(el('input', { type: 'hidden', name: k, value: fields[k] })); });
      document.body.appendChild(f); f.submit();
    }
    function hangupBtn(c) {
      if (!opts.hangup) return null;
      return el('button', { 'class': 'al-btn', type: 'button', onclick: function () {
        if (confirm('Hang up ' + c.name + "'s call now?")) post(opts.hangup, { channel: c.channel });
      } }, ['Hang up']);
    }
    function unlockBtn(id, name) {
      if (!opts.unlock) return null;
      return el('button', { 'class': 'al-btn al-ghost', type: 'button', style: 'margin-left:8px', onclick: function () {
        if (confirm('Unlock ' + name + "'s dial-out code now? Only do this if you know the wrong PINs were theirs.")) post(opts.unlock, { id: id });
      } }, ['Unlock now']);
    }

    function render() {
      box.textContent = '';
      var d = data || {};
      var top = el('div', { 'class': 'al-top' }, [
        el('span', { 'class': 'al-mute', id: 'al-age' }),
        opts.url ? el('button', { 'class': 'al-btn al-ghost', type: 'button', onclick: function () {
          paused = !paused; this.textContent = paused ? 'Resume auto-refresh' : 'Pause'; if (!paused) load(); tickAge();
        } }, [paused ? 'Resume auto-refresh' : 'Pause']) : null,
        opts.url ? el('button', { 'class': 'al-btn al-ghost', type: 'button', onclick: function () { load(); } }, ['Refresh now']) : null
      ]);
      box.appendChild(top);
      if (err) box.appendChild(el('div', { 'class': 'al-note', text: err }));
      if (d.warning) box.appendChild(el('div', { 'class': 'al-note', text: d.warning }));
      if (d.kill_switch) box.appendChild(el('div', { 'class': 'al-alert', text: 'Kill switch is engaged: nobody can sign in or call.' }));

      // --- signed in --------------------------------------------------------
      var devs = d.devices || [];
      var devRows = [];
      devs.forEach(function (x) {
        var online = x.status === 'Avail' || x.status === 'Reachable';
        var unk = x.status === 'Unknown' || x.status === 'NonQual';
        var st = el('span', { 'class': online ? 'al-ok' : (unk ? 'al-mute' : 'al-warn') }, [
          el('span', { 'class': 'al-dot' }),
          online ? 'Online' + (x.rtt_ms !== null && x.rtt_ms !== undefined ? ' · ' + Math.round(x.rtt_ms) + ' ms' : '')
                 : (unk ? 'Checking…' : 'Not answering')]);
        devRows.push(el('tr', null, [
          el('td', null, [x.name, ' ', typeTag(x.public)]),
          el('td', null, [doorTag(x.door)]),
          el('td', null, [el('code', { text: x.ip + (x.port ? ':' + x.port : '') })]),
          el('td', { 'class': 'al-hide-sm' }, [x.app || '—']),
          el('td', null, [st]),
          el('td', { 'class': 'al-hide-sm' }, [x.since ? dur(nowServer() - x.since) : '—'])
        ]));
        if (x.warn) devRows.push(el('tr', null, [el('td', { colspan: '6' }, [el('div', { 'class': 'al-alert', text: '⚠ ' + x.name + ': ' + x.warn })])]));
      });
      var signedIds = {};
      devs.forEach(function (x) { signedIds[x.user_id] = true; });
      var offline = (d.users || []).filter(function (u) { return u.enabled && !signedIds[u.id]; }).map(function (u) { return u.name; });
      box.appendChild(section('Signed in now', devs.length, el('div', null, [
        table(tcls, [['User'], ['Door'], ['Phone address'], ['App', 'al-hide-sm'], ['Status'], ['For', 'al-hide-sm']], devRows, 'Nobody is signed in.'),
        offline.length ? el('div', { 'class': 'al-mute', style: 'padding:4px 6px', text: 'Not signed in: ' + offline.join(', ') }) : null
      ])));

      // --- calls -------------------------------------------------------------
      var calls = d.calls || [];
      var callRows = calls.map(function (c) {
        var who = c.direction === 'in'
          ? ['← call from ', el('b', { text: c.other || 'unknown' })]
          : ['→ ', el('b', { text: c.other ? (c.other.indexOf('DISA-') === 0 ? 'DISA ' + c.other.slice(5) : c.other) : '…' })];
        var t = el('span', { 'class': 'al-timer', 'data-s': String(c.seconds), text: clock(c.seconds) });
        return el('tr', null, [
          el('td', null, [c.name, ' ', typeTag(c.public)]),
          el('td', null, who),
          el('td', null, [doorTag(c.door)]),
          el('td', null, [el('span', { 'class': /Blocked/.test(c.state) ? 'al-bad' : (c.state === 'Talking' ? 'al-ok' : '') , text: c.state })]),
          el('td', null, [t]),
          el('td', null, [hangupBtn(c)])
        ]);
      });
      box.appendChild(section('Calls in progress', calls.length,
        table(tcls, [['User'], ['Call'], ['Door'], ['State'], ['Time'], ['']], callRows, 'No calls right now.')));

      // --- DISA locks --------------------------------------------------------
      var locks = d.disa_locks || {};
      var lockIds = Object.keys(locks);
      if (lockIds.length) {
        var names = {};
        (d.users || []).forEach(function (u) { names[u.id] = u.name; });
        box.appendChild(section('Dial-out code locked', lockIds.length, el('div', null, lockIds.map(function (id) {
          return el('div', { 'class': 'al-alert' }, [(names[id] || id) + "'s dial-out code is locked after 5 wrong PINs until " + when(locks[id]) + '.',
            unlockBtn(id, names[id] || id)]);
        }))));
      }

      // --- sign-in history ---------------------------------------------------
      var EV = { 'in': ['Signed in', 'al-ok'], out: ['Signed out / went offline', 'al-mute'], moved: ['Changed network', 'al-info'], seen: ['Already signed in', 'al-mute'] };
      var hist = (d.signins || []).map(function (e) {
        var lab = EV[e.ev] || [e.ev, ''];
        var extra = e.ev === 'out' && e.dur ? ' after ' + dur(e.dur) : (e.ev === 'moved' && e.from ? ' (was ' + e.from + ')' : '');
        return el('tr', null, [
          el('td', { 'class': 'al-mute al-t', text: when(e.t) }),
          el('td', { text: e.name || e.user_id }),
          el('td', null, [el('span', { 'class': lab[1], text: lab[0] }), extra]),
          el('td', null, [doorTag(e.door)]),
          el('td', { 'class': 'al-hide-sm' }, [el('code', { text: e.ip || '' })])
        ]);
      });
      box.appendChild(section('Sign-in history', null,
        table(tcls, [['Time'], ['User'], ['What'], ['Door'], ['Address', 'al-hide-sm']], hist, 'Nothing recorded yet.')));

      // --- failed ------------------------------------------------------------
      var fails = d.failed || [];
      var failRows = fails.map(function (f) {
        return el('tr', null, [
          el('td', { 'class': 'al-mute al-t', text: when(f.t) || f.time }),
          el('td', null, [f.name ? f.name : el('code', { text: f.username })]),
          el('td', null, [el('span', { 'class': f.kind.indexOf('unknown') === 0 ? 'al-warn' : 'al-bad', text: f.kind })]),
          el('td', null, [f.door ? doorTag(f.door) : el('span', { 'class': 'al-mute', text: f.server || '—' })])
        ]);
      });
      box.appendChild(section('Failed sign-ins', fails.length, el('div', null, [
        table(tcls, [['Time'], ['User'], ['Problem'], ['Door']], failRows, 'No failed sign-ins in the recent PBX log.'),
        el('div', { 'class': 'al-mute', style: 'padding:4px 6px', text: 'Wrong passwords, unknown API usernames and wrong DISA PINs from the PBX log. Logins for anything that is not an API account are refused by the gateway before they reach the PBX.' })
      ])));
      tickAge();
    }

    function tickAge() {
      var a = box.querySelector('#al-age');
      if (a) {
        var age = gotAt ? Math.round((Date.now() - gotAt) / 1000) : null;
        a.textContent = (age === null ? 'Live' : 'Updated ' + (age < 2 ? 'just now' : age + 's ago')) +
          (opts.url ? (paused ? ' · auto-refresh paused' : ' · refreshes every ' + Math.round((opts.interval || 5000) / 1000) + 's') : '');
      }
      var add = gotAt ? (Date.now() - gotAt) / 1000 : 0;
      box.querySelectorAll('.al-timer').forEach(function (t) { t.textContent = clock(+t.getAttribute('data-s') + add); });
    }

    function take(j) {
      data = j; gotAt = Date.now(); err = '';
      if (j && j.now) skew = j.now - Date.now() / 1000;
      render();
    }

    function load() {
      if (!opts.url) return;
      fetch(opts.url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function (j) {
          if (!j || j.ok === false) throw new Error((j && j.error) || 'no data');
          take(j);
        })
        .catch(function (e) {
          err = 'Auto-refresh failed (' + e.message + '). Showing the last data; reload the page to update.';
          render();
        });
    }

    function schedule() {
      clearInterval(timer);
      if (!opts.url) return;
      timer = setInterval(function () { if (!paused && !document.hidden) load(); }, opts.interval || 5000);
    }

    if (opts.initial) take(opts.initial); else { render(); load(); }
    schedule();
    ticking = setInterval(tickAge, 1000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !paused) load(); });
    return { reload: load };
  }

  root.ApiUsersLive = ApiUsersLive;
})(window);
