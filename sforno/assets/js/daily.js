/*
 * ספורנו — זמני היום, המיקום וההלכה היומית בדפדפן (אפיון 07): SF.daily. הלוגיקה — calendar-core.js (SFCal) ו-daily-core.js
 * (SFDailyCore); כאן — רק הרשת, ה-store והמיקום של המכשיר.
 *
 *   loc      — המיקום הפעיל (SF.store.set — מסתנכרן ב-05 כפריט אחד; **לא נשלח לשרת בשום דרך אחרת** ולא נרשם ביומן)
 *   zmethod  — השיטה שהקורא בחר (מסתנכרן)
 *   zmethods:last — הרשימה המאושרת האחרונה (setRaw — כדי ש"היום" יתחלף בצאת הכוכבים גם בלי רשת)
 *   zdl      — חבילת החודש שהורדה (setRaw — של המכשיר הזה). בלי רשת מסך הזמנים מציג רק ממנה
 *   מנהלים רואים גם את שיטת הבדיקה ("לבדיקה בלבד"); קוראים — רק מאושרות.
 */
'use strict';

(function (SF) {
    const Cal = window.SFCal;
    const D = window.SFDailyCore;
    const deviceTz = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (e) { return 'UTC'; } })();
    let citiesP = null;
    let methodsP = null;
    const listeners = new Set();

    async function cities() {
        if (!citiesP) {
            citiesP = fetch('assets/data/cities.json', { credentials: 'same-origin' }).then(r => r.json()).then(j => j.cities || [])
                .catch(() => { citiesP = null; return []; });
        }
        return citiesP;
    }

    /** {list, dflt, offline}: מהרשת, ובלי רשת — מה שהתקבל בפעם האחרונה. מנהל — גם שיטת הבדיקה, מסומנת test. */
    async function methods(fresh) {
        if (methodsP && !fresh) return methodsP;
        methodsP = (async () => {
            try {
                const r = await SF.api('read-zmanim-methods');
                let list = r.methods;
                if (SF.isStaff && SF.isStaff()) {
                    try {
                        const a = await SF.api('zmanim-method-list');
                        for (const m of a.methods) if (m.test) list = list.concat([{ pid: m.pid, name: m.name, version: m.version, note: m.note, def: m.def, test: true }]);
                    } catch (e) { /* רק התוספת למנהל */ }
                }
                const out = { list, dflt: r.default, offline: false };
                await SF.store.setRaw('zmethods:last', { list: r.methods, dflt: r.default, at: new Date().toISOString() });
                return out;
            } catch (e) {
                if (e.code !== 'network') { methodsP = null; throw e; }
                const last = await SF.store.get('zmethods:last', null);
                methodsP = null;
                return { list: (last && last.list) || [], dflt: last ? last.dflt : null, offline: true };
            }
        })();
        return methodsP;
    }

    /** המצב הנוכחי: הערים, המיקום, השיטה, והיום העברי ברגע הזה */
    async function state(opts = {}) {
        const [cs, loc, chosen, ms] = await Promise.all([cities(), SF.store.get('loc', null), SF.store.get('zmethod', null), methods(opts.fresh).catch(() => ({ list: [], dflt: null, offline: true }))]);
        const place = D.place(loc, cs);
        const pick = D.pickMethod(ms.list, ms.dflt, chosen);
        const def = pick.method ? pick.method.def : null;
        const now = opts.now || Date.now();
        return { cities: cs, loc, place, methods: ms, pick, method: pick.method, today: Cal.today(now, place, def, deviceTz), now, deviceTz };
    }

    async function setLoc(loc) {
        await SF.store.set('loc', loc);
        for (const fn of listeners) { try { fn('loc'); } catch (e) { /* מאזין */ } }
    }
    async function setMethod(pid) {
        await SF.store.set('zmethod', pid);
        for (const fn of listeners) { try { fn('method'); } catch (e) { /* מאזין */ } }
    }

    /** ההלכה לתאריך עברי: מהרשת; בלי רשת — מהחבילה (עם המקום והשיטה שחושבה לפיהם). {item, offline?, place?, method?} */
    async function halacha(heb) {
        try {
            const r = await SF.api('read-halacha', { params: { y: heb.y, m: heb.m, d: heb.d } });
            return { item: r.item };
        } catch (e) {
            if (e.code !== 'network') throw e;
            const x = D.pkgHalacha(await SF.store.get('zdl', null), heb);
            return x ? { item: x.item, offline: true, place: x.place, method: x.method, at: x.at } : { item: null, offline: true };
        }
    }

    /** הורדת חודש — רק מלחיצה (tests/k-calendar סופר את הקריאות). צריך רשת, מיקום ושיטה. */
    async function download() {
        const st = await state({ fresh: true });
        if (st.methods.offline) { const e = new Error('אין חיבור — ההורדה לא הושלמה'); e.code = 'network'; throw e; }
        if (!st.place || !st.method) throw new Error('צריך לבחור מיקום ושיטה לפני ההורדה');
        const t = st.today;
        const tomorrow = Cal.hebFromFixed(t.fixed + 1);
        const hal = [];
        for (const h of [t.heb, tomorrow]) {
            try { hal.push({ y: h.y, m: h.m, d: h.d, item: (await SF.api('read-halacha', { params: { y: h.y, m: h.m, d: h.d } })).item }); } catch (e) { /* בלי ההלכה */ }
        }
        const pkg = D.buildPackage(Cal, st.method, st.place, st.loc, t.civil, hal, new Date().toISOString());
        await SF.store.setRaw('zdl', pkg);
        for (const fn of listeners) { try { fn('pkg'); } catch (e) { /* מאזין */ } }
        return pkg;
    }

    async function removePkg() {
        await SF.store.delRaw('zdl');
        for (const fn of listeners) { try { fn('pkg'); } catch (e) { /* מאזין */ } }
    }

    /** מיקום המכשיר — רק אחרי לחיצה. בסירוב / תקלה: null (המסך עובר לרשימת הערים, בלי שגיאה) */
    function devicePosition() {
        return new Promise(resolve => {
            if (!navigator.geolocation) { resolve({ ok: false, why: 'unsupported' }); return; }
            navigator.geolocation.getCurrentPosition(p => resolve({ ok: true, coords: p.coords }), e => resolve({ ok: false, why: e && e.code === 1 ? 'denied' : 'failed' }),
                { enableHighAccuracy: false, timeout: 15000, maximumAge: 600000 });
        });
    }

    const WEEKDAYS = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];
    /** "יום שני · 23.11.2026 · י"ג כסלו תשפ"ז" */
    const dateLine = (g, heb, wd) => 'יום ' + WEEKDAYS[wd] + ' · ' + g.d + '.' + g.m + '.' + g.y + ' · ' + Cal.hebText(heb);
    /**
     * "היום" לפי צאת הכוכבים: התאריך האזרחי (עדיין לא התחלף) והתאריך העברי — אחרי צאת הכוכבים עם "אור ל": "יום ראשון · 22.11.2026 ·
     * אור לי"ג כסלו תשפ"ז"
     */
    const todayLine = t => 'יום ' + WEEKDAYS[Cal.weekday(Cal.fixedFromGreg(t.civil.y, t.civil.m, t.civil.d))] + ' · ' + t.civil.d + '.' + t.civil.m + '.' + t.civil.y
        + ' · ' + (t.after ? 'אור ל' : '') + Cal.hebText(t.heb);
    /** "UTC+2" */
    function tzLabel(tz, ms) {
        const o = Cal.tzOffset(tz, ms);
        const s = o < 0 ? '−' : '+';
        const a = Math.abs(o);
        return 'UTC' + s + Math.floor(a / 60) + (a % 60 ? ':' + String(a % 60).padStart(2, '0') : '');
    }

    SF.daily = {
        cities, methods, state, setLoc, setMethod, halacha, download, removePkg, devicePosition, dateLine, todayLine, tzLabel, WEEKDAYS, deviceTz,
        pkg: () => SF.store.get('zdl', null),
        onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    };
})(window.SF);
