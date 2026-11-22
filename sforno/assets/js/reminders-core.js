/*
 * ספורנו — התזכורות (אפיון 08), בלי DOM ובלי רשת — נבדק ב-node (tests/reminders-core.mjs). **אותו אלגוריתם בדיוק כמו
 * lib/reminders.php** (התכנון בשרת, שממנו יוצאים Push ומייל): שינוי באחד = אותו שינוי בשני; l-reminders משווה את שניהם.
 *
 *   תזכורת  — {id, name, type, prayer, time | base + offset | base + before, days, from, to, active, channels, devices, created_at}
 *             type: fixed (שעה קבועה) · relative (יחסי לזמן) · before_end (לפני סוף זמן) · minyan (מניין שהמשתמש מזין)
 *             channels: null = ברירת המחדל הכללית (remprefs); devices: null = כל המכשירים שלי עם Push
 *   השתקה   — כל יום שבת / יום טוב (כולל רצף, ויום טוב שני מחוץ לישראל) מ-X דקות לפני השקיעה בערב עד Y דקות אחרי השקיעה
 *             ביום האחרון של הרצף, ועוד ההארכה של המשתמש (רק מאריכה). השיטה — MUTE: **שיטת בדיקה** עד שהמנהל יאשר (📦 §24)
 *   מופע    — {rem, ms, muted}. מושתק — לא נשלח בשום ערוץ, ולא יוצא ממנו קובץ יומן; ביציאה לא נשלח מה שהוחמץ (§3)
 */
'use strict';

(function (root, factory) {
    const R = factory();
    if (typeof module === 'object' && module.exports) module.exports = R;
    else root.SFRemCore = R;
})(typeof self !== 'undefined' ? self : this, function () {
    const TYPES = ['fixed', 'relative', 'before_end', 'minyan'];
    const HORIZON = 48 * 3600000;
    const DAY = 86400000;
    /** שיטת ההשתקה — **לבדיקה בלבד** (test: true) עד שהמנהל יאשר שיטה (📦 §24). רחבה בכוונה: מוקדם יותר, ומאוחר יותר */
    const MUTE = { test: true, name: 'שיטת בדיקה: מ-40 דקות לפני השקיעה בערב עד 72 דקות אחרי השקיעה במוצאי היום', before: 40, after: 72, depth: 0.833 };
    const EXT_MAX = 180;

    const okId = id => typeof id === 'string' && /^[A-Za-z0-9._-]{1,100}$/.test(id);
    const okPid = p => typeof p === 'string' && /^[a-z0-9]{16}$/.test(p);
    const isInt = (v, lo, hi) => Number.isInteger(v) && v >= lo && v <= hi;
    const okDate = s => typeof s === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(s);
    const str = (v, max) => typeof v === 'string' && v.trim() !== '' && v.length <= max;

    /** תזכורת מהמכשיר / מהשרת → צורה נקייה, או null אם היא פגומה (לא מתכננים ממנה כלום). */
    function clean(r) {
        if (!r || typeof r !== 'object' || !okId(r.id) || !TYPES.includes(r.type)) return null;
        const o = { id: r.id, name: str(r.name, 60) ? r.name.trim() : '', type: r.type, active: r.active !== false, created_at: typeof r.created_at === 'string' ? r.created_at.slice(0, 30) : '' };
        if (r.prayer && typeof r.prayer === 'object' && okPid(r.prayer.edition) && (r.prayer.sid == null || (typeof r.prayer.sid === 'string' && /^[A-Za-z0-9_-]{1,80}$/.test(r.prayer.sid)))) {
            o.prayer = { edition: r.prayer.edition, sid: r.prayer.sid || null, name: str(r.prayer.name, 80) ? r.prayer.name.trim() : '' };
        } else o.prayer = null;
        if (r.type === 'fixed' || r.type === 'minyan') {
            if (typeof r.time !== 'string' || !/^([01]\d|2[0-3]):[0-5]\d$/.test(r.time)) return null;
            o.time = r.time;
        } else {
            if (typeof r.base !== 'string' || !/^[a-z][a-z0-9_]{0,31}$/.test(r.base)) return null;
            o.base = r.base;
            if (r.type === 'relative') { if (!isInt(r.offset, -300, 300)) return null; o.offset = r.offset; }
            else { if (!isInt(r.before, 0, 300)) return null; o.before = r.before; }
        }
        if (r.type === 'minyan' && !o.name) return null;
        const days = Array.isArray(r.days) ? [...new Set(r.days.filter(d => isInt(d, 0, 6)))].sort() : [];
        if (!days.length) return null;
        o.days = days;
        o.from = okDate(r.from) ? r.from : null;
        o.to = okDate(r.to) ? r.to : null;
        o.channels = r.channels && typeof r.channels === 'object'
            ? { push: r.channels.push === true, inapp: r.channels.inapp === true, email: r.channels.email === true } : null;
        o.devices = Array.isArray(r.devices) ? [...new Set(r.devices.filter(okPid))].slice(0, 20) : null;
        return o;
    }

    /** ההעדפות הכלליות: ערוצי ברירת מחדל, הארכת ההשתקה (לפני / אחרי, בדקות), ואזור הזמן של המכשיר (כשאין מיקום) */
    function prefs(p) {
        const c = p && typeof p === 'object' && p.channels && typeof p.channels === 'object' ? p.channels : {};
        const ext = v => isInt(v, 0, EXT_MAX) ? v : 0;
        return { channels: { push: c.push !== false, inapp: c.inapp !== false, email: c.email === true },
            extend: { before: ext(p && p.extend && p.extend.before), after: ext(p && p.extend && p.extend.after) },
            tz: p && typeof p.tz === 'string' && p.tz.length <= 64 ? p.tz : null };
    }

    /** הערוצים של תזכורת: שלה, או ברירת המחדל */
    const channels = (rem, pr) => rem.channels || pr.channels;

    /** יום טוב (כולל יום שני בחו"ל). h — תאריך עברי {m, d} במזהי ש6 (1 ניסן, 7 תשרי) */
    function yomTov(h, israel) {
        if (h.m === 7) return [1, 2, 10, 15, 22].includes(h.d) || (!israel && (h.d === 16 || h.d === 23));
        if (h.m === 1) return h.d === 15 || h.d === 21 || (!israel && (h.d === 16 || h.d === 22));
        if (h.m === 3) return h.d === 6 || (!israel && h.d === 7);
        return false;
    }

    const kodesh = (Cal, f, israel) => Cal.weekday(f) === 6 || yomTov(Cal.hebFromFixed(f), israel);

    /** בישראל? לפי העיר (cc), אחרת לפי אזור הזמן של המקום / המכשיר */
    const inIsrael = (place, tz) => place && place.cc ? place.cc === 'IL' : (place ? place.tz : tz) === 'Asia/Jerusalem';

    function sunset(Cal, place, f) {
        if (!place) return null;
        const g = Cal.gregFromFixed(f);
        return Cal.sunAt(place.lat, place.lon, Cal.zonedToUtc(place.tz, g.y, g.m, g.d, 12, 0), MUTE.depth, 'pm');
    }

    /**
     * חלונות ההשתקה שנוגעים בטווח [fromMs, toMs): [{start, end, approx}]. בלי מיקום (או בלי שקיעה) — מ-12:00 בערב עד 01:00
     * אחרי היום האחרון, לפי אזור הזמן (approx: true) — רחב יותר, לא צר יותר.
     */
    function muteWindows(Cal, ctx, fromMs, toMs) {
        const tz = ctx.tz;
        const lp = ms => { const p = Cal.localParts(tz, ms); return Cal.fixedFromGreg(p.y, p.m, p.d); };
        const f0 = lp(fromMs) - 3, f1 = lp(toMs) + 3;
        const ext = ctx.extend || { before: 0, after: 0 };
        const out = [];
        for (let f = f0; f <= f1; f++) {
            if (!kodesh(Cal, f, ctx.israel) || kodesh(Cal, f - 1, ctx.israel)) continue;
            let n = f;
            while (kodesh(Cal, n + 1, ctx.israel)) n++;
            const s = sunset(Cal, ctx.place, f - 1), e = sunset(Cal, ctx.place, n);
            let start, end, approx = false;
            if (s !== null && e !== null) {
                start = s - MUTE.before * 60000;
                end = e + MUTE.after * 60000;
            } else {
                const ge = Cal.gregFromFixed(f - 1), ga = Cal.gregFromFixed(n + 1);
                start = Cal.zonedToUtc(tz, ge.y, ge.m, ge.d, 12, 0);
                end = Cal.zonedToUtc(tz, ga.y, ga.m, ga.d, 1, 0);
                approx = true;
            }
            start -= ext.before * 60000;
            end += ext.after * 60000;
            if (end > fromMs && start < toMs) out.push({ start, end, approx });
            f = n;
        }
        return out;
    }

    const pad = n => String(n).padStart(2, '0');
    const isoDate = g => g.y + '-' + pad(g.m) + '-' + pad(g.d);

    /**
     * המופעים בטווח [fromMs, toMs). ctx: {reminders (נקיות), tz, place|null, def|null, israel, extend}.
     * מחזיר {occ: [{rem, ms, muted}], mute, need: {place, method}} — need: יש תזכורת יחסית שלא ניתן לחשב (אין מיקום / שיטה /
     * הזמן לא קיים בשיטה).
     */
    function plan(Cal, ctx, fromMs, toMs) {
        const tz = ctx.tz;
        const mute = muteWindows(Cal, ctx, fromMs, toMs);
        const need = { place: false, method: false };
        const lp = ms => { const p = Cal.localParts(tz, ms); return Cal.fixedFromGreg(p.y, p.m, p.d); };
        const f0 = lp(fromMs) - 1, f1 = lp(toMs) + 1;
        const zcache = {};
        const zm = (g) => {
            const k = isoDate(g);
            if (!(k in zcache)) {
                const m = {};
                for (const z of Cal.zmanim(ctx.def, ctx.place, g.y, g.m, g.d)) m[z.key] = z.ms;
                zcache[k] = m;
            }
            return zcache[k];
        };
        const occ = [];
        for (const r of ctx.reminders) {
            if (!r || !r.active) continue;
            const rel = r.type === 'relative' || r.type === 'before_end';
            if (rel) {
                if (!ctx.place) { need.place = true; continue; }
                if (!ctx.def || !ctx.def.zmanim.some(z => z.key === r.base)) { need.method = true; continue; }
            }
            for (let f = f0; f <= f1; f++) {
                if (!r.days.includes(Cal.weekday(f))) continue;
                const g = Cal.gregFromFixed(f);
                const day = isoDate(g);
                if ((r.from && day < r.from) || (r.to && day > r.to)) continue;
                let ms;
                if (rel) {
                    const b = zm(g)[r.base];
                    if (b === null || b === undefined) continue;
                    ms = b + (r.type === 'relative' ? r.offset : -r.before) * 60000;
                } else {
                    const [hh, mm] = r.time.split(':').map(Number);
                    ms = Cal.zonedToUtc(tz, g.y, g.m, g.d, hh, mm);
                }
                if (ms < fromMs || ms >= toMs) continue;
                occ.push({ rem: r.id, ms, muted: mute.some(w => ms >= w.start && ms < w.end) });
            }
        }
        occ.sort((a, b) => a.ms - b.ms || (a.rem < b.rem ? -1 : a.rem > b.rem ? 1 : 0));
        return { occ, mute, need };
    }

    // ── יומן חיצוני (.ics) ─────────────────────────────────────
    const icsTime = ms => new Date(ms).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
    const icsText = s => String(s).replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n');
    const bytes = ch => { const c = ch.codePointAt(0); return c < 0x80 ? 1 : c < 0x800 ? 2 : c < 0x10000 ? 3 : 4; };
    /** קיפול שורה ל-75 בתים (RFC 5545 §3.1) — בלי לחתוך תו באמצע */
    function fold(line) {
        const out = [];
        let cur = '', n = 0;
        for (const ch of line) {
            const b = bytes(ch);
            if (n + b > (out.length ? 74 : 75)) { out.push(cur); cur = ''; n = 0; }
            cur += ch; n += b;
        }
        out.push(cur);
        return out.join('\r\n ');
    }

    /**
     * קובץ אירוע חד-פעמי למופע אחד. **מופע מושתק — null** (לא נוצר קובץ בתקופת השתקה, §4). אין מנוי ואין עדכון: מה
     * שנשמר ביומן — נשאר שם (המסך אומר את זה).
     */
    function ics(o, rem, url, nowMs) {
        if (!o || o.muted || !rem) return null;
        const title = rem.name || (rem.prayer && rem.prayer.name) || 'תזכורת';
        const lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sforno//Reminders//HE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
            'UID:' + rem.id + '-' + o.ms + '@sforno', 'DTSTAMP:' + icsTime(nowMs), 'DTSTART:' + icsTime(o.ms), 'DTEND:' + icsTime(o.ms + 5 * 60000),
            'SUMMARY:' + icsText(title)];
        if (rem.prayer && rem.prayer.name) lines.push('DESCRIPTION:' + icsText(rem.prayer.name));
        if (url) lines.push('URL:' + url);
        lines.push('BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' + icsText(title), 'TRIGGER:PT0M', 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR');
        return lines.map(fold).join('\r\n') + '\r\n';
    }

    /** הקישור של תזכורת: התפילה המקושרת בסידור, אחרת מסך התזכורות */
    const link = rem => rem && rem.prayer ? '#/r/' + rem.prayer.edition + (rem.prayer.sid ? '/' + rem.prayer.sid : '') : '#/reminders';

    return { TYPES, HORIZON, DAY, MUTE, EXT_MAX, clean, prefs, channels, yomTov, kodesh, inIsrael, muteWindows, plan, ics, fold, link };
});
