// לוח עברי וחגים — ליומן (calendar.js). בלי ספרייה: התאריך העברי מהדפדפן
// (Intl, לוח hebrew), והחגים לפי חודש ויום עבריים, כולל הדחיות (צומות שחלים בשבת,
// יום הזיכרון ויום העצמאות, יום השואה).
//
// לכל חג: name, short (לתא בחודש), kind — yomtov · chol · erev · fast · minor · modern · rc,
// ו-place — 'il' (ארץ ישראל בלבד), 'diaspora' (חו"ל בלבד), או '' (בכל מקום).
// השמות בעברית הם מפתחות התרגום — מתורגמים ב-add (t). התאריך העברי באותיות רק בממשק
// בעברית; בשפה אחרת — מ-Intl, בשפת הממשק ("25 Kislev 5787").
'use strict';

const HebCal = (() => {
    const fmt = new Intl.DateTimeFormat('en-u-ca-hebrew', { day: 'numeric', month: 'long', year: 'numeric' });
    const he = I18n.lang() === 'he';
    // התאריך העברי בשפת הממשק (לא עברית). לחודשים ולחגים — תמיד fmt האנגלי, למעלה
    const loc = `${I18n.lang()}-u-ca-hebrew-nu-latn`;
    const fmtLabel = new Intl.DateTimeFormat(loc, { day: 'numeric', month: 'long', year: 'numeric' });
    const fmtMonth = new Intl.DateTimeFormat(loc, { month: 'long' });
    const fmtMonthYear = new Intl.DateTimeFormat(loc, { month: 'long', year: 'numeric' });
    // i18n-none-start — שמות החודשים והאותיות: רק לתאריך העברי בממשק בעברית
    const MONTH = { Tishri: 'תשרי', Heshvan: 'חשוון', Kislev: 'כסלו', Tevet: 'טבת', Shevat: 'שבט', 'Adar I': 'אדר א׳', 'Adar II': 'אדר ב׳',
        Adar: 'אדר', Nisan: 'ניסן', Iyar: 'אייר', Sivan: 'סיוון', Tamuz: 'תמוז', Av: 'אב', Elul: 'אלול' };
    const cache = new Map();

    /** מספר באותיות: 25 → כ״ה, 787 → תשפ״ז. */
    function gematria(n) {
        const units = ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'];
        const tens = ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'];
        const hundreds = ['', 'ק', 'ר', 'ש', 'ת'];
        let s = '';
        n %= 1000;
        while (n >= 400) { s += 'ת'; n -= 400; }
        if (n >= 100) { s += hundreds[Math.floor(n / 100)]; n %= 100; }
        if (n === 15) s += 'טו';
        else if (n === 16) s += 'טז';
        else { s += tens[Math.floor(n / 10)] + units[n % 10]; }
        return s.length > 1 ? s.slice(0, -1) + '״' + s.slice(-1) : s + '׳';
    }
    // i18n-none-end

    function parts(d) {
        const p = Object.fromEntries(fmt.formatToParts(d).map(x => [x.type, x.value]));
        return { day: Number(p.day), month: p.month, year: Number(p.year) };
    }

    /** כמה ימים בכסלו השנה (29 או 30) — לחנוכה שנמשך לטבת. */
    function kislevLength(tevetDate, tevetDay) {
        const d = new Date(tevetDate); d.setDate(d.getDate() - tevetDay);
        return parts(d).day;
    }

    function holidays(d, h) {
        const { day: hd, month: hm } = h, dow = d.getDay(); // 0 = ראשון, 6 = שבת
        const out = [];
        // השם והקיצור — מפתחות תרגום; vars — למשתנים בשם (נר החנוכה)
        const add = (name, short, kind, place = '', vars) => out.push({ name: t(name, vars), short: t(short), kind, place });
        // נר חנוכה: באותיות בעברית, ובמספר בשפה אחרת
        const candle = n => ({ n: he ? gematria(n) : n });
        // i18n-ok-start
        if (hm === 'Tishri') {
            if (hd === 1 || hd === 2) add(hd === 1 ? 'ראש השנה א׳' : 'ראש השנה ב׳', 'ר״ה', 'yomtov');
            if ((hd === 3 && dow !== 6) || (hd === 4 && dow === 0)) add('צום גדליה', 'צום', 'fast');
            if (hd === 9) add('ערב יום כיפור', 'ערב יו״כ', 'erev');
            if (hd === 10) add('יום כיפור', 'יו״כ', 'yomtov');
            if (hd === 14) add('ערב סוכות', 'ערב סוכות', 'erev');
            if (hd === 15) add('סוכות', 'סוכות', 'yomtov');
            if (hd === 16) { add('חול המועד סוכות', 'חוה״מ', 'chol', 'il'); add('סוכות ב׳ — יום טוב שני של גלויות', 'סוכות ב׳', 'yomtov', 'diaspora'); }
            if (hd >= 17 && hd <= 20) add('חול המועד סוכות', 'חוה״מ', 'chol');
            if (hd === 21) add('הושענא רבה', 'הושע״ר', 'erev');
            if (hd === 22) { add('שמיני עצרת ושמחת תורה', 'שמח״ת', 'yomtov', 'il'); add('שמיני עצרת', 'שמ״ע', 'yomtov', 'diaspora'); }
            if (hd === 23) add('שמחת תורה — יום טוב שני של גלויות', 'שמח״ת', 'yomtov', 'diaspora');
        }
        if (hm === 'Kislev' && hd >= 25) add('חנוכה — נר {n}', 'חנוכה', 'minor', '', candle(hd - 24));
        if (hm === 'Tevet') {
            if (hd <= 3) { const n = kislevLength(d, hd) - 24 + hd; if (n <= 8) add('חנוכה — נר {n}', 'חנוכה', 'minor', '', candle(n)); }
            if (hd === 10) add('צום עשרה בטבת', 'צום', 'fast');
        }
        if (hm === 'Shevat' && hd === 15) add('ט״ו בשבט', 'ט״ו בשבט', 'minor');
        if (hm === 'Adar' || hm === 'Adar II') {
            if ((hd === 13 && dow !== 6) || (hd === 11 && dow === 4)) add('תענית אסתר', 'צום', 'fast');
            if (hd === 14) add('פורים', 'פורים', 'minor');
            if (hd === 15) add('שושן פורים', 'שושן פורים', 'minor');
        }
        if (hm === 'Adar I' && hd === 14) add('פורים קטן', 'פורים קטן', 'minor');
        if (hm === 'Nisan') {
            if (hd === 14) add('ערב פסח', 'ערב פסח', 'erev');
            if (hd === 15) add('פסח', 'פסח', 'yomtov');
            if (hd === 16) { add('חול המועד פסח', 'חוה״מ', 'chol', 'il'); add('פסח ב׳ — יום טוב שני של גלויות', 'פסח ב׳', 'yomtov', 'diaspora'); }
            if (hd >= 17 && hd <= 20) add('חול המועד פסח', 'חוה״מ', 'chol');
            if (hd === 21) add('שביעי של פסח', 'שביעי פסח', 'yomtov');
            if (hd === 22) add('אחרון של פסח — יום טוב שני של גלויות', 'אחרון פסח', 'yomtov', 'diaspora');
            if ((hd === 27 && dow !== 5 && dow !== 0) || (hd === 26 && dow === 4) || (hd === 28 && dow === 1)) add('יום הזיכרון לשואה ולגבורה', 'יום השואה', 'modern', 'il');
        }
        if (hm === 'Iyar') {
            // ה׳ באייר חל בשישי / שבת / שני / רביעי — ונדחה או מוקדם בהתאם
            if ((hd === 4 && dow === 2) || (hd === 3 && dow === 3) || (hd === 2 && dow === 3) || (hd === 5 && dow === 1)) add('יום הזיכרון לחללי מערכות ישראל', 'יום הזיכרון', 'modern', 'il');
            if ((hd === 5 && dow === 3) || (hd === 4 && dow === 4) || (hd === 3 && dow === 4) || (hd === 6 && dow === 2)) add('יום העצמאות', 'יום העצמאות', 'modern', 'il');
            if (hd === 18) add('ל״ג בעומר', 'ל״ג בעומר', 'minor');
            if (hd === 28) add('יום ירושלים', 'יום ירושלים', 'modern', 'il');
        }
        if (hm === 'Sivan') {
            if (hd === 5) add('ערב שבועות', 'ערב שבועות', 'erev');
            if (hd === 6) add('שבועות', 'שבועות', 'yomtov');
            if (hd === 7) add('שבועות ב׳ — יום טוב שני של גלויות', 'שבועות ב׳', 'yomtov', 'diaspora');
        }
        if (hm === 'Tamuz' && ((hd === 17 && dow !== 6) || (hd === 18 && dow === 0))) add('צום י״ז בתמוז', 'צום', 'fast');
        if (hm === 'Av') {
            if ((hd === 8 && dow !== 6) || (hd === 9 && dow === 6)) add('ערב תשעה באב', 'ערב ט׳ באב', 'erev');
            if ((hd === 9 && dow !== 6) || (hd === 10 && dow === 0)) add('תשעה באב', 'ט׳ באב', 'fast');
            if (hd === 15) add('ט״ו באב', 'ט״ו באב', 'minor');
        }
        if (hm === 'Elul' && hd === 29) add('ערב ראש השנה', 'ערב ר״ה', 'erev');
        // ראש חודש: ל׳ בחודש, וא׳ בחודש (חוץ מתשרי — ראש השנה)
        if (hd === 30 || (hd === 1 && hm !== 'Tishri')) add('ראש חודש', 'ר״ח', 'rc');
        // i18n-ok-end
        return out;
    }

    /**
     * יום אחד: { day: 'כ״ה', month: 'כסלו', year: 'תשפ״ז', label: 'כ״ה בכסלו תשפ״ז', holidays: [...] }.
     * diaspora — להציג גם את חגי חו"ל (יום טוב שני של גלויות).
     */
    function info(d, diaspora = true) {
        const key = d.toDateString();
        if (!cache.has(key)) {
            const noon = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 12);
            const h = parts(noon);
            if (he) {
                const month = MONTH[h.month] || h.month;
                cache.set(key, { day: gematria(h.day), month, year: gematria(h.year), label: `${gematria(h.day)} ב${month} ${gematria(h.year)}`, // i18n-none
                    all: holidays(d, h) });
            } else {
                cache.set(key, { day: String(h.day), month: fmtMonth.format(noon), year: String(h.year), label: fmtLabel.format(noon),
                    monthYear: fmtMonthYear.format(noon), all: holidays(d, h) });
            }
        }
        const x = cache.get(key);
        return { ...x, holidays: x.all.filter(y => diaspora || y.place !== 'diaspora') };
    }

    /** החודשים העבריים שחודש לועזי עובר בהם: "תשרי–חשוון תשפ״ז". */
    function monthSpan(year, month0) {
        const a = info(new Date(year, month0, 1)), b = info(new Date(year, month0 + 1, 0));
        // בשפה אחרת: "Tishri–Heshvan 5787" — החודש והשנה בסדר של השפה (Intl)
        if (!he) return a.month === b.month ? b.monthYear : `${a.year !== b.year ? a.monthYear : a.month}–${b.monthYear}`;
        return a.month === b.month ? `${a.month} ${a.year}` : `${a.month}${a.year !== b.year ? ' ' + a.year : ''}–${b.month} ${b.year}`;
    }

    return { info, monthSpan, gematria };
})();
