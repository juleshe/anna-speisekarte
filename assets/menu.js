/* Anna Imbiss – Speisekarte: Tabs + „Aufs Handy speichern“ */
(function () {
  'use strict';

  var FONTS = {
    serif: '"AnnaSK Gilda", "Gilda Display", Georgia, serif',
    slab: '"AnnaSK Slab", "Roboto Slab", Georgia, serif',
    sans: '"AnnaSK Sans", Roboto, Arial, sans-serif',
    script: '"AnnaSK Script", "Kaushan Script", cursive'
  };

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function idle(fn) {
    if (window.requestIdleCallback) window.requestIdleCallback(fn, { timeout: 2500 });
    else setTimeout(fn, 800);
  }

  function isIOS() {
    var ua = navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function fontsReady() {
    if (!document.fonts || !document.fonts.load) return Promise.resolve();
    var specs = ['25px "AnnaSK Gilda"', 'bold 21px "AnnaSK Slab"', '600 17px "AnnaSK Slab"', '13px "AnnaSK Sans"', '38px "AnnaSK Script"'];
    return Promise.all(specs.map(function (s) { return document.fonts.load(s, 'ÄÖÜß€1aZ'); })).catch(function () {});
  }

  function loadImg(src) {
    return new Promise(function (res, rej) {
      if (!src) { rej(new Error('kein Hintergrund')); return; }
      var img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = function () { res(img); };
      img.onerror = rej;
      img.src = src;
    });
  }

  function toBlob(canvas) {
    return new Promise(function (res, rej) {
      try {
        canvas.toBlob(function (b) { b ? res(b) : rej(new Error('toBlob')); }, 'image/jpeg', 0.9);
      } catch (e) { rej(e); }
    });
  }

  function renderFile(R, board, bg) {
    var c = document.createElement('canvas');
    c.width = R.W; c.height = R.H;
    R.renderBoard(c.getContext('2d'), board, bg);
    return toBlob(c).catch(function () {
      // Fremd-Hintergrund ohne CORS -> ohne Hintergrund erneut zeichnen
      var c2 = document.createElement('canvas');
      c2.width = R.W; c2.height = R.H;
      R.renderBoard(c2.getContext('2d'), board, null);
      return toBlob(c2);
    }).then(function (blob) {
      return new File([blob], board.file, { type: 'image/jpeg' });
    });
  }

  function makeFiles(data) {
    var R = window.AnnaMenuRenderer;
    if (!R) return Promise.reject(new Error('Renderer fehlt'));
    for (var k in FONTS) R.fonts[k] = FONTS[k];
    return fontsReady().then(function () {
      return Promise.all(data.boards.map(function (b) { return loadImg(b.bg).catch(function () { return null; }); }));
    }).then(function (bgs) {
      // nacheinander, damit schwache Handys nicht 4 Canvas gleichzeitig halten
      var files = [];
      return data.boards.reduce(function (p, b, i) {
        return p.then(function () { return renderFile(R, b, bgs[i]).then(function (f) { files.push(f); }); });
      }, Promise.resolve()).then(function () { return files; });
    });
  }

  /* ---------- Overlay (Fallback & Desktop) ---------- */
  function overlay(files, opener) {
    var urls = files.map(function (f) { return URL.createObjectURL(f); });
    var ov = document.createElement('div');
    ov.className = 'anna-sk-ov';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', 'Speisekarte speichern');
    var html = '<div class="anna-sk-ov__box">' +
      '<button type="button" class="anna-sk-ov__close" aria-label="Schließen">×</button>' +
      '<p class="anna-sk-ov__title">Speisekarte speichern</p>' +
      '<p class="anna-sk-ov__hint">Auf „Speichern“ tippen – oder ein Bild gedrückt halten und „In Fotos sichern“ wählen.</p>' +
      '<button type="button" class="anna-sk-ov__all">Alle Seiten speichern</button><ul class="anna-sk-ov__list">';
    files.forEach(function (f, i) {
      html += '<li><img src="' + urls[i] + '" alt="Speisekarte Seite ' + (i + 1) + '" loading="lazy">' +
        '<a class="anna-sk-ov__dl" href="' + urls[i] + '" download="' + f.name + '">Seite ' + (i + 1) + ' speichern</a></li>';
    });
    html += '</ul></div>';
    ov.innerHTML = html;
    document.body.appendChild(ov);
    document.documentElement.classList.add('anna-sk-noscroll');

    function close() {
      document.removeEventListener('keydown', onKey);
      ov.remove();
      document.documentElement.classList.remove('anna-sk-noscroll');
      urls.forEach(function (u) { URL.revokeObjectURL(u); });
      if (opener) opener.focus();
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    ov.querySelector('.anna-sk-ov__close').addEventListener('click', close);
    ov.querySelector('.anna-sk-ov__all').addEventListener('click', function () {
      var links = ov.querySelectorAll('.anna-sk-ov__dl');
      [].forEach.call(links, function (a, i) { setTimeout(function () { a.click(); }, i * 400); });
    });
    ov.querySelector('.anna-sk-ov__close').focus();
  }

  function share(files, btn) {
    // iPhone/iPad: Teilen-Menü -> „Bilder sichern“ legt sie direkt in Fotos ab
    if (isIOS() && navigator.canShare && navigator.canShare({ files: files })) {
      navigator.share({ files: files, title: 'Speisekarte' }).catch(function (err) {
        if (err && err.name === 'AbortError') return;
        overlay(files, btn);
      });
      return;
    }
    overlay(files, btn);
  }

  /* ---------- Init ---------- */
  function init(root) {
    var dataEl = root.querySelector('.anna-sk__data');
    if (!dataEl) return;
    var data;
    try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
    root.classList.add('anna-sk--js');

    // Tabs
    var tabs = [].slice.call(root.querySelectorAll('.anna-sk__tab'));
    var panels = [].slice.call(root.querySelectorAll('.anna-sk__board'));
    function select(i, focus) {
      tabs.forEach(function (t, k) {
        t.setAttribute('aria-selected', k === i ? 'true' : 'false');
        t.tabIndex = k === i ? 0 : -1;
        if (panels[k]) panels[k].hidden = k !== i;
      });
      if (focus) tabs[i].focus();
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { select(i); });
      t.addEventListener('keydown', function (e) {
        var n = tabs.length;
        if (e.key === 'ArrowRight') { e.preventDefault(); select((i + 1) % n, true); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); select((i - 1 + n) % n, true); }
      });
    });
    select(0);

    // Speichern
    var btn = root.querySelector('[data-anna-sk-save]');
    if (!btn) return;
    var label = btn.querySelector('span');
    var labelText = label ? label.textContent : '';
    var files = null, building = null;
    function build() {
      if (!building) building = makeFiles(data).then(function (f) { files = f; return f; });
      return building;
    }
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) {
        if (es.some(function (e) { return e.isIntersecting; })) { io.disconnect(); idle(build); }
      }, { rootMargin: '600px' });
      io.observe(btn);
    } else {
      idle(build);
    }
    btn.addEventListener('click', function () {
      if (files) { share(files, btn); return; }
      btn.disabled = true;
      if (label) label.textContent = 'Wird erstellt …';
      build().then(function (f) {
        btn.disabled = false;
        if (label) label.textContent = labelText;
        share(f, btn);
      }).catch(function (e) {
        btn.disabled = false;
        if (label) label.textContent = 'Leider fehlgeschlagen – bitte neu laden';
        building = null;
        if (window.console) console.error('[Speisekarte]', e);
      });
    });
  }

  ready(function () {
    [].forEach.call(document.querySelectorAll('[data-anna-sk]'), init);
  });
})();
