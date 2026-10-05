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

    window.HebCal = { hebrew, holidays, gematria };
})();
