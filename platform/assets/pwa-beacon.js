// נטען בכל דף של אפליקציה מחוברת — השער מוסיף אותו (platform/lib/pwa.php). שני תפקידים:
//
// 1. בדיקה אמיתית אם האפליקציה מותקנת במכשיר הזה, ודיווח לפלטפורמה:
//    - הדף פתוח במצב אפליקציה (display-mode: standalone / iOS navigator.standalone) → מותקנת, ודאי.
//    - getInstalledRelatedApps (כרום) מחזיר את ה-manifest של האפליקציה → מותקנת.
//    - beforeinstallprompt — הדפדפן מציע להתקין → לא מותקנת.
//    - appinstalled → הותקנה עכשיו.
// 2. "אכיפת PWA": בלשונית דפדפן בטלפון — פס תחתון "התקנה", עם התקנה בלחיצה כשהדפדפן מאפשר,
//    או ההסבר המדויק (אייפון: שיתוף ← הוספה למסך הבית; אנדרואיד: ⋮ ← התקנת אפליקציה).
//    ?pwa-install=1 בכתובת (הכפתור "התקנה" בפלטפורמה) — הפס מופיע מיד, גם במחשב.
//
// הכול בתוך Shadow DOM — העיצוב של האפליקציה לא נוגע בפס, והפס לא נוגע בה.
(() => {
    const me = document.currentScript;
    if (!me || window.__platformPwa) return;
    window.__platformPwa = true;
    const APP = me.dataset.app, NAME = me.dataset.name || APP, API = me.dataset.api;
    const BANNER = me.dataset.banner !== '0';   // "install": {"banner": false} ב-app.json
    const UA = navigator.userAgent;
    const isIOS = /iPhone|iPad|iPod/.test(UA) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isMobile = isIOS || /Android/.test(UA);
    const params = new URLSearchParams(location.search);
    const forced = params.has('pwa-install');
    const appMode = ['standalone', 'fullscreen', 'minimal-ui'].some(m => matchMedia(`(display-mode: ${m})`).matches)
        || navigator.standalone === true;
    // "מצב אפליקציה" לבד לא מוכיח שהאפליקציה הזו מותקנת — היא יכולה לרוץ בתוך החלון של
    // הפלטפורמה המותקנת. נחשב רק כשנפתחה מהאייקון שלה: ה-start_url שלה נושא pwa-launch=1
    // (platform/pwa.php, ובהגשת ה-manifest שלה בשער), ומאז — לכל אורך אותו חלון (sessionStorage).
    // באייפון אפליקציה לעולם לא נפתחת בתוך חלון של אחרת, ולכן navigator.standalone מספיק.
    const LAUNCH = `platform:launched:${APP}`;
    let launched = params.has('pwa-launch');
    try {
        if (launched) sessionStorage.setItem(LAUNCH, '1');
        else launched = sessionStorage.getItem(LAUNCH) === '1';
    } catch { /* */ }
    const standalone = appMode && (launched || navigator.standalone === true);

    // אותו מזהה מכשיר כמו בדף הראשי (אותו אתר → אותו localStorage).
    function deviceId() {
        try {
            let id = localStorage.getItem('platform:device');
            if (!/^[a-f0-9]{32}$/.test(id || '')) {
                id = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');
                localStorage.setItem('platform:device', id);
            }
            return id;
        } catch { return null; }
    }
    function deviceLabel() {
        const os = /iPhone/.test(UA) ? 'iPhone' : /iPad/.test(UA) || isIOS ? 'iPad' : /Android/.test(UA) ? 'Android'
            : /Windows/.test(UA) ? 'Windows' : /Mac OS X/.test(UA) ? 'Mac' : /Linux/.test(UA) ? 'Linux' : 'מכשיר';
        const br = /Edg\//.test(UA) ? 'Edge' : /SamsungBrowser/.test(UA) ? 'Samsung' : /Firefox|FxiOS/.test(UA) ? 'Firefox'
            : /Chrome|CriOS/.test(UA) ? 'Chrome' : /Safari/.test(UA) ? 'Safari' : '';
        return br ? `${os} · ${br}` : os;
    }
    const DEVICE = deviceId();
    let lastReport = '';
    function report(state, how) {
        if (!DEVICE || !API || lastReport === state + how) return;
        lastReport = state + how;
        fetch(`${API}?action=install_report`, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Platform': '1' },
            body: JSON.stringify({ device: DEVICE, label: deviceLabel(), app: APP, state, how }),
        }).catch(() => {});
    }

    if (standalone) { report('installed', 'opened'); return; }   // נפתחה מהאייקון — אין מה להציע
    if (appMode) return;   // בתוך חלון של אפליקציה אחרת — לא מדווחים ולא מציגים פס

    let promptEvent = null, installed = false, bar = null;
    addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        promptEvent = e;
        report('not_installed', 'prompt');
        maybeShow();
        if (bar) bar.update();
    });
    addEventListener('appinstalled', () => {
        installed = true;
        report('installed', 'installed');
        bar?.done();
    });
    if ('getInstalledRelatedApps' in navigator) {
        navigator.getInstalledRelatedApps().then(list => {
            if (list.some(a => a.platform === 'webapp')) {
                installed = true;
                report('installed', 'detected');
                bar?.remove();
            }
        }).catch(() => {});
    }

    // ---------- הפס ----------
    const KEY = `platform:pwa-bar:${APP}`;
    const dismissed = () => { try { return Number(localStorage.getItem(KEY) || 0) > Date.now(); } catch { return false; } };
    function maybeShow() {
        if (!BANNER || bar || installed || (!forced && (!isMobile || dismissed()))) return;
        bar = makeBar();
    }
    // נותנים לבדיקות רגע — שלא יופיע פס לאפליקציה שכבר מותקנת.
    setTimeout(maybeShow, forced ? 300 : 1500);

    function makeBar() {
        const host = document.createElement('div');
        host.style.cssText = 'position:fixed;inset:auto 0 0 0;z-index:2147483646';
        const root = host.attachShadow({ mode: 'open' });
        root.innerHTML = `<style>
            :host { all: initial; }
            .bar { margin: 8px; padding: 10px 12px; display: flex; align-items: center; gap: 10px; direction: rtl;
                font: 14px/1.4 system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; color: #fff;
                background: linear-gradient(135deg, #4338ca, #7c3aed); border-radius: 14px; box-shadow: 0 8px 24px rgba(0,0,0,.25);
                margin-bottom: calc(8px + env(safe-area-inset-bottom)); }
            .tx { flex: 1; min-width: 0; } .tx b { display: block; font-size: 15px; } .tx small { opacity: .85; }
            button { font: inherit; border: 0; cursor: pointer; border-radius: 10px; }
            .go { background: #fff; color: #4338ca; font-weight: 700; padding: 0 14px; min-height: 40px; white-space: nowrap; }
            .x { background: none; color: #fff; width: 36px; height: 36px; font-size: 18px; opacity: .85; }
            .help { margin: 0 8px 8px; padding: 12px 14px; background: #fff; color: #151827; border-radius: 14px; direction: rtl;
                font: 14px/1.6 system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; box-shadow: 0 8px 24px rgba(0,0,0,.2); }
            .help ol { margin: 0; padding-inline-start: 20px; } .help b { color: #4338ca; }
            @media (prefers-color-scheme: dark) { .help { background: #1a1d27; color: #e8eaf2; } .help b { color: #a5b4fc; } }
        </style>
        <div class="help" hidden></div>
        <div class="bar" role="region" aria-label="התקנה">
            <span aria-hidden="true" style="font-size:22px">📲</span>
            <div class="tx"><b></b><small>אייקון במסך הבית, נפתח במסך מלא</small></div>
            <button class="go" type="button">התקנה</button>
            <button class="x" type="button" aria-label="לא עכשיו">✕</button>
        </div>`;
        root.querySelector('.tx b').textContent = `${NAME} — כאפליקציה`;
        const help = root.querySelector('.help');
        root.querySelector('.go').addEventListener('click', async () => {
            if (promptEvent) {
                promptEvent.prompt();
                const { outcome } = await promptEvent.userChoice;
                promptEvent = null;
                if (outcome === 'accepted') api.done();
                return;
            }
            help.innerHTML = isIOS
                ? '<ol><li>לוחצים על <b>שיתוף</b> (הריבוע עם החץ) בתחתית המסך</li><li>בוחרים <b>"הוספה למסך הבית"</b></li><li>לוחצים <b>"הוספה"</b></li></ol>'
                : /Android/.test(UA)
                    ? '<ol><li>פותחים את <b>תפריט הדפדפן ⋮</b></li><li>בוחרים <b>"התקנת אפליקציה"</b> — לא "הוספה למסך הבית"</li><li>לוחצים <b>"התקנה"</b></li></ol>'
                    : '<ol><li>לוחצים על <b>סמל ההתקנה ⊕</b> בקצה שורת הכתובת</li><li>או בתפריט ⋮ ← <b>"התקנת ' + NAME.replace(/[<>&"]/g, '') + '"</b></li></ol>';
            help.hidden = !help.hidden;
        });
        root.querySelector('.x').addEventListener('click', () => {
            try { localStorage.setItem(KEY, String(Date.now() + 3 * 86400000)); } catch { /* */ }
            api.remove();
        });
        document.body.append(host);
        const api = {
            update() {},
            remove() { host.remove(); bar = null; },
            done() {
                root.querySelector('.bar').innerHTML = '<span style="font-size:20px">✓</span><div class="tx"><b>הותקנה</b><small>האייקון במסך הבית</small></div>';
                help.hidden = true;
                setTimeout(() => api.remove(), 3000);
            },
        };
        return api;
    }
})();
