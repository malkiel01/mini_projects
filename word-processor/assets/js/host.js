/**
 * עורך התמלילים בתוך אפליקציה אחרת — הצד של הדפדפן אצל המארחת. המדריך: word-processor/docs/integration.md.
 *
 *   <script src="https://mbe-plus.com/mini_projects/word-processor/assets/js/host.js"></script>
 *   const ed = WordProcessor.mount(document.getElementById('box'), {
 *     session: async () => (await fetch('api.php?action=editor_session&doc=' + id)).json(),   // → {embed_url, doc}
 *     onSaved: e => …, onChange: dirty => …, onClose: e => …,
 *   });
 *   await ed.save();      // לפני ניווט / סגירה
 *   ed.destroy();
 *
 * מטפל בכל מה שהפרוטוקול דורש (platform/docs/embed.md, פרקים 4 ו-6): ה-iframe, בדיקת המקור של כל הודעה,
 * "expired" (מבקש כתובת חדשה מהשרת שלכם ומחליף), גובה אוטומטי, ושמירה לפני סגירה (מחכה ל-saved עד 5 שניות).
 * סקריפט רגיל (לא module) — עובד בכל דף.
 */
(function () {
    'use strict';

    /**
     * @param {HTMLElement} box — היכן להציג
     * @param {object} o
     *   session()   — חובה. מבקש מהשרת שלכם embed_session ומחזיר {embed_url, doc} (או רק את הכתובת).
     *   height      — 'auto' (העורך גדל עם המסמך, ההודעה height) או גובה קבוע ב-CSS ('80vh', 600). ברירת מחדל: '100%'.
     *   onReady({doc, can, version}) · onChange(dirty) · onSaved({doc, version, updated_at}) · onPage(settings)
     *   onLock({by, since}) · onClose({saved}) · onError({code, message}) · onDoc(doc) — מזהה המסמך (גם כשנוצר חדש)
     */
    function mount(box, o) {
        if (!box || !o || typeof o.session !== 'function') throw new Error('WordProcessor.mount: box ו-session() חובה');
        var frame = document.createElement('iframe');
        frame.title = 'עורך התמלילים';
        frame.setAttribute('allow', 'clipboard-read; clipboard-write');
        frame.style.cssText = 'display:block;inline-size:100%;border:0;background:#e9ebef;';
        var auto = o.height === 'auto';
        frame.style.blockSize = auto ? '600px' : (typeof o.height === 'number' ? o.height + 'px' : (o.height || '100%'));
        box.appendChild(frame);

        var origin = '';
        var dirty = false;
        var waiters = [];
        var destroyed = false;
        var renewing = false;

        function call(fn, arg) { try { if (typeof fn === 'function') fn(arg); } catch (e) { console.error(e); } }

        function load() {
            return Promise.resolve(o.session()).then(function (s) {
                var url = typeof s === 'string' ? s : (s && s.embed_url);
                if (!url) throw new Error('session() לא החזיר embed_url');
                if (s && s.doc) call(o.onDoc, s.doc);
                origin = new URL(url).origin;
                frame.src = url;
            }).catch(function (e) {
                call(o.onError, { code: 'session', message: e && e.message || String(e) });
            });
        }

        function settle(ok) {
            var w = waiters; waiters = [];
            w.forEach(function (fn) { fn(ok); });
        }

        function onMessage(e) {
            if (destroyed || e.source !== frame.contentWindow || e.origin !== origin) return;   // רק מהעורך, רק מה-iframe הזה
            var m = e.data;
            if (!m || m.source !== 'word-processor') return;
            switch (m.type) {
                case 'ready': renewing = false; call(o.onReady, { doc: m.doc, can: m.can, version: m.version }); call(o.onDoc, m.doc); break;
                case 'change': dirty = !!m.dirty; call(o.onChange, dirty); if (!dirty) settle(true); break;
                case 'saved': dirty = false; call(o.onSaved, { doc: m.doc, version: m.version, updated_at: m.updated_at }); settle(true); break;
                case 'height': if (auto && m.px > 0) frame.style.blockSize = Math.ceil(m.px) + 'px'; break;
                case 'page': call(o.onPage, m.settings); break;
                case 'lock': call(o.onLock, { by: m.by, since: m.since }); break;
                case 'close': call(o.onClose, { saved: !!m.saved }); break;
                case 'error': call(o.onError, { code: m.code, message: m.message }); if (m.code !== 'offline') settle(false); break;
                case 'expired':
                    // הסשן פג או שה-iframe רוענן: כתובת חדשה מהשרת, ומחליפים
                    if (!renewing) { renewing = true; load(); }
                    break;
            }
        }
        window.addEventListener('message', onMessage);

        function post(type, extra) {
            if (!origin || !frame.contentWindow) return;
            var m = { type: type };
            for (var k in extra || {}) m[k] = extra[k];
            frame.contentWindow.postMessage(m, origin);
        }

        load();

        return {
            frame: frame,
            /** שמירה עכשיו. מחזיר Promise<boolean> — true כשנשמר (או שלא היה מה לשמור), false אחרי 5 שניות או שגיאה. */
            save: function () {
                if (!dirty) { post('save'); return Promise.resolve(true); }
                return new Promise(function (resolve) {
                    var t = setTimeout(function () { resolve(false); }, 5000);
                    waiters.push(function (ok) { clearTimeout(t); resolve(ok); });
                    post('save');
                });
            },
            /** הגדרות עמוד מהמארחת (מצב section). */
            setPage: function (settings) { post('set_page', { settings: settings }); },
            readonly: function (value) { post('readonly', { value: !!value }); },
            focus: function () { post('focus'); },
            get dirty() { return dirty; },
            /** טעינה מחדש (למשל אחרי ששיניתם הרשאה): כתובת חדשה מהשרת. */
            reload: function () { return load(); },
            destroy: function () {
                destroyed = true;
                window.removeEventListener('message', onMessage);
                settle(false);
                frame.remove();
            },
        };
    }

    window.WordProcessor = { mount: mount, version: 1 };
})();
