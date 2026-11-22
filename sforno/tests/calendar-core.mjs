/*
 * בדיקות הלוח העברי, זמני היום והמידע היומי במכשיר (assets/js/calendar-core.js, daily-core.js, reader-core.js) — מורץ מ-
 * tests/k-calendar.test.php:   node tests/calendar-core.mjs <fixture.json>   → {n, fails: [שם…]}
 * ה-fixture נבנה ב-PHP (lib/calendar.php): אותם תאריכים, מקומות ושיטות — וכאן בודקים שה-JS נותן **בדיוק** את אותו דבר,
 * ושהוא תואם ל-Intl של node (לוח עברי) ולטבלאות ב-tests/zmanim-cases.json. בלי דפדפן ובלי תלויות.
 */
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const C = require(new URL('../assets/js/calendar-core.js', import.meta.url).pathname);
const D = require(new URL('../assets/js/daily-core.js', import.meta.url).pathname);
const R = require(new URL('../assets/js/reader-core.js', import.meta.url).pathname);
const S = require(new URL('../assets/js/sync-core.js', import.meta.url).pathname);
const fx = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const zc = JSON.parse(readFileSync(new URL('./zmanim-cases.json', import.meta.url), 'utf8'));

let n = 0;
const fails = [];
const ok = (name, cond) => { n++; if (!cond) fails.push(name); };
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// ── K1: 1,000 תאריכים — זהים ל-PHP, ותואמים ל-Intl ─────────────
const F = new Intl.DateTimeFormat('en-u-ca-hebrew', { timeZone: 'UTC', year: 'numeric', month: 'long', day: 'numeric' });
const ICU = { Tishri: 7, Heshvan: 8, Kislev: 9, Tevet: 10, Shevat: 11, 'Adar I': 12, 'Adar II': 13, Adar: 13, Nisan: 1, Iyar: 2, Sivan: 3, Tamuz: 4, Av: 5, Elul: 6 };
let k1 = 0, k1i = 0, k1r = 0;
fx.dates.forEach(([y, m, d], i) => {
    const h = C.toHebrew(y, m, d);
    const p = fx.php[i];
    if (h.y !== p.y || h.m !== p.m || h.d !== p.d || h.leap !== p.leap || C.hebText(h) !== p.text) k1++;
    const parts = {};
    for (const x of F.formatToParts(new Date(Date.UTC(y, m - 1, d)))) parts[x.type] = x.value;
    if (+parts.year !== h.y || ICU[parts.month] !== h.m || +parts.day !== h.d) k1i++;
    const back = C.fromHebrew(h.y, h.m, h.d);
    if (!back || back.y !== y || back.m !== m || back.d !== d) k1r++;
});
ok('K1 1,000 תאריכים: JS = PHP (שנה, חודש, יום, מעוברת, הטקסט)' + (k1 ? ' — ' + k1 + ' שונים' : ''), k1 === 0 && fx.dates.length === 1000);
ok('K1 1,000 תאריכים: JS = Intl (לוח עברי של ICU)' + (k1i ? ' — ' + k1i + ' שונים' : ''), k1i === 0);
ok('K1 עברי ← לועזי ← עברי חוזר לאותו יום', k1r === 0);
ok('K1 גימטריה: ט"ו, ט"ז, תשפ"ז, י\'', C.gem(15) === 'ט"ו' && C.gem(16) === 'ט"ז' && C.gem(5787) === 'תשפ"ז' && C.gem(10) === "י'");

// ── K2: אדר בשנה פשוטה ובמעוברת (ש6) ─────────────────────────
const plain = C.toHebrew(2026, 3, 3);   // תשפ"ו — פשוטה
const leap = [C.toHebrew(2027, 2, 15), C.toHebrew(2027, 3, 15)];   // תשפ"ז — מעוברת
ok('K2 שנה פשוטה: אדר = 13 (אדר ב\'), ושמו "אדר"', !plain.leap && plain.m === 13 && C.monthName(13, false) === 'אדר' && C.hebText(plain).includes(' אדר '));
ok('K2 שנה פשוטה: אדר א\' (12) לא קיים', C.fromHebrew(5786, 12, 1) === null && C.monthLen(5786, 12) === 0);
ok('K2 שנה מעוברת: אדר א\' ואדר ב\' נפרדים', leap[0].leap && leap[0].m === 12 && leap[1].m === 13 && C.fromHebrew(5787, 12, 14).m !== C.fromHebrew(5787, 13, 14).m);
ok('K2 מפתח ההלכה: י"ד אדר בשנה פשוטה = י"ד אדר ב\' במעוברת', C.dayKey(C.toHebrew(2026, 3, 3)) === '13-14' && C.dayKey(C.hebFromFixed(C.fixedFromHeb(5787, 13, 14))) === '13-14');

// ── K6: זמני היום מול הטבלאות, בסטייה של עד דקה ──────────────
let k6 = [], k6n = 0;
for (const c of zc.cases) {
    const [y, m, d] = c.date.split('-').map(Number);
    const z = C.zmanim(zc.method, c, y, m, d);
    for (const [key, want] of Object.entries(c.expect)) {
        k6n++;
        const got = z.find(x => x.key === key).ms;
        if (want === null || got === null) { if (want !== got) k6.push(c.place + ' ' + c.date + ' ' + key + ': ' + (got === null ? 'null' : C.hhmm(c.tz, got))); continue; }
        const [wd, wt] = want.split('T');
        const [Y, M, Dd] = wd.split('-').map(Number);
        const [hh, mm] = wt.split(':').map(Number);
        const diff = (Math.round(got / 60000) * 60000 - C.zonedToUtc(c.tz, Y, M, Dd, hh, mm)) / 60000;
        if (Math.abs(diff) > 1) k6.push(c.place + ' ' + c.date + ' ' + key + ': ' + diff + ' דק\'');
    }
}
ok('K6 כל ' + k6n + ' הזמנים ב-zmanim-cases.json — עד דקה' + (k6.length ? ': ' + k6.slice(0, 4).join('; ') : ''), k6.length === 0 && k6n > 200);
ok('K6 מקום בלי שקיעה (טרומסו ביוני) — null, לא שעה', zc.cases.some(c => c.expect.sunset === null) && C.zmanim(zc.method, { lat: 69.649, lon: 18.955, tz: 'Europe/Oslo' }, 2026, 6, 21).every(z => z.ms === null));

// ── אותו חישוב ב-PHP וב-JS (ms זהים) ──────────────────────────
let px = 0;
for (const r of fx.zmanim) {
    const got = C.zmanim(r.def, r.place, r.y, r.m, r.d).map(z => z.ms);
    if (!same(got, r.ms)) { px++; if (px < 3) fails.push('PHP≠JS ' + JSON.stringify(r.place) + ' ' + r.y + '-' + r.m + '-' + r.d + ' ' + JSON.stringify(got) + ' / ' + JSON.stringify(r.ms)); }
}
ok('זמני היום: JS = PHP במילישנייה (' + fx.zmanim.length + ' מקומות × תאריכים × שיטות)', px === 0 && fx.zmanim.length >= 200);
let pe = 0;
for (const r of fx.methodErrors) if (!same(C.methodErrors(r.def), r.errors)) { pe++; fails.push('methodErrors ' + JSON.stringify(r.def).slice(0, 80) + ' js=' + JSON.stringify(C.methodErrors(r.def)) + ' php=' + JSON.stringify(r.errors)); }
ok('בדיקת הגדרת שיטה: אותן שגיאות ב-JS וב-PHP', pe === 0 && fx.methodErrors.length >= 8);
let pt = 0;
for (const r of fx.today) { const t = C.today(r.now, r.place, r.def); if (!same([t.fixed, t.after, t.approx, t.end], r.want)) pt++; }
ok('היום העברי ברגע נתון: JS = PHP', pt === 0 && fx.today.length > 10);

// ── K3: צאת הכוכבים — ההלכה והתהילים עוברים ליום הבא ────────
const jer = { lat: 31.769, lon: 35.2163, tz: 'Asia/Jerusalem' };
const def = fx.testMethod;
const tz = C.zmanim(def, jer, 2026, 11, 22).find(z => z.key === def.day_end).ms;
const before = C.today(tz - 60000, jer, def), after = C.today(tz + 60000, jer, def);
ok('K3 דקה לפני צאת הכוכבים — עדיין י"ב כסלו', before.heb.m === 9 && before.heb.d === 12 && !before.after);
ok('K3 דקה אחרי — כבר י"ג כסלו (אותו תאריך לועזי)', after.heb.d === 13 && after.after && after.civil.d === 22 && after.weekday === (before.weekday + 1) % 7);
ok('K3 מפתח ההלכה מתחלף בצאת הכוכבים', C.dayKey(before.heb) === '9-12' && C.dayKey(after.heb) === '9-13');
const tb = R.todayFrom(D.tehillimDay(before)), ta = R.todayFrom(D.tehillimDay(after));
ok('K3 תהילים "של היום" מתחלף בצאת הכוכבים (חודש ושבוע)', !same(tb.month, ta.month) && same(ta.month, R.monthChapters(13, 30)) && !same(tb.week, ta.week));
const nyc = { lat: 40.7143, lon: -74.006, tz: 'America/New_York' };
const tzNy = C.zmanim(def, nyc, 2026, 11, 22).find(z => z.key === def.day_end).ms;
ok('K3 באותו רגע: בירושלים כבר מחר, בניו יורק עדיין היום', C.today(tz + 60000, nyc, def).heb.d === 12 && tzNy > tz);
const noTz = C.today(Date.UTC(2026, 5, 21, 23, 0), { lat: 69.649, lon: 18.955, tz: 'Europe/Oslo' }, def);
ok('K3 בלי צאת הכוכבים (טרומסו ביוני) — חצות, ומסומן', noTz.approx === 'no_tzeit' && !noTz.after);
ok('K3 בלי שיטה — חצות, ומסומן', C.today(Date.UTC(2026, 10, 22, 20), jer, null).approx === 'no_method');

// ── K5: אין ברירת מחדל ← בוחרים לפני שרואים שעה ───────────────
const list = [{ pid: 'aaaaaaaaaaaaaaaa', name: 'א', version: 1, def }, { pid: 'bbbbbbbbbbbbbbbb', name: 'ב', version: 1, def }];
ok('K5 אין ברירת מחדל ואין בחירה — need, בלי שיטה', same(D.pickMethod(list, null, null), { method: null, chosen: false, need: true, lost: false }));
ok('K5 ברירת מחדל של המנהל — היא', D.pickMethod(list, 'bbbbbbbbbbbbbbbb', null).method.pid === 'bbbbbbbbbbbbbbbb');
ok('K5 הבחירה של הקורא גוברת', D.pickMethod(list, 'bbbbbbbbbbbbbbbb', 'aaaaaaaaaaaaaaaa').method.pid === 'aaaaaaaaaaaaaaaa');
ok('K5 שיטה שנבחרה ואינה מאושרת עוד — חוזרים לבחירה (lost)', D.pickMethod(list, null, 'cccccccccccccccc').need && D.pickMethod(list, null, 'cccccccccccccccc').lost);
ok('K5 שיטת הבדיקה לא ברשימה של הקוראים', !fx.publicMethods.some(m => /בדיקה/.test(m.name)) && fx.publicDefault === null);

// ── K4: אין הלכה ← אין רכיב ────────────────────────────────────
ok('K4 אין פריט — null (אין רכיב, אין טקסט חלופי)', D.homeHalacha({ item: null }) === null && D.homeHalacha(null) === null && D.homeHalacha({ item: { html: '  ' } }) === null);
ok('K4 יש פריט — הוא', D.homeHalacha({ item: { html: '<p>x</p>' } }).html === '<p>x</p>');

// ── K8: חבילת חודש — בלי רשת, עם שם העיר והשיטה; עיר אחרת ← "נדרש עדכון" ──
const cities = fx.cities;
const jlm = cities.find(c => c.en === 'Jerusalem'), bb = cities.find(c => c.en === 'Bnei Brak');
const locJ = { kind: 'city', id: jlm.id }, locB = { kind: 'city', id: bb.id };
const m1 = { pid: 'aaaaaaaaaaaaaaaa', name: 'שיטה א', version: 3, def };
const pkg = D.buildPackage(C, m1, D.place(locJ, cities), locJ, { y: 2026, m: 11, d: 22 }, [{ y: 5787, m: 9, d: 12, item: { html: '<p>הלכה</p>' } }, { y: 5787, m: 9, d: 13, item: null }], '2026-11-22T10:00:00Z');
ok('K8 30 יום, מ-22.11 עד 21.12', pkg.days.length === 30 && pkg.from === '2026-11-22' && pkg.to === '2026-12-21');
ok('K8 החבילה נושאת את המקום, השיטה וגרסתה', pkg.place.name === 'ירושלים' && pkg.method.name === 'שיטה א' && pkg.method.version === 3 && pkg.method.def === def);
const st = D.pkgState(pkg, locJ, m1.pid, null, '2026-12-01');
const day = D.pkgDay(C, pkg, '2026-12-01');
ok('K8 בלי רשת, אותה עיר — הזמנים מהחבילה, זהים לחישוב', st.state === 'ok' && day && same(day.rows.map(r => r.ms), C.zmanim(def, jlm, 2026, 12, 1).map(z => z.ms)));
const moved = D.pkgState(pkg, locB, m1.pid, null, '2026-12-01');
ok('K8 עיר אחרת בלי רשת ← "הזמנים מחושבים לירושלים — נדרש עדכון"', moved.state === 'other_place' && moved.text === 'הזמנים מחושבים לירושלים — נדרש עדכון');
ok('K8 גרסת שיטה חדשה / שיטה אחרת / אחרי סוף החבילה — נדרש עדכון', D.pkgState(pkg, locJ, m1.pid, 4, '2026-12-01').state === 'method_updated'
    && D.pkgState(pkg, locJ, 'bbbbbbbbbbbbbbbb', null, '2026-12-01').state === 'other_method' && D.pkgState(pkg, locJ, m1.pid, 3, '2026-12-22').state === 'expired');
ok('K8 ההלכה בחבילה — רק מה שהשרת נתן, עם המקום והשיטה', pkg.halacha.length === 1 && D.pkgHalacha(pkg, { y: 5787, m: 9, d: 12 }).place === 'ירושלים' && D.pkgHalacha(pkg, { y: 5787, m: 9, d: 13 }) === null);
ok('K8 אין חבילה — none', D.pkgState(null, locJ, null, null, '2026-12-01').state === 'none');

// ── K9 / מיקום: מיקום המכשיר מעוגל, ואזור הזמן — של המקום ────
const dev = D.deviceLoc(C, { latitude: 32.08374, longitude: 34.83311 }, cities, 'Europe/London');
ok('מיקום המכשיר — מעוגל ל-0.01°, אזור הזמן של העיר הקרובה (לא של המכשיר)', dev.lat === 32.08 && dev.lon === 34.83 && dev.tz === 'Asia/Jerusalem' && dev.near === 'בני ברק');
const far = D.deviceLoc(C, { latitude: -54.8, longitude: -68.3 }, cities, 'America/Argentina/Ushuaia');
ok('רחוק מכל עיר ברשימה — אזור הזמן של המכשיר', far.tz === 'America/Argentina/Ushuaia' && far.near === null);
ok('חיפוש עיר בעברית ובאנגלית', D.findCities(cities, 'בני').some(c => c.en === 'Bnei Brak') && D.findCities(cities, 'lake').some(c => c.en === 'Lakewood') && D.findCities(cities, '').length === cities.length);
ok('עיר שלא ברשימה — אין מקום (המסך מבקש לבחור)', D.place({ kind: 'city', id: -1 }, cities) === null && D.place(null, cities) === null);
ok('רשימת הערים: כל אחת עם אזור זמן תקין', cities.every(c => C.validTz(c.tz) && typeof c.elev === 'number') && cities.length >= 100);

// ── K10 במכשיר: המיקום והשיטה — פריט אחד כל אחד, שמתעדכן ─────
ok('K10 loc / zmethod מסתנכרנים כפריט אחד (pref:loc / pref:zmethod)', S.syncable('loc') && S.syncable('zmethod') && same(S.itemsFor('loc', locJ).map(i => [i.pid, i.kind]), [['pref:loc', 'pref']])
    && S.keyOf('pref:loc') === 'loc' && S.keyOf('pref:zmethod') === 'zmethod' && same(S.build('loc', { 'pref:loc': locB }), locB));
ok('K10 חבילת הזמנים (zdl) לא מסתנכרנת', !S.syncable('zdl') && !S.syncable('zmethods:last'));

console.log(JSON.stringify({ n, fails }));
process.exit(fails.length ? 1 : 0);
