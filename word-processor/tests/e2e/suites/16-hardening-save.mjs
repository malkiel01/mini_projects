/**
 * סבב הקשחה (ביקורות 03 / 04): R06 / F01 — טיוטה במכשיר: יציאה לפני הטיימר, הקלדה רצופה (maxWait), אחסון חסום;
 * F08 — flush בזמן הקלדה מחזיר dirty, flushUntilClean מנקה; F09 — עותק מהתנגשות נשמר בנפרד;
 * R07 / F06 — שחזור גרסה בתוך 10 דקות: המצב שלפני והמשוחזר — שתי גרסאות (גם אחרי פתיחה מחדש).
 */
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    // בדיקות יחידה על saver.js בדפדפן (IndexedDB אמיתי, send מדומה)
    const unit = await p.evaluate(async () => {
        const { createSaver, drafts } = await import('./assets/js/saver.js');
        const wait = ms => new Promise(r => setTimeout(r, ms));
        const out = {};
        let doc = { v: 0 };
        const mk = (key, extra = {}) => createSaver({ version: 1, draftKey: key, snapshot: () => structuredClone(doc), send: async () => ({ version: 2 }), ...extra });
        // 1. שינוי ומיד יציאה (destroy) — לפני טיימר הטיוטה
        doc = { v: 1 };
        const a = mk('t:destroy');
        doc = { v: 2 };
        a.changed();
        a.destroy();
        await wait(300);
        out.destroy = (await drafts.get('t:destroy'))?.json?.v ?? null;
        // 2. הקלדה רצופה (שינוי כל 100 מ"ש, 1.5 שנ') — הטיוטה נכתבת בזמן ההקלדה, לא רק בסוף
        const b = mk('t:typing');
        let seen = null;
        for (let i = 1; i <= 15; i++) { doc = { v: 100 + i }; b.changed(); await wait(100); if (i === 13) seen = (await drafts.get('t:typing'))?.json?.v ?? null; }
        out.typing = seen;
        b.destroy();
        // 3. אחסון חסום — כשל נראה (localFailed), local === 'failed'
        const orig = indexedDB.open.bind(indexedDB);
        indexedDB.open = () => { throw new DOMException('blocked', 'SecurityError'); };
        const events = [];
        doc = { v: 1 };
        const c = mk('t:blocked', { onState: (s, info) => events.push(info.localFailed) });
        doc = { v: 3 };
        c.changed();
        await c.flushDraft();
        out.blocked = { local: c.local, failedEvent: events.includes(true) };
        indexedDB.open = orig;
        c.destroy();
        // 4. F08 — הקלדה בזמן השמירה: flush מחזיר dirty: true; flushUntilClean — נקי
        doc = { v: 1 };
        let release;
        const slow = createSaver({ version: 1, draftKey: 't:slow', snapshot: () => structuredClone(doc),
            send: async () => { await new Promise(r => { release = r; }); return { version: Date.now() }; } });
        doc = { v: 5 };
        const f = slow.flush();
        await wait(20);
        doc = { v: 6 };   // הוקלד בזמן הבקשה
        release();
        const r1 = await f;
        const p2 = slow.flushUntilClean();
        await wait(20); release();
        const r2 = await p2;
        out.f08 = { first: r1.dirty, clean: r2.dirty, dirtyAfter: slow.dirty };
        slow.destroy();
        // 5. F09 — עותק מהתנגשות נשמר בנפרד, reset לא מוחק אותו
        doc = { v: 1 };
        const d = mk('t:conflict');
        doc = { v: 7 };
        d.changed();
        await d.keepConflictCopy();
        d.reset(9);
        out.conflict = (await drafts.get('t:conflict:conflict'))?.json?.v ?? null;
        d.destroy();
        return out;
    });
    t.ok('R06 / F01: יציאה מיד אחרי שינוי — הטיוטה נכתבת (לא מתבטלת)', unit.destroy === 2, JSON.stringify(unit.destroy));
    t.ok('R06 / F01: הקלדה רצופה — הטיוטה מתעדכנת בזמן ההקלדה (maxWait)', unit.typing >= 105, String(unit.typing));
    t.ok('R06 / F01: אחסון חסום — כשל גלוי (local: failed, localFailed)', unit.blocked.local === 'failed' && unit.blocked.failedEvent, JSON.stringify(unit.blocked));
    t.ok('F08: הקלדה בזמן שמירה — flush מחזיר dirty, flushUntilClean מנקה', unit.f08.first === true && unit.f08.clean === false && unit.f08.dirtyAfter === false, JSON.stringify(unit.f08));
    t.ok('F09: עותק מהתנגשות נשמר בנפרד ולא נמחק ב-reset', unit.conflict === 7, String(unit.conflict));

    // R07: שתי שמירות בתוך 10 דקות (מתאחדות), שחזור הגרסה הקודמת דרך החלון
    const doc = await t.newDoc(p, 'שחזור', { type: 'doc', content: [P('מקור')] });
    await t.openDoc(p, doc);
    // הגרסה של "מקור" — סגורה (כאילו עברו 10 דקות): השמירות הבאות יוצרות גרסה חדשה ומתאחדות ביניהן
    execFileSync('php', ['-r', `$d = new PDO('sqlite:' . $argv[1]); $d->prepare('UPDATE versions SET started_at = started_at - 700 WHERE doc = ?')->execute([$argv[2]]);`,
        join(t.data, 'word-processor.sqlite'), doc]);
    await p.click('.wp-doc p');
    await p.keyboard.press('End');
    await p.keyboard.type(' עריכה ראשונה');
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(500);
    const texts = async () => {
        const vs = (await t.api(p, 'my_versions', { doc })).versions;
        const out = [];
        for (const v of vs) out.push((await t.api(p, 'my_version', { doc, id: v.id })).json.content.map(b => (b.content || []).map(x => x.text).join('')).join('/'));
        return out;
    };
    p.on('dialog', d => d.accept());
    const before = await texts();
    await t.tool(p, 'גרסאות');
    await p.waitForSelector('dialog .use-row');
    await p.locator('dialog .use-row').last().click();
    await p.waitForSelector('dialog', { state: 'detached' });
    await p.waitForTimeout(400);
    const after = await texts();
    const shown = await p.locator('.wp-doc p').first().textContent();
    t.ok('R07: אחרי השחזור — התוכן המשוחזר במסך', shown === before[before.length - 1], `${shown} / ${before.join(' | ')}`);
    t.ok('R07: המצב שלפני השחזור נשמר כגרסה נפרדת', after.length === before.length + 1 && after.includes('מקור עריכה ראשונה') && after[0] === before[before.length - 1], after.join(' | '));
    // עריכה מיד אחרי השחזור (אותו משתמש, בתוך 10 דקות) — גרסה חדשה, לא דורסת את המשוחזר
    await p.click('.wp-doc p');
    await p.keyboard.press('End');
    await p.keyboard.type(' אחרי');
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(500);
    await t.openDoc(p, doc);
    const final = await texts();
    t.ok('R07: אחרי עריכה ופתיחה מחדש — המשוחזר והמצב שלפניו עדיין בגרסאות', final[0] === 'מקור אחרי' && final.includes('מקור עריכה ראשונה') && final.filter(x => x === 'מקור').length >= 1 && final.length === after.length + 1, final.join(' | '));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
