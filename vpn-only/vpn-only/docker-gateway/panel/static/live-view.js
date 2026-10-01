/*
 * API Users – tabs + Live dashboard + Sign-in history + Failed sign-ins.
 * Same file on the PBX page (inlined by views/page.php) and the remote panel (/static/live-view.js).
 * SOURCE COPY: pbx-module/apiusers/assets/live-view.js -> run scripts/sync-assets.sh after editing.
 *
 *   var tabs = ApiUsersTabs(document.getElementById('apiu-tabs'));
 *   ApiUsersLive({
 *     tabs:       tabs,                          tab bar (counts are written into it)
 *     dash:       element, history: element, failed: element
 *     initial:    {...} | null                   first data set for the dashboard
 *     url:        live data URL                  (PBX: ajax.php?module=apiusers&command=live, panel: /live.json)
 *     historyUrl: full lists URL                 (PBX: ...&command=history, panel: /history.json)
 *     interval:   5000                           ms between refreshes (paused while the tab is hidden)
 *     hangup:     {url, fields}                  POST form for "Hang up"
 *     unlock:     {url, fields} | null           POST form for "Unlock now" on a locked DISA code (PBX page only)
 *     del:        {url, fields, idsName}         POST form for bulk delete (adds kind, ids / all=1)
 *     disa:       true|false                     edition has DISA dial-out codes
 *     light:      true                           host page is always light (FreePBX): no dark-mode colors
 *   })
 *
 * All data goes into the page with textContent, never innerHTML.
 */
(function (root) {
  'use strict';

  var DOORS = { vpn: 'VPN', lan: 'Home Wi-Fi', pub: 'Public door' };
  var CSS = [
    /* tabs */
    '.apiu-tabs .at-bar{display:flex;gap:4px;overflow-x:auto;border-bottom:1px solid rgba(128,128,128,.3);margin:6px 0 14px;scrollbar-width:thin}',
    '.apiu-tabs .at-bar button{font:inherit;font-size:14px;background:none;border:0;border-bottom:3px solid transparent;color:inherit;opacity:.7;padding:9px 12px 8px;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:7px}',
    '.apiu-tabs .at-bar button:hover{opacity:1}',
    '.apiu-tabs .at-bar button.at-on{opacity:1;font-weight:600;border-bottom-color:#1f6feb}',
    '.apiu-tabs .at-n{display:inline-block;min-width:20px;padding:0 6px;border-radius:99px;font-size:11.5px;line-height:18px;text-align:center;background:rgba(128,128,128,.18);font-weight:600}',
    '.apiu-tabs .at-n.at-bad{background:#cf222e;color:#fff}',
    '.apiu-tabs .at-n:empty{display:none}',
    '.apiu-tabs > section[data-panel]{display:none}.apiu-tabs > section.at-show{display:block}',
    /* dashboard */
    '.apiu-live{--ok:#1a7f37;--warn:#9a6700;--bad:#cf222e;--info:#1f6feb;--line:rgba(128,128,128,.25);--soft:rgba(128,128,128,.07)}',
    '@media (prefers-color-scheme:dark){.apiu-live:not(.apiu-light){--ok:#3fb950;--warn:#d29922;--bad:#ff7b72;--info:#58a6ff}}',
    '.apiu-live .ad-top{display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;margin-bottom:10px}',
    '.apiu-live .ad-mute{opacity:.66;font-size:12.5px}',
    '.apiu-live .ad-live{display:inline-flex;align-items:center;gap:6px;font-size:12.5px}',
    '.apiu-live .ad-pulse{width:8px;height:8px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 0 rgba(26,127,55,.5);animation:adp 2s infinite}',
    '.apiu-live .ad-paused .ad-pulse{background:#999;animation:none}',
    '@keyframes adp{0%{box-shadow:0 0 0 0 rgba(63,185,80,.45)}70%{box-shadow:0 0 0 7px rgba(63,185,80,0)}100%{box-shadow:0 0 0 0 rgba(63,185,80,0)}}',
    '.apiu-live .ad-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}',
    '.apiu-live .ad-tile{border:1px solid var(--line);border-radius:10px;padding:10px 14px;background:var(--soft);position:relative;overflow:hidden}',
    '.apiu-live .ad-tile:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--c,#888)}',
    '.apiu-live .ad-tile b{display:block;font-size:28px;line-height:1.15;font-variant-numeric:tabular-nums;font-weight:700}',
    '.apiu-live .ad-tile span{font-size:12px;opacity:.72;text-transform:uppercase;letter-spacing:.04em}',
    '.apiu-live .ad-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:12px;align-items:start}',
    '.apiu-live .ad-col{display:grid;gap:12px;min-width:0}',
    '.apiu-live .ad-card{border:1px solid var(--line);border-radius:10px;overflow:hidden;min-width:0}',
    '.apiu-live .ad-card > header{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 14px;background:var(--soft);border-bottom:1px solid var(--line)}',
    '.apiu-live .ad-card > header h4{margin:0;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;display:flex;align-items:center;gap:8px}',
    '.apiu-live .ad-card > header a{font-size:12.5px;cursor:pointer;color:var(--info);text-decoration:none}',
    '.apiu-live .ad-row{display:flex;gap:11px;align-items:center;padding:9px 14px;border-bottom:1px solid var(--line);min-width:0}',
    '.apiu-live .ad-row:last-child{border-bottom:0}',
    '.apiu-live .ad-av{flex:none;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-weight:700;font-size:13px;background:rgba(31,111,235,.14);color:var(--info);position:relative}',
    '.apiu-live .ad-av i{position:absolute;right:-1px;bottom:-1px;width:11px;height:11px;border-radius:50%;border:2px solid #fff;background:var(--c,#999)}',
    '.apiu-live .ad-main{flex:1;min-width:0}',
    '.apiu-live .ad-l1{display:flex;flex-wrap:wrap;align-items:center;gap:4px 7px;font-weight:600}',
    '.apiu-live .ad-l2{font-size:12.5px;opacity:.75;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.apiu-live .ad-side{flex:none;text-align:right;font-size:12.5px}',
    '.apiu-live .ad-empty{padding:16px 14px;opacity:.6;font-size:13px}',
    '.apiu-live .ad-tag{display:inline-block;font-size:11px;line-height:1.55;padding:0 7px;border-radius:99px;border:1px solid currentColor;white-space:nowrap;font-weight:500}',
    '.apiu-live .ad-ok{color:var(--ok)}.apiu-live .ad-warn{color:var(--warn)}.apiu-live .ad-bad{color:var(--bad)}.apiu-live .ad-info{color:var(--info)}',
    '.apiu-live .ad-timer{font-variant-numeric:tabular-nums;font-weight:700;font-size:15px}',
    '.apiu-live .ad-alert{border-left:4px solid var(--bad);background:rgba(207,34,46,.07);padding:8px 12px;margin:0 0 10px;border-radius:4px;font-size:13.5px}',
    '.apiu-live .ad-note{border-left:4px solid var(--warn);background:rgba(154,103,0,.07);padding:8px 12px;margin:0 0 10px;border-radius:4px;font-size:13.5px}',
    '.apiu-live button.ad-btn{font:inherit;font-size:12.5px;padding:4px 10px;border-radius:6px;cursor:pointer;border:1px solid var(--bad);color:var(--bad);background:transparent}',
    '.apiu-live button.ad-btn:disabled{opacity:.4;cursor:default}',
    '.apiu-live button.ad-ghost{border-color:var(--line);color:inherit}',
    '.apiu-live code{font-size:12px}',
    '.apiu-live .ad-foot{padding:7px 14px;font-size:12.5px;opacity:.7;border-top:1px solid var(--line)}',
    /* full lists */
    '.apiu-live .ad-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:8px}',
    '.apiu-live .ad-bar .ad-grow{flex:1}',
    '.apiu-live table.ad-t{width:100%;border-collapse:collapse}',
    '.apiu-live table.ad-t td,.apiu-live table.ad-t th{text-align:left;padding:7px 8px;border-bottom:1px solid var(--line);font-size:13.5px;vertical-align:middle}',
    '.apiu-live table.ad-t th{font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;opacity:.7}',
    '.apiu-live table.ad-t tr.ad-sel td{background:rgba(31,111,235,.08)}',
    '.apiu-live table.ad-t input{margin:0}',
    '.apiu-live .ad-scroll{overflow-x:auto;border:1px solid var(--line);border-radius:10px}',
    '@media (max-width:900px){.apiu-live .ad-grid{grid-template-columns:minmax(0,1fr)}.apiu-live .ad-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}',
    '@media (max-width:640px){.apiu-live .ad-hide-sm{display:none}}'
  ].join('\n');

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      if (attrs[k] === null || attrs[k] === undefined || attrs[k] === false) continue;
      if (k === 'class') e.className = attrs[k];
      else if (k === 'text') e.textContent = attrs[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
      else if (k === 'checked') e.checked = !!attrs[k];
      else e.setAttribute(k, attrs[k]);
    }
    (kids || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return e;
  }
  function css() {
    if (!document.getElementById('apiu-live-css')) document.head.appendChild(el('style', { id: 'apiu-live-css', text: CSS }));
  }
  function tag(text, cls) { return el('span', { 'class': 'ad-tag ' + (cls || ''), text: text }); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function dur(s) {
    s = Math.max(0, Math.round(s));
    var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
    if (d) return d + 'd ' + h + 'h';
    if (h) return h + 'h ' + m + 'm';
    if (m) return m + 'm';
    return s + 's';
  }
  function clock(s) { s = Math.max(0, Math.floor(s)); var h = Math.floor(s / 3600); return (h ? h + ':' + pad(Math.floor(s % 3600 / 60)) : Math.floor(s / 60)) + ':' + pad(s % 60); }
  function when(epoch) {
    if (!epoch) return '';
    var d = new Date(epoch * 1000), now = new Date();
    var t = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (d.toDateString() === now.toDateString()) return t;
    var y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return 'Yesterday ' + t;
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + t;
  }
  function doorTag(d) { return d ? tag(DOORS[d] || d, d === 'pub' ? 'ad-warn' : 'ad-info') : null; }
  function typeTag(pub) { return tag(pub ? 'PUBLIC' : 'VPN', pub ? 'ad-warn' : 'ad-info'); }
  function initials(n) {
    var p = String(n || '?').replace(/[^A-Za-z0-9 _-]/g, '').split(/[\s_-]+/).filter(Boolean);
    return ((p[0] || '?').charAt(0) + (p[1] ? p[1].charAt(0) : (p[0] || '').charAt(1) || '')).toUpperCase();
  }
  var EV = { 'in': ['Signed in', 'ad-ok'], out: ['Signed out', ''], moved: ['Changed network', 'ad-info'], seen: ['Already signed in', ''] };
  function evText(e) {
    var lab = EV[e.ev] || [e.ev, ''];
    var extra = e.ev === 'out' && e.dur ? ' after ' + dur(e.dur) : (e.ev === 'moved' && e.from ? ' (was ' + e.from + ')' : '');
    return [el('span', { 'class': lab[1], text: lab[0] }), extra ? el('span', { 'class': 'ad-mute', text: extra }) : null];
  }
  function post(cfg, extra) {
    var f = el('form', { method: 'post', action: cfg.url, style: 'display:none' });
    var fields = Object.assign({}, cfg.fields || {});
    Object.keys(fields).forEach(function (k) { f.appendChild(el('input', { type: 'hidden', name: k, value: fields[k] })); });
    (extra || []).forEach(function (kv) { f.appendChild(el('input', { type: 'hidden', name: kv[0], value: kv[1] })); });
    document.body.appendChild(f); f.submit();
  }

  // ------------------------------------------------------------------ tabs
  function ApiUsersTabs(box) {
    css();
    var listeners = [];
    var btns = box.querySelectorAll(':scope > .at-bar button[data-tab]');
    function names() { return Array.prototype.map.call(btns, function (b) { return b.getAttribute('data-tab'); }); }
    function current() { var b = box.querySelector(':scope > .at-bar button.at-on'); return b ? b.getAttribute('data-tab') : null; }
    function show(name, noHash) {
      if (names().indexOf(name) < 0) name = names()[0];
      Array.prototype.forEach.call(btns, function (b) { b.classList.toggle('at-on', b.getAttribute('data-tab') === name); });
      box.querySelectorAll(':scope > section[data-panel]').forEach(function (s) { s.classList.toggle('at-show', s.getAttribute('data-panel') === name); });
      if (!noHash) { try { history.replaceState(null, '', location.pathname + location.search + '#tab=' + name); } catch (e) {} }
      listeners.forEach(function (fn) { fn(name); });
    }
    Array.prototype.forEach.call(btns, function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-tab')); }); });
    var m = /tab=([a-z]+)/.exec(location.hash || '');
    show(m ? m[1] : (box.getAttribute('data-default') || 'dash'), !m);
    return {
      show: show, current: current,
      onChange: function (fn) { listeners.push(fn); },
      setCount: function (name, n) {
        var c = box.querySelector(':scope > .at-bar [data-count="' + name + '"]');
        if (c) c.textContent = (n === null || n === undefined) ? '' : String(n);
      }
    };
  }

  // ------------------------------------------------------------------ live
  function ApiUsersLive(opts) {
    css();
    var tabs = opts.tabs, data = null, hist = null, gotAt = 0, histAt = 0, skew = 0, paused = false, err = '', histErr = '';
    var sel = { history: {}, failed: {} };
    [opts.dash, opts.history, opts.failed].forEach(function (b) {
      if (b) { b.classList.add('apiu-live'); if (opts.light) b.classList.add('apiu-light'); }
    });
    function nowS() { return Date.now() / 1000 + skew; }

    function hangupBtn(c) {
      if (!opts.hangup) return null;
      return el('button', { 'class': 'ad-btn', type: 'button', onclick: function () {
        if (confirm('Hang up ' + c.name + "'s call now?")) post(opts.hangup, [['channel', c.channel]]);
      } }, ['Hang up']);
    }
    function unlockBtn(id, name) {
      if (!opts.unlock) return null;
      return el('button', { 'class': 'ad-btn ad-ghost', type: 'button', style: 'margin-left:8px', onclick: function () {
        if (confirm('Unlock ' + name + "'s dial-out code now? Only do this if you know the wrong PINs were theirs.")) post(opts.unlock, [['id', id]]);
      } }, ['Unlock now']);
    }
    function card(title, count, link, body, foot) {
      return el('div', { 'class': 'ad-card' }, [
        el('header', null, [
          el('h4', null, [title, count === null ? null : el('span', { 'class': 'ad-tag', text: String(count) })]),
          link ? el('a', { onclick: function () { tabs && tabs.show(link); } }, ['View all →']) : null
        ]),
        body, foot || null]);
    }
    function tile(n, label, color) {
      return el('div', { 'class': 'ad-tile', style: '--c:' + color }, [el('b', { text: String(n) }), el('span', { text: label })]);
    }

    // ---------------------------------------------------------- dashboard
    function renderDash() {
      var box = opts.dash; if (!box) return;
      var d = data || {};
      box.textContent = '';
      box.appendChild(el('div', { 'class': 'ad-top' + (paused ? ' ad-paused' : '') }, [
        el('span', { 'class': 'ad-live' }, [el('span', { 'class': 'ad-pulse' }), el('span', { 'class': 'ad-mute', id: 'ad-age' })]),
        opts.url ? el('button', { 'class': 'ad-btn ad-ghost', type: 'button', onclick: function () {
          paused = !paused; if (!paused) load(); renderDash();
        } }, [paused ? 'Resume' : 'Pause']) : null,
        opts.url ? el('button', { 'class': 'ad-btn ad-ghost', type: 'button', onclick: function () { load(); } }, ['Refresh']) : null
      ]));
      if (err) box.appendChild(el('div', { 'class': 'ad-note', text: err }));
      if (d.warning) box.appendChild(el('div', { 'class': 'ad-note', text: d.warning }));
      if (d.kill_switch) box.appendChild(el('div', { 'class': 'ad-alert', text: 'Kill switch is engaged: nobody can sign in or call.' }));
      var names = {}; (d.users || []).forEach(function (u) { names[u.id] = u.name; });
      Object.keys(d.disa_locks || {}).forEach(function (id) {
        box.appendChild(el('div', { 'class': 'ad-alert' }, [(names[id] || id) + "'s dial-out code is locked after 5 wrong PINs until " + when(d.disa_locks[id]) + '.', unlockBtn(id, names[id] || id)]));
      });

      var devs = d.devices || [], calls = d.calls || [], st = d.stats || {};
      var enabled = (d.users || []).filter(function (u) { return u.enabled; }).length;
      box.appendChild(el('div', { 'class': 'ad-tiles' }, [
        tile(devs.length, 'Signed in' + (enabled ? ' of ' + enabled : ''), 'var(--ok)'),
        tile(calls.length, 'Live calls', 'var(--info)'),
        tile(st.signins_24h || 0, 'Sign-ins, 24 h', '#8250df'),
        tile(st.failed_24h || 0, 'Failed, 24 h', (st.failed_24h ? 'var(--bad)' : '#888'))
      ]));

      // left: signed in + live calls
      var devRows = devs.map(function (x) {
        var online = x.status === 'Avail' || x.status === 'Reachable';
        var unk = x.status === 'Unknown' || x.status === 'NonQual';
        var color = online ? 'var(--ok)' : (unk ? '#999' : 'var(--warn)');
        return el('div', { 'class': 'ad-row' }, [
          el('div', { 'class': 'ad-av', style: '--c:' + color, title: online ? 'Online' : (unk ? 'Checking' : 'Not answering') }, [initials(x.name), el('i')]),
          el('div', { 'class': 'ad-main' }, [
            el('div', { 'class': 'ad-l1' }, [x.name, typeTag(x.public), doorTag(x.door)]),
            el('div', { 'class': 'ad-l2', title: x.app || '' }, [(x.ip ? x.ip + (x.port ? ':' + x.port : '') : '') + (x.app ? ' · ' + x.app : '')])
          ]),
          el('div', { 'class': 'ad-side' }, [
            el('div', { 'class': online ? 'ad-ok' : (unk ? 'ad-mute' : 'ad-warn'),
              text: online ? (x.rtt_ms !== null && x.rtt_ms !== undefined ? Math.round(x.rtt_ms) + ' ms' : 'Online') : (unk ? 'Checking…' : 'No answer') }),
            el('div', { 'class': 'ad-mute', text: x.since ? dur(nowS() - x.since) : '' })
          ])
        ]);
      });
      var warn = devs.filter(function (x) { return x.warn; }).map(function (x) {
        return el('div', { 'class': 'ad-alert', style: 'margin:8px 12px', text: '⚠ ' + x.name + ': ' + x.warn });
      });
      var signedIds = {}; devs.forEach(function (x) { signedIds[x.user_id] = true; });
      var offline = (d.users || []).filter(function (u) { return u.enabled && !signedIds[u.id]; }).map(function (u) { return u.name; });
      var left = el('div', { 'class': 'ad-col' }, [
        card('Signed in now', devs.length, null,
          el('div', null, devRows.length ? devRows.concat(warn) : [el('div', { 'class': 'ad-empty', text: 'Nobody is signed in.' })]),
          offline.length ? el('div', { 'class': 'ad-foot', text: 'Not signed in: ' + offline.join(', ') }) : null),
        card('Live calls', calls.length, null, el('div', null, calls.length ? calls.map(function (c) {
          var other = c.other ? (c.other.indexOf('DISA-') === 0 ? 'DISA ' + c.other.slice(5) : c.other) : '…';
          var stCls = /Blocked/.test(c.state) ? 'ad-bad' : (c.state === 'Talking' ? 'ad-ok' : 'ad-mute');
          return el('div', { 'class': 'ad-row' }, [
            el('div', { 'class': 'ad-av', style: '--c:' + (c.state === 'Talking' ? 'var(--ok)' : 'var(--warn)') }, [c.direction === 'in' ? '↓' : '↑', el('i')]),
            el('div', { 'class': 'ad-main' }, [
              el('div', { 'class': 'ad-l1' }, [c.name, el('span', { 'class': 'ad-mute', text: c.direction === 'in' ? '← from' : '→' }), el('span', { text: other }), doorTag(c.door)]),
              el('div', { 'class': 'ad-l2' }, [el('span', { 'class': stCls, text: c.state })])
            ]),
            el('div', { 'class': 'ad-side' }, [el('div', { 'class': 'ad-timer', 'data-s': String(c.seconds), text: clock(c.seconds) }), hangupBtn(c)])
          ]);
        }) : [el('div', { 'class': 'ad-empty', text: 'No calls right now.' })]))
      ]);

      // right: last 5 sign-ins + last 5 failed
      var sRows = (d.signins || []).map(function (e) {
        return el('div', { 'class': 'ad-row' }, [
          el('div', { 'class': 'ad-main' }, [
            el('div', { 'class': 'ad-l1' }, [e.name || e.user_id, doorTag(e.door)]),
            el('div', { 'class': 'ad-l2' }, evText(e))
          ]),
          el('div', { 'class': 'ad-side ad-mute', text: when(e.t) })
        ]);
      });
      var fRows = (d.failed || []).map(function (f) {
        return el('div', { 'class': 'ad-row' }, [
          el('div', { 'class': 'ad-main' }, [
            el('div', { 'class': 'ad-l1' }, [f.name ? f.name : el('code', { text: f.username }), doorTag(f.door)]),
            el('div', { 'class': 'ad-l2' }, [el('span', { 'class': f.kind.indexOf('unknown') === 0 ? 'ad-warn' : 'ad-bad', text: f.kind })])
          ]),
          el('div', { 'class': 'ad-side ad-mute', text: when(f.t) || f.time })
        ]);
      });
      var right = el('div', { 'class': 'ad-col' }, [
        card('Last 5 sign-ins', null, 'history', el('div', null, sRows.length ? sRows : [el('div', { 'class': 'ad-empty', text: 'Nothing recorded yet.' })])),
        card('Last 5 failed sign-ins', null, 'failed', el('div', null, fRows.length ? fRows : [el('div', { 'class': 'ad-empty', text: 'No failed sign-ins.' })]))
      ]);
      box.appendChild(el('div', { 'class': 'ad-grid' }, [left, right]));
      tickAge();
    }

    // ---------------------------------------------------------- full lists
    function renderList(kind) {
      var box = kind === 'history' ? opts.history : opts.failed; if (!box) return;
      box.textContent = '';
      var rows = hist ? (kind === 'history' ? hist.signins : hist.failed) : null;
      var picked = sel[kind];
      if (rows) { var live = {}; rows.forEach(function (r) { live[r.id] = 1; }); Object.keys(picked).forEach(function (id) { if (!live[id]) delete picked[id]; }); }
      var nSel = Object.keys(picked).length;
      var kindName = kind === 'history' ? 'signins' : 'failed';
      function del(all) {
        if (!opts.del) return;
        var n = all ? rows.length : nSel;
        var what = kind === 'history' ? 'sign-in history entr' : 'failed sign-in entr';
        if (!confirm((all ? 'Delete ALL ' : 'Delete ') + n + ' ' + what + (n === 1 ? 'y' : 'ies') + '? This is written to the audit log.')) return;
        var extra = [['kind', kindName]];
        if (all) extra.push(['all', '1']); else Object.keys(picked).forEach(function (id) { extra.push([opts.del.idsName || 'ids', id]); });
        post({ url: opts.del.url + '#tab=' + kind, fields: opts.del.fields }, extra);
      }
      box.appendChild(el('div', { 'class': 'ad-bar' }, [
        el('span', { 'class': 'ad-mute ad-grow', text: rows === null ? (histErr || 'Loading…') :
          rows.length + (kind === 'history' ? ' entries (newest first, last 300 kept)' : ' failed sign-ins in the recent PBX log') + (nSel ? ' · ' + nSel + ' selected' : '') }),
        opts.del ? el('button', { 'class': 'ad-btn', type: 'button', disabled: !nSel, onclick: function () { del(false); } }, ['Delete selected' + (nSel ? ' (' + nSel + ')' : '')]) : null,
        opts.del ? el('button', { 'class': 'ad-btn', type: 'button', disabled: !(rows && rows.length), onclick: function () { del(true); } }, ['Delete all']) : null
      ]));
      if (rows === null) return;
      if (!rows.length) { box.appendChild(el('div', { 'class': 'ad-scroll' }, [el('div', { 'class': 'ad-empty', text: kind === 'history' ? 'Nothing recorded.' : 'No failed sign-ins.' })])); return; }
      var allOn = rows.length && nSel === rows.length;
      var head = el('input', { type: 'checkbox', title: 'Select all', checked: allOn, onchange: function () {
        rows.forEach(function (r) { if (head.checked) picked[r.id] = 1; else delete picked[r.id]; }); renderList(kind);
      } });
      var trs = rows.map(function (r) {
        var cb = el('input', { type: 'checkbox', checked: !!picked[r.id], onchange: function () { if (cb.checked) picked[r.id] = 1; else delete picked[r.id]; renderList(kind); } });
        var cells = kind === 'history'
          ? [el('td', { 'class': 'ad-mute', text: when(r.t) }), el('td', { text: r.name || r.user_id }), el('td', null, evText(r)),
             el('td', null, [doorTag(r.door)]), el('td', { 'class': 'ad-hide-sm' }, [el('code', { text: r.ip || '' })]), el('td', { 'class': 'ad-hide-sm ad-mute', text: r.app || '' })]
          : [el('td', { 'class': 'ad-mute', text: when(r.t) || r.time }), el('td', null, [r.name ? r.name : el('code', { text: r.username })]),
             el('td', null, [el('span', { 'class': r.kind.indexOf('unknown') === 0 ? 'ad-warn' : 'ad-bad', text: r.kind })]),
             el('td', null, [r.door ? doorTag(r.door) : el('span', { 'class': 'ad-mute', text: r.server || '—' })])];
        return el('tr', { 'class': picked[r.id] ? 'ad-sel' : '' }, [el('td', { style: 'width:28px' }, [cb])].concat(cells));
      });
      var hcells = kind === 'history' ? ['Time', 'User', 'What', 'Door', 'Address', 'App'] : ['Time', 'User', 'Problem', 'Door'];
      box.appendChild(el('div', { 'class': 'ad-scroll' }, [el('table', { 'class': 'ad-t' }, [
        el('thead', null, [el('tr', null, [el('th', null, [head])].concat(hcells.map(function (h, i) {
          return el('th', { 'class': (kind === 'history' && i >= 4) ? 'ad-hide-sm' : null, text: h });
        })))]),
        el('tbody', null, trs)
      ])]));
      if (kind === 'failed') box.appendChild(el('p', { 'class': 'ad-mute', style: 'margin:8px 2px', text:
        (opts.disa ? 'Wrong passwords, unknown API usernames and wrong DISA PINs' : 'Wrong passwords and unknown API usernames') +
        ' from the PBX log. Deleting only hides them here (the PBX log is not changed). Logins for anything that is not an API account are refused by the gateway before they reach the PBX.' }));
    }

    function tickAge() {
      var a = opts.dash && opts.dash.querySelector('#ad-age');
      if (a) {
        var age = gotAt ? Math.round((Date.now() - gotAt) / 1000) : null;
        a.textContent = paused ? 'Paused' : (age === null ? 'Live' : (age < 2 ? 'Live · updated just now' : 'Live · updated ' + age + 's ago'));
      }
      var add = gotAt ? (Date.now() - gotAt) / 1000 : 0;
      if (opts.dash) opts.dash.querySelectorAll('.ad-timer').forEach(function (t) { t.textContent = clock(+t.getAttribute('data-s') + add); });
    }

    function counts() {
      if (!tabs) return;
      if (data) {
        tabs.setCount('history', data.signins_total);
        tabs.setCount('failed', data.failed_total || null);
      }
      if (hist) {
        tabs.setCount('history', hist.signins.length);
        tabs.setCount('failed', hist.failed.length || null);
      }
    }

    function getJSON(url) {
      return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (j) { if (!j || j.ok === false) throw new Error((j && j.error) || 'no data'); return j; });
    }
    function load() {
      if (!opts.url) return;
      getJSON(opts.url).then(function (j) {
        data = j; gotAt = Date.now(); err = ''; if (j.now) skew = j.now - Date.now() / 1000;
        if (hist) { hist = null; }          // totals changed: list tabs re-fetch when shown
        counts(); renderDash();
        var cur = tabs && tabs.current();
        if (cur === 'history' || cur === 'failed') loadHist();
      }).catch(function (e) {
        err = 'Auto-refresh failed (' + e.message + '). Showing the last data; reload the page to update.'; renderDash();
      });
    }
    function loadHist() {
      if (!opts.historyUrl) return;
      getJSON(opts.historyUrl).then(function (j) {
        hist = j; histAt = Date.now(); histErr = ''; counts(); renderList('history'); renderList('failed');
      }).catch(function (e) { histErr = 'Could not load (' + e.message + ')'; renderList('history'); renderList('failed'); });
    }

    if (opts.initial) { data = opts.initial; gotAt = Date.now(); if (data.now) skew = data.now - Date.now() / 1000; counts(); renderDash(); }
    else { renderDash(); load(); }
    renderList('history'); renderList('failed');
    if (tabs) tabs.onChange(function (name) {
      if ((name === 'history' || name === 'failed') && (!hist || Date.now() - histAt > 4000)) loadHist();
      if (name === 'dash' && !paused) load();
    });
    var cur0 = tabs && tabs.current();
    if (cur0 === 'history' || cur0 === 'failed') loadHist();
    setInterval(function () {
      if (paused || document.hidden) return;
      var cur = tabs ? tabs.current() : 'dash';
      if (cur === 'dash') load();
      else if (cur === 'history' || cur === 'failed') { if (Date.now() - histAt > (opts.interval || 5000) * 3) loadHist(); }
    }, opts.interval || 5000);
    setInterval(tickAge, 1000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !paused && (!tabs || tabs.current() === 'dash')) load(); });
    return { reload: load };
  }

  root.ApiUsersTabs = ApiUsersTabs;
  root.ApiUsersLive = ApiUsersLive;
})(window);
