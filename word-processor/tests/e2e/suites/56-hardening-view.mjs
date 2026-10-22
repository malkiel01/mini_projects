/**
 * סבב הקשחה (ביקורת 04): R03 — יציאה מתצוגה מקדימה לא מחזירה הרשאת עריכה שבוטלה; R04 — חיפוש רגולרי עם התאמה
 * ריקה ליד אמוג'י / ביטוי איטי לא מקפיא; R05 — בתצוגה המקדימה אין סימני עריכה (¶, חיפוש, הערות, חלוניות).
 */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'הקשחה', { type: 'doc', content: [P('😀abc שלום עולם'), P('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!'), P('שורה שלישית')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);

    // R03: תצוגה מקדימה → ההרשאה מבוטלת מבחוץ (כמו המארחת / נעילה שאבדה) → יציאה: נשאר קריאה בלבד
    await t.tool(p, 'תצוגה לפני הדפסה');
    await p.waitForSelector('.wp-editor.wp-preview');
    await E(() => document.querySelector('.wp-editor').wordEditor.setEditable(false));
    await p.keyboard.press('Escape');
    await p.waitForSelector('.wp-editor:not(.wp-preview)');
    const st = await E(() => ({ ed: document.querySelector('.wp-doc').editor.isEditable, ce: document.querySelector('.wp-doc').getAttribute('contenteditable') }));
    t.ok('R03: יציאה מהתצוגה המקדימה — נשאר קריאה בלבד', st.ed === false && st.ce === 'false', JSON.stringify(st));
    await E(() => document.querySelector('.wp-editor').wordEditor.setEditable(true));
    // ההפך: ההרשאה ניתנה בזמן התצוגה — בתצוגה עצמה עדיין לא עורכים, ביציאה — כן
    await t.tool(p, 'תצוגה לפני הדפסה');
    await p.waitForSelector('.wp-editor.wp-preview');
    await E(() => { const w = document.querySelector('.wp-editor').wordEditor; w.setEditable(false); w.setEditable(true); });
    const during = await E(() => document.querySelector('.wp-doc').editor.isEditable);
    await p.keyboard.press('Escape');
    await p.waitForSelector('.wp-editor:not(.wp-preview)');
    t.ok('R03: הרשאה שניתנה בזמן התצוגה — לא בתצוגה, כן אחריה', during === false && await E(() => document.querySelector('.wp-doc').editor.isEditable) === true);

    // R04: (?=😀) על "😀abc" — התאמה ריקה; קודם הלולאה לא נגמרה. עכשיו: נגמר מהר, בלי התאמות שנספרות
    const t0 = Date.now();
    const r = await E(async () => {
        const { findMatches } = await import('./assets/js/find.js');
        const ed = document.querySelector('.wp-doc').editor;
        const a = Date.now();
        const out = findMatches(ed.state.doc, '(?=😀)', { regex: true });
        return { ms: Date.now() - a, n: Array.isArray(out) ? out.length : out.error };
    });
    t.ok('R04: התאמה ריקה לפני אמוג\'י — מסתיים (בלי לולאה)', r.ms < 400 && Date.now() - t0 < 5000, JSON.stringify(r));
    const r2 = await E(async () => {
        const { findMatches } = await import('./assets/js/find.js');
        const ed = document.querySelector('.wp-doc').editor;
        return [findMatches(ed.state.doc, '', { regex: true }), findMatches(ed.state.doc, '(?:)', { regex: true }), findMatches(ed.state.doc, 'שלום', {})].map(x => Array.isArray(x) ? x.length : x.error);
    });
    t.ok('R04: ביטוי ריק — אין התאמות; חיפוש רגיל עדיין עובד', r2.join() === '0,0,1', r2.join());
    // ביטוי עם backtracking מעריכי — בפס החיפוש: בדיקה ב-Worker מבטלת אותו, והעורך לא קופא
    await p.click('.wp-doc p >> nth=0');
    await p.keyboard.press('Control+f');
    await p.waitForSelector('.wp-findbar:not([hidden])');
    await p.click('.wp-findbar button[aria-label="אפשרויות חיפוש"]').catch(() => {});
    await p.locator('.wp-findbar label.find-opt:has-text("ביטוי רגולרי") input').check();
    const t1 = Date.now();
    await p.fill('.wp-findbar .find-in', '(a+)+$');
    await p.waitForFunction(() => /בוטל|נעצר/.test(document.querySelector('.wp-findbar .find-count').textContent), null, { timeout: 8000 }).catch(() => {});
    const msg = await p.locator('.wp-findbar .find-count').textContent();
    // העורך נשאר חי: הקלדה מגיבה מיד
    const alive = await E(() => new Promise(res => { const a = performance.now(); requestAnimationFrame(() => res(performance.now() - a)); }));
    t.ok('R04: ביטוי איטי — החיפוש מבוטל עם הודעה, והעורך לא קפא', /בוטל|נעצר/.test(msg) && alive < 500 && Date.now() - t1 < 8000, `${msg} (${Date.now() - t1}ms, frame ${Math.round(alive)}ms)`);
    await p.fill('.wp-findbar .find-in', 'ש.ום');
    await p.waitForTimeout(300);
    t.ok('R04: אחרי זה — ביטוי רגיל עובד', (await p.locator('.wp-findbar .find-count').textContent()).includes('1 מתוך 1'));
    await p.keyboard.press('Escape');

    // R05: סימני פסקה, חיפוש פעיל, חלונית ניווט והערות — ואז תצוגה מקדימה: אף סימן עריכה לא גלוי
    await p.click('.wp-doc p >> nth=0');
    await p.keyboard.press('Control+Shift+Digit8');
    await t.tool(p, 'חלונית ניווט');
    await p.keyboard.press('Control+f');
    await p.fill('.wp-findbar .find-in', 'שלום');
    await p.waitForTimeout(200);
    await t.tool(p, 'תצוגה לפני הדפסה');
    await p.waitForSelector('.wp-editor.wp-preview');
    const vis = await E(() => {
        const after = sel => [...document.querySelectorAll(sel)].filter(el => getComputedStyle(el, '::after').display !== 'none' && getComputedStyle(el, '::after').content !== 'none').length;
        const shown = sel => [...document.querySelectorAll(sel)].filter(el => el.offsetParent !== null && getComputedStyle(el).display !== 'none').length;
        const bg = sel => [...document.querySelectorAll(sel)].filter(el => getComputedStyle(el).backgroundColor !== 'rgba(0, 0, 0, 0)').length;
        return { marks: after('.wp-mk-p') + after('.wp-mk-sp'), find: bg('.wp-doc .wp-find'), panes: shown('.wp-nav, .wp-findbar, .wp-comments, .wp-paste') };
    });
    t.ok('R05: בתצוגה המקדימה — בלי ¶ / ·, בלי סימון חיפוש, בלי חלוניות', vis.marks === 0 && vis.find === 0 && vis.panes === 0, JSON.stringify(vis));
    await p.keyboard.press('Escape');
    await p.waitForSelector('.wp-editor:not(.wp-preview)');
    t.ok('R05: ביציאה — סימני הפסקה חוזרים (התצוגה משוחזרת)', await p.locator('.wp-doc .wp-mk-p').count() > 0);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
