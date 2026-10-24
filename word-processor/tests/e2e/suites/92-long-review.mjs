/**
 * מסמך ארוך עם סקירה (תנאי הקבלה של שלב 4): ~150 עמודים, 300 הערות על טקסט ו-300 הערות שוליים — "בלי עיכוב".
 * נמדדים: פתיחה עד עימוד יציב, הקלדה בתחילת המסמך ובאמצעו (מההקשה עד שתי מסגרות), הוספת הערה, וחלונית
 * ההערות; ושהעימוד אחרי ההקלדה (מהמטמון ומהמיפוי) זהה למדידה מאפס. הספים נדיבים (CI איטי) — הם תופסים האטה גסה,
 * לא מודדים מהירות. לפני התיקון: ~575 מ"ש להקשה.
 * לחקירה: WP_LONG='{"cm":false}' (בלי הערות) / '{"fn":false}' (בלי הערות שוליים) / '{"n":600}' (חצי מסמך).
 */
const WORDS = 'המסמך הזה נכתב כדי לבדוק שהעורך עומד בעומס של מסמך ארוך עם הרבה הערות ועדיין מגיב מהר לכל הקשה'.split(' ');
const sentence = i => Array.from({ length: 70 }, (_, k) => WORDS[(i * 7 + k) % WORDS.length]).join(' ');

function build({ n = 1200, cm = true, fn = true } = {}) {
    const content = [], comments = {};
    const every = n / 300;
    for (let i = 0; i < n; i++) {
        const c = [];
        const text = sentence(i);
        if (cm && i % every === 0) {             // 300 הערות על טקסט — על המילה הראשונה של הפסקה
            const id = 'c' + i;
            comments[id] = { author: 'דנה לוי', date: '2026-10-24T10:00:00Z', text: 'הערה מספר ' + (i / every + 1), done: false, replies: [] };
            const [first, ...rest] = text.split(' ');
            c.push({ type: 'text', text: first, marks: [{ type: 'comment', attrs: { id } }] }, { type: 'text', text: ' ' + rest.join(' ') });
        } else c.push({ type: 'text', text });
        if (fn && i % every === 1) c.push({ type: 'footnote', attrs: { text: 'הערת שוליים ' + ((i - 1) / every + 1) } });   // 300 הערות שוליים
        if (i % 100 === 0) content.push({ type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'פרק ' + (i / 100 + 1) }] });
        content.push({ type: 'paragraph', content: c });
    }
    return { type: 'doc', attrs: { comments }, content };
}

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'סקירה ארוכה', build(process.env.WP_LONG ? JSON.parse(process.env.WP_LONG) : undefined));
    const s0 = Date.now();
    await p.goto(t.editor + '#/d/' + doc);
    await p.waitForSelector('.wp-doc');
    const shown = Date.now() - s0;
    await t.settle(p);
    const settled = Date.now() - s0;
    const pages = await p.locator('.wp-page').count();
    const marks = await p.locator('.wp-doc .wp-cmt').count();
    t.ok('המסמך נפתח: ~150 עמודים, 300 הערות מסומנות, 300 הערות שוליים', pages >= 130 && marks === 300
        && await p.locator('.wp-doc .wp-fn-ref, .wp-doc [data-footnote]').count() >= 300, `${pages} עמודים, ${marks} הערות`);
    t.ok('פתיחה — המסמך על המסך תוך 10 שניות, והעימוד יציב תוך 30', shown < 10000 && settled < 30000, `על המסך ${shown}ms, יציב ${settled}ms`);

    const typing = async target => {
        await p.evaluate(x => { const e = document.querySelector('.wp-doc').editor; let at = 0;
            e.state.doc.descendants((n, pos) => { if (!at && n.type.name === 'heading' && n.textContent === x) at = pos + n.nodeSize + 3; });
            e.chain().focus().setTextSelection(at).run(); }, target);
        await p.waitForTimeout(500);
        return p.evaluate(async () => {
            const out = [];
            for (let i = 0; i < 20; i++) {
                const s = performance.now();
                document.querySelector('.wp-doc').editor.commands.insertContent('א');
                await new Promise(res => requestAnimationFrame(() => requestAnimationFrame(res)));
                out.push(performance.now() - s);
            }
            out.sort((a, b) => a - b);
            return { median: Math.round(out[10]), p90: Math.round(out[18]) };
        });
    };
    for (const target of ['פרק 1', 'פרק 7']) {
        const r = await typing(target);
        t.ok(`הקלדה ב"${target}" — חציון מתחת ל-100ms`, r.median < 100, `חציון ${r.median}ms, 90% — ${r.p90}ms`);
    }

    // מה שחוסך את הזמן (מטמון השורות של פסקה שלא השתנתה, עימוד ממופה) לא משנה את התוצאה: אותו עימוד כמו מדידה מאפס
    const snap = () => p.evaluate(() => {
        const v = document.querySelector('.wp-doc').editor.view;
        const at = el => { try { return v.posAtDOM(el, 0); } catch { return null; } };
        return [...v.dom.querySelectorAll('.wp-break')].map(el => `${at(el)}:${el.dataset.page}:${Math.round(parseFloat(el.style.height))}`).join('|')
            + '§' + [...v.dom.querySelectorAll('.wp-fn-area')].map(a => [...a.querySelectorAll('.wp-fn-note')].map(n => n.dataset.n).join(',')).join('|');
    });
    const before = await snap();
    await p.evaluate(() => document.querySelector('.wp-doc').editor.storage.wpPages.schedule());
    await t.settle(p);
    const after = await snap();
    t.ok('אחרי ההקלדה — אותו עימוד כמו מדידה מאפס (כל מעבר, הגובה שלו וההערות בכל עמוד)', before === after && before.split('|').length > 200,
        before === after ? `${before.split('§')[0].split('|').length} מעברים` : `${before.slice(0, 200)} ≠ ${after.slice(0, 200)}`);

    // הערה בתחתית עמוד מאוחר (הווידג'ט נשמר כשהמסמך זז — אסור שיפתח הערה אחרת)
    await p.click('.wp-fn-area .wp-fn-note[data-n="200"]');
    await p.waitForSelector('dialog .fn-edit-doc');
    const fnText = await p.locator('dialog .fn-edit-doc').textContent();
    t.ok('לחיצה על הערה 200 בתחתית העמוד — נפתחת הערה 200', fnText.includes('הערת שוליים 200'), fnText);
    await p.keyboard.press('Escape');
    await p.waitForSelector('dialog', { state: 'detached' });

    // הערה חדשה באמצע, וחלונית ההערות
    const c0 = Date.now();
    await p.keyboard.press('Shift+ArrowLeft');
    await p.keyboard.press('Shift+ArrowLeft');
    await p.keyboard.press('Control+Alt+KeyM');
    await p.waitForSelector('.wp-comments:not([hidden]) .cm-edit');
    const opened = Date.now() - c0;
    await p.keyboard.type('הערה חדשה');
    await p.keyboard.press('Control+Enter');
    await p.waitForFunction(() => Object.keys(document.querySelector('.wp-doc').editor.state.doc.attrs.comments).length === 301);
    const cards = await p.locator('.wp-comments .cm-card').count();
    t.ok('הערה חדשה: החלונית נפתחת תוך 3 שניות, עם כל 301 ההערות', opened < 3000 && cards === 301, `${opened}ms, ${cards} כרטיסים`);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
