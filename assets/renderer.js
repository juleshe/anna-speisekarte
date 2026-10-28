/*!
 * Anna Imbiss – Tafel-Renderer (1920×1080)
 * Zeichnet eine Speisekarten-Tafel im Look der TV-Speisekarte auf ein Canvas.
 * Läuft im Browser und in Node (@napi-rs/canvas) mit derselben API.
 */
(function (root) {
  'use strict';

  var W = 1920, H = 1080;
  var COLX = [48, 708, 1368], CW = 502;
  var TOP = 18, MAXY = 1074;
  var BOX = { w: 88, h: 50, gap: 21 };
  var C = {
    white: '#ffffff', desc: '#c3c6d8', yellow: '#f5c92e', banner: '#f5d23a',
    ink: '#2b2c45', sep: 'rgba(255,255,255,0.15)', navy: '#303377'
  };
  var F = {
    serif: '"Gilda Display", Georgia, serif',
    slab: '"Roboto Slab", Georgia, serif',
    sans: 'Roboto, Arial, sans-serif',
    script: '"Kaushan Script", cursive'
  };

  // ---------- Hilfsfunktionen ----------
  function chars(s) { return Array.from(String(s)); }

  function spacedWidth(ctx, s, sp) {
    if (!sp) return ctx.measureText(s).width;
    var w = 0, cs = chars(s);
    for (var i = 0; i < cs.length; i++) w += ctx.measureText(cs[i]).width + sp;
    return cs.length ? w - sp : 0;
  }

  // Text mit Zeichenabstand (letterSpacing wird nicht überall unterstützt)
  function drawSpaced(ctx, s, x, y, sp, align) {
    var w = spacedWidth(ctx, s, sp);
    if (align === 'center') x -= w / 2;
    else if (align === 'right') x -= w;
    var prev = ctx.textAlign;
    ctx.textAlign = 'left';
    if (!sp) { ctx.fillText(s, x, y); ctx.textAlign = prev; return w; }
    var cs = chars(s);
    for (var i = 0; i < cs.length; i++) {
      ctx.fillText(cs[i], x, y);
      x += ctx.measureText(cs[i]).width + sp;
    }
    ctx.textAlign = prev;
    return w;
  }

  function rr(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y); ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r); ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h); ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r); ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
  }

  function wrap(ctx, text, maxW, sp) {
    var words = String(text).split(/\s+/).filter(Boolean), lines = [], line = '';
    for (var i = 0; i < words.length; i++) {
      var test = line ? line + ' ' + words[i] : words[i];
      if (line && spacedWidth(ctx, test, sp) > maxW) { lines.push(line); line = words[i]; }
      else line = test;
    }
    if (line) lines.push(line);
    return lines;
  }

  // ---------- Elemente ----------
  // Preis-Kachel: leicht gekippt, heller Rahmen oben/links, dunkle Kante unten/rechts
  function drawPriceBox(ctx, x, y) {
    var w = BOX.w, h = BOX.h;
    ctx.save();
    ctx.translate(x + w / 2, y + h / 2);
    ctx.rotate(-0.013);
    ctx.translate(-w / 2, -h / 2);
    ctx.shadowColor = 'rgba(0,0,0,0.35)'; ctx.shadowBlur = 5; ctx.shadowOffsetX = 2; ctx.shadowOffsetY = 3;
    ctx.fillStyle = 'rgba(105,105,145,0.48)';
    rr(ctx, 0, 0, w, h, 3); ctx.fill();
    ctx.shadowColor = 'transparent';
    ctx.strokeStyle = 'rgba(22,22,52,0.35)'; ctx.lineWidth = 1.5;
    rr(ctx, 0.5, 0.5, w - 1, h - 1, 3); ctx.stroke();
    ctx.fillStyle = 'rgba(72,72,118,0.42)';
    rr(ctx, 4, 4, w - 7, h - 7, 2); ctx.fill();
    ctx.fillStyle = 'rgba(18,18,48,0.45)';
    ctx.fillRect(w - 3, 2, 3, h - 2);
    ctx.fillRect(2, h - 3, w - 2, 3);
    ctx.restore();
  }

  function drawPrice(ctx, price, bx, y) {
    drawPriceBox(ctx, bx, y);
    ctx.fillStyle = C.white;
    var size = 28;
    ctx.font = size + 'px ' + F.serif;
    while (ctx.measureText(price).width > BOX.w - 12 && size > 18) { size--; ctx.font = size + 'px ' + F.serif; }
    drawSpaced(ctx, price, bx + BOX.w / 2, y + 37, 0, 'center');
  }

  function boxXs(right, n) {
    return n >= 2 ? [right - 2 * BOX.w - BOX.gap, right - BOX.w] : [right - BOX.w];
  }

  function drawBadge(ctx, text, x, y) {
    ctx.font = 'bold 21px ' + F.slab;
    var label = String(text).toUpperCase();
    var tw = spacedWidth(ctx, label, 1.6);
    var w = Math.max(270, tw + 64), h = 58;
    ctx.save();
    ctx.translate(x + w / 2, y + h / 2);
    ctx.rotate(-0.018);
    ctx.shadowColor = 'rgba(0,0,0,0.22)'; ctx.shadowBlur = 8; ctx.shadowOffsetY = 2;
    ctx.fillStyle = 'rgba(14,14,42,0.30)';
    rr(ctx, -w / 2, -h / 2, w, h, 4); ctx.fill();
    ctx.shadowColor = 'transparent';
    ctx.fillStyle = 'rgba(150,145,200,0.30)';
    rr(ctx, -w / 2 + 8, -h / 2 + 8, w - 16, h - 16, 2); ctx.fill();
    ctx.fillStyle = C.yellow;
    drawSpaced(ctx, label, 0, 8, 1.6, 'center');
    ctx.restore();
  }

  function drawLabels(ctx, labels, right, baseline) {
    var xs = boxXs(right, labels.length);
    ctx.font = 'bold 21px ' + F.slab;
    ctx.fillStyle = C.yellow;
    for (var i = 0; i < labels.length; i++) {
      drawSpaced(ctx, labels[i], xs[i] + BOX.w / 2, baseline, 1.4, 'center');
    }
  }

  function drawBubble(ctx, text, cx, cy) {
    var r = 50;
    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(-0.06);
    // Sprechblasen-Spitze
    ctx.beginPath();
    ctx.moveTo(-26, 38); ctx.lineTo(-40, 66); ctx.lineTo(-8, 47); ctx.closePath();
    ctx.fillStyle = C.banner; ctx.fill();
    ctx.strokeStyle = '#fff'; ctx.lineWidth = 3; ctx.stroke();
    ctx.shadowColor = 'rgba(0,0,0,0.3)'; ctx.shadowBlur = 8; ctx.shadowOffsetY = 2;
    ctx.beginPath(); ctx.arc(0, 0, r, 0, Math.PI * 2);
    ctx.fillStyle = C.banner; ctx.fill();
    ctx.shadowColor = 'transparent';
    ctx.lineWidth = 4.5; ctx.strokeStyle = '#fff'; ctx.stroke();
    var lines = String(text).split('|');
    ctx.fillStyle = C.ink;
    var size = 17;
    ctx.font = 'bold ' + size + 'px ' + F.slab;
    var maxW = 0;
    for (var i = 0; i < lines.length; i++) maxW = Math.max(maxW, ctx.measureText(lines[i]).width);
    while (maxW > 78 && size > 11) {
      size--; ctx.font = 'bold ' + size + 'px ' + F.slab; maxW = 0;
      for (var j = 0; j < lines.length; j++) maxW = Math.max(maxW, ctx.measureText(lines[j]).width);
    }
    var lh = size + 3, y0 = -((lines.length - 1) * lh) / 2 + size * 0.36;
    for (var k = 0; k < lines.length; k++) drawSpaced(ctx, lines[k], 0, y0 + k * lh, 0, 'center');
    ctx.restore();
  }

  function drawBanner(ctx, b, x, top, right) {
    var hasBubble = !!b.bubble;
    ctx.font = '600 17px ' + F.slab;
    var tw = ctx.measureText(b.text).width;
    var bw = Math.min(right - x - 4, Math.max(tw + 70, 360)), bh = 46;
    var size = 17;
    while (tw > bw - 30 && size > 12) { size--; ctx.font = '600 ' + size + 'px ' + F.slab; tw = ctx.measureText(b.text).width; }
    var bx = x + 24;
    if (bx + bw > right + 8) bx = right + 8 - bw;
    // Bubble rechts neben dem Banner: Banner darf dafür bis 120 px in den Spaltenzwischenraum rücken
    var bubbleRight = false;
    if (hasBubble) {
      var bxMax = W - 4 - 100 - bw;
      if (bx <= bxMax) bubbleRight = true;
      else if (bxMax >= x - 120) { bx = bxMax; bubbleRight = true; }
    }
    var by = top + (hasBubble ? 64 : 30);
    ctx.save();
    ctx.translate(bx + bw / 2, by + bh / 2);
    ctx.rotate(-0.022);
    ctx.strokeStyle = 'rgba(255,255,255,0.9)'; ctx.lineWidth = 2;
    ctx.strokeRect(-bw / 2 - 5, -bh / 2 - 5, bw, bh);
    ctx.shadowColor = 'rgba(0,0,0,0.25)'; ctx.shadowBlur = 6; ctx.shadowOffsetY = 2;
    ctx.fillStyle = C.banner;
    ctx.fillRect(-bw / 2, -bh / 2, bw, bh);
    ctx.shadowColor = 'transparent';
    ctx.fillStyle = C.ink;
    drawSpaced(ctx, b.text, 0, 6, 0, 'center');
    ctx.restore();
    if (hasBubble) {
      // rechts neben dem Banner, wenn Platz ist – sonst darüber
      if (bubbleRight) drawBubble(ctx, b.bubble, bx + bw + 46, by - 18);
      else drawBubble(ctx, b.bubble, Math.min(bx + bw - 40, W - 58), by - 38);
    }
  }

  function drawScript(ctx, text, x, top, right) {
    var size = 38, sp = 3, maxW = right - x;
    ctx.font = size + 'px ' + F.script;
    while (spacedWidth(ctx, text, sp) > maxW && size > 22) { size--; ctx.font = size + 'px ' + F.script; }
    var w = spacedWidth(ctx, text, sp);
    var cx = Math.min(x + maxW * 0.56, right - w / 2 + 10);
    ctx.save();
    ctx.translate(cx, top + 42);
    ctx.rotate(-0.045);
    ctx.fillStyle = C.white;
    ctx.shadowColor = 'rgba(0,0,0,0.25)'; ctx.shadowBlur = 4;
    drawSpaced(ctx, text, 0, 0, sp, 'center');
    ctx.restore();
  }

  function drawKat(ctx, b, x, top, right) {
    var labels = b.labels || [];
    if (b.name) {
      drawBadge(ctx, b.name, x - 5, top + 9);
    }
    if (b.price) {
      drawPrice(ctx, b.price, right - BOX.w, top + 14);
      return;
    }
    if (b.note) {
      ctx.font = '19px ' + F.serif;
      ctx.fillStyle = C.yellow;
      var lines = wrap(ctx, b.note, 200, 1);
      var base = b.name ? top + 52 - (lines.length - 1) * 24 : top + 10;
      for (var i = 0; i < lines.length; i++) drawSpaced(ctx, lines[i], right, base + i * 24, 1, 'right');
    }
    if (labels.length) drawLabels(ctx, labels, right, b.name ? top + 76 : top + 36);
  }

  function drawItem(ctx, b, x, top, right) {
    var prices = b.prices || [];
    var xs = boxXs(right, prices.length);
    for (var i = 0; i < prices.length; i++) drawPrice(ctx, prices[i], xs[i], top + 9);
    var textRight = (prices.length ? xs[0] : right) - 14;

    // Nummer + Name
    var nx = x;
    ctx.fillStyle = C.white;
    if (b.nr) {
      ctx.font = '17px ' + F.serif;
      nx += drawSpaced(ctx, b.nr + '.)', x, top + 42, 0.6, 'left') + 7;
    }
    var suffixW = 0;
    if (b.suffix) { ctx.font = '600 13px ' + F.slab; suffixW = spacedWidth(ctx, b.suffix, 0.4) + 10; }
    var size = 25, sp = 1.8, maxW = textRight - nx - suffixW;
    ctx.font = size + 'px ' + F.serif;
    while (spacedWidth(ctx, b.name, sp) > maxW && sp > 0.4) { sp -= 0.2; }
    while (spacedWidth(ctx, b.name, sp) > maxW && size > 18) { size--; ctx.font = size + 'px ' + F.serif; }
    var nw = drawSpaced(ctx, b.name, nx, top + 42, sp, 'left');
    if (b.suffix) {
      ctx.font = '600 13px ' + F.slab;
      drawSpaced(ctx, b.suffix, nx + nw + 10, top + 41, 0.4, 'left');
    }

    // Beschreibung (max. 2 Zeilen, sonst kleiner)
    if (b.desc) {
      ctx.fillStyle = C.desc;
      var ds = 13, dsp = 0.7, lines;
      ctx.font = ds + 'px ' + F.sans;
      lines = wrap(ctx, b.desc, textRight - x, dsp);
      while (lines.length > 2 && ds > 10) { ds--; dsp = 0.4; ctx.font = ds + 'px ' + F.sans; lines = wrap(ctx, b.desc, textRight - x, dsp); }
      var lh = ds + 1;
      for (var j = 0; j < Math.min(lines.length, 3); j++) drawSpaced(ctx, lines[j], x + 1, top + 60 + j * lh, dsp, 'left');
    }

    // Trennlinie
    ctx.fillStyle = C.sep;
    ctx.fillRect(x + 1, top + 79, right - x - 1, 1);
  }

  function blockHeight(b) {
    switch (b.t) {
      case 'kat': return b.name ? 80 : 46;
      case 'item': return 80;
      case 'banner': return b.bubble ? 118 : 98;
      case 'script': return 62;
      case 'space': return +b.h || 0;
      default: return 0;
    }
  }

  function drawColumn(ctx, blocks, x) {
    var right = x + CW;
    var total = TOP;
    for (var i = 0; i < blocks.length; i++) total += blockHeight(blocks[i]);
    var s = total > MAXY ? (MAXY - TOP) / (total - TOP) : 1;
    ctx.save();
    if (s < 1) { ctx.translate(x, TOP); ctx.scale(s, s); ctx.translate(-x, -TOP); }
    var y = TOP;
    for (var k = 0; k < blocks.length; k++) {
      var b = blocks[k];
      if (b.t === 'kat') drawKat(ctx, b, x, y, right);
      else if (b.t === 'item') drawItem(ctx, b, x, y, right);
      else if (b.t === 'banner') drawBanner(ctx, b, x, y, right);
      else if (b.t === 'script') drawScript(ctx, b.text, x, y, right);
      y += blockHeight(b);
    }
    ctx.restore();
  }

  function renderBoard(ctx, board, bg) {
    ctx.save();
    ctx.textBaseline = 'alphabetic';
    if (bg) ctx.drawImage(bg, 0, 0, W, H);
    else { ctx.fillStyle = C.navy; ctx.fillRect(0, 0, W, H); }
    var cols = board.columns || [];
    for (var i = 0; i < 3; i++) if (cols[i]) drawColumn(ctx, cols[i], COLX[i]);
    ctx.restore();
  }

  var api = { W: W, H: H, renderBoard: renderBoard, fonts: F };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  root.AnnaMenuRenderer = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
