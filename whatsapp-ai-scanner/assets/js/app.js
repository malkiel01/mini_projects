'use strict';

/*
 * ה-UI של הבעלים בדפדפן הטלפון. שלושה מסכים: שאלה, חשבונות, הגדרות.
 * כל התקשורת עם השרת עוברת דרך api/index.php ב-POST/JSON.
 *
 * הסריקה עצמה אינה נעשית כאן — דפדפן אינו יכול לקרוא אפליקציה אחרת.
 * הגשר (אפליקציית האנדרואיד) מזרים את ההודעות לשרת, וה-UI הזה רק
 * שואל שאלות עליהן. ראו README.
 */

const API = 'api/index.php';

async function api(action, payload) {
    const opts = { method: 'POST', headers: { 'Content-Type': 'application/json' } };
    opts.body = JSON.stringify(payload || {});
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, opts);
    const data = await res.json().catch(() => ({ success: false, error: 'תשובה לא תקינה מהשרת' }));
    if (!data.success) throw new Error(data.error || 'שגיאה');
    return data;
}

const $ = (id) => document.getElementById(id);
const show = (el, on) => { el.hidden = !on; };

/* ── ניווט בין המסכים ─────────────────────────────────────────── */
function openTab(name) {
    ['ask', 'accounts', 'settings'].forEach((t) => show($(t), t === name));
    document.querySelectorAll('.tabs button').forEach((b) =>
        b.classList.toggle('active', b.dataset.tab === name));
    if (name === 'accounts') loadAccounts();
    if (name === 'settings') loadSettings();
}
document.querySelectorAll('.tabs button').forEach((b) =>
    b.addEventListener('click', () => openTab(b.dataset.tab)));

/* ── התקנה / כניסה ────────────────────────────────────────────── */
let configured = false;

async function boot() {
    const state = await api('state');
    configured = state.configured;
    if (state.owner) return enterApp();
    show($('gate'), true);
    $('gateTitle').textContent = configured ? 'כניסה' : 'התקנה ראשונה';
    $('gateHint').textContent = configured
        ? 'הזן את סיסמת הבעלים.'
        : 'קבע סיסמה שתגן על ההתכתבויות. זו הכניסה היחידה למערכת.';
    $('pw').setAttribute('autocomplete', configured ? 'current-password' : 'new-password');
}

$('gateBtn').addEventListener('click', async () => {
    const pw = $('pw').value;
    const msg = $('gateMsg');
    msg.textContent = '';
    msg.className = 'msg';
    try {
        if (configured) {
            await api('login', { password: pw });
        } else {
            if (pw.length < 8) throw new Error('הסיסמה חייבת להיות באורך 8 תווים לפחות');
            await api('setup', { password: pw });
        }
        $('pw').value = '';
        enterApp();
    } catch (e) {
        msg.textContent = e.message;
        msg.className = 'msg err';
    }
});
$('pw').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('gateBtn').click(); });

function enterApp() {
    show($('gate'), false);
    show($('tabs'), true);
    show($('logoutBtn'), true);
    openTab('ask');
    loadAccounts();   // כדי למלא את בורר החשבונות בשאלה
}

$('logoutBtn').addEventListener('click', async () => {
    await api('logout').catch(() => {});
    location.reload();
});

/* ── שאלה ותשובה ──────────────────────────────────────────────── */
$('askBtn').addEventListener('click', async () => {
    const question = $('q').value.trim();
    const box = $('answer');
    if (!question) return;
    const btn = $('askBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>חושב…';
    show(box, true);
    box.textContent = '';
    try {
        const r = await api('ask', {
            question,
            account_id: $('qAccount').value,
            from: $('qFrom').value,
            to: $('qTo').value,
        });
        box.textContent = r.answer;
        const meta = document.createElement('span');
        meta.className = 'meta';
        meta.textContent = `נסרקו ${r.used} הודעות${r.truncated ? ' (חלון עדכני בלבד — צמצם תאריכים לדיוק רב יותר)' : ''}.`;
        box.appendChild(meta);
    } catch (e) {
        box.textContent = 'שגיאה: ' + e.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'שאל';
    }
});

/* ── חשבונות ──────────────────────────────────────────────────── */
async function loadAccounts() {
    let accounts = [];
    try { accounts = (await api('accounts')).accounts; } catch (e) { return; }

    const list = $('accountList');
    list.innerHTML = '';
    accounts.forEach((a) => {
        const li = document.createElement('li');
        const kindHe = a.kind === 'business' ? 'עסקי' : 'פרטי';
        li.innerHTML =
            `<span class="tag ${a.kind}">${kindHe}</span>` +
            `<span>${escapeHtml(a.label)}</span>` +
            `<span class="count">${a.message_count} הודעות</span>` +
            `<button class="del" title="מחק">🗑</button>`;
        li.querySelector('.del').addEventListener('click', async () => {
            if (!confirm(`למחוק את "${a.label}" ואת כל ההודעות שנקלטו תחתיו?`)) return;
            await api('delete_account', { id: a.id });
            loadAccounts();
        });
        list.appendChild(li);
    });

    // בורר החשבונות במסך השאלה
    const sel = $('qAccount');
    const cur = sel.value;
    sel.innerHTML = '<option value="">כל החשבונות</option>' +
        accounts.map((a) => `<option value="${a.id}">${escapeHtml(a.label)}</option>`).join('');
    sel.value = cur;
}

$('addAccBtn').addEventListener('click', async () => {
    const label = $('accLabel').value.trim();
    if (!label) return;
    try {
        await api('add_account', { label, kind: $('accKind').value });
        $('accLabel').value = '';
        loadAccounts();
    } catch (e) {
        alert(e.message);
    }
});

/* ── הגדרות ───────────────────────────────────────────────────── */
async function loadSettings() {
    let s;
    try { s = await api('settings'); } catch (e) { return; }
    const ai = s.ai;

    const prov = $('aiProvider');
    prov.innerHTML = ai.providers.map((p) =>
        `<option value="${p.id}"${p.id === ai.provider ? ' selected' : ''}>${p.label}</option>`).join('');
    $('aiModel').value = ai.model || '';
    $('aiModel').placeholder = providerDefault(ai, ai.provider) || 'ברירת מחדל של הספק';
    $('keyTail').textContent = ai.has_key ? `מפתח נשמר (…${ai.key_tail})` : 'לא הוגדר מפתח';

    prov.onchange = () => { $('aiModel').placeholder = providerDefault(ai, prov.value) || 'ברירת מחדל'; };

    $('pairToken').textContent = s.pair_token;
}
function providerDefault(ai, id) {
    const p = ai.providers.find((x) => x.id === id);
    return p ? p.default : '';
}

$('saveAiBtn').addEventListener('click', async () => {
    const msg = $('settingsMsg');
    msg.textContent = '';
    msg.className = 'msg';
    try {
        await api('save_ai', {
            provider: $('aiProvider').value,
            model: $('aiModel').value.trim(),
            key: $('aiKey').value,   // ריק = לא לשנות
        });
        $('aiKey').value = '';
        msg.textContent = 'נשמר.';
        msg.className = 'msg okmsg';
        loadSettings();
    } catch (e) {
        msg.textContent = e.message;
        msg.className = 'msg err';
    }
});

$('copyTokenBtn').addEventListener('click', () => {
    navigator.clipboard?.writeText($('pairToken').textContent).then(() => {
        $('copyTokenBtn').textContent = 'הועתק';
        setTimeout(() => ($('copyTokenBtn').textContent = 'העתק'), 1500);
    });
});

$('rotateTokenBtn').addEventListener('click', async () => {
    if (!confirm('אסימון חדש ינתק כל מכשיר שמחובר כעת. להמשיך?')) return;
    const r = await api('rotate_token');
    $('pairToken').textContent = r.pair_token;
});

/* ── עזר ──────────────────────────────────────────────────────── */
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

boot().catch((e) => {
    $('gate').hidden = false;
    $('gateMsg').textContent = 'שגיאת חיבור לשרת: ' + e.message;
    $('gateMsg').className = 'msg err';
});
