/**
 * עריכה משותפת בהטמעה (שלב 5): שני משתמשים של המארחת (רינה ומשה) פותחים את אותו מסמך, כל אחד בחלון שלו —
 * שניהם עורכים בבת אחת (בלי נעילה), כל אחד רואה את מה שהשני כתב תוך שנייה-שתיים ואת העיגול של השני, והמסמך
 * שבשרת כולל את שניהם. רינה סוגרת — משה כבר לא רואה אותה.
 */
import { execFileSync } from 'node:child_process';

export default async function (t) {
    await (await fetch(t.host + 'api.php?action=register')).json();
    execFileSync('php', ['-r', `putenv('WP_DATA_DIR=${t.data}'); require '${t.app}/lib/db.php'; db()->exec("UPDATE clients SET status = 'active'");`]);
    const open = async u => {
        const ctx = await t.browser.newContext({ viewport: { width: 1100, height: 800 } });
        const p = await ctx.newPage();
        p.errors = [];
        p.on('pageerror', e => p.errors.push(e.message));
        await p.goto(t.host + `?u=${u}&editor=` + encodeURIComponent(t.editor));
        await p.waitForFunction(() => window.events?.includes('ready'), null, { timeout: 15000 });
        return p;
    };
    const rina = await open('u1');
    const moshe = await open('u2');
    const fr = p => p.frameLocator('iframe');
    // הטקסט של המסמך עצמו (בלי שמות הסמנים של האחרים); ה-iframe ממקור אחר — דרך ה-frame, לא contentDocument
    const text = p => fr(p).locator('.wp-doc').evaluate(el => el.editor.state.doc.textContent);
    const until = async (fn, ms = 8000) => { for (const end = Date.now() + ms; Date.now() < end; await new Promise(r => setTimeout(r, 200))) if (await fn()) return true; return false; };
    const peersOf = p => fr(p).locator('.embed-bar .peers').getAttribute('title').then(x => x || '');
    t.ok('שניהם בעריכה (בלי נעילה)', (await rina.locator('#log').textContent()).includes('"can":"edit"') && (await moshe.locator('#log').textContent()).includes('"can":"edit"'));

    // רינה בסוף, משה בהתחלה — בו בזמן
    await fr(rina).locator('.wp-doc').click();
    await rina.keyboard.press('Control+End');
    await rina.keyboard.type(' רינה כתבה');
    await fr(moshe).locator('.wp-doc').click();
    await moshe.keyboard.press('Control+Home');
    await moshe.keyboard.type('משה כתב ');
    const both = s => s.includes('רינה כתבה') && s.includes('משה כתב');
    await until(async () => both(await text(rina)) && both(await text(moshe)));
    const [a, b] = [await text(rina), await text(moshe)];
    t.ok('כל אחד רואה את מה שהשני כתב — אותו מסמך בשני החלונות', both(a) && a === b, JSON.stringify([a.slice(0, 80), b.slice(0, 80)]));
    await rina.evaluate(() => window.ed.save());
    await moshe.evaluate(() => window.ed.save());
    const srv = (await (await fetch(t.host + 'api.php?action=export&f=text')).json()).text;
    t.ok('בשרת — שניהם', both(srv), srv.slice(0, 120));
    const peers = await peersOf(moshe);
    t.ok('משה רואה שרינה במסמך (עיגול בכותרת)', peers.includes('רינה'), peers);

    // שמירה מחוץ לעריכה המשותפת (כמו שחזור גרסה) — עידן חדש: החלון נטען מחדש במקום, בלי לרענן את הדף, עם התוכן החדש
    const docId = JSON.parse((await moshe.locator('#log').textContent()).match(/ready (\{[^\n]*\})/)[1]).doc;
    execFileSync('php', ['-r', `putenv('WP_DATA_DIR=${t.data}'); require '${t.app}/lib/embed.php'; $r = db()->query("SELECT * FROM docs WHERE id = '${docId}'")->fetch();`
        + ` doc_save($r, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'נשמר מבחוץ']]]]], null, 'x', 'מערכת');`]);
    await moshe.evaluate(() => { window.marker = 1; });
    const reloaded = await until(async () => (await text(moshe)) === 'נשמר מבחוץ');
    t.ok('שמירה מבחוץ — נטען מחדש במקום (אותו דף), עם התוכן החדש, ועדיין בעריכה', reloaded && await moshe.evaluate(() => window.marker === 1)
        && await fr(moshe).locator('.wp-doc').evaluate(el => el.editor.isEditable), await text(moshe));
    await fr(moshe).locator('.wp-doc').click();
    await moshe.keyboard.press('Control+End');
    await moshe.keyboard.type(' ואחרי');
    await moshe.evaluate(() => window.ed.save());
    t.ok('ממשיכים לערוך אחרי הטעינה מחדש — נשמר', (await (await fetch(t.host + 'api.php?action=export&f=text')).json()).text.includes('נשמר מבחוץ ואחרי'));

    // רינה סוגרת — יוצאת מהנוכחות
    await rina.goto('about:blank');
    await rina.context().close();
    // ה-leave נשלח ב-keepalive ביציאה; כשההקשר נסגר מיד הוא לפעמים לא יוצא, ואז הנוכחות פגה בשרת (PRESENCE_SECONDS = 15)
    await until(async () => !(await peersOf(moshe)).includes('רינה'), 25000);
    t.ok('רינה סגרה — משה כבר לא רואה אותה', !(await peersOf(moshe)).includes('רינה'), await peersOf(moshe));
    t.ok('בלי שגיאות JS', !moshe.errors.length && !rina.errors.length, [...moshe.errors, ...rina.errors].join(' | '));
    await moshe.context().close();
}
