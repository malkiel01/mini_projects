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
    const data = await res.json().catch(() => ({ success: false, error: t('resp_invalid') }));
    if (!data.success) throw new Error(data.error || t('error'));
    return data;
}

const $ = (id) => document.getElementById(id);

// החלת שפת הממשק על הטקסט הסטטי מיד עם הטעינה (i18n.js נטען קודם).
applyI18n();
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
    $('gateTitle').textContent = configured ? t('login') : t('first_setup');
    $('gateHint').textContent = configured ? t('login_hint') : t('setup_hint');
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
            if (pw.length < 8) throw new Error(t('pw_min'));
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
async function askQuestion(speakAnswer) {
    const question = $('q').value.trim();
    const box = $('answer');
    if (!question) return;
    const btn = $('askBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>' + t('thinking');
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
        meta.textContent = t('scanned_n', { n: r.used, note: r.truncated ? t('truncated_note') : '' });
        box.appendChild(meta);
        // הקראת התשובה בקול — רק כששאלנו בקול, כדי לא להפתיע בשאלת טקסט.
        if (speakAnswer) speak(r.answer, aiState?.answer_lang || uiLang());
    } catch (e) {
        box.textContent = t('err_prefix') + e.message;
    } finally {
        btn.disabled = false;
        btn.textContent = t('ask_btn');
    }
}
$('askBtn').addEventListener('click', () => askQuestion(false));

/* ── קול: שאלה בדיבור ותשובה מוקראת ──────────────────────────────
 * מסלול א': לחיצה על המיקרופון → זיהוי דיבור (Web Speech API) ממלא את
 * תיבת השאלה → שליחה אוטומטית → הקראת התשובה. אין "היי רובוט" ברקע —
 * הדפדפן אינו יכול להאזין ברקע; זה דורש נגיעה אחת לפני הדיבור. */
const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
const SPEECH_LOCALES = { he: 'he-IL', en: 'en-US', ar: 'ar-SA', ru: 'ru-RU', fr: 'fr-FR', es: 'es-ES' };

/** קוד שפה פנימי → קוד BCP-47 לזיהוי/הקראה. 'auto' או לא-מוכר → שפת הממשק. */
function speechLocale(code) {
    return SPEECH_LOCALES[code] || SPEECH_LOCALES[uiLang()] || 'he-IL';
}

/** מקריא טקסט בקול בשפה הנתונה, אם הדפדפן תומך. */
function speak(text, langCode) {
    try {
        if (!('speechSynthesis' in window)) return;
        speechSynthesis.cancel();
        const u = new SpeechSynthesisUtterance(text);
        u.lang = speechLocale(langCode);
        speechSynthesis.speak(u);
    } catch (e) { /* הקראה היא נוחות, לא נשברים אם נכשלה */ }
}

let recog = null;
let recognizing = false;

function micState(on) {
    const b = $('micBtn');
    b.textContent = on ? '⏺' : '🎤';
    b.setAttribute('title', on ? t('listening') : t('mic_title'));
}

function setupVoice() {
    const b = $('micBtn');
    if (!SpeechRec) return;   // אין תמיכה → הכפתור נשאר מוסתר
    show(b, true);
    b.addEventListener('click', () => {
        if (recognizing) { try { recog && recog.stop(); } catch (e) {} return; }
        recog = new SpeechRec();
        recog.lang = speechLocale(uiLang());
        recog.interimResults = false;
        recog.maxAlternatives = 1;
        recognizing = true;
        micState(true);
        recog.onresult = (e) => { $('q').value = e.results[0][0].transcript; };
        recog.onerror = () => {};
        recog.onend = () => {
            recognizing = false;
            micState(false);
            if ($('q').value.trim()) askQuestion(true);   // שליחה + הקראה
        };
        try { recog.start(); } catch (e) { recognizing = false; micState(false); }
    });
}

/* ── חשבונות ──────────────────────────────────────────────────── */
async function loadAccounts() {
    let accounts = [];
    try { accounts = (await api('accounts')).accounts; } catch (e) { return; }

    const list = $('accountList');
    list.innerHTML = '';
    accounts.forEach((a) => {
        const li = document.createElement('li');
        const appLabel = a.wa_package === 'com.whatsapp.w4b' ? t('biz')
            : a.wa_package === 'com.whatsapp' ? t('regular')
            : (a.wa_package ? a.wa_package : t('no_app'));
        li.innerHTML =
            `<span class="tag ${a.kind}">${escapeHtml(appLabel)}</span>` +
            `<span>${escapeHtml(a.label)} <span class="accid" title="${escapeHtml(t('accid_title'))}">#${a.id}</span></span>` +
            `<span class="count">${t('n_messages', { n: a.message_count })}</span>` +
            `<button class="del" title="${escapeHtml(t('del_title'))}">🗑</button>`;
        li.querySelector('.del').addEventListener('click', async () => {
            if (!confirm(t('del_confirm', { label: a.label }))) return;
            await api('delete_account', { id: a.id });
            loadAccounts();
        });
        list.appendChild(li);
    });

    // בורר החשבונות במסך השאלה
    const sel = $('qAccount');
    const cur = sel.value;
    sel.innerHTML = `<option value="">${escapeHtml(t('all_accounts'))}</option>` +
        accounts.map((a) => `<option value="${a.id}">${escapeHtml(a.label)}</option>`).join('');
    sel.value = cur;
}

$('accApp').addEventListener('change', () => {
    show($('accCustomPkg'), $('accApp').value === '__custom__');
});

$('addAccBtn').addEventListener('click', async () => {
    const label = $('accLabel').value.trim();
    if (!label) return;
    let pkg = $('accApp').value;
    if (pkg === '__custom__') pkg = $('accCustomPkg').value.trim();
    if (!pkg) { alert(t('pick_app')); return; }
    const kind = pkg === 'com.whatsapp.w4b' ? 'business' : 'personal';
    try {
        await api('add_account', { label, kind, package: pkg });
        $('accLabel').value = '';
        $('accCustomPkg').value = '';
        loadAccounts();
    } catch (e) {
        alert(e.message);
    }
});

/* ── הגדרות ───────────────────────────────────────────────────── */
let aiState = null;      // ה-ai האחרון מהשרת
let liveModels = {};     // מודלים שנטענו חי, לפי ספק

const CUSTOM = '__custom__';

async function loadSettings() {
    let s;
    try { s = await api('settings'); } catch (e) { return; }
    aiState = s.ai;

    const prov = $('aiProvider');
    prov.innerHTML = aiState.providers.map((p) =>
        `<option value="${p.id}"${p.id === aiState.provider ? ' selected' : ''}>${p.label}</option>`).join('');

    populateModels(aiState.provider, aiState.model || '');
    $('keyTail').textContent = aiState.has_key
        ? t('key_saved', { tail: aiState.key_tail }) : t('key_none');

    const lang = $('answerLang');
    lang.innerHTML = (aiState.answer_langs || []).map((l) =>
        `<option value="${l.code}"${l.code === aiState.answer_lang ? ' selected' : ''}>${escapeHtml(l.label)}</option>`).join('');

    // בורר שפת הממשק — נשמר מקומית (i18n.js), החלפה חיה בלי סבב שרת.
    const ui = $('uiLang');
    ui.innerHTML = I18N_LANGS.map((l) =>
        `<option value="${l.code}"${l.code === uiLang() ? ' selected' : ''}>${escapeHtml(l.label)}</option>`).join('');
    ui.onchange = () => { setUiLang(ui.value); applyI18n(); loadSettings(); loadAccounts(); };

    prov.onchange = () => populateModels(prov.value, '');
    $('pairToken').textContent = s.pair_token;
    $('logToken').textContent = s.log_token || '—';
}

/* יומן אבחון */
$('copyLogTokenBtn').addEventListener('click', () => {
    navigator.clipboard?.writeText($('logToken').textContent).then(() => {
        $('copyLogTokenBtn').textContent = t('copied');
        setTimeout(() => ($('copyLogTokenBtn').textContent = t('copy')), 1500);
    });
});

$('rotateLogTokenBtn').addEventListener('click', async () => {
    if (!confirm(t('rotate_log_confirm'))) return;
    const r = await api('rotate_log_token');
    $('logToken').textContent = r.log_token;
});

$('viewLogsBtn').addEventListener('click', async () => {
    const box = $('logsView');
    show(box, true);
    box.textContent = t('loading');
    try {
        const r = await api('logs');
        box.textContent = (r.logs || []).map((l) => {
            const ts = new Date(l.ts * 1000).toLocaleString();
            return `${ts} [${l.source}/${l.action}] ${l.status} ${l.detail}`;
        }).join('\n') || t('log_empty');
    } catch (e) {
        box.textContent = t('err_prefix') + e.message;
    }
});

function providerMeta(id) {
    return (aiState?.providers || []).find((x) => x.id === id) || { models: [], default: '' };
}

/** בונה את רשימת המודלים הנפתחת: מובנים + נטענים-חי + הנבחר + "אחר". */
function populateModels(provider, selected) {
    const meta = providerMeta(provider);
    const merged = [];
    const seen = new Set();
    const add = (id, label) => { if (id && !seen.has(id)) { seen.add(id); merged.push({ id, label: label || id }); } };

    (liveModels[provider] || []).forEach((m) => add(m.id, m.label));
    (meta.models || []).forEach((m) => add(m.id, m.label));
    if (selected) add(selected, selected);           // מודל ששמור אך אינו ברשימה
    if (meta.default) add(meta.default, meta.default);

    const sel = $('aiModel');
    sel.innerHTML =
        merged.map((m) => `<option value="${escapeHtml(m.id)}">${escapeHtml(m.label)}</option>`).join('') +
        `<option value="${CUSTOM}">${escapeHtml(t('model_other'))}</option>`;

    sel.value = selected && seen.has(selected) ? selected : (merged[0]?.id || CUSTOM);
    onModelSelectChange();
    sel.onchange = onModelSelectChange;
}

function onModelSelectChange() {
    const custom = $('aiModel').value === CUSTOM;
    show($('aiModelCustom'), custom);
    if (custom) $('aiModelCustom').focus();
}

function chosenModel() {
    return $('aiModel').value === CUSTOM
        ? $('aiModelCustom').value.trim()
        : $('aiModel').value;
}

$('loadModelsBtn').addEventListener('click', async () => {
    const msg = $('settingsMsg');
    const btn = $('loadModelsBtn');
    msg.textContent = '';
    msg.className = 'msg';
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = t('loading');
    try {
        const r = await api('list_models');
        const provider = $('aiProvider').value;
        liveModels[provider] = r.models;
        populateModels(provider, chosenModel());
        msg.textContent = t('models_loaded', { n: r.models.length });
        msg.className = 'msg okmsg';
    } catch (e) {
        // הכשל הנפוץ: אין מפתח שמור. אומרים זאת במפורש.
        msg.textContent = e.message + t('load_models_needkey');
        msg.className = 'msg err';
    } finally {
        btn.disabled = false;
        btn.textContent = orig;
    }
});

$('saveAiBtn').addEventListener('click', async () => {
    const msg = $('settingsMsg');
    msg.textContent = '';
    msg.className = 'msg';
    try {
        await api('save_ai', {
            provider: $('aiProvider').value,
            model: chosenModel(),
            key: $('aiKey').value,   // ריק = לא לשנות
            answer_lang: $('answerLang').value,
        });
        $('aiKey').value = '';
        msg.textContent = t('saved');
        msg.className = 'msg okmsg';
        loadSettings();
    } catch (e) {
        msg.textContent = e.message;
        msg.className = 'msg err';
    }
});

$('copyTokenBtn').addEventListener('click', () => {
    navigator.clipboard?.writeText($('pairToken').textContent).then(() => {
        $('copyTokenBtn').textContent = t('copied');
        setTimeout(() => ($('copyTokenBtn').textContent = t('copy')), 1500);
    });
});

$('rotateTokenBtn').addEventListener('click', async () => {
    if (!confirm(t('rotate_token_confirm'))) return;
    const r = await api('rotate_token');
    $('pairToken').textContent = r.pair_token;
});

/* ── עזר ──────────────────────────────────────────────────────── */
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

setupVoice();   // אחרי הגדרת ה-const של זיהוי הדיבור (מניעת TDZ)

boot().catch((e) => {
    $('gate').hidden = false;
    $('gateMsg').textContent = t('conn_err') + e.message;
    $('gateMsg').className = 'msg err';
});
