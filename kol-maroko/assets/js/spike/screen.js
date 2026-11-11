/**
 * ספייק V0 — המסך שבמסך המפתח: טוען קובץ בדיקה לקנבס הדומם, בחירה / גרירה / פעולות מינימליות, התיקון האחרון,
 * והרצת הרתמה בדפדפן הזה. אין כאן שמירה: הכול בזיכרון, ורענון מחזיר למצב ההתחלתי.
 * ב-Safari וב-iOS אין בדיקה אוטומטית — "דוח מכשיר" למטה הוא מה שהבעלים מעתיק אחרי שניסה ביד.
 */
window.KolSpikeScreen = (() => {
  'use strict';
  const FIX = [['lexicon', 'המילון — 1,496 כרטיסים'], ['handwritten', 'קוד שנכתב ביד'], ['broken', 'קוד שבור באמצע הקלדה']];
  function show(el, { api, h }) {
    const K = window.KolSrc, C = window.KolCanvas, pwn = [], drags = [];
    const fixture = async name => { const r = await api('dev-spike-fixture', { params: { name } }); return Uint8Array.from(atob(r.b64), c => c.charCodeAt(0)); };
    const pick = h('select', { 'aria-label': 'קובץ בדיקה' }, FIX.map(([v, t]) => h('option', { value: v }, t)));
    const host = h('div', {}), info = h('pre', { class: 'spike-info', dir: 'ltr' }, 'לא נבחר אלמנט'), last = h('pre', { class: 'spike-info', dir: 'ltr' }, '—'), out = h('div', {}), device = h('pre', { class: 'spike-info', dir: 'ltr' });
    const canvas = new C.Canvas(host);
    const act = fn => () => { try { fn(); } catch (e) { if (!(e instanceof K.SrcError)) throw e; last.textContent = '⛔ נדחה: ' + e.message; } report(); };
    const sel = () => { if (!canvas.selected) throw new K.SrcError('קודם בוחרים אלמנט בקנבס'); return canvas.selected; };
    const shift = d => act(() => { const n = sel(), i = n.parent.kids.indexOf(n) + d; if (i < 0 || i >= n.parent.kids.length) throw new K.SrcError('אין לאן'); const pp = K.pathOf(n.parent); canvas.apply(m => K.html.move(m, n, n.parent, i)); canvas.select(K.byPath(canvas.m, pp.concat(i))); });
    const btn = (t, fn) => h('button', { type: 'button', class: 'btn', onclick: fn }, t);
    const report = () => { device.textContent = 'דוח מכשיר (להעתקה):\n' + navigator.userAgent + '\nסוגי קלט שהגיעו לקנבס: ' + ([...new Set(canvas.events || [])].join(', ') || '—')
      + '\nגרירות שהצליחו: ' + drags.length + '\nסקריפטים של העמוד שרצו בקנבס: ' + pwn.length + (pwn.length ? ' ⚠️ ' + pwn.join(', ') : ' (תקין)'); };
    window.addEventListener('message', e => { if (typeof e.data === 'string' && e.data.indexOf('kol-spike-pwned') === 0) { pwn.push(e.data); report(); } });
    canvas.onSelect = n => {
      if (!n) { info.textContent = 'לא נבחר אלמנט'; return; }
      const m = canvas.m;
      info.textContent = canvas.label(n) + '\nנתיב: ' + K.pathOf(n).join(' › ') + (n.implied ? '\nלא במקור — הדפדפן הוסיף אותו' : '\nשורות ' + K.lineOf(m.src, n.from) + '–' + K.lineOf(m.src, K.endOf(m, n)) + ' · תווים ' + n.from + '–' + K.endOf(m, n)
        + (n.broken ? '\n⚠️ אזור שבור — נערך רק בעורך הקוד' : '') + '\n\n' + m.src.slice(n.from, Math.min(K.endOf(m, n), n.from + 600)));
      report();
    };
    canvas.onChange = (patches, before) => {
      const t = canvas.timing; if (patches.length > 1 || patches.some(p => p.text.length > 0 && p.to === p.from)) drags.push(1);
      last.textContent = patches.map(p => `[${p.from}–${p.to}] שורה ${K.lineOf(before, p.from)}: − ${JSON.stringify(before.slice(p.from, p.to).slice(0, 160))}\n    + ${JSON.stringify(p.text.slice(0, 160))}`).join('\n')
        + `\n${Math.round(t.total)}ms (ניתוח ${Math.round(t.reparse)} · DOM ${Math.round(t.dom)} · מיפוי ${Math.round(t.map)} · פריסה ${Math.round(t.layout)})`;
      report();
    };
    canvas.onReject = e => { last.textContent = '⛔ נדחה: ' + e.message; };
    const load = async () => {
      try {
        const [a, b] = await Promise.all([fixture(pick.value + '.html'), fixture(pick.value + '.css')]);
        const t = await canvas.load(C.decode(a), C.decode(b));
        last.textContent = `נטען: ${a.length.toLocaleString()} בייט, ${canvas.m.count.toLocaleString()} אלמנטים · ${Math.round(t.total)}ms (ניתוח ${Math.round(t.parseHtml)} · הצגה ${Math.round(t.render)} · מיפוי ${Math.round(t.map)} · פריסה ${Math.round(t.layout)})`
          + (canvas.mapErrors.length ? `\n⚠️ ${canvas.mapErrors.length} אי-התאמות במיפוי` : '') + (canvas.m.errors.length ? '\nשגיאות ניתוח: ' + canvas.m.errors.map(e => e.code).join(', ') : '');
      } catch (e) { last.textContent = 'שגיאה: ' + e.message; }
      report();
    };
    const runAll = async () => {
      out.replaceChildren(h('p', { class: 'hint' }, 'רץ… (כדקה)'));
      const tmp = h('div', { style: 'height:480px' }); out.append(tmp);
      try {
        const r = await window.KolSpikeHarness.run({ fixture, host: tmp, progress: m => { out.firstChild.textContent = 'רץ… ' + m; } });
        const row = (k, ok, t) => h('tr', {}, h('td', {}, k), h('td', {}, ok ? '✅ עבר' : '❌ נכשל'), h('td', { dir: 'auto' }, t));
        out.replaceChildren(h('table', { class: 'spike-table' },
          row('K1 הלוך-חזור', r.k1.pass, 'שישה קבצים, בייט בבייט'),
          row('K2 תיקון נקודתי', r.k2.pass, ['lexicon', 'handwritten'].map(b => `${b}: ${r.k2[b].pass}/${r.k2[b].total} (פעולה הפוכה זהה: ${r.k2[b].inverseOp})`).join(' · ')),
          row('K3 כל אלמנט נבחר', r.k3.pass, Object.keys(r.k3).filter(f => f !== 'pass').map(f => `${f}: ${r.k3[f].mapped}/${r.k3[f].domElements}`).join(' · ')),
          row('K4 קוד שבור', r.k4.pass, `מחוץ לאזור: ${r.k4.ok.filter(x => x.ok).length}/${r.k4.ok.length} · נדחו באזור: ${r.k4.rejected.filter(x => x.rejected && x.unchanged).length}/${r.k4.rejected.length}`),
          row('K5 סקריפטים לא רצים', r.k5.attackPassStatic, `הודעות מהקנבס: ${r.k5.messagesFromCanvas.length} · בבקרה: ${r.k5.controlMessages.length}. לחיצה, גרירה ומגע — ביד, בקנבס שלמעלה`),
          row('K6 טעינה ≤ 2 שנ׳', r.k6.loadPass, r.k6.load.medianMs + 'ms'),
          row('K6 פעולה ≤ 100ms', r.k6.opPass, `HTML: חציון ${r.k6.opHtml.medianMs}ms, מקסימום ${r.k6.opHtml.maxMs}ms · CSS: ${r.k6.opCss.medianMs}ms`)),
          h('details', {}, h('summary', {}, 'התוצאות המלאות (JSON)'), h('textarea', { rows: 12, readonly: true, dir: 'ltr', style: 'width:100%' }, JSON.stringify(r, null, 1))));
      } catch (e) { out.replaceChildren(h('p', { class: 'error' }, 'הרתמה נכשלה: ' + e.message)); }
    };
    el.replaceChildren(
      h('p', { class: 'hint' }, 'ניסוי תחום (V0): האם אפשר לערוך קובץ שנכתב ביד בתיקוני טקסט נקודתיים, ולבחור כל אלמנט כשסקריפטים כבויים. אין שמירה — רענון מחזיר הכול.'),
      h('div', { class: 'spike-bar' }, pick, btn('טען', load), btn('↑ לפני', shift(-1)), btn('↓ אחרי', shift(1)),
        btn('⧉ שכפול', act(() => canvas.apply(m => K.html.duplicate(m, sel())))), btn('🗑 מחיקה', act(() => { const n = sel(); canvas.select(null); canvas.apply(m => K.html.remove(m, n)); })),
        btn('↑ הורה', act(() => { const n = sel(); if (n.parent) canvas.select(n.parent); })), btn('↶ ביטול', () => { canvas.undo(); last.textContent = 'בוטל'; })),
      host, h('h3', {}, 'האלמנט שנבחר'), info, h('h3', {}, 'התיקון האחרון בקובץ'), last, device,
      h('div', { class: 'spike-bar' }, btn('▶️ הרץ את הבדיקות האוטומטיות בדפדפן הזה', runAll)), out);
    pick.addEventListener('change', load);
    load();
  }
  return { show };
})();
