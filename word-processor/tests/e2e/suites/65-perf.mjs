/**
 * ביצועים (תנאי הקבלה של שלב 3): טבלה של 200 שורות — הקלדה חלקה. לכל הקשה: מההקשה ועד שהעימוד נרגע (שתי מסגרות,
 * כלומר ~33ms הוא המינימום שאפשר למדוד). הסף נדיב (מחשבי CI איטיים) — הוא תופס האטה גסה, לא מודד מהירות.
 */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const rows = Array.from({ length: 200 }, (_, i) => ({ type: 'tableRow', content: ['שורה ' + (i + 1), 'תיאור של השורה עם כמה מילים', String(1000 + i)].map(x => ({ type: 'tableCell', content: [P(x)] })) }));
    const doc = await t.newDoc(p, 'ביצועים', { type: 'doc', content: [P('לפני'), { type: 'table', content: rows }, P('אחרי')] });
    await t.openDoc(p, doc);
    for (const target of ['שורה 5', 'שורה 150']) {
        await p.evaluate(x => { const e = document.querySelector('.wp-doc').editor; let at = 0; e.state.doc.descendants((n, pos) => { if (n.isText && n.text === x) at = pos + n.nodeSize; }); e.chain().focus().setTextSelection(at).run(); }, target);
        await p.waitForTimeout(300);
        const r = await p.evaluate(async () => {
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
        t.ok(`200 שורות, הקלדה ב"${target}" — חציון מתחת ל-100ms`, r.median < 100, `חציון ${r.median}ms, 90% — ${r.p90}ms`);
    }
    t.ok('העימוד: 200 השורות על כמה עמודים', await p.locator('.wp-page').count() >= 8);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
