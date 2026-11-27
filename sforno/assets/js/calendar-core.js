/*
 * ספורנו — הלוח העברי וזמני היום (אפיון 07), בלי DOM ובלי רשת: אותו אלגוריתם בדיוק כמו lib/calendar.php
 * (הבדיקה tests/k-calendar משווה ביניהם ומול intl). רץ בדפדפן (window.SFCal), ב-service worker וב-node.
 *
 *   ימים     — "יום קבוע" (R.D., ספירת ימים רציפה: 1 = 1.1.0001 גרגוריאני). ההמרה לועזי ↔ עברי עוברת דרכו.
 *   עברי     — חשבון המולד והדחיות (Dershowitz–Reingold). **מזהי חודש (ש6): 1 ניסן … 6 אלול, 7 תשרי … 11 שבט,
 *              12 אדר א', 13 אדר ב'. בשנה פשוטה אדר = 13**, ו-12 לא קיים בה.
 *   שמש      — נוסחאות NOAA (General Solar Position): נטייה ומשוואת הזמן, זווית שעה לגובה מבוקש, בשני סבבי דיוק סביב
 *              הצהריים המקומיים של התאריך. אין זריחה / אין זווית כזו ← null — **אף פעם לא שעה מומצאת**.
 *   אזור זמן — של המיקום (IANA), דרך Intl בדפדפן / DateTimeZone ב-PHP — לא של המכשיר. שעון קיץ — מזה.
 *   שיטה     — {day_end, zmanim: [{key, name, type, …}]} — angle (מעלות מתחת לאופק, am/pm), offset (דקות מזמן אחר),
 *              hours (שעות זמניות: from + hours × (to − from) / 12). זמן שתלוי בזמן שאינו ניתן לחישוב — גם הוא null.
 *   "היום"   — היום העברי מתחלף ב-day_end של השיטה (צאת הכוכבים) במיקום הפעיל; בלי שיטה / מיקום, או כשהזמן לא ניתן
 *              לחישוב — בחצות, ומסומן (approx), כדי שהמסך יאמר זאת.
 */
'use strict';

(function (root, factory) {
    const C = factory();
    if (typeof module === 'object' && module.exports) module.exports = C;
    else root.SFCal = C;
})(typeof self !== 'undefined' ? self : this, function () {
    const fl = Math.floor;
    const mod = (a, b) => a - b * fl(a / b);
    const DAY = 86400000;

    // ── הלוח הגרגוריאני ↔ יום קבוע ────────────────────────────
    const gLeap = y => mod(y, 4) === 0 && ![100, 200, 300].includes(mod(y, 400));
    function fixedFromGreg(y, m, d) {
        return 365 * (y - 1) + fl((y - 1) / 4) - fl((y - 1) / 100) + fl((y - 1) / 400) + fl((367 * m - 362) / 12)
            + (m <= 2 ? 0 : gLeap(y) ? -1 : -2) + d;
    }
    function gregYear(f) {
        const d0 = f - 1, n400 = fl(d0 / 146097), d1 = mod(d0, 146097), n100 = fl(d1 / 36524), d2 = mod(d1, 36524);
        const n4 = fl(d2 / 1461), d3 = mod(d2, 1461), n1 = fl(d3 / 365);
        const y = 400 * n400 + 100 * n100 + 4 * n4 + n1;
        return n100 === 4 || n1 === 4 ? y : y + 1;
    }
    function gregFromFixed(f) {
        const y = gregYear(f);
        const prior = f - fixedFromGreg(y, 1, 1);
        const corr = f < fixedFromGreg(y, 3, 1) ? 0 : gLeap(y) ? 1 : 2;
        const m = fl((12 * (prior + corr) + 373) / 367);
        return { y, m, d: f - fixedFromGreg(y, m, 1) + 1 };
    }
    /** יום בשבוע: 0 ראשון … 6 שבת */
    const weekday = f => mod(f, 7);

    // ── הלוח העברי ─────────────────────────────────────────────
    const EPOCH = -1373427;
    const hLeap = y => mod(7 * y + 1, 19) < 7;
    function elapsed(y) {
        const months = fl((235 * y - 234) / 19);
        const parts = 12084 + 13753 * months;
        const day = 29 * months + fl(parts / 25920);
        return mod(3 * (day + 1), 7) < 3 ? day + 1 : day;
    }
    function delay(y) {
        const a = elapsed(y - 1), b = elapsed(y), c = elapsed(y + 1);
        return c - b === 356 ? 2 : b - a === 382 ? 1 : 0;
    }
    const newYear = y => EPOCH + elapsed(y) + delay(y);
    const yearDays = y => newYear(y + 1) - newYear(y);
    /** אורך חודש לפי המספור הפנימי (12 = אדר / אדר א', 13 = אדר ב' — רק בשנה מעוברת). */
    function lenR(m, y) {
        const n = yearDays(y);
        if ([2, 4, 6, 10, 13].includes(m)) return 29;
        if (m === 12 && !hLeap(y)) return 29;
        if (m === 8 && n % 10 !== 5) return 29;
        if (m === 9 && n % 10 === 3) return 29;
        return 30;
    }
    function fixedFromHebR(y, m, d) {
        let f = newYear(y) + d - 1;
        const last = hLeap(y) ? 13 : 12;
        if (m < 7) {
            for (let k = 7; k <= last; k++) f += lenR(k, y);
            for (let k = 1; k < m; k++) f += lenR(k, y);
        } else {
            for (let k = 7; k < m; k++) f += lenR(k, y);
        }
        return f;
    }
    // מזהה חיצוני (ש6) ↔ פנימי
    const toR = (m, y) => (!hLeap(y) && m === 13 ? 12 : m);
    const fromR = (m, y) => (!hLeap(y) && m === 12 ? 13 : m);
    const validMonth = (m, y) => Number.isInteger(m) && m >= 1 && m <= 13 && !(m === 12 && !hLeap(y));

    /** אורך החודש (מזהה ש6) בשנה; 0 — החודש לא קיים בשנה הזו (אדר א' בשנה פשוטה). */
    const monthLen = (y, m) => (validMonth(m, y) ? lenR(toR(m, y), y) : 0);

    function hebFromFixed(f) {
        const approx = fl((f - EPOCH) / (35975351 / 98496)) + 1;
        let y = approx - 1;
        while (newYear(y + 1) <= f) y++;
        const start = f < fixedFromHebR(y, 1, 1) ? 7 : 1;
        let m = start;
        while (f > fixedFromHebR(y, m, lenR(m, y))) m++;
        return { y, m: fromR(m, y), d: f - fixedFromHebR(y, m, 1) + 1, leap: hLeap(y) };
    }
    /** יום קבוע של תאריך עברי; null — התאריך לא קיים בשנה הזו (ל' חשון בשנה חסרה, אדר א' בשנה פשוטה). */
    function fixedFromHeb(y, m, d) {
        if (!Number.isInteger(y) || !validMonth(m, y) || !Number.isInteger(d) || d < 1 || d > lenR(toR(m, y), y)) return null;
        return fixedFromHebR(y, toR(m, y), d);
    }
    const toHebrew = (y, m, d) => hebFromFixed(fixedFromGreg(y, m, d));
    function fromHebrew(y, m, d) {
        const f = fixedFromHeb(y, m, d);
        return f === null ? null : gregFromFixed(f);
    }

    // ── שמות ובגימטריה ────────────────────────────────────────
    const MONTHS = { 1: 'ניסן', 2: 'אייר', 3: 'סיון', 4: 'תמוז', 5: 'אב', 6: 'אלול', 7: 'תשרי', 8: 'חשון', 9: 'כסלו', 10: 'טבת', 11: 'שבט', 12: "אדר א'", 13: "אדר ב'" };
    /** סדר החודשים בשנה (מתשרי). */
    const ORDER = [7, 8, 9, 10, 11, 12, 13, 1, 2, 3, 4, 5, 6];
    /** שם החודש; בשנה פשוטה (leap = false) 13 נקרא "אדר". leap = null — השם הכללי (לניהול). */
    const monthName = (m, leap) => (m === 13 && leap === false ? 'אדר' : MONTHS[m] || '');
    const ONES = ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'];
    const TENS = ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'];
    const HUND = ['', 'ק', 'ר', 'ש', 'ת', 'תק', 'תר', 'תש', 'תת', 'תתק'];
    /** מספר באותיות, עם גרש / גרשיים: 15 ← ט"ו, 787 ← תשפ"ז. אלפים — נשמטים. */
    function gem(n) {
        n = mod(n, 1000);
        let s = HUND[fl(n / 100)];
        const r = n % 100;
        if (r === 15) s += 'טו';
        else if (r === 16) s += 'טז';
        else s += TENS[fl(r / 10)] + ONES[r % 10];
        if (!s) return '';
        return s.length === 1 ? s + "'" : s.slice(0, -1) + '"' + s.slice(-1);
    }
    /** "ט"ו בשבט תשפ"ז" */
    const hebText = h => gem(h.d) + ' ' + monthName(h.m, h.leap) + ' ' + gem(h.y);
    /** המפתח של תאריך עברי בלי שנה (להלכה היומית): "13-14" */
    const dayKey = h => h.m + '-' + h.d;

    // ── אזור זמן (של המיקום) ──────────────────────────────────
    const fmts = new Map();
    function fmt(tz) {
        if (!fmts.has(tz)) {
            fmts.set(tz, new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric' }));
        }
        return fmts.get(tz);
    }
    /** {y, m, d, hh, mm, ss} בשעון המקומי של tz */
    function localParts(tz, ms) {
        const p = {};
        for (const x of fmt(tz).formatToParts(new Date(ms))) if (x.type !== 'literal') p[x.type] = parseInt(x.value, 10);
        return { y: p.year, m: p.month, d: p.day, hh: p.hour % 24, mm: p.minute, ss: p.second };
    }
    /** ההפרש מ-UTC בדקות ברגע הזה */
    function tzOffset(tz, ms) {
        const p = localParts(tz, ms);
        return Math.round((Date.UTC(p.y, p.m - 1, p.d, p.hh, p.mm, p.ss) - fl(ms / 1000) * 1000) / 60000);
    }
    /** רגע (ms) של שעה מקומית בתאריך — שעה שמדלגים עליה במעבר לשעון קיץ: אחרי המעבר */
    function zonedToUtc(tz, y, m, d, hh, mm) {
        const wall = Date.UTC(y, m - 1, d, hh, mm);
        let t = wall - tzOffset(tz, wall) * 60000;
        t = wall - tzOffset(tz, t) * 60000;
        return t;
    }
    const validTz = tz => { try { fmt(tz); return true; } catch (e) { return false; } };

    // ── השמש (NOAA) ────────────────────────────────────────────
    const rad = x => (x * Math.PI) / 180;
    const deg = x => (x * 180) / Math.PI;
    /** נטייה (מעלות) ומשוואת הזמן (דקות) ברגע ms */
    function solar(ms) {
        const T = (ms / DAY + 2440587.5 - 2451545) / 36525;
        const L0 = mod(280.46646 + T * (36000.76983 + T * 0.0003032), 360);
        const M = 357.52911 + T * (35999.05029 - 0.0001537 * T);
        const e = 0.016708634 - T * (0.000042037 + 0.0000001267 * T);
        const C = Math.sin(rad(M)) * (1.914602 - T * (0.004817 + 0.000014 * T)) + Math.sin(rad(2 * M)) * (0.019993 - 0.000101 * T) + Math.sin(rad(3 * M)) * 0.000289;
        const om = 125.04 - 1934.136 * T;
        const lam = L0 + C - 0.00569 - 0.00478 * Math.sin(rad(om));
        const eps = 23 + (26 + (21.448 - T * (46.815 + T * (0.00059 - T * 0.001813))) / 60) / 60 + 0.00256 * Math.cos(rad(om));
        const decl = deg(Math.asin(Math.sin(rad(eps)) * Math.sin(rad(lam))));
        const yy = Math.tan(rad(eps / 2)) ** 2;
        const eq = 4 * deg(yy * Math.sin(2 * rad(L0)) - 2 * e * Math.sin(rad(M)) + 4 * e * yy * Math.sin(rad(M)) * Math.cos(2 * rad(L0))
            - 0.5 * yy * yy * Math.sin(4 * rad(L0)) - 1.25 * e * e * Math.sin(2 * rad(M)));
        return { decl, eq };
    }
    /** הצהריים האמיתיים (ms) הקרובים לרגע t0 */
    function transit(lon, t0) {
        let n = t0;
        for (let i = 0; i < 2; i++) {
            const day0 = fl(n / DAY) * DAY;
            n = day0 + (720 - 4 * lon - solar(n).eq) * 60000;
            while (n - t0 > DAY / 2) n -= DAY;
            while (t0 - n > DAY / 2) n += DAY;
        }
        return n;
    }
    /**
     * הרגע (ms) שבו מרכז השמש נמצא depth מעלות מתחת לאופק (depth שלילי — מעליו), בבוקר (am) או בערב (pm) של היום
     * הסולארי שהצהריים שלו קרובים ל-t0. null — השמש לא מגיעה לגובה הזה ביום הזה.
     */
    function sunAt(lat, lon, t0, depth, dir) {
        const noon = transit(lon, t0);
        let t = noon;
        for (let i = 0; i < 3; i++) {
            const { decl } = solar(t);
            const cosH = (Math.cos(rad(90 + depth)) - Math.sin(rad(lat)) * Math.sin(rad(decl))) / (Math.cos(rad(lat)) * Math.cos(rad(decl)));
            if (!(cosH >= -1 && cosH <= 1)) return null;
            const H = deg(Math.acos(cosH));
            const nn = transit(lon, t);
            t = nn + (dir === 'am' ? -1 : 1) * H * 4 * 60000;
        }
        return Math.round(t);
    }

    // ── השיטות ─────────────────────────────────────────────────
    const KEY = /^[a-z][a-z0-9_]{0,31}$/;
    const num = (v, lo, hi) => typeof v === 'number' && isFinite(v) && v >= lo && v <= hi;
    /** בדיקת הגדרת שיטה. מחזיר רשימת שגיאות (ריקה = תקינה). אותן בדיקות ב-PHP (sf_cal_method_errors). */
    function methodErrors(def) {
        const errs = [];
        if (!def || typeof def !== 'object' || !Array.isArray(def.zmanim)) return ['חסרה רשימת הזמנים'];
        if (def.zmanim.length < 1 || def.zmanim.length > 40) errs.push('בין 1 ל-40 זמנים');
        const keys = new Set(def.zmanim.map(z => z && z.key));
        const seen = new Set();
        def.zmanim.forEach((z, i) => {
            const at = 'זמן ' + (i + 1) + ': ';
            if (!z || typeof z !== 'object') { errs.push(at + 'לא תקין'); return; }
            if (typeof z.key !== 'string' || !KEY.test(z.key)) errs.push(at + 'מזהה באנגלית קטנה, ספרות וקו תחתון');
            else if (seen.has(z.key)) errs.push(at + 'המזהה "' + z.key + '" כבר קיים');
            if (typeof z.name !== 'string' || !z.name.trim() || z.name.length > 60) errs.push(at + 'שם בין 1 ל-60 תווים');
            const ref = k => typeof k === 'string' && k !== z.key && keys.has(k);
            if (z.type === 'angle') {
                if (!num(z.deg, -5, 30)) errs.push(at + 'זווית בין ‎-5 ל-30 מעלות');
                if (z.dir !== 'am' && z.dir !== 'pm') errs.push(at + 'בוקר או ערב');
            } else if (z.type === 'offset') {
                if (!ref(z.base)) errs.push(at + 'זמן הבסיס לא קיים בשיטה');
                if (!num(z.min, -300, 300)) errs.push(at + 'דקות בין ‎-300 ל-300');
            } else if (z.type === 'hours') {
                if (!ref(z.from) || !ref(z.to)) errs.push(at + 'תחילת היום וסופו לא קיימים בשיטה');
                if (!num(z.hours, 0, 12)) errs.push(at + 'שעות זמניות בין 0 ל-12');
            } else errs.push(at + 'סוג לא מוכר');
            if (typeof z.key === 'string') seen.add(z.key);
        });
        if (!errs.length && order(def) === null) errs.push('יש זמנים שתלויים זה בזה במעגל');
        if (typeof def.day_end !== 'string' || !seen.has(def.day_end)) errs.push('חסר הזמן שבו מתחלף היום (צאת הכוכבים)');
        return errs;
    }

    const deps = z => (z.type === 'offset' ? [z.base] : z.type === 'hours' ? [z.from, z.to] : []);
    /** סדר החישוב: כל זמן אחרי מה שהוא תלוי בו (הסדר שבשיטה נשמר בתצוגה). null — מעגל. */
    function order(def) {
        const done = new Set(), out = [];
        for (let pass = 0; pass <= def.zmanim.length && out.length < def.zmanim.length; pass++) {
            for (const z of def.zmanim) if (!done.has(z.key) && deps(z).every(k => done.has(k))) { done.add(z.key); out.push(z); }
        }
        return out.length === def.zmanim.length ? out : null;
    }

    /**
     * זמני היום לתאריך אזרחי במיקום. place: {lat, lon, tz}. מחזיר [{key, name, ms|null}] לפי הסדר של השיטה.
     * הזמנים מחושבים ליום הסולארי שהצהריים שלו קרובים ל-12:00 המקומית של התאריך — שקיעה אחרי חצות (רייקיאוויק ביוני)
     * נשארת של התאריך הזה.
     */
    function zmanim(def, place, y, m, d) {
        const t0 = zonedToUtc(place.tz, y, m, d, 12, 0);
        const got = {};
        for (const z of order(def) || []) {
            let ms = null;
            if (z.type === 'angle') ms = sunAt(place.lat, place.lon, t0, z.deg, z.dir);
            else if (z.type === 'offset') ms = got[z.base] == null ? null : got[z.base] + Math.round(z.min * 60000);
            else if (z.type === 'hours') ms = got[z.from] == null || got[z.to] == null ? null : got[z.from] + Math.round(((got[z.to] - got[z.from]) * z.hours) / 12);
            got[z.key] = ms;
        }
        return def.zmanim.map(z => ({ key: z.key, name: z.name, ms: got[z.key] === undefined ? null : got[z.key] }));
    }

    /** "06:31" בשעון המקומי של tz, מעוגל לדקה הקרובה */
    function hhmm(tz, ms) {
        const p = localParts(tz, Math.round(ms / 60000) * 60000);
        return String(p.hh).padStart(2, '0') + ':' + String(p.mm).padStart(2, '0');
    }

    /**
     * היום העברי ברגע now במיקום ובשיטה. place או def חסרים ← חצות בשעון של tz (או של המכשיר), approx: 'no_method'.
     * day_end לא ניתן לחישוב ← חצות, approx: 'no_tzeit'. מחזיר {greg, fixed, heb, weekday, monthLen, after, approx, end}.
     */
    function today(now, place, def, deviceTz) {
        const tz = (place && place.tz) || deviceTz || 'UTC';
        const p = localParts(tz, now);
        let f = fixedFromGreg(p.y, p.m, p.d);
        let after = false, approx = null, end = null;
        if (place && def && Array.isArray(def.zmanim)) {
            const z = zmanim(def, place, p.y, p.m, p.d).find(x => x.key === def.day_end);
            end = z ? z.ms : null;
            if (end === null) approx = 'no_tzeit';
            else if (now >= end) { f += 1; after = true; }
        } else approx = 'no_method';
        const heb = hebFromFixed(f);
        return { greg: gregFromFixed(f), civil: { y: p.y, m: p.m, d: p.d }, fixed: f, heb, weekday: weekday(f), monthLen: monthLen(heb.y, heb.m), after, approx, end };
    }

    // ── מיקום ──────────────────────────────────────────────────
    /** מרחק (ק"מ) בין שתי נקודות */
    function km(a, b) {
        const dl = rad(b.lat - a.lat), dn = rad(b.lon - a.lon);
        const s = Math.sin(dl / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dn / 2) ** 2;
        return 12742 * Math.asin(Math.min(1, Math.sqrt(s)));
    }
    /** העיר הקרובה ברשימה */
    function nearest(cities, pt) {
        let best = null, bd = Infinity;
        for (const c of cities) { const x = km(c, pt); if (x < bd) { bd = x; best = c; } }
        return best ? { city: best, km: bd } : null;
    }
    /** מיקום המכשיר נשמר מעוגל ל-0.01° (כקילומטר) — לא מדויק יותר ממה שהחישוב צריך (§19) */
    const roundPt = v => Math.round(v * 100) / 100;

    return {
        fixedFromGreg, gregFromFixed, weekday, hebFromFixed, fixedFromHeb, toHebrew, fromHebrew, monthLen, isLeap: hLeap, yearDays,
        MONTHS, ORDER, monthName, gem, hebText, dayKey,
        localParts, tzOffset, zonedToUtc, validTz,
        solar, sunAt, methodErrors, zmanim, hhmm, today, km, nearest, roundPt,
    };
});
