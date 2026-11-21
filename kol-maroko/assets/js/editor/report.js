/**
 * העורך החזותי — "🐞 לא הצלחתי לשנות את זה" (03 §12).
 *
 * מצביעים על אלמנט, ונפתחת מטלה ב-DevLoop (קוד KM-, תווית "לא הצלחתי לשנות") עם: צילום הקנבס והאלמנט מסומן, שביל הניווט,
 * התגית והמחלקות, הקובץ והשורות, רוחב הקנבס, ומה ניסו לשנות (לא חובה). כל חבר מדווח; המטלה נסגרת רק ב-✅.
 * כאן רק משתמשים ב-API של DevLoop (dev-report, dev-attach) — המודול עצמו אינו נערך.
 */
(function () {
  'use strict';
  const LABEL = 'לא הצלחתי לשנות';
  const STATUS = { triage: 'לבירור', open: 'פתוח', in_progress: 'בעבודה', verify: 'ממתין לאימות', question: 'שאלה אליך', closed: 'סגור ✅' };
  const toDataUrl = blob => new Promise((ok, bad) => { const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = bad; r.readAsDataURL(blob); });
  const timeout = (p, ms) => Promise.race([p, new Promise((_, bad) => setTimeout(() => bad(new Error('timeout')), ms))]);

  /** מה שנכנס למטלה על האלמנט — טקסט בלבד. */
  function describe(canvas, K, n, file, device) {
    const chain = []; for (let x = n; x; x = x.parent) chain.unshift(canvas.label(x).replace(/ · .*/, ''));
    let src = n; while (src && src.implied) src = src.parent;
    const a = src ? K.lineOf(canvas.m.src, src.from) : 0, b = src ? K.lineOf(canvas.m.src, K.endOf(canvas.m, src)) : 0, cls = K.classesOf(n);
    return { label: canvas.label(n).replace(/ · .*/, ''), path: chain.join(' › '), tag: n.tag, classes: cls, file: file.path, lines: a ? (a === b ? '' + a : a + '–' + b) : '', implied: !!n.implied,
      width: device.width, device: device.label, visual: canvas.visual(n) };
  }
  function detail(d, tried) {
    return ['מה ניסיתי לשנות: ' + (tried.trim() || '(לא נכתב)'), '', 'אלמנט: ' + d.label, 'שביל: ' + d.path, 'תגית: <' + d.tag + '>' + (d.classes.length ? ' · מחלקות: ' + d.classes.map(c => '.' + c).join(' ') : ' · בלי מחלקות'),
      'קובץ: ' + d.file + (d.lines ? ' · שורות ' + d.lines : '') + (d.implied ? ' (האלמנט אינו במקור — הדפדפן הוסיף; השורות של ההורה)' : ''),
      'רוחב הקנבס: ' + d.width + 'px (' + d.device + ')', 'בעמוד: ' + ({ visible: 'נראה', shown: 'מוסתר בעמוד — הוצג לעריכה', zero: 'בגודל אפס', nonvisual: 'בלי מראה (ראש העמוד / סקריפט)', none: 'אינו מוצג' }[d.visual] || d.visual)].join('\n');
  }

  /**
   * צילום הקנבס עם האלמנט מסומן. הקנבס הוא מסמך של אותו מקור, ולכן מצלמים אותו בלי ספרייה: ה-DOM (בלי סקריפטים)
   * נכנס ל-SVG ‏foreignObject, התמונות של הפרויקט מוטמעות כ-data:, והתוצאה מצוירת ל-<canvas>. דפדפן שאינו מרשה —
   * מקבלים תרשים: מסגרת הקנבס והאלמנט המסומן במקומו.
   */
  async function shoot(canvas, n) {
    const win = canvas.frame.contentWindow, doc = canvas.doc, W = Math.min(win.innerWidth, 1600), H = Math.min(win.innerHeight, 1400);
    const e = canvas.elOf(n), r = e && e.getClientRects().length ? e.getBoundingClientRect() : null, label = canvas.label(n).replace(/ · .*/, '');
    let cv, g; const fresh = () => { cv = document.createElement('canvas'); cv.width = W; cv.height = H; g = cv.getContext('2d'); };
    fresh();
    const mark = () => {
      if (!r) { g.fillStyle = '#b3261e'; g.font = 'bold 14px system-ui, sans-serif'; g.fillText('⚑ ' + label + ' — אינו נראה בעמוד', 12, 22); return; }
      g.strokeStyle = '#d0021b'; g.lineWidth = 3; g.strokeRect(r.left + 1.5, r.top + 1.5, Math.max(r.width - 3, 4), Math.max(r.height - 3, 4));
      g.font = 'bold 13px ui-monospace, monospace'; const tw = g.measureText(label).width + 10, ty = r.top > 22 ? r.top - 20 : r.top + 2;
      g.fillStyle = '#d0021b'; g.fillRect(r.left, ty, tw, 18); g.fillStyle = '#fff'; g.fillText(label, r.left + 5, ty + 13);
    };
    const png = () => new Promise((ok, bad) => { try { cv.toBlob(b => (b ? ok(b) : bad(new Error('אין צילום'))), 'image/png'); } catch (x) { bad(x); } });
    try {
      const root = doc.documentElement.cloneNode(true);
      for (const x of root.querySelectorAll('script, noscript, template, iframe, object, embed, link, base, meta, audio, video')) x.remove();
      let budget = 4e6;
      const inline = async url => {
        if (!url || /^data:/i.test(url)) return url;
        const abs = new URL(url, doc.baseURI).href;
        if (new URL(abs).origin !== location.origin || budget <= 0) return null;
        try { const res = await timeout(fetch(abs, { credentials: 'same-origin' }), 3000); if (!res.ok) return null; const b = await res.blob(); if (b.size > 1.5e6 || b.size > budget) return null; budget -= b.size; return await toDataUrl(b); } catch { return null; }
      };
      const imgs = Array.from(root.querySelectorAll('img[src]')).slice(0, 24);
      await Promise.all(imgs.map(async im => { const d = await inline(im.getAttribute('src')); im.removeAttribute('srcset'); if (d) im.setAttribute('src', d); else im.removeAttribute('src'); }));
      for (const st of root.querySelectorAll('style')) {
        const text = st.textContent, urls = [...new Set(Array.from(text.matchAll(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g), m => m[2]))].slice(0, 12);
        if (!urls.length) continue;
        const map = new Map(await Promise.all(urls.map(async u => [u, await inline(u)])));
        st.textContent = text.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g, (all, q, u) => (map.get(u) ? 'url("' + map.get(u) + '")' : 'none'));
      }
      const xhtml = new XMLSerializer().serializeToString(root), sx = win.scrollX, sy = win.scrollY, dw = Math.max(doc.documentElement.scrollWidth, W), dh = Math.max(doc.documentElement.scrollHeight, H);
      const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + W + '" height="' + H + '"><foreignObject x="' + (-sx) + '" y="' + (-sy) + '" width="' + dw + '" height="' + dh + '">' + xhtml + '</foreignObject></svg>';
      const img = new Image();
      await timeout(new Promise((ok, bad) => { img.onload = ok; img.onerror = () => bad(new Error('svg')); img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg); }), 5000);
      g.fillStyle = '#fff'; g.fillRect(0, 0, W, H); g.drawImage(img, 0, 0); mark();
      return { blob: await png(), full: true };
    } catch (err) {
      fresh();                                                            // קנבס נקי (הקודם אולי "נצבע" ואינו נקרא)
      g.fillStyle = '#f4f5f7'; g.fillRect(0, 0, W, H); g.fillStyle = '#5d6475'; g.font = '13px system-ui, sans-serif';
      g.fillText('תרשים: הדפדפן הזה אינו מרשה צילום מלא של הקנבס. מסומן מקום האלמנט.', 12, H - 14); mark();
      return { blob: await png(), full: false };
    }
  }

  /** שולח: מטלה (dev-report) ואז הצילום (dev-attach). מחזיר את קוד המטלה. */
  async function send(d, tried, shot) {
    const r = await api('dev-report', { title: LABEL + ': ' + d.label, detail: detail(d, tried), kind: 'bug', priority: 'normal', area: LABEL,
      context: { url: location.href, screen: innerWidth + '×' + innerHeight, ua: navigator.userAgent, version: state.version || '', lang: 'he', at: new Date().toISOString() } });
    let attached = false;
    if (shot && shot.blob) {
      try {
        const res = await fetch('api.php?action=dev-attach&code=' + encodeURIComponent(r.code) + '&name=' + encodeURIComponent('canvas-' + d.label.replace(/[^\w.#-]+/g, '_').slice(0, 40) + '.png'),
          { method: 'POST', credentials: 'same-origin', headers: { 'X-Kol': '1', 'X-CSRF': state.csrf, 'Content-Type': 'image/png' }, body: shot.blob });
        attached = res.ok;
      } catch { attached = false; }
    }
    return { code: r.code, attached };
  }
  const mine = async () => (await api('dev-my-reports')).items || [];

  window.KolReport = { LABEL, STATUS, describe, detail, shoot, send, mine };
})();
