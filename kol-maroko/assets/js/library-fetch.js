// קול מרוקו — הבאת ההקלטות מהאתר אל הכלי (פרוסה A2). בעל האתר בלבד, ורק בלחיצה:
// "בדוק מה יש באתר" (HEAD לכל כתובת) ← מסך אישור ← "הבא את ההקלטות". הדפדפן מבקש מהשרת צעדים קצרים אחד אחרי השני;
// כל הקלטה מסומנת בשרת כשהסתיימה, ולכן סגירת הדפדפן באמצע — ופתיחה מחדש — ממשיכה מאותה נקודה.
// גם "מדוד את כולן" (משך ההקלטות שבכלי) — כאן. אין innerHTML: כל טקסט מהשרת נכנס כטקסט.
'use strict';

const LibraryFetch = (() => {
  let S = null;                 // rec_status האחרון
  let busy = false, confirm = null, err = '', onChange = () => {};
  let body = null;              // גוף החלון כשהוא פתוח
  const num = n => Number(n || 0).toLocaleString('he-IL');
  const mb = n => (n / 1048576).toLocaleString('he-IL', { maximumFractionDigits: n < 10485760 ? 1 : 0 }) + 'MB';
  const SCAN = { ok: 'קיימות', missing: 'חסרות', blocked: 'חסומות', error: 'שגיאה', skipped: 'כתובת שאינה מותרת' };
  const running = () => S && S.job.status === 'running' && !S.job.stale;

  /** השורה בראש ספריית ההקלטות (בעלים בלבד). */
  function panel(counts, change) {
    onChange = change;
    const line = h('span', { class: 'use-tx' });
    const el = h('div', { class: 'lf-panel' },
      h('span', { 'aria-hidden': 'true' }, '🌐'), line,
      h('button', { type: 'button', class: 'btn ghost', onclick: open }, counts.site ? 'הבאה מהאתר' : 'פרטים'));
    const say = () => {
      const j = S?.job || {};
      line.textContent = !S ? `${num(counts.site)} הקלטות עדיין רק באתר` + (counts.failed ? ` · ${num(counts.failed)} נכשלו בהבאה` : '')
        : running() ? (j.phase === 'scan' ? 'בודק מה יש באתר…' : `מביא… ${num(j.done)} מתוך ${num(j.total)}`)
        : j.stale || j.status === 'paused' ? (j.phase === 'scan' ? 'הבדיקה נעצרה באמצע' : 'ההבאה נעצרה באמצע') + ' — אפשר להמשיך'
        : j.status === 'blocked' ? 'האתר חוסם את הכלי'
        : `${num(S.counts.site)} באתר · ${num(S.counts.tool)} בכלי` + (S.counts.failed ? ` · ${num(S.counts.failed)} נכשלו` : '');
    };
    say();
    panel.say = say;
    if (!S) api('rec_status').then(x => { S = x; say(); if (running()) loop(); }).catch(() => {});
    return el;
  }

  async function open() {
    body = h('div', { class: 'lf-body' });
    const dlg = sheet('🌐 הבאת ההקלטות מהאתר', body);
    dlg.addEventListener('close', () => { body = null; confirm = null; }, { once: true });
    try { S = await api('rec_status'); err = ''; } catch (e) { err = e.message; }
    draw();
  }

  async function op(action, b = {}) {
    try { S = await api(action, b); err = ''; } catch (e) { err = e.message; toast(e.message, true); }
    draw();
    if (running()) loop();
  }

  /** הצעדים: כל עוד העבודה רצה. גם כשהחלון סגור — ברקע, עד שהדף נסגר. */
  async function loop() {
    if (busy) return;
    busy = true;
    try {
      while (running()) {
        const x = await api(S.job.phase === 'scan' ? 'rec_scan_step' : 'rec_fetch_step', {});
        S = x;
        draw();
        if (x.busy) await new Promise(r => setTimeout(r, 1500));     // צעד אחר רץ (לשונית נוספת) — מחכים
      }
    } catch (e) { err = e.message; toast(e.message, true); }
    busy = false;
    draw();
    onChange();
  }

  async function plan() {
    try { confirm = (await api('rec_fetch_plan')).plan; err = ''; } catch (e) { err = e.message; }
    draw();
  }

  function item(x, extra) {
    return h('li', {}, h('b', { dir: 'ltr' }, '#' + x.word_id), ' ', h('span', {}, x.word || ''), ' — ', h('span', {}, extra),
      h('div', { class: 'lf-url', dir: 'ltr' }, x.url));
  }

  function draw() {
    panel.say?.();
    if (!body || !S) { if (body && err) body.replaceChildren(h('p', { class: 'error' }, err)); return; }
    const c = S.counts, j = S.job, sc = c.scan;
    const notTool = c.total - c.tool;
    const out = [];
    out.push(h('p', { class: 'lf-stats' }, h('b', {}, num(c.total)), ' הקלטות · ', h('b', {}, num(c.tool)), ' בכלי · ', h('b', {}, num(c.site)), ' באתר',
      c.failed ? [' · ', h('b', { class: 'lib-bad' }, num(c.failed)), ' נכשלו'] : null, ` · מ-${S.host}`));
    if (err) out.push(h('p', { class: 'error' }, err));

    // ── העבודה הנוכחית ──
    const btn = (label, fn, cls = 'ghost') => h('button', { type: 'button', class: 'btn ' + cls, onclick: fn }, label);
    if (j.phase === 'scan' && (j.status === 'running' || j.status === 'paused')) {
      const done = notTool - sc[''];
      out.push(h('div', { class: 'summary', role: 'status' },
        h('p', {}, j.stale || j.status === 'paused' ? 'הבדיקה נעצרה באמצע.' : 'בודק מה יש באתר…', ` ${num(done)} מתוך ${num(notTool)}`),
        h('progress', { max: notTool || 1, value: done, 'aria-label': 'התקדמות הבדיקה' }),
        h('div', { class: 'row-btns wrap' },
          running() ? btn('⏸ השהה', () => op('rec_job_op', { op: 'pause' })) : btn('▶ המשך', () => op('rec_job_op', { op: 'resume' }), 'primary'),
          btn('⏹ עצור', () => op('rec_job_op', { op: 'stop' })))));
    }
    if (j.phase === 'fetch' && ['running', 'paused', 'full'].includes(j.status)) {
      const left = c.todo;
      out.push(h('div', { class: 'summary', role: 'status' },
        h('p', {}, j.status === 'full' ? 'ההבאה נעצרה: תקרת הדיסק מלאה. מגדילים אותה ב-🗂️ קבצים ← תקרת הקבצים, וממשיכים.'
          : j.stale || j.status === 'paused' ? 'ההבאה נעצרה באמצע — ממשיכים מאותה נקודה. מה שכבר הובא לא יורד שוב.' : 'מביא את ההקלטות…'),
        h('p', { class: 'lf-nums' }, `הובאו ${num(j.done)} · נותרו ${num(left)} · נכשלו ${num(j.failed)} · ${mb(j.got || 0)}`),
        h('progress', { max: (j.done || 0) + (j.failed || 0) + left || 1, value: (j.done || 0) + (j.failed || 0), 'aria-label': 'התקדמות ההבאה' }),
        h('div', { class: 'row-btns wrap' },
          running() ? btn('⏸ השהה', () => op('rec_job_op', { op: 'pause' })) : btn('▶ המשך', () => op('rec_job_op', { op: 'resume' }), 'primary'),
          btn('⏹ עצור', () => op('rec_job_op', { op: 'stop' })))));
    }
    if (j.phase === 'fetch' && (j.status === 'done' || j.status === 'stopped')) {
      out.push(h('p', { class: 'hint' }, `${j.status === 'done' ? 'ההבאה הסתיימה' : 'ההבאה נעצרה'}: הובאו ${num(j.done)}, נכשלו ${num(j.failed)} (${mb(j.got || 0)}).`));
    }

    // ── חסימה ──
    if (j.blocked) {
      out.push(h('div', { class: 'warnbox', role: 'alert' },
        h('b', {}, 'האתר חוסם את הכלי.'), ' הבקשות מהשרת של הכלי נחסמות (Cloudflare) — זו לא בעיה בהקלטות עצמן. ההבאה לא תתחיל. שתי דרכים:',
        h('ol', {},
          h('li', {}, h('b', {}, 'ייצוא ידני: '), 'בוורדפרס — הגדרות ← קול מרוקו ← "ייצוא ההקלטות", ואז כאן: ⚙️ הגדרות ← 🎙️ ייבוא ההקלטות.'),
          h('li', {}, h('b', {}, 'כלל ב-Cloudflare: '), 'Security ← Security rules ← Create rule ← Custom rule. ב-Edit expression מדביקים:',
            h('pre', { class: 'lf-pre', dir: 'ltr' }, '(starts_with(http.request.uri.path, "/wp-content/uploads/") and http.request.method in {"GET" "HEAD"} and http.user_agent contains "KolMaroko-AudioFetch")'),
            'Then take action: Skip (כל הרכיבים), Place at: First ← Deploy. ואחר כך — "בדוק שוב".'))));
    }

    // ── תוצאות הבדיקה ──
    const scanned = notTool - sc[''];
    if (scanned > 0 && !(j.phase === 'scan' && running())) {
      out.push(h('h3', { class: 'sec-title' }, 'מה יש באתר'));
      out.push(h('ul', { class: 'lf-scan' }, Object.entries(SCAN).map(([k, l]) => sc[k] ? h('li', {}, h('b', {}, num(sc[k])), ' ' + l) : null),
        h('li', {}, 'נפח: ', h('b', {}, mb(c.bytes)), c.unknown_size ? ` (+${num(c.unknown_size)} בגודל לא ידוע)` : ''),
        h('li', {}, 'מקום פנוי: ', h('b', {}, mb(S.free)), ` מתוך ${mb(S.cap)}`)));
      const bad = S.lists.scan;
      if (bad.length) out.push(h('details', { class: 'lf-list' }, h('summary', {}, `${num(bad.length)} חסרות / חסומות / שגויות`),
        h('ul', {}, bad.slice(0, 300).map(x => item(x, `${SCAN[x.scan] || x.scan}${x.note ? ' · ' + x.note : ''}`)))));
    }

    // ── אישור ──
    if (confirm) {
      const p = confirm;
      out.push(h('div', { class: 'summary lf-confirm', role: 'group', 'aria-label': 'אישור ההבאה' },
        h('b', {}, 'להביא את ההקלטות אל הכלי?'),
        h('ul', {},
          h('li', {}, `כמה: ${num(p.count)} הקלטות`),
          h('li', {}, `מאיפה: ${p.host} (קריאה בלבד — האתר אינו משתנה)`),
          h('li', {}, `נפח: ${mb(p.bytes)}` + (p.unknown_size ? ` (+${num(p.unknown_size)} בגודל לא ידוע)` : '')),
          h('li', {}, `מקום פנוי: ${mb(p.free)} · יישאר: ${mb(Math.max(0, p.after))}`)),
        p.enough ? null : h('p', { class: 'error' }, `אין מספיק מקום: חסרים ${mb(p.short)}. מגדילים את התקרה ב-🗂️ קבצים ← תקרת הקבצים, וחוזרים לכאן.`),
        h('div', { class: 'row-btns wrap' },
          p.ok ? btn(`✓ כן, הבא ${num(p.count)} הקלטות`, () => { const t = p.token; confirm = null; op('rec_fetch_start', { confirm: true, token: t }); }, 'primary') : null,
          btn('ביטול', () => { confirm = null; draw(); }))));
    }

    // ── הפעולות ──
    if (!running() && !confirm) {
      const canFetch = S.scanned && c.todo > 0 && !j.blocked;
      out.push(h('div', { class: 'row-btns wrap' },
        notTool ? btn(scanned ? '🔎 בדוק שוב' : '🔎 בדוק מה יש באתר', () => op('rec_scan_start', {}), canFetch ? 'ghost' : 'primary') : null,
        canFetch ? btn(`⬇️ הבא את ההקלטות (${num(c.todo)})`, plan, 'primary') : null));
      if (!notTool) out.push(h('p', { class: 'hint' }, 'כל ההקלטות כבר בכלי.'));
      else if (!S.scanned) out.push(h('p', { class: 'hint' }, 'הבדיקה שולחת לכל כתובת בקשת HEAD בלבד — לא מורידה דבר. ההבאה אפשרית רק אחריה, ורק באישור.'));
    }

    // ── כישלונות ──
    const fails = S.lists.failed;
    if (fails.length) {
      out.push(h('h3', { class: 'sec-title' }, `נכשלו בהבאה · ${num(fails.length)}`));
      out.push(h('ul', { class: 'lf-fails' }, fails.slice(0, 300).map(x => h('li', {},
        h('div', { class: 'use-tx' }, h('span', {}, h('b', { dir: 'ltr' }, '#' + x.word_id), ' ', x.word, ' — ', h('b', { class: 'lib-bad' }, x.reason)),
          h('small', { class: 'lf-url', dir: 'ltr' }, x.url)),
        running() ? null : h('button', { type: 'button', class: 'btn ghost', 'aria-label': `נסה שוב: ${x.word}`, onclick: () => op('rec_job_op', { op: 'retry', word_id: x.word_id }) }, 'נסה שוב')))));
      if (!running()) out.push(btn('↻ נסה שוב את כולן', () => op('rec_job_op', { op: 'retry' })));
    }

    // ── הגדרות ──
    const set = S.settings;
    const f = (label, key, min, max, v) => h('label', { class: 'f' }, h('span', {}, label), h('input', { type: 'number', min, max, value: v, 'data-k': key, inputmode: 'numeric' }));
    const box = h('div', { class: 'lf-set' },
      f('הורדות במקביל', 'conc', 1, 4, set.conc), f('השהיה בין קבוצות (מילישניות)', 'delay_ms', 0, 5000, set.delay_ms),
      f('תקרה לקובץ (MB)', 'file_max_mb', 1, 600, Math.round(set.file_max / 1048576)));
    out.push(h('details', { class: 'lf-list' }, h('summary', {}, '⚙️ קצב ותקרה'), box,
      btn('שמור', () => { const b = {}; box.querySelectorAll('input').forEach(i => { b[i.dataset.k] = +i.value; }); op('rec_settings_set', b); })));
    out.push(h('p', { class: 'hint' }, 'מוצגות ההקלטות של המילים. קובצי שמע אחרים שבאתר עדיין אינם מוצגים.'));
    body.replaceChildren(...out.filter(Boolean));
  }

  // ── "מדוד את כולן": משך ההקלטות שבכלי, ברקע ────────────
  const M = { on: false, done: 0, total: 0 };
  /** rows — שורות הספרייה. מודד אחת-אחת (metadata בלבד) ושומר בשרת. onTick(M) לכל התקדמות. */
  async function measureAll(rows, onTick) {
    if (M.on) { M.on = false; return; }                     // לחיצה שנייה — עצירה
    const todo = rows.filter(r => r.state === 'tool' && r.file && r.file.duration == null && !r.missing && r.src);
    Object.assign(M, { on: true, done: 0, total: todo.length });
    onTick(M);
    for (const r of todo) {
      if (!M.on) break;
      const d = await new Promise(res => {
        const a = new Audio(); a.preload = 'metadata';
        const end = v => { clearTimeout(t); a.removeAttribute('src'); a.load(); res(v); };
        const t = setTimeout(() => end(NaN), 15000);
        a.addEventListener('loadedmetadata', () => end(a.duration), { once: true });
        a.addEventListener('error', () => end(NaN), { once: true });
        a.src = r.src;
      });
      if (isFinite(d) && d > 0) {
        try { await api('rec_duration', { word_id: r.id, file_id: r.file.id, duration: d }); r.file.duration = Math.round(d * 100) / 100; } catch { /* הבא בתור */ }
      }
      M.done++;
      onTick(M);
    }
    M.on = false;
    onTick(M);
  }

  return { panel, measureAll, _state: () => ({ S, busy, confirm, M }) };
})();
