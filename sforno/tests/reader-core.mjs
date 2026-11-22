/*
 * בדיקות הלוגיקה של הקורא (assets/js/reader-core.js, ו-strip של render.js) — מורץ מ-tests/g-reader.test.php:
 *   node tests/reader-core.mjs <pairs.json>
 * בלי דפדפן ובלי תלויות. מדפיס JSON: {n, fails: [שם…], wordHit: [true|false לכל צמד]} — ההשוואה ל-PHP (wordHit) בצד ה-PHP.
 */
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const C = require(new URL('../assets/js/reader-core.js', import.meta.url).pathname);
const R = require(new URL('../assets/js/render.js', import.meta.url).pathname);

let n = 0;
const fails = [];
const ok = (name, cond) => { n++; if (!cond) fails.push(name); };
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// ── K10: הסדר האישי מול רשימת הבסיס ─────────────────────────
const P = i => ('p' + String(i).padStart(15, '0'));
const base1 = [{ pid: P(1), name: 'שחרית' }, { pid: P(2), name: 'מנחה' }, { pid: P(3), name: 'ערבית' }];
const m0 = C.mergeOrder(null, base1);
ok('K10 משתמש חדש — הסדר של הבסיס', same(m0.order, [P(1), P(2), P(3)]));
const mine = { order: [P(3), P(1), P(2)], names: m0.names };
const base2 = [{ pid: P(2), name: 'מנחה' }, { pid: P(1), name: 'שחרית' }, { pid: P(4), name: 'תיקון חצות' }];   // המנהל: הזיז, הסיר את ערבית, הוסיף
const m1 = C.mergeOrder(mine, base2);
ok('K10 שינוי בבסיס לא משנה את הסדר האישי', same(m1.order.slice(0, 3), [P(3), P(1), P(2)]));
ok('K10 פריט חדש בבסיס — בסוף', m1.order[3] === P(4) && m1.list[3].available === true);
ok('K10 פריט שהוסר — נשאר במקומו, "לא זמין", עם השם שנשמר', m1.list[0].pid === P(3) && m1.list[0].available === false && m1.list[0].name === 'ערבית');
ok('K10 הפריטים הזמינים — עם הנתונים העדכניים של הבסיס', m1.list[1].name === 'שחרית' && m1.list[1].available);
ok('K10 מיזוג חוזר יציב', same(C.mergeOrder({ order: m1.order, names: m1.names }, base2).order, m1.order));
ok('K10 move', same(C.move([P(1), P(2), P(3)], P(3), 'up'), [P(1), P(3), P(2)]) && same(C.move([P(1)], P(1), 'up'), [P(1)]));
ok('K10 מזהה לא תקין בסדר האישי — מתעלמים', C.mergeOrder({ order: ['<x>', P(1)], names: {} }, base1).order[0] === P(1));

// ── K3: מנהגים — קבוע מול זמני ──────────────────────────────
const groups = [
    { grp: 'place', customs: [{ rule: 'eretz', name: 'ארץ ישראל', default_on: true }, { rule: 'chul', name: 'חוץ לארץ', default_on: false }] },
    { grp: 'quorum', customs: [{ rule: 'yachid', name: 'יחיד', default_on: false }, { rule: 'minyan', name: 'מניין', default_on: false }] },
];
let st = C.conds.enter(groups, {});
ok('K3 בלי הגדרה — ברירת המחדל, ואם אין — הראשון', same(st.perm, { place: 'eretz', quorum: 'yachid' }) && st.temp === null);
st = C.conds.enter(groups, { place: 'chul', quorum: 'gone' });
ok('K3 הגדרה קבועה שנשמרה; מנהג שאינו זמין — ברירת מחדל', same(st.perm, { place: 'chul', quorum: 'yachid' }));
const map0 = C.conds.map(st, groups);
ok('K3 המפה ל-render', same(map0, { eretz: false, chul: true, yachid: true, minyan: false }));
let t1 = C.conds.set(st, 'quorum', 'minyan');
ok('K3 שינוי בתוך התפילה — זמני, הקבוע לא השתנה', C.conds.isTemp(t1) && same(t1.perm, st.perm) && C.conds.current(t1).quorum === 'minyan');
const out = C.conds.leave(t1);
ok('K3 יציאה מהתפילה מבטלת את השינוי הזמני', !C.conds.isTemp(out) && out.temp === null && C.conds.current(out).quorum === 'yachid');
const again = C.conds.enter(groups, out.perm);
ok('K3 כניסה חוזרת — ההגדרה הקבועה', C.conds.current(again).quorum === 'yachid');
const saved = C.conds.save(t1);
ok('K3 "לשמור כקבוע"', saved.perm.quorum === 'minyan' && saved.temp === null);
ok('K3 חזרה לערך הקבוע — לא נחשב זמני', !C.conds.isTemp(C.conds.set(t1, 'quorum', 'yachid')));
ok('comboKey = sf_combo_key', C.comboKey({ quorum: 'yachid', place: 'eretz' }) === 'place=eretz;quorum=yachid');

// ── K2: המקום — sid + היסט יחסי ─────────────────────────────
const rects = [{ sid: 'sec00001', top: -400, bottom: 900 }, { sid: 'par00001', top: -40, bottom: 60 }, { sid: 'par00002', top: 60, bottom: 160 }];
ok('K2 הרכיב העמוק שהקו עובר בו, והיסט יחסי', same(C.pickTop(rects, 10), { sid: 'par00001', off: 0.5 }));
ok('K2 בין בלוקים — הקטע (ההורה)', C.pickTop([{ sid: 'sec00001', top: -400, bottom: 900 }, { sid: 'par00001', top: 20, bottom: 60 }], 10).sid === 'sec00001');
ok('K2 רכיב מוסתר (גובה 0) לא נבחר', C.pickTop([{ sid: 'a0000001', top: 0, bottom: 0 }, { sid: 'b0000001', top: 30, bottom: 80 }], 10).sid === 'b0000001');
ok('K2 גלילה חזרה: top + off·גובה − line', C.scrollDelta({ top: 500, bottom: 700 }, 0.25, 100) === 450);

// ── K7: קישור שיתוף — רק מזהים ──────────────────────────────
const link = C.shareLink('https://x.test/apps/sforno/index.html?utm=1#/r/old', 'abcdefghij012345', 'par00001');
ok('K7 הקישור: בסיס + #/r/<edition>/<sid> בלבד', link === 'https://x.test/apps/sforno/index.html#/r/abcdefghij012345/par00001');
ok('K7 sid לא תקין — לא נכנס', C.shareLink('https://x.test/', 'abcdefghij012345', 'פלוני') === 'https://x.test/#/r/abcdefghij012345');
let threw = false;
try { C.shareLink('https://x.test/', 'ספר של פלוני', 'par00001'); } catch (e) { threw = true; }
ok('K7 מהדורה שאינה מזהה — נדחה', threw);
ok('K7 shareLink לא מקבל שם או הערה (שלושה פרמטרים)', C.shareLink.length === 3);

// ── K6: הסתרת ניקוד בתצוגה, העתקה מהמקור ────────────────────
const src = 'בָּרוּךְ אַתָּה הֽ֑י';
const sm = R.stripMap(src, { niqqud: true, taamim: true });
ok('K6 בלי ניקוד וטעמים', sm.text === 'ברוך אתה הי' && !/[֑-ׇ]/.test(sm.text.replace(/[־׀׃׆]/g, '')));
ok('K6 רק ניקוד — הטעמים נשארים', R.strip('הַ֑', { niqqud: true }) === 'ה֑' && R.strip('הַ֑', { taamim: true }) === 'הַ');
const a = sm.text.indexOf('אתה'), z = a + 3;
ok('K6 מיפוי בחירה בתצוגה ← המקור (כולל הניקוד של האות האחרונה)', src.slice(sm.map[a], sm.map[z]) === 'אַתָּה');
ok('K6 מיפוי עד הסוף', src.slice(sm.map[0], sm.map[sm.text.length]) === src);
ok('K6 בלי הסתרה — הטקסט כמו שהוא', R.strip(src, null) === src && R.strip(src, {}) === src);
ok('K6 העתקה עם מקור', C.copyText(['בָּרוּךְ אַתָּה', ' שורה  \n'], C.sourceLine('סידור', 'עדות המזרח', 'שחרית')) === 'בָּרוּךְ אַתָּה\nשורה\n— סידור עדות המזרח, שחרית');
ok('K6 מקף, פסק וסוף פסוק — נשארים', R.strip('א־ב׀ג׃', { niqqud: true, taamim: true }) === 'א־ב׀ג׃');

// ── K9: wakeLock — כשל שקט ──────────────────────────────────
const wl = [
    C.wakeLock(undefined), C.wakeLock({}), C.wakeLock({ wakeLock: {} }),
    C.wakeLock({ wakeLock: { request: () => Promise.reject(new Error('NotAllowedError')) } }),
    C.wakeLock({ wakeLock: { request: () => { throw new Error('sync'); } } }),
    C.wakeLock({ wakeLock: { request: () => Promise.resolve({ ok: 1 }) } }),
];
const res = await Promise.all(wl.map(p => p.then(v => v, () => 'threw')));
ok('K9 כל הכשלים — null, בלי חריגה', res.slice(0, 5).every(v => v === null));
ok('K9 הצלחה — האובייקט', res[5] && res[5].ok === 1);

// ── תהילים ──────────────────────────────────────────────────
const all = arr => { const s = new Set(); for (const [x, y] of arr) for (let i = x; i <= y; i++) s.add(i); return s; };
ok('תהילים: חלוקת החודש מכסה 1–150', all(C.MONTH).size === 150 && C.MONTH.length === 30);
ok('תהילים: חלוקת השבוע מכסה 1–150, בלי חפיפה', all(C.WEEK).size === 150 && C.WEEK.reduce((s, [x, y]) => s + y - x + 1, 0) === 150);
ok('תהילים: כ"ט בחודש חסר — גם של ל\'', same(C.monthChapters(29, 29), [140, 141, 142, 143, 144, 145, 146, 147, 148, 149, 150]) && C.monthChapters(29, 30).length === 5);
const fakeHeb = d => ({ 21: 29, 22: 1 })[d.getDate()] || d.getDate();
const td = C.today(new Date(2026, 10, 21, 23, 59), fakeHeb);
ok('תהילים: "של היום" לפי חצות מקומית', td.day === 29 && td.monthLen === 29 && td.weekday === 6 && td.week[0] === 120);
ok('תהילים: פרקים מתוכן העניינים', same(C.chapters([{ sid: 'a', kind: 'section', parent_sid: null, title: 'פרק א' }, { sid: 'x', kind: 'anchor', parent_sid: 'a' },
    { sid: 'b', kind: 'section', parent_sid: null, title: null }]), [{ n: 1, sid: 'a', title: 'פרק א' }, { n: 2, sid: 'b', title: 'פרק 2' }]));
ok('תהילים: היום העברי מ-Intl', C.hebDayIntl(new Date(2026, 10, 22, 12)) >= 1 && C.hebDayIntl(new Date(2026, 10, 22, 12)) <= 30);

// ── wordHit (ההשוואה ל-PHP — בצד ה-PHP) ──────────────────────
const pairs = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const wordHit = pairs.map(([w, t]) => C.wordHit(w, t));

process.stdout.write(JSON.stringify({ n, fails, wordHit }));
