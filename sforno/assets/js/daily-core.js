/*
 * ספורנו — זמני היום, המיקום וההלכה היומית במכשיר (אפיון 07), בלי DOM ובלי רשת — נבדק ב-node (tests/calendar-core.mjs).
 * החישוב עצמו — calendar-core.js (Cal). כאן: איזו שיטה מוצגת, מהו המיקום הפעיל, חבילת החודש שהורדה, ומה מופיע במסך הבית.
 *
 *   שיטה     — זו שהקורא בחר (zmethod, מסתנכרן) אם היא עדיין ברשימה המאושרת; אחרת ברירת המחדל של המנהל; אחרת **אין**
 *              (need: true) — המסך מבקש לבחור ולא מציג שעה (§3, §11).
 *   מיקום    — loc (מסתנכרן, פריט אחד: רק המיקום הפעיל, §19): {kind: 'city', id} או {kind: 'device', lat, lon, tz, near}.
 *              מיקום המכשיר — מעוגל ל-0.01°, ואזור הזמן — של העיר הקרובה ברשימה (עד 300 ק"מ), אחרת של המכשיר.
 *   חבילה    — zdl (setRaw, לא מסתנכרן — של המכשיר הזה): הזמנים של 30 יום, המיקום, השיטה עם הגרסה שלה, וההלכה שהשרת
 *              כבר נתן (היום ומחר). בלי רשת — רק ממנה; מיקום אחר מזה של החבילה ← "נדרש עדכון", לא זמנים של עיר אחרת.
 */
'use strict';

(function (root, factory) {
    const D = factory();
    if (typeof module === 'object' && module.exports) module.exports = D;
    else root.SFDailyCore = D;
})(typeof self !== 'undefined' ? self : this, function () {
    const PKG_DAYS = 30;
    const NEAR_KM = 300;

    const pad = n => String(n).padStart(2, '0');
    const iso = g => g.y + '-' + pad(g.m) + '-' + pad(g.d);

    /** השיטה שמוצגת. list — המאושרות (read-zmanim-methods), dflt — של המנהל או null, chosen — pid שהקורא בחר. */
    function pickMethod(list, dflt, chosen) {
        const all = Array.isArray(list) ? list : [];
        const mine = all.find(m => m && m.pid === chosen);
        if (mine) return { method: mine, chosen: true, need: false };
        const d = all.find(m => m && m.pid === dflt);
        if (d) return { method: d, chosen: false, need: false };
        return { method: null, chosen: false, need: true, lost: !!chosen };
    }

    /** המיקום השמור → מקום לחישוב {lat, lon, elev, tz, name, kind, id?}. null — אין מיקום (או עיר שלא ברשימה). */
    function place(loc, cities) {
        if (!loc || typeof loc !== 'object') return null;
        if (loc.kind === 'city') {
            const c = (cities || []).find(x => x.id === loc.id);
            return c ? { kind: 'city', id: c.id, name: c.name, lat: c.lat, lon: c.lon, elev: c.elev || 0, tz: c.tz } : null;
        }
        if (loc.kind === 'device' && typeof loc.lat === 'number' && typeof loc.lon === 'number' && Math.abs(loc.lat) <= 90 && Math.abs(loc.lon) <= 180 && typeof loc.tz === 'string') {
            return { kind: 'device', name: loc.near ? 'מיקום המכשיר (ליד ' + loc.near + ')' : 'מיקום המכשיר', lat: loc.lat, lon: loc.lon, elev: 0, tz: loc.tz };
        }
        return null;
    }

    /** מיקום מהמכשיר → loc לשמירה: מעוגל, עם אזור הזמן של העיר הקרובה (או של המכשיר) */
    function deviceLoc(Cal, coords, cities, deviceTz) {
        const pt = { lat: Cal.roundPt(coords.latitude), lon: Cal.roundPt(coords.longitude) };
        const n = Cal.nearest(cities || [], pt);
        const close = n && n.km <= NEAR_KM;
        return { kind: 'device', lat: pt.lat, lon: pt.lon, tz: close ? n.city.tz : deviceTz || 'UTC', near: close ? n.city.name : null };
    }

    /** אותו מקום? (לחבילה: עיר — אותה עיר; מכשיר — אותן קואורדינטות מעוגלות ואותו אזור זמן) */
    function samePlace(a, b) {
        if (!a || !b || a.kind !== b.kind) return false;
        if (a.kind === 'city') return a.id === b.id;
        return a.lat === b.lat && a.lon === b.lon && a.tz === b.tz;
    }

    /** חיפוש עיר: עברית או אנגלית, מתחילת מילה; בלי שאילתה — הכול */
    function findCities(cities, q) {
        const s = String(q || '').trim().toLowerCase();
        if (!s) return cities || [];
        return (cities || []).filter(c => (' ' + c.name + ' ' + c.en.toLowerCase()).includes(' ' + s) || c.name.includes(s));
    }

    /** הזמנים של יום אחד לתצוגה: [{key, name, time: "06:31" | null}] */
    function dayRows(Cal, def, pl, g) {
        return Cal.zmanim(def, pl, g.y, g.m, g.d).map(z => ({ key: z.key, name: z.name, ms: z.ms, time: z.ms === null ? null : Cal.hhmm(pl.tz, z.ms) }));
    }

    /**
     * חבילת חודש: 30 יום מהתאריך האזרחי from, עם הזמנים, המקום, השיטה וגרסתה. halacha — מה שהשרת נתן (לא יותר).
     * kept — מתי נבנתה. הזמנים נשמרים כ-ms (אותו רגע בכל מכשיר), והתצוגה — בשעון של tz של המקום.
     */
    function buildPackage(Cal, method, pl, loc, from, halacha, at) {
        const f0 = Cal.fixedFromGreg(from.y, from.m, from.d);
        const days = [];
        for (let i = 0; i < PKG_DAYS; i++) {
            const g = Cal.gregFromFixed(f0 + i);
            const h = Cal.hebFromFixed(f0 + i);
            days.push({ date: iso(g), heb: Cal.hebText(h), z: Cal.zmanim(method.def, pl, g.y, g.m, g.d).map(z => ({ key: z.key, name: z.name, ms: z.ms })) });
        }
        return { v: 1, at: at || null, loc, place: { name: pl.name, lat: pl.lat, lon: pl.lon, tz: pl.tz, kind: pl.kind },
            method: { pid: method.pid, name: method.name, version: method.version, def: method.def },
            from: iso(from), to: days[days.length - 1].date, days, halacha: (halacha || []).filter(x => x && x.item && x.item.html) };
    }

    /**
     * מצב החבילה מול המיקום והשיטה הפעילים, בתאריך today ("YYYY-MM-DD" של המקום):
     *   none · ok · other_place (המיקום הפעיל אחר — "נדרש עדכון") · other_method · method_updated (גרסה חדשה בשרת, כשידוע)
     *   · expired (התאריך אחרי סוף החבילה)
     */
    function pkgState(pkg, loc, methodPid, methodVersion, today) {
        if (!pkg || !Array.isArray(pkg.days) || !pkg.days.length) return { state: 'none', text: 'לא הורד' };
        const where = pkg.place.name;
        if (!samePlace(pkg.loc, loc)) return { state: 'other_place', text: 'הזמנים מחושבים ל' + where + ' — נדרש עדכון' };
        if (methodPid && pkg.method.pid !== methodPid) return { state: 'other_method', text: 'החבילה לפי ' + pkg.method.name + ' — נדרש עדכון' };
        if (methodVersion && pkg.method.version !== methodVersion) return { state: 'method_updated', text: 'השיטה עודכנה מאז ההורדה — נדרש עדכון' };
        if (today && today > pkg.to) return { state: 'expired', text: 'החבילה הסתיימה ב-' + pkg.to + ' — נדרש עדכון' };
        return { state: 'ok', text: 'זמין בלי אינטרנט עד ' + pkg.to };
    }

    /** היום בחבילה: {date, heb, rows: [{key, name, time|null}]} או null */
    function pkgDay(Cal, pkg, date) {
        const d = pkg && Array.isArray(pkg.days) ? pkg.days.find(x => x.date === date) : null;
        if (!d) return null;
        return { date: d.date, heb: d.heb, rows: d.z.map(z => ({ key: z.key, name: z.name, ms: z.ms, time: z.ms === null ? null : Cal.hhmm(pkg.place.tz, z.ms) })) };
    }

    /** ההלכה מהחבילה לתאריך עברי {y, m, d} — עם התאריך, המקום והשיטה שחושבה לפיהם (§4) */
    function pkgHalacha(pkg, h) {
        if (!pkg || !Array.isArray(pkg.halacha)) return null;
        const x = pkg.halacha.find(it => it.y === h.y && it.m === h.m && it.d === h.d);
        return x ? { item: x.item, place: pkg.place.name, method: pkg.method.name, at: pkg.at } : null;
    }

    /** מה מוצג ברכיב ההלכה במסך הבית: הפריט, או null — **ואז אין רכיב בכלל**, לא הודעה ולא תוכן חלופי (§4, §21.9) */
    function homeHalacha(res) {
        const it = res && res.item;
        return it && typeof it.html === 'string' && it.html.trim() !== '' ? it : null;
    }

    /** "של היום" בתהילים מהיום העברי (today של Cal): יום בשבוע, יום בחודש ואורך החודש */
    const tehillimDay = t => ({ weekday: t.weekday, day: t.heb.d, monthLen: t.monthLen });

    return { PKG_DAYS, iso, pickMethod, place, deviceLoc, samePlace, findCities, dayRows, buildPackage, pkgState, pkgDay, pkgHalacha, homeHalacha, tehillimDay };
});
