/* לוח עברי: תאריך עברי באותיות, וחגים ומועדים (לפי מנהג ארץ ישראל).
   ההמרה עצמה נעשית בדפדפן (Intl, לוח hebrew) — כאן רק השמות והכללים,
   כולל דחיות: צומות שחלים בשבת, ימי הזיכרון והעצמאות, יום השואה. */
(() => {
    'use strict';

    const fmt = new Intl.DateTimeFormat('en-u-ca-hebrew', {
        day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC',
    });

    const MONTHS = {
        'Tishri': 'תשרי', 'Heshvan': 'חשוון', 'Kislev': 'כסלו', 'Tevet': 'טבת',
        'Shevat': 'שבט', 'Adar': 'אדר', 'Adar I': 'אדר א׳', 'Adar II': 'אדר ב׳',
        'Nisan': 'ניסן', 'Iyar': 'אייר', 'Sivan': 'סיוון', 'Tamuz': 'תמוז',
        'Av': 'אב', 'Elul': 'אלול',
    };

    /** מספר באותיות עבריות: 15 → ט״ו, 787 → תשפ״ז. */
    function gematria(n) {
        const ones = ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'];
        const tens = ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'];
        let s = '';
        n %= 1000;
        for (const [v, l] of [[400, 'ת'], [300, 'ש'], [200, 'ר'], [100, 'ק']]) {
            while (n >= v) { s += l; n -= v; }
        }
        if (n === 15) s += 'טו';
        else if (n === 16) s += 'טז';
        else s += tens[Math.floor(n / 10)] + ones[n % 10];
        return s.length === 1 ? s + '׳' : s.slice(0, -1) + '״' + s.slice(-1);
    }

    const cache = new Map();

    /** פרטי התאריך העברי של יום לועזי (שנה, חודש 1–12, יום). */
    function hebrew(y, m, d) {
        const key = `${y}-${m}-${d}`;
        if (cache.has(key)) return cache.get(key);
        const parts = Object.fromEntries(
            fmt.formatToParts(new Date(Date.UTC(y, m - 1, d))).map(p => [p.type, p.value]));
        const info = {
            day: Number(parts.day),
            month: parts.month,                 // שם באנגלית — מפתח לכללים
            year: Number(parts.year),
            weekday: new Date(Date.UTC(y, m - 1, d)).getUTCDay(),   // 0 = ראשון
        };
        info.monthHe = MONTHS[info.month] || info.month;
        info.dayHe = gematria(info.day);
        info.yearHe = gematria(info.year);
        info.label = `${info.dayHe} ${info.monthHe}`;
        info.full = `${info.dayHe} ב${info.monthHe} ${info.yearHe}`;
        cache.set(key, info);
        return info;
    }

    function shift(y, m, d, days) {
        const t = new Date(Date.UTC(y, m - 1, d + days));
        return [t.getUTCFullYear(), t.getUTCMonth() + 1, t.getUTCDate()];
    }

    /**
     * החגים של יום נתון. type קובע צבע:
     * yomtov — חג (אסור במלאכה), erev — ערב חג, holiday — מועד אחר,
     * fast — צום, memorial — יום זיכרון, rc — ראש חודש.
     */
    function holidays(y, m, d) {
        const h = hebrew(y, m, d);
        const { day, month, weekday: wd } = h;
        const out = [];
        const add = (name, type) => out.push({ name, type });

        // באדר של שנה פשוטה, ובאדר ב׳ של מעוברת — פורים ותענית אסתר
        const purimMonth = month === 'Adar' || month === 'Adar II';

        switch (month) {
            case 'Tishri':
                if (day === 1 || day === 2) add('ראש השנה', 'yomtov');
                if ((day === 3 && wd !== 6) || (day === 4 && wd === 0)) add('צום גדליה', 'fast');
                if (day === 9) add('ערב יום כיפור', 'erev');
                if (day === 10) add('יום כיפור', 'yomtov');
                if (day === 14) add('ערב סוכות', 'erev');
                if (day === 15) add('סוכות', 'yomtov');
                if (day >= 16 && day <= 20) add('חול המועד סוכות', 'holiday');
                if (day === 21) add('הושענא רבה', 'holiday');
                if (day === 22) add('שמיני עצרת · שמחת תורה', 'yomtov');
                break;
            case 'Tevet':
                if (day === 10) add('עשרה בטבת', 'fast');
                break;
            case 'Shevat':
                if (day === 15) add('ט״ו בשבט', 'holiday');
                break;
            case 'Adar I':
                if (day === 14) add('פורים קטן', 'holiday');
                break;
            case 'Nisan':
                if (day === 14) add('ערב פסח', 'erev');
                if (day === 15) add('פסח', 'yomtov');
                if (day >= 16 && day <= 20) add('חול המועד פסח', 'holiday');
                if (day === 21) add('שביעי של פסח', 'yomtov');
                if ((day === 27 && wd !== 5 && wd !== 0) || (day === 26 && wd === 4) || (day === 28 && wd === 1)) {
                    add('יום השואה', 'memorial');
                }
                break;
            case 'Iyar':
                if ((day === 4 && (wd === 2 || wd === 3)) || (day === 3 && wd === 3) ||
                    (day === 2 && wd === 3) || (day === 5 && wd === 1)) add('יום הזיכרון', 'memorial');
                if ((day === 5 && (wd === 3 || wd === 4)) || (day === 4 && wd === 4) ||
                    (day === 3 && wd === 4) || (day === 6 && wd === 2)) add('יום העצמאות', 'holiday');
                if (day === 18) add('ל״ג בעומר', 'holiday');
                if (day === 28) add('יום ירושלים', 'holiday');
                break;
            case 'Sivan':
                if (day === 5) add('ערב שבועות', 'erev');
                if (day === 6) add('שבועות', 'yomtov');
                break;
            case 'Tamuz':
                if ((day === 17 && wd !== 6) || (day === 18 && wd === 0)) add('י״ז בתמוז', 'fast');
                break;
            case 'Av':
                if ((day === 9 && wd !== 6) || (day === 10 && wd === 0)) add('תשעה באב', 'fast');
                if (day === 15) add('ט״ו באב', 'holiday');
                break;
            case 'Elul':
                if (day === 29) add('ערב ראש השנה', 'erev');
                break;
        }

        if (purimMonth) {
            if ((day === 13 && wd !== 6) || (day === 11 && wd === 4)) add('תענית אסתר', 'fast');
            if (day === 14) add('פורים', 'holiday');
            if (day === 15) add('שושן פורים', 'holiday');
        }

        // חנוכה: שמונה ימים מכ״ה בכסלו (חוצה לטבת)
        for (let k = 0; k < 8; k++) {
            const p = hebrew(...shift(y, m, d, -k));
            if (p.month === 'Kislev' && p.day === 25) {
                add(`חנוכה · נר ${gematria(k + 1)}`, 'holiday');
                break;
            }
        }

        // ראש חודש: ל׳ בחודש היוצא, וא׳ בחודש (חוץ מתשרי — שם זה ראש השנה)
        if (day === 30) add('ראש חודש ' + hebrew(...shift(y, m, d, 1)).monthHe, 'rc');
        if (day === 1 && month !== 'Tishri') add('ראש חודש ' + h.monthHe, 'rc');

        return out;
    }

    /* ── פרשת השבוע (מנהג ארץ ישראל) ─────────────────────────────── */

    const PARSHIYOT = [
        'בראשית', 'נח', 'לך לך', 'וירא', 'חיי שרה', 'תולדות', 'ויצא', 'וישלח', 'וישב', 'מקץ',
        'ויגש', 'ויחי', 'שמות', 'וארא', 'בא', 'בשלח', 'יתרו', 'משפטים', 'תרומה', 'תצוה',
        'כי תשא', 'ויקהל', 'פקודי', 'ויקרא', 'צו', 'שמיני', 'תזריע', 'מצורע', 'אחרי מות', 'קדושים',
        'אמור', 'בהר', 'בחוקותי', 'במדבר', 'נשא', 'בהעלותך', 'שלח', 'קרח', 'חקת', 'בלק',
        'פינחס', 'מטות', 'מסעי', 'דברים', 'ואתחנן', 'עקב', 'ראה', 'שופטים', 'כי תצא', 'כי תבוא',
        'נצבים', 'וילך', 'האזינו',
    ];
    /*
     * אילו פרשיות מחוברות (לפי האינדקס של הראשונה בצמד), לכל אחד מ-14 סוגי השנה.
     * מפתח: "יום ראש השנה (0=ראשון)-אורך השנה בימים". הטבלה נגזרה מ-@hebcal/core
     * על פני 500 שנה (תר״–תתק״), בלי סתירה אחת: 21 ויקהל־פקודי, 26 תזריע־מצורע,
     * 28 אחרי מות־קדושים, 31 בהר־בחוקותי, 41 מטות־מסעי, 50 נצבים־וילך.
     */
    const JOINS = {
        '1-353': [21, 26, 28, 31, 41, 50], '1-355': [21, 26, 28, 31, 41, 50],
        '1-383': [41, 50],                 '1-385': [],
        '2-354': [21, 26, 28, 31, 41, 50], '2-384': [],
        '4-354': [21, 26, 28, 41],         '4-355': [26, 28, 31, 41],
        '4-383': [],                       '4-385': [50],
        '6-353': [21, 26, 28, 31, 41],     '6-355': [21, 26, 28, 31, 41, 50],
        '6-383': [41, 50],                 '6-385': [41, 50],
    };

    /** שבת שאין בה פרשה, כי היא חג או חול המועד (בארץ ישראל). */
    function festivalShabbat(h) {
        return (h.month === 'Tishri' && [1, 2, 10, 15, 16, 17, 18, 19, 20, 21, 22].includes(h.day)) ||
               (h.month === 'Nisan' && h.day >= 15 && h.day <= 21) ||
               (h.month === 'Sivan' && h.day === 6);
    }

    /** התאריך הלועזי של כ״ב בתשרי בשנה עברית נתונה (שמחת תורה בארץ). */
    function simchatTorah(hebYear) {
        const gy = hebYear - 3761;
        for (let t = Date.UTC(gy, 8, 1); t <= Date.UTC(gy, 10, 1); t += 864e5) {
            const d = new Date(t);
            const h = hebrew(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
            if (h.month === 'Tishri' && h.day === 22 && h.year === hebYear) return t;
        }
        throw new Error('simchatTorah: not found ' + hebYear);
    }

    const cycles = new Map();

    /** כל השבתות של מחזור קריאה אחד (משמחת תורה עד שמחת תורה) → שם הפרשה. */
    function cycle(hebYear) {
        if (cycles.has(hebYear)) return cycles.get(hebYear);
        const start = simchatTorah(hebYear);
        const end = simchatTorah(hebYear + 1);
        let t = start + ((6 - new Date(start).getUTCDay()) || 7) * 864e5;   // השבת שאחרי שמחת תורה

        const shabbatot = [];
        for (; t < end; t += 7 * 864e5) {
            const d = new Date(t);
            const h = hebrew(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
            shabbatot.push({ t, skip: festivalShabbat(h) });
        }
        const rhDay = new Date(start - 21 * 864e5).getUTCDay();
        const length = Math.round((end - start) / 864e5);
        const joined = new Set(JOINS[`${rhDay}-${length}`] || []);

        const map = new Map();
        let i = 0;
        for (const x of shabbatot) {
            if (x.skip || i >= PARSHIYOT.length) continue;
            if (joined.has(i)) { map.set(x.t, PARSHIYOT[i] + '־' + PARSHIYOT[i + 1]); i += 2; }
            else { map.set(x.t, PARSHIYOT[i]); i += 1; }
        }
        cycles.set(hebYear, map);
        return map;
    }

    /** שם הפרשה לשבת נתונה, או null (יום חול, או שבת של חג). */
    function parasha(y, m, d) {
        const t = Date.UTC(y, m - 1, d);
        if (new Date(t).getUTCDay() !== 6) return null;
        const h = hebrew(y, m, d);
        // המחזור מתחיל בכ״ב בתשרי; לפני כן — עדיין המחזור של השנה הקודמת
        const year = t > simchatTorah(h.year) ? h.year : h.year - 1;
        return cycle(year).get(t) || null;
    }

    /* ── זמני היום ─────────────────────────────────────────────────── */

    const rad = Math.PI / 180;

    /**
     * שעת השמש בזווית נתונה (zenith במעלות) — האלגוריתם של ה-Almanac for
     * Computers, דיוק של כדקה. מחזיר חותמת זמן UTC, או null בקטבים.
     */
    function sunTime(y, m, d, lat, lon, zenith, rising) {
        const n = Math.round((Date.UTC(y, m - 1, d) - Date.UTC(y, 0, 0)) / 864e5);
        const lngHour = lon / 15;
        const t = n + ((rising ? 6 : 18) - lngHour) / 24;
        const M = 0.9856 * t - 3.289;
        let L = M + 1.916 * Math.sin(M * rad) + 0.020 * Math.sin(2 * M * rad) + 282.634;
        L = (L + 360) % 360;
        let RA = Math.atan(0.91764 * Math.tan(L * rad)) / rad;
        RA = (RA + 360) % 360;
        RA += Math.floor(L / 90) * 90 - Math.floor(RA / 90) * 90;
        RA /= 15;
        const sinDec = 0.39782 * Math.sin(L * rad);
        const cosDec = Math.cos(Math.asin(sinDec));
        const cosH = (Math.cos(zenith * rad) - sinDec * Math.sin(lat * rad)) / (cosDec * Math.cos(lat * rad));
        if (cosH > 1 || cosH < -1) return null;
        let H = rising ? 360 - Math.acos(cosH) / rad : Math.acos(cosH) / rad;
        H /= 15;
        const T = H + RA - 0.06571 * t - 6.622;
        const UT = ((T - lngHour) % 24 + 24) % 24;
        return Date.UTC(y, m - 1, d) + UT * 3600e3;
    }

    const clock = new Intl.DateTimeFormat('he-IL', {
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'Asia/Jerusalem',
    });
    const hhmm = ts => ts == null ? '' : clock.format(new Date(ts));

    /**
     * זמני היום למיקום נתון:
     * loc = { lat, lon, candleOffset, havdalah: { mode: 'tzeit' | 'minutes', minutes } }
     * מחזיר שקיעה, ובערב שבת/חג — הדלקת נרות, ובמוצאי שבת/חג — צאת.
     */
    function zmanim(y, m, d, loc) {
        const sunset = sunTime(y, m, d, loc.lat, loc.lon, 90.833, false);
        const out = { sunset: hhmm(sunset) };

        const today = holidays(y, m, d);
        const tomorrow = holidays(...shift(y, m, d, 1));
        const wd = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
        const isYomtov = list => list.some(h => h.type === 'yomtov');

        // הדלקת נרות: ערב שבת וערב חג. כשמחר חג והיום כבר שבת או חג (יום טוב
        // שני של ראש השנה, חג שנכנס במוצאי שבת) — מדליקים אחרי צאת.
        if (wd === 5 || isYomtov(tomorrow)) {
            if (wd === 6 || (isYomtov(today) && wd !== 5)) out.candlesAfter = true;
            else out.candles = hhmm(sunset - (loc.candleOffset ?? 20) * 60e3);
        }
        // צאת: מוצאי שבת, ומוצאי חג — כשמחר כבר אינו שבת או חג
        if ((wd === 6 || isYomtov(today)) && !isYomtov(tomorrow) && !(wd === 5)) {
            const h = loc.havdalah || {};
            const ts = h.mode === 'minutes'
                ? sunset + (h.minutes || 40) * 60e3
                : sunTime(y, m, d, loc.lat, loc.lon, 98.5, false);      // 8.5° מתחת לאופק
            out.havdalah = hhmm(ts);
            out.havdalahLabel = wd === 6 && !isYomtov(today) ? 'צאת שבת' : 'צאת החג';
        }
        if (out.candlesAfter) out.candles = out.havdalah || hhmm(sunTime(y, m, d, loc.lat, loc.lon, 98.5, false));
        return out;
    }

    /* מיקומים מוכנים. candleOffset — כמה דקות לפני השקיעה מדליקים (מנהג המקום). */
    const PLACES = [
        { id: 'jerusalem',   name: 'ירושלים',       lat: 31.778, lon: 35.235, candleOffset: 40 },
        { id: 'bnei-brak',   name: 'בני ברק',       lat: 32.084, lon: 34.834, candleOffset: 20 },
        { id: 'tel-aviv',    name: 'תל אביב',       lat: 32.085, lon: 34.781, candleOffset: 20 },
        { id: 'haifa',       name: 'חיפה',          lat: 32.794, lon: 34.990, candleOffset: 30 },
        { id: 'beer-sheva',  name: 'באר שבע',       lat: 31.252, lon: 34.791, candleOffset: 20 },
        { id: 'petah-tikva', name: 'פתח תקווה',     lat: 32.087, lon: 34.887, candleOffset: 20 },
        { id: 'ashdod',      name: 'אשדוד',         lat: 31.804, lon: 34.655, candleOffset: 20 },
        { id: 'netanya',     name: 'נתניה',         lat: 32.332, lon: 34.860, candleOffset: 20 },
        { id: 'beit-shemesh', name: 'בית שמש',      lat: 31.747, lon: 34.988, candleOffset: 30 },
        { id: 'modiin-illit', name: 'מודיעין עילית', lat: 31.933, lon: 35.043, candleOffset: 30 },
        { id: 'beitar',      name: 'ביתר עילית',    lat: 31.697, lon: 35.120, candleOffset: 40 },
        { id: 'elad',        name: 'אלעד',          lat: 32.052, lon: 34.951, candleOffset: 20 },
        { id: 'tzfat',       name: 'צפת',           lat: 32.964, lon: 35.496, candleOffset: 30 },
        { id: 'tiberias',    name: 'טבריה',         lat: 32.796, lon: 35.530, candleOffset: 20 },
        { id: 'eilat',       name: 'אילת',          lat: 29.558, lon: 34.952, candleOffset: 20 },
    ];

    window.HebCal = { hebrew, holidays, gematria, parasha, zmanim, PLACES };
})();
