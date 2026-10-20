/** ההטמעה בתוך אפליקציה ממקור אחר (host/), דרך host.js — כמו שמארחת אמיתית משתמשת. */
import { execFileSync } from 'node:child_process';

export default async function (t) {
    const reg = await (await fetch(t.host + 'api.php?action=register')).json();
    // מקור אחר (פורט אחר) → ממתין לאישור מנהל העורך; כאן מאשרים ישירות במסד הבדיקה
    execFileSync('php', ['-r', `putenv('WP_DATA_DIR=${t.data}'); require '${t.app}/lib/db.php'; db()->exec("UPDATE clients SET status = 'active'");`]);
    t.ok('רישום המארחת', ['pending', 'active'].includes(reg.status), reg.status);

    const ctx = await t.browser.newContext({ viewport: { width: 1100, height: 800 } });
    const p = await ctx.newPage();
    const errors = [];
    p.on('pageerror', e => errors.push(e.message));
    const exp = async f => (await fetch(t.host + 'api.php?action=export&f=' + f)).json();
    await p.goto(t.host + '?editor=' + encodeURIComponent(t.editor));
    await p.waitForFunction(() => window.events?.includes('ready'), null, { timeout: 15000 });
    const fr = p.frameLocator('iframe');
    await fr.locator('.wp-doc').click();
    await p.keyboard.type('הטמעה עובדת');
    await p.waitForFunction(() => window.events.includes('saved'), null, { timeout: 8000 });
    const log = () => p.locator('#log').textContent();
    t.ok('saved אחרי הקלדה, change true ואז false', (await log()).includes('change true') && (await log()).includes('change false'));
    t.ok('הטקסט בשרת', (await exp('text')).text.includes('הטמעה עובדת'));

    await p.evaluate(() => window.ed.setPage({ orientation: 'landscape' }));
    await p.waitForTimeout(200);
    t.ok('ed.setPage + ed.save() → true', await p.evaluate(() => window.ed.save()) === true);
    t.ok('הגדרות העמוד מהמארחת נשמרו', (await exp('json')).page.orientation === 'landscape');
    t.ok('אירוע page למארחת', (await log()).includes('page "landscape"'));

    await p.route('**/api.php?action=embed_save', r => r.abort());
    await fr.locator('.wp-doc').click();
    await p.keyboard.type(' עוד');
    t.ok('בלי רשת: ed.save() → false, ומצב מנותק', await p.evaluate(() => window.ed.save()) === false
        && (await fr.locator('.embed-bar .save-state[role=status]').textContent()).includes('מנותק'));
    await p.unroute('**/api.php?action=embed_save');
    await p.waitForTimeout(6000);
    t.ok('החיבור חזר → נשמר לבד', (await exp('text')).text.includes('עוד'));
    await fr.locator('button:has-text("סגירה")').click();
    await p.waitForTimeout(500);
    t.ok('close עם saved:true', (await log()).includes('close {"saved":true}'));
    t.ok('בלי שגיאות JS', !errors.length, errors.join(' | '));
    await ctx.close();
}
