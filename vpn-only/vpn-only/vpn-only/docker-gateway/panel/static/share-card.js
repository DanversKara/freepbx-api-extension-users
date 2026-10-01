/*
 * API Users – share card (vanilla JS, no dependencies except vendored qrcode.js).
 *
 * ONE source, TWO copies (keep them identical):
 *   pbx-module/apiusers/assets/share-card.js     (FreePBX page, inlined by views/page.php)
 *   docker-gateway/panel/static/share-card.js    (remote panel, /static/share-card.js)
 * scripts/sync-assets.sh copies the PBX copy to the panel; run it after editing.
 *
 * Usage:  ApiUsersShareCard(containerElement, {
 *           name, username, password, reach, allowed: ["701"], vpn: "10.0.1.1:5070",
 *           lan: "192.168.8.100:5072", site: "home phone system" })
 *
 * Everything is generated in the browser. Nothing is stored or sent anywhere
 * except where the user explicitly sends it (email draft / share sheet).
 *
 * ZOIPER-PRO-TODO: add a "tls" server line (and "Transport: TLS, SRTP: SDES") when
 *   public TLS exists; the data object will then carry d.tls = "pbx.example.com:5443".
 */
(function () {
  'use strict';

  function h(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }

  // Error correction chosen by length (measured: short codes scan 20/20 at 'H' under
  // blur/noise vs 3/20 at 'L'; long links do best at 'M').
  function makeQr(text) {
    var q = qrcode(0, text.length <= 32 ? 'H' : 'M');
    q.addData(text);
    q.make();
    return q;
  }

  // Pixel-perfect: every module is a whole number of pixels, centred in the box.
  // (Fractional modules made the first version look smeared when zoomed.)
  function drawQr(ctx, q, x, y, size) {
    var n = q.getModuleCount(), quiet = 4, total = n + quiet * 2;
    var cell = Math.max(1, Math.floor(size / total)), used = cell * total;
    var ox = x + Math.floor((size - used) / 2), oy = y + Math.floor((size - used) / 2);
    ctx.fillStyle = '#fff';
    ctx.fillRect(x, y, size, size);
    ctx.fillStyle = '#000';
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
      if (q.isDark(r, c)) ctx.fillRect(ox + (c + quiet) * cell, oy + (r + quiet) * cell, cell, cell);
    }
  }

  // Canvas at an exact multiple of the module grid, shown with pixelated scaling.
  function qrCanvas(text, cssPx, onclick) {
    var q = makeQr(text), total = q.getModuleCount() + 8, cell = 10, px = total * cell;
    var c = h('canvas', { width: px, height: px, title: 'Tap to enlarge',
      style: 'width:' + cssPx + 'px;height:' + cssPx + 'px;image-rendering:pixelated;cursor:zoom-in;border:1px solid #e3e6ea;border-radius:4px' });
    drawQr(c.getContext('2d'), q, 0, 0, px);
    if (onclick) c.addEventListener('click', onclick);
    return c;
  }

  // Full-screen view of one code: easiest for a phone camera to read.
  function zoom(label, value) {
    var side = Math.min(window.innerWidth, window.innerHeight) - 120;
    var ov = h('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:zoom-out' }, [
      qrCanvas(value, Math.max(200, side)),
      h('div', { style: 'color:#fff;font:16px system-ui,sans-serif;margin-top:12px;text-align:center;word-break:break-all;max-width:90vw' },
        [label + ': ' + value]),
      h('div', { style: 'color:#bbb;font:13px system-ui,sans-serif;margin-top:6px' }, ['Scan with the phone camera (not Zoiper). Tap anywhere to close.'])
    ]);
    ov.addEventListener('click', function () { ov.remove(); });
    document.body.appendChild(ov);
  }

  // One small QR per thing they have to type: few characters = big, easy-to-scan codes.
  function qrItems(d, withPw, link) {
    var items = [];
    if (link) items.push({ label: 'VPN invite link', value: link, step: 'vpn' });
    items.push({ label: 'Username', value: d.username });
    if (withPw) items.push({ label: 'Password', value: d.password });
    if (d.vpn) items.push({ label: 'Domain (VPN)', value: d.vpn });
    if (d.lan) items.push({ label: 'Domain (home Wi-Fi)', value: d.lan });
    return items;
  }

  function settingsText(d, withPw) {
    var t = 'Zoiper: Add account > SIP (manual)\n' +
            'Username: ' + d.username + '\n' +
            'Password: ' + (withPw ? d.password : '(sent separately)') + '\n';
    if (d.vpn) t += 'Domain (via VPN): ' + d.vpn + '\n';
    if (d.lan) t += 'Domain (our home Wi-Fi): ' + d.lan + '\n';
    t += 'Transport: UDP   STUN: off';
    return t;
  }

  function fullText(d, withPw, link) {
    var lines = ['Hi ' + d.name + '! Here is your line to my ' + (d.site || 'home phone system') + '.', ''];
    var n = 1;
    if (link) lines.push(n++ + ') Install the VPN app and join with this link:', '   ' + link, '   (Keep "internet exit" / "exit node" OFF.)', '');
    lines.push(n++ + ') Install Zoiper (free) and add a SIP account:');
    settingsText(d, withPw).split('\n').forEach(function (l) { lines.push('   ' + l); });
    lines.push('');
    var calls = (d.allowed && d.allowed.length) ? d.allowed.join(', ') : 'nobody yet';
    lines.push(n++ + ') You can call: ' + calls + '. To reach you, we dial ' + d.reach + '.');
    lines.push('', 'Keep this private: anyone with these details can use your line.');
    return lines.join('\n');
  }

  // ---- PNG card -------------------------------------------------------------
  function wrap(ctx, text, maxW) {
    var out = [];
    text.split('\n').forEach(function (para) {
      var words = para.split(' '), line = '';
      words.forEach(function (w) {
        // break very long tokens (URLs, passwords) character by character
        while (ctx.measureText(w).width > maxW) {
          var i = 1;
          while (i < w.length && ctx.measureText(w.slice(0, i + 1)).width <= maxW) i++;
          if (line) { out.push(line); line = ''; }
          out.push(w.slice(0, i)); w = w.slice(i);
        }
        var t = line ? line + ' ' + w : w;
        if (ctx.measureText(t).width > maxW && line) { out.push(line); line = w; } else line = t;
      });
      out.push(line);
    });
    return out;
  }

  function renderPng(d, withPw, link) {
    var W = 1080, pad = 56, qrSize = 360;
    var c = document.createElement('canvas'), ctx = c.getContext('2d');
    var hasLink = !!link;
    c.width = W;
    c.height = 2600;   // scratch height; cropped to the content at the end
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, c.height);
    ctx.fillStyle = '#1f6feb'; ctx.fillRect(0, 0, W, 150);
    ctx.fillStyle = '#ffffff'; ctx.font = 'bold 54px system-ui, Arial, sans-serif';
    ctx.fillText('Your phone line', pad, 92);
    ctx.font = '28px system-ui, Arial, sans-serif';
    ctx.fillText('for ' + d.name + '  ·  reach #' + d.reach, pad, 132);

    var y = 210, n = 1;
    function title(t) { ctx.fillStyle = '#111'; ctx.font = 'bold 36px system-ui, Arial, sans-serif'; ctx.fillText(t, pad, y); y += 24; }
    function body(text, maxW) {
      ctx.font = '28px ui-monospace, Menlo, Consolas, monospace'; ctx.fillStyle = '#222';
      var ty = y + 36; wrap(ctx, text, maxW).forEach(function (l) { ctx.fillText(l, pad, ty); ty += 40; }); return ty;
    }
    if (hasLink) {
      title(n++ + '. Join the VPN');
      var ty = body('Install the VPN app, then open this link (or scan the code).\n\nKeep "internet exit" / "exit node" OFF.\n\n' + link, W - pad * 3 - qrSize);
      drawQr(ctx, makeQr(link), W - pad - qrSize, y, qrSize);
      ctx.font = '22px system-ui, Arial, sans-serif'; ctx.fillStyle = '#555';
      ctx.fillText('scan with the phone camera', W - pad - qrSize + 10, y + qrSize + 30);
      y = Math.max(ty, y + qrSize + 50) + 40;
    }
    title(n++ + '. Add to Zoiper (free)');
    y = body(settingsText(d, withPw), W - pad * 2) + 10;
    // row of small codes, one per field
    var items = qrItems(d, withPw, null), cols = items.length, gap = 24;
    var tileW = Math.floor((W - pad * 2 - gap * (cols - 1)) / cols), qs = Math.min(tileW, 230);
    var maxLines = 1;
    items.forEach(function (it, i) {
      var x = pad + i * (tileW + gap);
      drawQr(ctx, makeQr(it.value), x, y, qs);
      ctx.fillStyle = '#111'; ctx.font = 'bold 20px system-ui, Arial, sans-serif';
      ctx.fillText(it.label, x, y + qs + 28);
      ctx.font = '20px ui-monospace, Menlo, Consolas, monospace'; ctx.fillStyle = '#333';
      var lines = wrap(ctx, it.value, tileW), vy = y + qs + 56;
      maxLines = Math.max(maxLines, lines.length);
      lines.forEach(function (l) { ctx.fillText(l, x, vy); vy += 26; });
    });
    y += qs + 56 + 26 * maxLines + 24;
    ctx.font = '22px system-ui, Arial, sans-serif'; ctx.fillStyle = '#555';
    ctx.fillText('Scan each code with the phone camera, copy, and paste into the matching Zoiper field.', pad, y); y += 70;
    ctx.fillStyle = '#111'; ctx.font = 'bold 36px system-ui, Arial, sans-serif';
    ctx.fillText(n + '. Call', pad, y); y += 50;
    ctx.font = '28px system-ui, Arial, sans-serif'; ctx.fillStyle = '#222';
    wrap(ctx, 'You can call: ' + ((d.allowed && d.allowed.length) ? d.allowed.join(', ') : 'nobody yet') +
              '.  To reach you, we dial ' + d.reach + '.', W - pad * 2).forEach(function (l) { ctx.fillText(l, pad, y); y += 40; });
    y += 30;
    ctx.fillStyle = '#b42318'; ctx.font = 'bold 24px system-ui, Arial, sans-serif';
    ctx.fillText('Keep this private: anyone with these details can use your line.', pad, y);
    var out = document.createElement('canvas');
    out.width = W; out.height = y + 50;
    out.getContext('2d').drawImage(c, 0, 0);
    return out;
  }

  function copy(text, btn) {
    function done(ok) { var o = btn.textContent; btn.textContent = ok ? 'Copied ✓' : 'Copy failed'; setTimeout(function () { btn.textContent = o; }, 1500); }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
      return;
    }
    // http:// fallback (the FreePBX page on the LAN is usually plain http)
    var ta = h('textarea', { style: 'position:fixed;left:-9999px' }); ta.value = text;
    document.body.appendChild(ta); ta.select();
    var ok = false; try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta); done(ok);
  }

  function safeName(s) { return String(s).replace(/[^A-Za-z0-9_-]+/g, '_') || 'user'; }

  window.ApiUsersShareCard = function (root, d) {
    root.innerHTML = '';
    var linkIn = h('input', { type: 'text', placeholder: 'Optional: paste their AstroWarp / Tailscale / WireGuard invite link',
                              style: 'width:100%;padding:7px 9px;border:1px solid #c9ced6;border-radius:7px;font:inherit;margin:6px 0' });
    var pw = h('input', { type: 'checkbox', checked: 'checked' });
    var preview = h('div', { style: 'display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;margin:10px 0' });
    var btnStyle = 'font:inherit;font-size:13px;padding:6px 12px;border-radius:7px;border:1px solid #c9ced6;background:#fff;color:#111;cursor:pointer;margin:2px 4px 2px 0';

    function state() { return { link: linkIn.value.trim(), withPw: pw.checked }; }

    function refresh() {
      var s = state();
      preview.innerHTML = '';
      var tiles = h('div', { style: 'display:flex;gap:14px;flex-wrap:wrap' });
      qrItems(d, s.withPw, s.link).forEach(function (it) {
        tiles.appendChild(h('div', { style: 'text-align:center;font-size:12px;width:150px' }, [
          qrCanvas(it.value, 140, function () { zoom(it.label, it.value); }),
          h('div', { style: 'font-weight:600;margin-top:4px' }, [it.label]),
          h('div', { style: 'font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;color:#333' }, [it.value])
        ]));
      });
      preview.appendChild(h('div', { style: 'width:100%' }, [tiles,
        h('div', { style: 'font-size:12px;color:#555;margin:6px 0 10px' },
          ['Tap a code to show it full screen. Scan with the phone camera app or Google Lens, not Zoiper\'s own scanner (that one only reads Zoiper\'s paid provisioning codes).'])]));
      preview.appendChild(h('pre', { style: 'flex:1;min-width:240px;white-space:pre-wrap;font-size:12px;margin:0;padding:10px;background:#f6f7f9;color:#111;border-radius:7px' },
        [fullText(d, s.withPw, s.link)]));
    }

    var bDownload = h('button', { type: 'button', style: btnStyle, onclick: function () {
      var s = state(), c = renderPng(d, s.withPw, s.link);
      c.toBlob(function (b) {
        var a = h('a', { href: URL.createObjectURL(b), download: 'phone-line-' + safeName(d.name) + '.png' });
        document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      });
    } }, ['⬇ Download image']);

    var bCopy = h('button', { type: 'button', style: btnStyle, onclick: function (e) {
      var s = state(); copy(fullText(d, s.withPw, s.link), e.currentTarget);
    } }, ['Copy text']);

    var bEmail = h('button', { type: 'button', style: btnStyle, onclick: function () {
      var s = state();
      location.href = 'mailto:?subject=' + encodeURIComponent('Your phone line, ' + d.name) +
                      '&body=' + encodeURIComponent(fullText(d, s.withPw, s.link));
    } }, ['✉ Email']);

    var bShare = h('button', { type: 'button', style: btnStyle + ';display:none', onclick: function () {
      var s = state(), c = renderPng(d, s.withPw, s.link);
      c.toBlob(function (b) {
        var f = new File([b], 'phone-line-' + safeName(d.name) + '.png', { type: 'image/png' });
        var data = { title: 'Your phone line', text: fullText(d, s.withPw, s.link) };
        if (navigator.canShare && navigator.canShare({ files: [f] })) data.files = [f];
        navigator.share(data).catch(function () {});
      });
    } }, ['Share…']);
    if (navigator.share) bShare.style.display = 'inline-block';

    var bPrint = h('button', { type: 'button', style: btnStyle, onclick: function () {
      var s = state(), url = renderPng(d, s.withPw, s.link).toDataURL('image/png');
      var w = window.open('', '_blank');
      if (!w) return;
      w.document.write('<title>Phone line - ' + safeName(d.name) + '</title><img src="' + url +
                       '" style="max-width:100%" onload="window.print()">');
      w.document.close();
    } }, ['🖨 Print']);

    linkIn.addEventListener('input', refresh);
    pw.addEventListener('change', refresh);

    root.appendChild(h('div', { style: 'margin-top:10px;padding:12px;border:1px dashed #c9ced6;border-radius:9px;background:#fff;color:#111' }, [
      h('b', null, ['Share with ' + d.name]),
      linkIn,
      h('label', { style: 'display:block;font-size:13px;margin:4px 0;color:#111' }, [pw,
        ' Include password (untick to send it separately, e.g. by text, which is safer for email)']),
      preview,
      h('div', null, [bDownload, bCopy, bEmail, bShare, bPrint])
    ]));
    refresh();
  };
})();
