/**
 * שער גבולות הדף (ביקורת 04, R01): כל מילה במסמך — בתוך אזור התוכן של העמוד שהיא עליו, ולא חתוכה.
 * לכל צומת טקסט, מילה אחרי מילה (בלי רווחים — רווח בסוף שורה מימין לשמאל "תלוי" בשוליים, כמו ב-Word):
 * המלבן של המילה (Range.getClientRects — מלבן לכל שורה), העמוד שמרכז המילה בגובה שלו בתוכו, ואזור התוכן של העמוד
 * (העמוד פחות השוליים, כפי שצוירו: הכותרת העליונה בגובה השוליים העליונים ובריפוד השוליים בצדדים).
 * סף: 1.5px לרוחב; לגובה — המלבן של הגופן גבוה מהשורה, ולכן מותר לו לחרוג עד רבע מגובהו (המרכז — תמיד בפנים).
 * בנוסף: מילה שאב שלה חותך אותה (overflow לא גלוי) — הפרה גם היא: אסור לפתור חריגה בהסתרה.
 * חריג מוגדר אחד: הערת שוליים ארוכה שנמשכת לעמוד הבא (.wp-fn-part — חלון בגובה החלק שבעמוד). מילה שמוסתרת בחלון
 * כזה תקינה רק אם אותה מילה (אותו מקום באותה הערה) גלויה במלואה בחלון ההמשך של ההערה — אחרת זו הפרה.
 * גם סימוני העריכה של מעבר עמוד / מעבר מקטע (R02): הקו שלהם (::before / border, ברוחב הפנימי של הצומת) — בתוך
 * אזור התוכן של העמוד שהם עליו.
 * לא נבדקים: הכותרת העליונה והתחתונה ומספר העמוד (בשוליים בכוונה), סימן המים.
 * מחזיר מערך הפרות: [{ text, page, rect, box, why }] — ריק כשהכול בפנים.
 */
export function checkBounds(p, { tol = 1.5, limit = 50 } = {}) {
    return p.evaluate(([tol, limit]) => {
        const doc = document.querySelector('.wp-doc');
        const pages = [...document.querySelectorAll('.wp-pages .wp-page')].map(pg => {
            const r = pg.getBoundingClientRect();
            const z = r.width / pg.offsetWidth || 1;
            const hdr = pg.querySelector('.wp-hdr'), ftr = pg.querySelector('.wp-ftr');
            const cs = getComputedStyle(hdr);
            return { r, box: { left: r.left + parseFloat(cs.paddingLeft) * z, right: r.right - parseFloat(cs.paddingRight) * z,
                top: r.top + hdr.offsetHeight * z, bottom: r.bottom - ftr.offsetHeight * z } };
        });
        const out = [];
        const skip = el => el.closest('.wp-break, .wp-hf, .wp-wm, [hidden]') || getComputedStyle(el).display === 'none';
        // האב הקרוב שחותך (overflow שאינו visible) — בתוך המסמך
        const clipper = el => { for (let a = el; a && a !== doc; a = a.parentElement) { const o = getComputedStyle(a); if (o.overflowX !== 'visible' || o.overflowY !== 'visible') return a; } return null; };
        const inside = (rc, b) => rc.left >= b.left - tol && rc.right <= b.right + tol && rc.top >= b.top - rc.height / 4 && rc.bottom <= b.bottom + rc.height / 4;
        // הערה שנחתכה: המילה (צומת הטקסט ה-ti בתוך ההערה, מילה מ-offset) גלויה בחלון אחר של אותה הערה?
        const parts = [...doc.querySelectorAll('.wp-fn-part')];
        const textNodes = el => { const a = [], tw = document.createTreeWalker(el, NodeFilter.SHOW_TEXT); for (let x = tw.nextNode(); x; x = tw.nextNode()) a.push(x); return a; };
        const shownElsewhere = (part, n, start, end) => {
            const ti = textNodes(part).indexOf(n);
            return parts.some(o => {
                if (o === part || o.textContent !== part.textContent) return false;
                const m = textNodes(o)[ti];
                if (!m) return false;
                const r = document.createRange();
                r.setStart(m, start); r.setEnd(m, end);
                const ob = o.getBoundingClientRect();
                const rs = [...r.getClientRects()].filter(x => x.width >= 0.5);
                return rs.length > 0 && rs.every(x => inside(x, ob));
            });
        };
        const w = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT);
        for (let n = w.nextNode(); n && out.length < limit; n = w.nextNode()) {
            if (!n.data.trim() || skip(n.parentElement)) continue;
            const clipEl = clipper(n.parentElement);
            const clip = clipEl?.getBoundingClientRect();
            for (const m of n.data.matchAll(/\S+/gu)) {
                const range = document.createRange();
                range.setStart(n, m.index);
                range.setEnd(n, m.index + m[0].length);
                for (const rc of range.getClientRects()) {
                    if (rc.width < 0.5 || rc.height < 0.5) continue;
                    const text = m[0].slice(0, 30);
                    const rect = [rc.left, rc.top, rc.right, rc.bottom].map(v => Math.round(v));
                    if (clip && !inside(rc, clip)) {
                        if (clipEl.classList.contains('wp-fn-part') && shownElsewhere(clipEl, n, m.index, m.index + m[0].length)) continue;
                        out.push({ text, page: null, rect, box: [clip.left, clip.top, clip.right, clip.bottom].map(v => Math.round(v)), why: 'חתוך (מוסתר) בתוך אלמנט' });
                        continue;
                    }
                    const cy = (rc.top + rc.bottom) / 2;
                    const k = pages.findIndex(pg => cy >= pg.r.top && cy <= pg.r.bottom);
                    if (k < 0) { out.push({ text, page: null, rect, why: 'בין עמודים / מחוץ לכל עמוד' }); continue; }
                    const b = pages[k].box;
                    const vt = Math.max(tol, rc.height / 4);
                    const why = rc.left < b.left - tol ? 'משמאל לאזור התוכן' : rc.right > b.right + tol ? 'מימין לאזור התוכן'
                        : (rc.top < b.top - vt || cy < b.top) ? 'מעל אזור התוכן' : (rc.bottom > b.bottom + vt || cy > b.bottom) ? 'מתחת לאזור התוכן' : null;
                    if (why) out.push({ text, page: k + 1, rect, box: [b.left, b.top, b.right, b.bottom].map(v => Math.round(v)), why });
                }
            }
        }
        // סימוני מעבר עמוד / מקטע: הרוחב הפנימי (בלי הגבולות השקופים של המקטע) — בתוך אזור התוכן של העמוד
        for (const el of doc.querySelectorAll('.wp-section-break, .wp-page-break')) {
            if (getComputedStyle(el).display === 'none') continue;
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            const z = r.width / el.offsetWidth || 1;
            const left = r.left + parseFloat(cs.borderLeftWidth) * z, right = r.right - parseFloat(cs.borderRightWidth) * z;
            // הסימון יושב בתחתית הטקסט של העמוד: העמוד — זה שהקו (ראש הצומת) בתוכו, או האחרון שמעליו
            let k = -1;
            pages.forEach((pg, i) => { if (r.top >= pg.r.top - 1) k = i; });
            if (k < 0) { out.push({ text: el.className, page: null, rect: [left, r.top, right, r.bottom].map(Math.round), why: 'סימון מחוץ לעמודים' }); continue; }
            const b = pages[k].box;
            if (left < b.left - tol || right > b.right + tol)
                out.push({ text: el.className, page: k + 1, rect: [left, r.top, right, r.bottom].map(Math.round), box: [b.left, b.top, b.right, b.bottom].map(Math.round), why: 'סימון המעבר חורג מרוחב העמוד' });
        }
        return out;
    }, [tol, limit]);
}
