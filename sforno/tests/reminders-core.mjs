/*
 * בדיקות התזכורות במכשיר (assets/js/reminders-core.js) — מורץ מ-tests/l-reminders.test.php:
 *   node tests/reminders-core.mjs <fixture.json>   → {n, fails: [שם…]}
 * ה-fixture נבנה ב-PHP (lib/reminders.php): אותם מקרים — וכאן בודקים שה-JS נותן **בדיוק** את אותו דבר (המופעים במילישנייה,
 * "מושתק", חלונות ההשתקה, clean/prefs), ושבתוך האפליקציה ובקובץ היומן מושתק = לא יוצא (L5, L6). בלי דפדפן ובלי תלויות.
 */
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const Cal = require(new URL('../assets/js/calendar-core.js', import.meta.url).pathname);
const R = require(new URL('../assets/js/reminders-core.js', import.meta.url).pathname);
const fx = JSON.parse(readFileSync(process.argv[2], 'utf8'));

let n = 0;
const fails = [];
const ok = (name, cond) => { n++; if (!cond) fails.push(name); };
const stable = v => Array.isArray(v) ? '[' + v.map(stable).join(',') + ']'
    : v && typeof v === 'object' ? '{' + Object.keys(v).sort().map(k => JSON.stringify(k) + ':' + stable(v[k])).join(',') + '}' : JSON.stringify(v);
const same = (a, b) => stable(a) === stable(b);

// ── clean / prefs — זהים ל-PHP ──────────────────────────────
fx.clean.forEach((c, i) => ok(`clean #${i} (${c.in.id}) זהה ל-PHP`, same(R.clean(c.in), c.out)));
fx.prefs.forEach((c, i) => ok(`prefs #${i} זהה ל-PHP`, same(R.prefs(c.in), c.out)));

// ── התכנון: אותם מופעים, "מושתק" וחלונות — במילישנייה ──────────
for (const c of fx.cases) {
    const ctx = Object.assign({}, c.ctx, { reminders: c.ctx.reminders });
    const p = R.plan(Cal, ctx, c.from, c.to);
    ok(`${c.label}: חלונות ההשתקה זהים ל-PHP`, same(p.mute, c.plan.mute));
    ok(`${c.label}: המופעים ו"מושתק" זהים ל-PHP (${c.plan.occ.length})`, same(p.occ, c.plan.occ));
    ok(`${c.label}: need זהה ל-PHP`, same(p.need, c.plan.need));
    // L5 — בתוך האפליקציה: מופע מושתק לא מוצג כבאנר (arm מסנן !o.muted) ולא יוצא ממנו קובץ יומן
    const muted = p.occ.filter(o => o.muted);
    const byId = Object.fromEntries(ctx.reminders.map(r => [r.id, r]));
    ok(`${c.label}: L6 אין .ics למופע מושתק`, muted.every(o => R.ics(o, byId[o.rem], 'https://x.test/', Date.now()) === null));
    const free = p.occ.filter(o => !o.muted);
    ok(`${c.label}: L6 יש .ics למופע שאינו מושתק`, free.every(o => /DTSTART:\d{8}T\d{6}Z/.test(R.ics(o, byId[o.rem], 'https://x.test/', Date.now()) || '')));
}
ok('יש מקרים עם מופעים מושתקים ולא מושתקים', fx.cases.some(c => c.plan.occ.some(o => o.muted)) && fx.cases.some(c => c.plan.occ.some(o => !o.muted)));

// ── .ics: מבנה, תווים, קיפול ────────────────────────────────
{
    const rem = R.clean({ id: 'i1', name: 'שחרית, ותיקין; בבית\nהכנסת — שם ארוך מאוד מאוד מאוד', type: 'fixed', time: '05:45', days: [0], prayer: { edition: 'abcdefghijklmnop', sid: 's1', name: 'שחרית' } })
        || R.clean({ id: 'i1', name: 'שחרית, ותיקין; בבית הכנסת — שם ארוך מאוד מאוד', type: 'fixed', time: '05:45', days: [0], prayer: { edition: 'abcdefghijklmnop', sid: 's1', name: 'שחרית' } });
    const t = R.ics({ rem: 'i1', ms: Date.UTC(2026, 10, 22, 3, 45), muted: false }, rem, 'https://x.test/sforno/index.html#/r/abcdefghijklmnop/s1', Date.UTC(2026, 10, 21));
    ok('ics: VCALENDAR/VEVENT/VALARM, DTSTART ב-UTC', /^BEGIN:VCALENDAR\r\n/.test(t) && t.includes('BEGIN:VEVENT') && t.includes('BEGIN:VALARM') && t.includes('DTSTART:20261122T034500Z') && t.endsWith('END:VCALENDAR\r\n'));
    ok('ics: פסיק ונקודה-פסיק מוברחים', t.includes('\\,') && t.includes('\\;'));
    const enc = new TextEncoder();
    ok('ics: כל שורה עד 75 בתים (קיפול)', t.split('\r\n').every(l => enc.encode(l).length <= 75));
    ok('ics: קיפול לא חותך תו', !t.includes('\uFFFD') && t.replace(/\r\n /g, '').includes('SUMMARY:' + rem.name.replace(/,/g, '\\,').replace(/;/g, '\\;')));
    ok('ics: הקישור לתפילה', t.replace(/\r\n /g, '').includes('URL:https://x.test/sforno/index.html#/r/abcdefghijklmnop/s1'));
    ok('ics: מושתק → null', R.ics({ rem: 'i1', ms: 1, muted: true }, rem, '', 0) === null);
}

// ── שונות ───────────────────────────────────────────────────
ok('link: תפילה מקושרת → #/r/<מהדורה>/<sid>', R.link({ prayer: { edition: 'abcdefghijklmnop', sid: 's1' } }) === '#/r/abcdefghijklmnop/s1' && R.link({ prayer: null }) === '#/reminders');
ok('ישראל / חו"ל: לפי cc, אחרת לפי אזור הזמן', R.inIsrael({ cc: 'IL', tz: 'Asia/Jerusalem' }, null) && !R.inIsrael({ cc: 'US', tz: 'America/New_York' }, null)
    && R.inIsrael(null, 'Asia/Jerusalem') && !R.inIsrael(null, 'Europe/London'));
ok('יום טוב: פסח, שבועות, ראש השנה, יום כיפור, סוכות, שמיני עצרת; שני — רק בחו"ל',
    R.yomTov({ m: 1, d: 15 }, true) && !R.yomTov({ m: 1, d: 16 }, true) && R.yomTov({ m: 1, d: 16 }, false) && R.yomTov({ m: 3, d: 6 }, true) && !R.yomTov({ m: 3, d: 7 }, true)
    && R.yomTov({ m: 7, d: 2 }, true) && R.yomTov({ m: 7, d: 10 }, true) && R.yomTov({ m: 7, d: 22 }, true) && !R.yomTov({ m: 7, d: 23 }, true) && R.yomTov({ m: 7, d: 23 }, false)
    && !R.yomTov({ m: 7, d: 17 }, false) && !R.yomTov({ m: 9, d: 25 }, false));
ok('שיטת ההשתקה מסומנת לבדיקה', R.MUTE.test === true);

process.stdout.write(JSON.stringify({ n, fails }));
