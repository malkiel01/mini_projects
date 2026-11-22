/*
 * ספורנו — התזכורות בדפדפן (אפיון 08): SF.rem. הלוגיקה — reminders-core.js (SFRemCore, אותו חישוב כמו בשרת).
 *
 *   reminders  — רשימת התזכורות (SF.store.set — מסתנכרנת; לקורא השרת מתכנן ממנה Push ומייל)
 *   remprefs   — ברירת המחדל של הערוצים, הארכת ההשתקה, ואזור הזמן של המכשיר (כשאין מיקום) — מסתנכרן
 *   push:dev   — מכשיר ה-Push של הדפדפן הזה (setRaw — של המכשיר בלבד); push:owner — של מי הוא נרשם ('r' / 'g')
 *
 *   בתוך האפליקציה — מחושב כאן, גם בלי רשת ובלי חשבון: הקרובות, ובאנר כשמגיע הזמן (כל עוד האפליקציה פתוחה).
 *   Push כשהאפליקציה סגורה — רק מהשרת (cron). אורח עם Push: ההגדרות נשלחות (מוצפנות בשרת) למכשיר ה-Push האנונימי שלו,
 *   **רק אחרי שהפעיל Push** — אחרת שום דבר לא יוצא מהמכשיר.
 */
'use strict';

(function (SF) {
    const R = window.SFRemCore;
    const Cal = window.SFCal;
    const listeners = new Set();
    let timer = null;
    let guestTimer = null;
    const shown = new Set();
    const emit = why => { for (const fn of listeners) { try { fn(why); } catch (e) { /* מאזין */ } } };

    const newId = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    const list = async () => (await SF.store.get('reminders', [])).filter(r => r && typeof r === 'object');
    const prefsRaw = () => SF.store.get('remprefs', null);

    async function save(rem) {
        const r = Object.assign({}, rem, { id: rem.id || newId(), created_at: rem.created_at || new Date().toISOString() });
        if (!R.clean(r)) throw new Error('התזכורת לא שלמה — בדקו את השעה, הימים והשם');
        await SF.store.update('reminders', [], l => l.filter(x => x.id !== r.id).concat([r]));
        changed('save');
        return r;
    }
    async function remove(id) {
        await SF.store.update('reminders', [], l => l.filter(x => x.id !== id));
        changed('remove');
    }
    async function setPrefs(p) {
        const cur = R.prefs(await prefsRaw());
        await SF.store.set('remprefs', Object.assign({}, cur, p, { tz: SF.daily.deviceTz }));
        changed('prefs');
    }

    /** ההקשר לחישוב — המקום, השיטה (מאושרת בלבד, כמו בשרת), אזור הזמן וישראל / חו"ל */
    async function ctx() {
        const [st, rems, pr] = await Promise.all([SF.daily.state(), list(), prefsRaw()]);
        const p = R.prefs(pr);
        let place = null;
        if (st.place) {
            const c = st.place.kind === 'city' ? st.cities.find(x => x.id === st.place.id) : null;
            place = { lat: st.place.lat, lon: st.place.lon, tz: st.place.tz, cc: c ? c.cc : null };
        }
        const tz = place ? place.tz : (p.tz || SF.daily.deviceTz || 'Asia/Jerusalem');
        const def = st.method && !st.method.test ? st.method.def : null;
        return { reminders: rems.map(R.clean).filter(Boolean), raw: rems, prefs: p, tz, place, def, israel: R.inIsrael(place, tz), extend: p.extend, st };
    }

    /** המופעים הקרובים (48 שעות), עם התזכורת עצמה */
    async function upcoming(hours = 48) {
        const c = await ctx();
        const now = Date.now();
        const pl = R.plan(Cal, c, now - 10 * 60000, now + hours * 3600000);
        const by = Object.fromEntries(c.reminders.map(r => [r.id, r]));
        return { ctx: c, mute: pl.mute, need: pl.need, occ: pl.occ.map(o => Object.assign({}, o, { r: by[o.rem] })) };
    }

    // ── בתוך האפליקציה: באנר ותזמון ────────────────────────────
    function banner(o) {
        const key = o.rem + ':' + o.ms;
        if (shown.has(key)) return;
        shown.add(key);
        const r = o.r;
        const el = SF.h('div', { class: 'rem-banner', role: 'alert' },
            SF.h('span', { 'aria-hidden': 'true' }, '⏰'),
            SF.h('a', { href: R.link(r), onclick: () => el.remove() }, SF.h('b', null, r.name || (r.prayer && r.prayer.name) || 'תזכורת'),
                SF.h('span', { class: 'muted' }, ' · ' + Cal.hhmm(o.tz, o.ms))),
            SF.h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'סגירה', onclick: () => el.remove() }, '✕'));
        document.body.appendChild(el);
    }

    async function arm() {
        clearTimeout(timer);
        let u;
        try { u = await upcoming(24); } catch (e) { return; }
        const now = Date.now();
        const inapp = u.occ.filter(o => !o.muted && R.channels(o.r, u.ctx.prefs).inapp);
        for (const o of inapp) if (o.ms <= now && o.ms > now - 10 * 60000) banner(Object.assign({ tz: u.ctx.tz }, o));
        const next = inapp.find(o => o.ms > now);
        if (next) timer = setTimeout(arm, Math.min(next.ms - now + 500, 6 * 3600000));
        else timer = setTimeout(arm, 6 * 3600000);
    }

    // ── Push ───────────────────────────────────────────────────
    const isIos = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const standalone = () => (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
    const b64 = s => { const p = '='.repeat((4 - s.length % 4) % 4); const raw = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(raw, c => c.charCodeAt(0)); };

    /** מצב ה-Push בדפדפן הזה: active · prompt (צריך אישור) · denied · ios_install (ב-iPhone רק מותקנת) · unsupported · server_off */
    async function pushState() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return isIos() && !standalone() ? 'ios_install' : 'unsupported';
        if (Notification.permission === 'denied') return 'denied';
        const dev = await SF.store.get('push:dev', null);
        let sub = null;
        try { const reg = await navigator.serviceWorker.getRegistration(); sub = reg && reg.pushManager ? await reg.pushManager.getSubscription() : null; } catch (e) { /* */ }
        return dev && sub && Notification.permission === 'granted' ? 'active' : 'prompt';
    }

    /** הפעלה — רק מלחיצה (הדפדפן מבקש אישור). רושם את המנוי בשרת, ולאורח — שולח את ההגדרות */
    async function enablePush() {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') { const e = new Error(perm === 'denied' ? 'ההתראות נחסמו בדפדפן. אפשר לאפשר אותן בהגדרות האתר' : 'לא אושרו התראות'); e.code = 'denied'; throw e; }
        const k = await SF.api('push-key');
        if (!k.key) throw new Error('ההתראות עוד לא זמינות בשרת');
        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(k.key) });
        const r = await SF.api('push-subscribe', { body: { sub: sub.toJSON() } });
        await SF.store.setRaw('push:dev', r.device);
        await SF.store.setRaw('push:owner', SF.account && SF.account.reader() ? 'r' : 'g');
        await guestSync(true);
        emit('push');
        return r.device;
    }

    async function disablePush() {
        const dev = await SF.store.get('push:dev', null);
        if (dev) { try { await SF.api('push-unsubscribe', { body: { device: dev } }); } catch (e) { if (e.code !== 'not_found') throw e; } }
        try { const reg = await navigator.serviceWorker.getRegistration(); const sub = reg && await reg.pushManager.getSubscription(); if (sub) await sub.unsubscribe(); } catch (e) { /* */ }
        await SF.store.delRaw('push:dev');
        await SF.store.delRaw('push:owner');
        emit('push');
    }

    const testPush = async () => SF.api('push-test', { body: { device: await SF.store.get('push:dev', null) } });

    /** הדפדפן נרשם כאורח והתחבר לחשבון (או להפך) — אותו מנוי עובר לבעלים הנוכחי */
    async function rebind() {
        const dev = await SF.store.get('push:dev', null);
        if (!dev || Notification.permission !== 'granted') return;
        const want = SF.account && SF.account.reader() ? 'r' : 'g';
        if ((await SF.store.get('push:owner', null)) === want) return;
        try {
            const reg = await navigator.serviceWorker.getRegistration();
            const sub = reg && await reg.pushManager.getSubscription();
            if (!sub) return;
            const r = await SF.api('push-subscribe', { body: { sub: sub.toJSON() } });
            await SF.store.setRaw('push:dev', r.device);
            await SF.store.setRaw('push:owner', want);
            await guestSync(true);
        } catch (e) { /* ננסה בפעם הבאה */ }
    }

    /** אורח עם Push: ההגדרות לשרת (כדי שיישלח כשהאפליקציה סגורה). קורא — דרך הסנכרון של 05, לא כאן */
    async function guestSync(now) {
        clearTimeout(guestTimer);
        if (SF.account && SF.account.reader()) return;
        if ((await SF.store.get('push:owner', null)) !== 'g' || !(await SF.store.get('push:dev', null))) return;
        const go = async () => {
            const [rems, pr, where, zm] = await Promise.all([list(), prefsRaw(), SF.store.get('loc', null), SF.store.get('zmethod', null)]);
            // ⚙️ 08: המיקום יוצא מהמכשיר רק כאן — לאורח שהפעיל Push, כדי שהשרת יחשב זמנים יחסיים והשתקה; נשמר מוצפן, לא ביומן
            const payload = { reminders: rems, prefs: Object.assign({}, R.prefs(pr), { tz: SF.daily.deviceTz }), zmethod: zm, loc: where };
            try { await SF.api('reminders-guest-sync', { body: payload }); } catch (e) { if (e.code === 'network') guestTimer = setTimeout(go, 60000); }
        };
        if (now) return go();
        guestTimer = setTimeout(go, 1500);
    }

    function changed(why) {
        emit(why);
        arm();
        guestSync(false);
    }

    /** קובץ .ics למופע — null אם הוא מושתק */
    function icsFile(o, tzNow) {
        const url = new URL('index.html' + R.link(o.r), location.href).href;
        const text = R.ics(o, o.r, url, tzNow || Date.now());
        return text ? new Blob([text], { type: 'text/calendar;charset=utf-8' }) : null;
    }

    SF.rem = {
        list, save, remove, setPrefs, prefs: async () => R.prefs(await prefsRaw()), ctx, upcoming, pushState, enablePush, disablePush, testPush, icsFile,
        isIos, standalone, newId,
        onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },
        start() {
            SF.daily.onChange(() => changed('daily'));
            SF.sync.on(() => rebind());   // כניסה / יציאה מחשבון — המנוי עובר לבעלים הנוכחי (rebind יוצא מיד אם לא השתנה)
            document.addEventListener('visibilitychange', () => { if (!document.hidden) arm(); });
            setTimeout(() => { arm(); rebind(); }, 800);
        },
    };
})(window.SF);
