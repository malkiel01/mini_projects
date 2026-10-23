// העורך החדש: מסך הבית (העיצובים שלי) ומסך העריכה. ‏spec/03-editor.md.

import { h, $, toast, sheet, closeSheet, isSheetOpen, confirmDialog, field, rangeField, seg, colorField, toggle, pickFiles, download, timeAgo } from './ui.js';
import { SIZES, newDoc, textLayer, shapeLayer, imageLayer, backgroundLayer, layerLabel, uid, clone } from './doc.js';
import { FONTS, ensureFont, ensureDocFonts, nearestWeight } from './fonts.js';
import { History } from './history.js';
import { designs, assets } from './db.js';
import { Canvas } from './canvas.js';
import { ingestImage, importFromChat, blobToImage, canvasToBlob, exportPdf, exportGcard, safeName, personalSkill, skillAsPrompt, skillZip } from './io.js';

const app = $('#app');
let ed = null;   // העיצוב הפתוח: { id, doc, history, canvas, dirty }

// ═══ מסך הבית ═════════════════════════════════════════════════════════════

async function showHome() {
    ed = null;
    document.title = 'סטודיו עיצוב';
    const list = await designs.list().catch(() => []);
    const tiles = h('div', { class: 'tiles' },
        tile('➕', 'עיצוב חדש', newDesignSheet),
        tile('📥', 'ייבוא מהצ\'אט', importSheet),
        tile('🤖', 'סקיל לצ\'אט', skillSheet),
        tile('📂', 'פתיחת קובץ', async () => { const f = await pickFiles('.gcard,.zip,.json'); if (f.length) runImport(f, ''); }));

    const rows = list.length
        ? h('ul', { class: 'list', 'aria-label': 'העיצובים שלי' }, list.map(d => h('li', { class: 'row' },
            h('button', { class: 'row-main', onclick: () => openDesign(d.id) },
                h('span', { class: 'thumb', style: { aspectRatio: `${d.w} / ${d.h}` } },
                    d.thumb ? h('img', { src: URL.createObjectURL(d.thumb), alt: '' }) : null),
                h('span', { class: 'row-text' },
                    h('span', { class: 'row-title' }, d.title),
                    h('span', { class: 'row-sub' }, `\u2066${d.w}×${d.h}\u2069 · ${timeAgo(d.updatedAt)}`)),
                h('span', { class: 'chev', 'aria-hidden': 'true' }, '‹')),
            h('button', { class: 'icon-btn', 'aria-label': `פעולות על ${d.title}`, onclick: () => designMenu(d) }, '⋯'))))
        : h('p', { class: 'empty' }, 'עוד אין עיצובים. התחילו מעיצוב חדש, או ייבאו רקע מהצ\'אט.');

    app.replaceChildren(
        h('header', { class: 'topbar' }, h('h1', {}, '🎨 סטודיו עיצוב')),
        h('main', { class: 'home' },
            h('p', { class: 'hint' }, 'מבקשים מהצ\'אט רקע עם הסקיל, מייבאים אותו לכאן, ומוסיפים טקסטים, תמונות וצורות.'),
            tiles,
            h('h2', { class: 'section' }, 'העיצובים שלי'),
            rows,
            // מיזוג נמענים ושליחה בוואטסאפ עוד לא עברו לעורך (spec/09-roadmap.md, שלב 4).
            h('a', { class: 'legacy-link', href: '../index.html' }, h('span', {}, '🗂️ הגרסה הקודמת: נמענים ווואטסאפ'), h('span', { class: 'chev', 'aria-hidden': 'true' }, '‹'))));
}

function tile(icon, label, onclick) {
    return h('button', { class: 'tile', onclick }, h('span', { class: 'tile-icon', 'aria-hidden': 'true' }, icon), h('span', {}, label));
}

function newDesignSheet() {
    const wIn = h('input', { type: 'number', min: 16, max: 10000, value: 1080, inputMode: 'numeric' });
    const hIn = h('input', { type: 'number', min: 16, max: 10000, value: 1350, inputMode: 'numeric' });
    sheet('עיצוב חדש', h('div', {},
        h('div', { class: 'tiles sizes' }, SIZES.map(s => h('button', { class: 'tile size', onclick: () => createDesign(s.w, s.h, s.label) },
            h('span', { class: 'size-box', style: { aspectRatio: `${s.w} / ${s.h}` }, 'aria-hidden': 'true' }),
            h('span', {}, s.label), h('span', { class: 'muted small', dir: 'ltr' }, `${s.w}×${s.h}`)))),
        h('h3', { class: 'section' }, 'גודל אחר'),
        h('div', { class: 'two' }, field('רוחב (px)', wIn), field('גובה (px)', hIn)),
        h('button', { class: 'btn primary block', onclick: () => createDesign(+wIn.value, +hIn.value, 'מותאם') }, 'יצירה')));
}

async function createDesign(w, hgt, label) {
    w = Math.min(10000, Math.max(16, Math.round(w) || 1080));
    hgt = Math.min(10000, Math.max(16, Math.round(hgt) || 1350));
    closeSheet();
    const doc = newDoc(w, hgt, `${label} חדש`);
    const id = uid('d');
    await designs.put({ id, title: doc.title, doc, updatedAt: Date.now() });
    openDesign(id);
}

function designMenu(d) {
    sheet(d.title, h('div', { class: 'menu' },
        h('button', { class: 'menu-item', onclick: () => openDesign(d.id) }, '✏️ פתיחה'),
        h('button', { class: 'menu-item', onclick: async () => {
            const rec = await designs.get(d.id);
            const id = uid('d');
            await designs.put({ ...rec, id, title: rec.title + ' (עותק)', updatedAt: Date.now() });
            closeSheet(); showHome(); toast('שוכפל');
        } }, '⧉ שכפול'),
        h('button', { class: 'menu-item', onclick: async () => {
            const rec = await designs.get(d.id);
            await download(await exportGcard(rec.doc), safeName(rec.title) + '.gcard').catch(e => toast(e.message, 'error'));
        } }, '⬇️ הורדה כקובץ עיצוב'),
        h('button', { class: 'menu-item danger', onclick: async () => {
            if (!(await confirmDialog(`למחוק את "${d.title}"? אי אפשר לבטל.`, 'מחיקה', true))) return;
            await designs.remove(d.id);
            showHome(); toast('נמחק');
        } }, '🗑️ מחיקה')));
}

// ═══ ייבוא מהצ'אט וסקיל ═══════════════════════════════════════════════════

function importSheet() {
    const files = [];
    const list = h('ul', { class: 'file-list', 'aria-live': 'polite' });
    const text = h('textarea', { rows: 5, dir: 'ltr', placeholder: '{ "version": 1, "page": … }', class: 'mono' });
    const drop = h('button', { class: 'drop', onclick: async () => add(await pickFiles('image/*,.json,.gcard,.zip', true)) },
        h('span', { class: 'tile-icon', 'aria-hidden': 'true' }, '📎'), h('span', {}, 'בחירת תמונות או קובץ מהצ\'אט'),
        h('span', { class: 'muted small' }, 'design.gcard · תמונות · design.json'));
    const add = fs => { for (const f of fs) { files.push(f); list.append(h('li', {}, `${f.type.startsWith('image/') ? '🖼️' : '📄'} ${f.name}`)); } };
    drop.addEventListener('dragover', e => e.preventDefault());
    drop.addEventListener('drop', e => { e.preventDefault(); add([...e.dataTransfer.files]); });

    sheet('ייבוא מהצ\'אט', h('div', {},
        h('p', { class: 'hint' }, 'הצ\'אט נותן תמונת רקע (בלי טקסט) ובלוק JSON. בחרו את התמונות והדביקו את ה-JSON — הטקסטים יהפכו לשכבות שאפשר לערוך. תמונה לבד — נכנסת כרקע.'),
        drop, list,
        field('ה-JSON מהצ\'אט (לא חובה)', text),
        h('div', { class: 'row-actions' },
            navigator.clipboard?.readText ? h('button', { class: 'btn ghost', onclick: async () => {
                try { text.value = await navigator.clipboard.readText(); } catch { toast('אין גישה ללוח — הדביקו ידנית'); }
            } }, '📋 הדבקה') : null,
            h('button', { class: 'btn primary', onclick: () => runImport(files, text.value) }, 'ייבוא'))), { wide: true });
}

async function runImport(files, pasted) {
    try {
        const { doc, loaded, fixes } = await importFromChat(files, pasted);
        const id = uid('d');
        await designs.put({ id, title: doc.title, doc, updatedAt: Date.now() });
        closeSheet();
        await openDesign(id, loaded);
        if (fixes.length) {
            sheet(`${fixes.length} תיקונים בייבוא`, h('div', {}, h('ul', { class: 'fixes' }, fixes.map(f => h('li', {}, f))),
                h('button', { class: 'btn primary block', onclick: closeSheet }, 'הבנתי')));
        } else toast('יובא ✓');
    } catch (e) {
        toast(e.message || 'הייבוא נכשל', 'error');
    }
}

async function skillSheet() {
    let skill;
    try { skill = await personalSkill(); } catch (e) { toast(e.message, 'error'); return; }
    const prompt = skillAsPrompt(skill);
    const copy = async (t, what) => {
        try { await navigator.clipboard.writeText(t); toast(`${what} הועתק ✓`); }
        catch { toast('ההעתקה נחסמה — סמנו את הטקסט והעתיקו ידנית', 'error'); }
    };
    const pane = (name, steps, buttons) => h('section', { class: 'card' }, h('h3', {}, name), h('ol', { class: 'steps' }, steps.map(s => h('li', {}, s))), h('div', { class: 'row-actions' }, buttons));
    sheet('סקיל לצ\'אט', h('div', {},
        h('p', { class: 'hint' }, 'הסקיל מלמד את הצ\'אט ליצור רקע בלי טקסט, ולתת קובץ פריסה שהעורך הופך לשכבות. בלי טוקנים — על החשבון שלכם בצ\'אט.'),
        pane('ChatGPT', ['להעתיק את ההוראות', 'להדביק בתחילת שיחה חדשה (או ב-Project / GPT משלכם)', 'לבקש: "רקע למודעה של…"', 'להוריד את התמונה ולהעתיק את ה-JSON → "ייבוא מהצ\'אט"'],
            [h('button', { class: 'btn primary', onclick: () => copy(prompt, 'הסקיל') }, '📋 העתקה'), h('a', { class: 'btn ghost', href: 'https://chatgpt.com/', target: '_blank', rel: 'noopener' }, '↗ ChatGPT')]),
        pane('Gemini', ['להעתיק את ההוראות', 'ליצור Gem חדש ולהדביק, או להדביק בתחילת שיחה', 'לבקש רקע, ולייבא כמו למעלה'],
            [h('button', { class: 'btn primary', onclick: () => copy(prompt, 'הסקיל') }, '📋 העתקה'), h('a', { class: 'btn ghost', href: 'https://gemini.google.com/', target: '_blank', rel: 'noopener' }, '↗ Gemini')]),
        pane('Claude', ['להוריד את קובץ הסקיל', 'Settings ← Capabilities ← Skills ← העלאה', 'Claude לא יוצר תמונות — הוא נותן פריסה, ואת הרקע מעלים בנפרד'],
            [h('button', { class: 'btn primary', onclick: async () => download(await skillZip(skill), 'design-background-for-editor.zip').catch(e => toast(e.message, 'error')) }, '⬇️ הורדה'), h('a', { class: 'btn ghost', href: 'https://claude.ai/', target: '_blank', rel: 'noopener' }, '↗ Claude')]),
        h('details', {}, h('summary', {}, 'הטקסט המלא'), h('pre', { class: 'mono pre', dir: 'ltr', tabindex: 0 }, prompt))), { wide: true });
}

// ═══ מסך העריכה ═══════════════════════════════════════════════════════════

async function openDesign(id, preloaded) {
    const rec = await designs.get(id);
    if (!rec) { toast('העיצוב לא נמצא', 'error'); return showHome(); }
    closeSheet();

    const stageEl = h('div', { class: 'stage', tabindex: 0, role: 'application', 'aria-roledescription': 'קנבס', 'aria-label': 'קנבס העיצוב. חיצים מזיזים את השכבה הנבחרת; Tab עובר בין השכבות' });
    const zoomLabel = h('button', { class: 'zoom-val', 'aria-label': 'התאמה למסך', onclick: () => ed.canvas.fit() }, '100%');
    const saveState = h('span', { class: 'save-state', role: 'status', 'aria-live': 'polite' }, '');
    const undoBtn = h('button', { class: 'icon-btn', 'aria-label': 'ביטול', onclick: undo }, '↶');
    const redoBtn = h('button', { class: 'icon-btn', 'aria-label': 'חזרה', onclick: redo }, '↷');
    const titleBtn = h('button', { class: 'title-btn', onclick: renameSheet }, rec.title);
    const ctx = h('div', { class: 'ctxbar', role: 'toolbar', 'aria-label': 'פעולות על השכבה הנבחרת' });

    app.replaceChildren(
        h('header', { class: 'topbar editor-top' },
            h('button', { class: 'icon-btn', 'aria-label': 'חזרה לעיצובים', onclick: async () => { await saveNow(); showHome(); } }, '›'),
            titleBtn, saveState, undoBtn, redoBtn,
            h('button', { class: 'btn primary sm', onclick: exportSheet }, '⬇️ ייצוא')),
        h('main', { class: 'editor' },
            stageEl,
            h('div', { class: 'zoombar', role: 'group', 'aria-label': 'זום' },
                h('button', { class: 'icon-btn', 'aria-label': 'הקטנה', onclick: () => ed.canvas.zoomBy(1 / 1.25) }, '−'),
                zoomLabel,
                h('button', { class: 'icon-btn', 'aria-label': 'הגדלה', onclick: () => ed.canvas.zoomBy(1.25) }, '+')),
            ctx),
        h('nav', { class: 'bottomnav', 'aria-label': 'כלים' },
            navBtn('➕', 'הוספה', addSheet),
            navBtn('🧱', 'שכבות', layersSheet),
            navBtn('🖼️', 'רקע', backgroundSheet),
            navBtn('📐', 'גודל', pageSizeSheet),
            navBtn('🤖', 'צ\'אט', skillSheet)));

    const history = new History(() => { undoBtn.disabled = !history.canUndo; redoBtn.disabled = !history.canRedo; });
    ed = { id, doc: rec.doc, history, ctx, saveState, titleBtn, zoomLabel, stageEl, selection: [] };
    document.title = rec.title;

    ed.canvas = new Canvas(stageEl, {
        onSelect: ids => { ed.selection = ids; renderCtx(); },
        onCommit: () => commit(),
        onEditText: l => editTextInPlace(l),
        onZoom: z => { zoomLabel.textContent = Math.round(z * 100) + '%'; },
    });

    // תמונות: מה שכבר נטען בייבוא, והשאר מ-IndexedDB.
    for (const [aid, a] of Object.entries(ed.doc.assets)) {
        let img = preloaded?.get(aid);
        if (!img) { const blob = await assets.get(a.src); if (blob) img = await blobToImage(blob).catch(() => null); }
        if (img) ed.canvas.setImage(aid, img);
    }
    await ensureDocFonts(ed.doc);
    ed.canvas.setDoc(ed.doc, { fit: true });
    history.reset(ed.doc);
    renderCtx();
    stageEl.addEventListener('keydown', onStageKey);
    // הגדלים של טקסט נגזרים בציור הראשון — שומרים אותם כמצב ההתחלה.
    scheduleSave(true);
}

function navBtn(icon, label, onclick) {
    return h('button', { class: 'nav-btn', onclick }, h('span', { 'aria-hidden': 'true', class: 'nav-icon' }, icon), h('span', {}, label));
}

/** סוף פעולה: רושם בהיסטוריה, מצייר ושומר. */
function commit() {
    ed.canvas.render();
    if (ed.history.commit(ed.doc)) scheduleSave();
    renderCtx();
}

function undo() {
    const d = ed?.history.undo();
    if (d) { ed.doc = d; ed.canvas.setDoc(d); ed.canvas.select(ed.selection); scheduleSave(); }
}

function redo() {
    const d = ed?.history.redo();
    if (d) { ed.doc = d; ed.canvas.setDoc(d); ed.canvas.select(ed.selection); scheduleSave(); }
}

// ─── שמירה אוטומטית ───────────────────────────────────────────────────────

let saveTimer = null;
function scheduleSave(quiet) {
    if (!ed) return;
    ed.dirty = true;
    if (!quiet) ed.saveState.textContent = 'שומר…';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNow, 800);
}

async function saveNow() {
    clearTimeout(saveTimer);
    if (!ed?.dirty) return;
    const cur = ed;
    cur.dirty = false;
    try {
        const big = Math.max(cur.doc.page.width, cur.doc.page.height);
        const thumb = await canvasToBlob(cur.canvas.pageCanvas(360 / big), 'image/jpeg', 0.8).catch(() => undefined);
        await designs.put({ id: cur.id, title: cur.doc.title, doc: clone(cur.doc), updatedAt: Date.now(), thumb });
        if (ed === cur) cur.saveState.textContent = 'נשמר ✓';
    } catch (e) {
        cur.dirty = true;
        cur.saveState.textContent = 'לא נשמר';
        toast('השמירה במכשיר נכשלה: ' + (e.message || e), 'error');
    }
}

window.addEventListener('pagehide', () => { saveNow(); });
document.addEventListener('visibilitychange', () => { if (document.hidden) saveNow(); });

// ─── שכבה נבחרת ───────────────────────────────────────────────────────────

const sel = () => ed.selection.map(id => ed.doc.layers.find(l => l.id === id)).filter(Boolean);

function renderCtx() {
    const ls = sel();
    const ctx = ed.ctx;
    if (!ls.length) { ctx.replaceChildren(); ctx.hidden = true; return; }
    ctx.hidden = false;
    const l = ls[0];
    const one = ls.length === 1;
    const b = (icon, label, fn, extra = {}) => h('button', { class: 'ctx-btn', onclick: fn, ...extra }, h('span', { 'aria-hidden': 'true' }, icon), h('span', { class: 'ctx-label' }, label));
    ctx.replaceChildren(...[
        one && l.type === 'text' ? b('✏️', 'טקסט', () => editTextInPlace(l)) : null,
        one ? b('🎨', 'עיצוב', () => propsSheet(l)) : null,
        one ? b('📐', 'מיקום', () => positionSheet(l)) : null,
        b('⧉', 'שכפול', duplicate),
        b('⇅', 'סדר', orderSheet),
        b(ls.every(x => x.locked) ? '🔒' : '🔓', ls.every(x => x.locked) ? 'נעול' : 'נעילה', toggleLock, { 'aria-pressed': String(ls.every(x => x.locked)) }),
        b('🗑️', 'מחיקה', removeSelected)].filter(Boolean));
}

function addLayer(l, { select = true } = {}) {
    ed.doc.layers.push(l);
    commit();
    if (select) ed.canvas.select([l.id]);
}

function duplicate() {
    const ids = [];
    for (const l of sel()) {
        const c = clone(l);
        c.id = uid();
        c.x += 30; c.y += 30;
        delete c.locked;
        if (c.role === 'background') c.role = 'photo';
        ed.doc.layers.push(c);
        ids.push(c.id);
    }
    commit();
    ed.canvas.select(ids);
}

function removeSelected() {
    const ids = new Set(ed.selection);
    if (!ids.size) return;
    ed.doc.layers = ed.doc.layers.filter(l => !ids.has(l.id));
    ed.canvas.select([]);
    commit();
    toast('נמחק — ↶ מחזיר');
}

function toggleLock() {
    const ls = sel();
    const lock = !ls.every(l => l.locked);
    ls.forEach(l => { if (lock) l.locked = true; else delete l.locked; });
    commit();
}

function move(dir) {
    // dir: +1 קדימה, -1 אחורה, +Infinity לראש, -Infinity לתחתית
    const ids = new Set(ed.selection);
    const L = ed.doc.layers;
    const picked = L.filter(l => ids.has(l.id));
    const rest = L.filter(l => !ids.has(l.id));
    let idx = L.findIndex(l => ids.has(l.id));
    if (dir === Infinity) idx = rest.length;
    else if (dir === -Infinity) idx = 0;
    else idx = Math.max(0, Math.min(rest.length, idx + dir));
    rest.splice(idx, 0, ...picked);
    ed.doc.layers = rest;
    commit();
}

function orderSheet() {
    sheet('סדר שכבות', h('div', { class: 'menu' },
        h('button', { class: 'menu-item', onclick: () => move(Infinity) }, '⤒ לראש'),
        h('button', { class: 'menu-item', onclick: () => move(1) }, '↑ קדימה'),
        h('button', { class: 'menu-item', onclick: () => move(-1) }, '↓ אחורה'),
        h('button', { class: 'menu-item', onclick: () => move(-Infinity) }, '⤓ לתחתית'),
        h('hr'),
        h('button', { class: 'menu-item', onclick: () => alignPage('x') }, '↔ מרכז אופקי בדף'),
        h('button', { class: 'menu-item', onclick: () => alignPage('y') }, '↕ מרכז אנכי בדף')));
}

function alignPage(axis) {
    for (const l of sel()) {
        if (axis === 'x') l.x = (ed.doc.page.width - l.w) / 2;
        else l.y = (ed.doc.page.height - l.h) / 2;
    }
    commit();
}

// ─── הוספה ────────────────────────────────────────────────────────────────

function addSheet() {
    const t = (icon, label, fn) => tile(icon, label, () => { closeSheet(); fn(); });
    sheet('הוספה', h('div', {},
        h('div', { class: 'tiles' },
            t('🔠', 'כותרת', () => addText('title')),
            t('🔤', 'כותרת משנה', () => addText('subtitle')),
            t('📝', 'טקסט', () => addText('body')),
            t('🖼️', 'תמונה', addImage)),
        h('h3', { class: 'section' }, 'צורות'),
        h('div', { class: 'tiles four' },
            t('⬛', 'מלבן', () => addLayer(shapeLayer(ed.doc, 'rect'))),
            t('⚫', 'עיגול', () => addLayer(shapeLayer(ed.doc, 'ellipse'))),
            t('⭐', 'כוכב', () => addLayer({ ...shapeLayer(ed.doc, 'star'), fill: '#e19a00' })),
            t('➖', 'קו', () => addLayer(shapeLayer(ed.doc, 'line'))),
            t('⬢', 'מצולע', () => addLayer({ ...shapeLayer(ed.doc, 'polygon'), points: 6 })),
            t('➡️', 'חץ', () => addLayer({ ...shapeLayer(ed.doc, 'arrow'), h: Math.min(ed.doc.page.width, ed.doc.page.height) * 0.08 })))));
}

// סינכרוני בכוונה: בטלפון (בעיקר iOS) המקלדת נפתחת רק כש-focus קורה בתוך ההקשה עצמה. הפונט נטען
// במקביל, והטקסט מצויר מחדש כשהוא מוכן.
function addText(preset) {
    const l = textLayer(ed.doc, preset);
    l.style.color = contrastColor(l);
    addLayer(l);
    editTextInPlace(l, true);
    ensureFont(l.style.font, l.style.weight).then(() => ed?.canvas.render());
}

/** צבע טקסט שקריא על מה שמתחתיו: בהירות ממוצעת של האזור בדף שבו הטקסט יופיע. */
function contrastColor(l) {
    try {
        const { width: W } = ed.doc.page;
        const k = 120 / W;
        const c = ed.canvas.pageCanvas(k);
        const x = Math.max(0, Math.floor(l.x * k)), y = Math.max(0, Math.floor(l.y * k));
        const w = Math.max(1, Math.min(c.width - x, Math.ceil(l.w * k))), h = Math.max(1, Math.min(c.height - y, Math.ceil(l.h * k)));
        const d = c.getContext('2d').getImageData(x, y, w, h).data;
        let sum = 0;
        for (let i = 0; i < d.length; i += 4) sum += 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2];
        return sum / (d.length / 4) < 140 ? '#ffffff' : '#1b1e26';
    } catch { return '#1b1e26'; }
}

async function addImage() {
    const files = await pickFiles('image/*', true);
    for (const f of files) {
        try {
            const im = await ingestImage(f);
            const aid = uid('a');
            ed.doc.assets[aid] = { type: 'image', src: im.src };
            ed.canvas.setImage(aid, im.img);
            addLayer(imageLayer(ed.doc, aid, im.w, im.h));
        } catch (e) { toast(e.message, 'error'); }
    }
}

// ─── עריכת טקסט במקום ─────────────────────────────────────────────────────
// ‏textarea מעל הטקסט, באותו פונט, גודל וכיוון — כך שהמקלדת, ההשלמה וההכתבה של הטלפון עובדות.

function editTextInPlace(l, selectAll = false) {
    const r = ed.canvas.screenRect(l.id);
    if (!r) return;
    const s = l.style;
    const ta = h('textarea', {
        class: 'inplace', value: l.text, dir: s.dir === 'ltr' ? 'ltr' : 'rtl', 'aria-label': 'עריכת טקסט',
        style: {
            left: r.x + 'px', top: r.y + 'px', width: l.w * r.zoom + 'px', minHeight: s.size * (s.lineHeight || 1.2) * r.zoom + 'px',
            fontFamily: `"${s.font}"`, fontWeight: nearestWeight(s.font, s.weight), fontStyle: s.italic ? 'italic' : 'normal',
            fontSize: s.size * r.zoom + 'px', lineHeight: s.lineHeight || 1.2, color: s.color, textAlign: s.align,
            transform: `rotate(${r.rotation}deg)`,
        },
    });
    const grow = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; };
    let done = false;
    const finish = save => {
        if (done) return;
        done = true;
        if (save) l.text = ta.value;
        ta.remove();
        ed.canvas.hideNode(l.id, false);
        if (save && !l.text.trim()) { ed.doc.layers = ed.doc.layers.filter(x => x.id !== l.id); ed.canvas.select([]); }
        commit();
        ed.stageEl.focus({ preventScroll: true });
    };
    ta.addEventListener('input', grow);
    ta.addEventListener('blur', () => finish(true));
    ta.addEventListener('keydown', e => {
        e.stopPropagation();
        if (e.key === 'Escape') finish(false);
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) finish(true);
    });
    ed.stageEl.append(ta);
    ed.canvas.hideNode(l.id, true);
    grow();
    ta.focus({ preventScroll: true });
    if (selectAll) ta.select();
}

// ─── מאפיינים ─────────────────────────────────────────────────────────────

/** שינוי חי (בלי היסטוריה) — ההיסטוריה נרשמת ב-change ובסגירת הגיליון. */
const live = fn => v => { fn(v); ed.canvas.render(); };

function propsSheet(l) {
    let body;
    if (l.type === 'text') body = textProps(l);
    else if (l.type === 'image') body = imageProps(l);
    else body = shapeProps(l);
    sheet(l.type === 'text' ? 'עיצוב טקסט' : l.type === 'image' ? 'תמונה' : 'צורה', body, { onClose: commit });
}

function textProps(l) {
    const s = l.style;
    const fontList = h('div', { class: 'font-list', role: 'radiogroup', 'aria-label': 'פונט' }, FONTS.map(f => {
        const btn = h('button', { class: 'font-opt' + (f.family === s.font ? ' on' : ''), role: 'radio', 'aria-checked': String(f.family === s.font), style: { fontFamily: `"${f.family}"` },
            onclick: async () => {
                s.font = f.family;
                await ensureFont(s.font, s.weight);
                fontList.querySelectorAll('.font-opt').forEach(x => { x.classList.toggle('on', x === btn); x.setAttribute('aria-checked', String(x === btn)); });
                commit();
            } }, f.family);
        ensureFont(f.family, 400);
        return btn;
    }));
    return h('div', {},
        fontList,
        rangeField('גודל', { min: 6, max: Math.round(Math.max(ed.doc.page.width, ed.doc.page.height) / 3), value: s.size, onInput: live(v => { s.size = v; }), onCommit: commit }),
        h('div', { class: 'two' },
            colorField('צבע', s.color, live(v => { s.color = v; }), commit),
            seg('משקל', [{ value: 400, label: 'רגיל' }, { value: 700, label: 'מודגש' }, { value: 800, label: 'שחור' }], nearestWeight(s.font, s.weight) >= 800 ? 800 : nearestWeight(s.font, s.weight) >= 700 ? 700 : 400,
                async v => { s.weight = v; await ensureFont(s.font, v); commit(); })),
        seg('יישור', [{ value: 'right', icon: '⇥', label: 'ימין' }, { value: 'center', icon: '↔', label: 'מרכז' }, { value: 'left', icon: '⇤', label: 'שמאל' }, { value: 'justify', icon: '☰', label: 'מיושר' }], s.align, v => { s.align = v; commit(); }),
        rangeField('ריווח שורות', { min: 0.7, max: 3, step: 0.05, value: s.lineHeight || 1.2, onInput: live(v => { s.lineHeight = v; }), onCommit: commit }),
        rangeField('שקיפות', { min: 0, max: 100, value: Math.round((l.opacity ?? 1) * 100), unit: '%', onInput: live(v => { l.opacity = v / 100; }), onCommit: commit }),
        h('h3', { class: 'section' }, 'אפקטים'),
        toggle('צל', !!s.shadow, v => { if (v) s.shadow = { x: 0, y: s.size * 0.06, blur: s.size * 0.2, color: '#00000088' }; else delete s.shadow; commit(); }),
        toggle('קו מתאר', !!s.stroke, v => { if (v) s.stroke = { width: Math.max(1, s.size * 0.04), color: '#ffffff' }; else delete s.stroke; commit(); }),
        s.stroke ? colorField('צבע קו המתאר', s.stroke.color, live(v => { s.stroke.color = v; }), commit) : null,
        toggle('רקע לטקסט', !!s.background, v => { if (v) s.background = { color: '#ffd166', radius: s.size * 0.4, padding: s.size * 0.25 }; else delete s.background; commit(); }),
        s.background ? colorField('צבע הרקע', s.background.color, live(v => { s.background.color = v; }), commit) : null);
}

function imageProps(l) {
    l.filters ||= { brightness: 0, contrast: 0, saturation: 0, blur: 0 };
    const f = l.filters;
    return h('div', {},
        h('div', { class: 'row-actions' },
            h('button', { class: 'btn ghost', 'aria-pressed': String(!!l.flipX), onclick: () => { l.flipX = !l.flipX; commit(); } }, '⇋ היפוך אופקי'),
            h('button', { class: 'btn ghost', 'aria-pressed': String(!!l.flipY), onclick: () => { l.flipY = !l.flipY; commit(); } }, '⇵ היפוך אנכי'),
            h('button', { class: 'btn ghost', onclick: () => replaceImage(l) }, '🔄 החלפה')),
        seg('צורה', [{ value: 'none', label: 'רגיל' }, { value: 'rounded', label: 'מעוגל' }, { value: 'circle', label: 'עיגול' }], l.mask || 'none', v => { if (v === 'none') delete l.mask; else l.mask = v; commit(); }),
        rangeField('בהירות', { min: -0.5, max: 0.5, step: 0.01, value: f.brightness, onInput: live(v => { f.brightness = v; }), onCommit: commit }),
        rangeField('ניגודיות', { min: -60, max: 60, value: f.contrast, onInput: live(v => { f.contrast = v; }), onCommit: commit }),
        rangeField('רוויה', { min: -2, max: 2, step: 0.05, value: f.saturation, onInput: live(v => { f.saturation = v; }), onCommit: commit }),
        rangeField('טשטוש', { min: 0, max: 30, value: f.blur, onInput: live(v => { f.blur = v; }), onCommit: commit }),
        rangeField('שקיפות', { min: 0, max: 100, value: Math.round((l.opacity ?? 1) * 100), unit: '%', onInput: live(v => { l.opacity = v / 100; }), onCommit: commit }),
        toggle('צל', !!l.shadow, v => { if (v) l.shadow = { x: 0, y: 8, blur: 24, color: '#00000066' }; else delete l.shadow; commit(); }));
}

async function replaceImage(l) {
    const [f] = await pickFiles('image/*');
    if (!f) return;
    try {
        const im = await ingestImage(f);
        const aid = uid('a');
        ed.doc.assets[aid] = { type: 'image', src: im.src };
        ed.canvas.setImage(aid, im.img);
        l.asset = aid;
        // שומרים על הרוחב, והגובה לפי יחס התמונה החדשה.
        l.h = l.w * (im.h / im.w);
        commit();
    } catch (e) { toast(e.message, 'error'); }
}

function shapeProps(l) {
    return h('div', {},
        colorField('מילוי', l.fill, live(v => { l.fill = v; }), commit),
        l.shape === 'rect' ? rangeField('עיגול פינות', { min: 0, max: Math.round(Math.min(l.w, l.h) / 2), value: l.radius || 0, onInput: live(v => { l.radius = v; }), onCommit: commit }) : null,
        ['star', 'polygon'].includes(l.shape) ? rangeField(l.shape === 'star' ? 'קודקודים' : 'צלעות', { min: 3, max: 16, value: l.points || 5, onInput: live(v => { l.points = v; }), onCommit: commit }) : null,
        toggle('קו מתאר', !!l.stroke, v => { if (v) l.stroke = { width: Math.max(2, Math.min(l.w, l.h) * 0.03), color: '#1b1e26' }; else delete l.stroke; commit(); }),
        l.stroke ? colorField('צבע קו המתאר', l.stroke.color, live(v => { l.stroke.color = v; }), commit) : null,
        rangeField('שקיפות', { min: 0, max: 100, value: Math.round((l.opacity ?? 1) * 100), unit: '%', onInput: live(v => { l.opacity = v / 100; }), onCommit: commit }),
        toggle('צל', !!l.shadow, v => { if (v) l.shadow = { x: 0, y: 8, blur: 24, color: '#00000066' }; else delete l.shadow; commit(); }));
}

/** מיקום וגודל במספרים — חלופה לגרירה (נגישות, WCAG 2.5.7). */
function positionSheet(l) {
    const n = (label, key, opts = {}) => {
        const input = h('input', { type: 'number', value: Math.round(l[key] * 10) / 10, step: 1, inputMode: 'decimal', ...opts });
        input.addEventListener('change', () => {
            const v = parseFloat(input.value);
            if (!Number.isFinite(v)) return;
            if ((key === 'w' || key === 'h') && v < 1) return;
            if (key === 'w' && l.type !== 'text') {
                if (keep.checked) l.h *= v / l.w;
            } else if (key === 'h' && keep.checked) l.w *= v / l.h;
            l[key] = v;
            commit();
        });
        return field(label, input);
    };
    const keep = h('input', { type: 'checkbox', checked: true });
    sheet('מיקום וגודל', h('div', {},
        h('p', { class: 'hint' }, 'בפיקסלים של הדף. גם חיצים במקלדת מזיזים (Shift — ב-10).'),
        h('div', { class: 'two' }, n('X (מהקצה השמאלי)', 'x'), n('Y', 'y')),
        h('div', { class: 'two' }, n('רוחב', 'w'), l.type === 'text' ? null : n('גובה', 'h')),
        l.type === 'text' ? null : h('label', { class: 'toggle' }, keep, h('span', {}, 'שמירה על יחס')),
        n('סיבוב (מעלות)', 'rotation', { min: -360, max: 360 })), { onClose: commit });
}

// ─── שכבות ────────────────────────────────────────────────────────────────

function layersSheet() {
    const ul = h('ul', { class: 'layers', 'aria-label': 'שכבות, מהעליונה לתחתונה' });
    // ציור מחדש מחליף את הכפתורים — הפוקוס חוזר לאותו כפתור באותה שורה, כדי שמקלדת וקורא מסך לא יאבדו מקום.
    const draw = () => {
        const f = document.activeElement;
        const at = ul.contains(f) ? [f.closest('li')?.dataset.id, [...f.parentNode.children].indexOf(f)] : null;
        render();
        if (at) ul.querySelector(`li[data-id="${at[0]}"]`)?.children[at[1]]?.focus();
    };
    const render = () => {
        const L = ed.doc.layers.slice().reverse();
        ul.replaceChildren(...(L.length ? L.map(l => {
            const on = ed.selection.includes(l.id);
            const icon = l.type === 'text' ? '🔤' : l.type === 'image' ? '🖼️' : '⬛';
            return h('li', { class: 'layer-row' + (on ? ' on' : ''), 'data-id': l.id },
                h('button', { class: 'layer-main', 'aria-pressed': String(on), onclick: () => { ed.canvas.select([l.id]); draw(); } },
                    h('span', { 'aria-hidden': 'true' }, icon), h('span', { class: 'layer-name' }, layerLabel(l))),
                h('button', { class: 'icon-btn', 'aria-label': l.hidden ? `הצגת ${layerLabel(l)}` : `הסתרת ${layerLabel(l)}`, 'aria-pressed': String(!!l.hidden), onclick: () => { l.hidden = !l.hidden; if (!l.hidden) delete l.hidden; commit(); draw(); } }, l.hidden ? '🚫' : '👁️'),
                h('button', { class: 'icon-btn', 'aria-label': l.locked ? `שחרור ${layerLabel(l)}` : `נעילת ${layerLabel(l)}`, 'aria-pressed': String(!!l.locked), onclick: () => { if (l.locked) delete l.locked; else l.locked = true; commit(); draw(); } }, l.locked ? '🔒' : '🔓'),
                h('button', { class: 'icon-btn', 'aria-label': `העלאת ${layerLabel(l)}`, onclick: () => { ed.canvas.select([l.id]); move(1); draw(); } }, '▲'),
                h('button', { class: 'icon-btn', 'aria-label': `הורדת ${layerLabel(l)}`, onclick: () => { ed.canvas.select([l.id]); move(-1); draw(); } }, '▼'));
        }) : [h('li', { class: 'empty' }, 'אין שכבות עדיין — ➕ הוספה')]));
    };
    draw();
    sheet('שכבות', ul);
}

// ─── רקע וגודל הדף ────────────────────────────────────────────────────────

function backgroundSheet() {
    const bgLayer = ed.doc.layers.find(l => l.role === 'background' && l.type === 'image');
    sheet('רקע', h('div', {},
        colorField('צבע הדף', ed.doc.page.background, live(v => { ed.doc.page.background = v; }), commit),
        h('div', { class: 'row-actions' },
            h('button', { class: 'btn primary', onclick: async () => {
                const [f] = await pickFiles('image/*');
                if (!f) return;
                try {
                    const im = await ingestImage(f);
                    const aid = uid('a');
                    ed.doc.assets[aid] = { type: 'image', src: im.src };
                    ed.canvas.setImage(aid, im.img);
                    const nl = backgroundLayer(ed.doc, aid, im.w, im.h);
                    if (bgLayer) Object.assign(bgLayer, { ...nl, id: bgLayer.id });
                    else ed.doc.layers.unshift(nl);
                    closeSheet(); commit();
                } catch (e) { toast(e.message, 'error'); }
            } }, bgLayer ? '🔄 החלפת תמונת הרקע' : '🖼️ תמונת רקע'),
            bgLayer ? h('button', { class: 'btn ghost danger', onclick: () => { ed.doc.layers = ed.doc.layers.filter(l => l !== bgLayer); closeSheet(); commit(); } }, '🗑️ הסרה') : null),
        h('p', { class: 'hint' }, 'תמונת רקע ננעלת, כדי שגרירה עליה תזיז את התצוגה ולא את הרקע. שחרור — ב"שכבות".')), { onClose: commit });
}

function pageSizeSheet() {
    const { width: W, height: H } = ed.doc.page;
    const apply = (w, hgt) => {
        const k = Math.min(w / W, hgt / H);
        const dx = (w - W * k) / 2, dy = (hgt - H * k) / 2;
        for (const l of ed.doc.layers) {
            l.x = l.x * k + dx; l.y = l.y * k + dy; l.w *= k; l.h *= k;
            if (l.type === 'text') l.style.size *= k;
            if (l.role === 'background' && l.type === 'image') {
                const nat = l.w / l.h;
                const c = Math.max(w / l.w, hgt / l.h);
                l.w *= c; l.h = l.w / nat; l.x = (w - l.w) / 2; l.y = (hgt - l.h) / 2;
            }
        }
        ed.doc.page.width = w; ed.doc.page.height = hgt;
        closeSheet(); commit(); ed.canvas.fit();
    };
    sheet('גודל הדף', h('div', {},
        h('p', { class: 'hint' }, `עכשיו: \u2066${W}×${H}\u2069. התוכן מוקטן או מוגדל באופן יחסי, והרקע ממלא את הדף.`),
        h('div', { class: 'tiles sizes' }, SIZES.map(s => h('button', { class: 'tile size' + (s.w === W && s.h === H ? ' on' : ''), onclick: () => apply(s.w, s.h) },
            h('span', { class: 'size-box', style: { aspectRatio: `${s.w} / ${s.h}` }, 'aria-hidden': 'true' }),
            h('span', {}, s.label), h('span', { class: 'muted small', dir: 'ltr' }, `${s.w}×${s.h}`))))));
}

function renameSheet() {
    const input = h('input', { type: 'text', value: ed.doc.title, maxLength: 120, autofocus: true });
    const save = () => {
        const t = input.value.trim();
        if (t) { ed.doc.title = t; ed.titleBtn.textContent = t; document.title = t; commit(); }
        closeSheet();
    };
    input.addEventListener('keydown', e => { if (e.key === 'Enter') save(); });
    sheet('שם העיצוב', h('div', {}, field('שם', input), h('button', { class: 'btn primary block', onclick: save }, 'שמירה')));
}

// ─── ייצוא ────────────────────────────────────────────────────────────────

function exportSheet() {
    ed.canvas.select([]);
    let fmt = 'png', scale = 1;
    const { width: W, height: H } = ed.doc.page;
    const info = h('p', { class: 'hint' });
    const upd = () => {
        info.textContent = fmt === 'gcard' ? 'קובץ עיצוב — לפתיחה מחדש בעורך או להעברה למכשיר אחר'
            : fmt === 'pdf' ? `PDF בגודל הדף, ${W >= 1700 ? 300 : 150}dpi`
                : `\u2066${Math.round(W * scale)}×${Math.round(H * scale)}\u2069 פיקסלים`;
    };
    upd();
    const go = async share => {
        const name = safeName(ed.doc.title);
        try {
            toast('מכין…');
            let blob, file;
            if (fmt === 'gcard') { await saveNow(); blob = await exportGcard(ed.doc); file = name + '.gcard'; }
            else {
                const c = ed.canvas.pageCanvas(fmt === 'pdf' ? 1 : scale);
                if (fmt === 'pdf') { blob = await exportPdf(c, ed.doc); file = name + '.pdf'; }
                else { blob = await canvasToBlob(c, fmt === 'jpg' ? 'image/jpeg' : 'image/png', 0.92); file = `${name}.${fmt}`; }
            }
            if (share) {
                const f = new File([blob], file, { type: blob.type });
                await navigator.share({ files: [f], title: ed.doc.title });
            } else await download(blob, file);
            if (!window.GreetingCardsAndroid?.saveFile) toast('מוכן ✓');
        } catch (e) {
            if (e.name !== 'AbortError') toast(e.message || 'הייצוא נכשל', 'error');
        }
    };
    const canShare = !!navigator.canShare?.({ files: [new File([''], 'x.png', { type: 'image/png' })] });
    sheet('ייצוא', h('div', {},
        seg('פורמט', [{ value: 'png', label: 'PNG' }, { value: 'jpg', label: 'JPG' }, { value: 'pdf', label: 'PDF' }, { value: 'gcard', label: 'עיצוב' }], fmt, v => { fmt = v; upd(); }),
        seg('גודל', [{ value: 1, label: '×1' }, { value: 2, label: '×2' }, { value: 0.5, label: '×½' }], scale, v => { scale = +v; upd(); }),
        info,
        h('div', { class: 'row-actions' },
            h('button', { class: 'btn primary', onclick: () => go(false) }, '⬇️ הורדה'),
            canShare ? h('button', { class: 'btn ghost', onclick: () => go(true) }, '↗ שיתוף') : null)));
}

// ─── מקלדת ────────────────────────────────────────────────────────────────

function onStageKey(e) {
    if (isSheetOpen()) return;
    const ls = sel();
    const k = e.key;
    const mod = e.ctrlKey || e.metaKey;
    if (k === 'Tab' && !mod) {
        // מעבר בין שכבות במקלדת. בסוף הרשימה — יוצאים מהקנבס כרגיל.
        const L = ed.doc.layers.filter(l => !l.hidden);
        const i = L.findIndex(l => l.id === ed.selection[0]);
        const j = i + (e.shiftKey ? -1 : 1);
        if (j >= 0 && j < L.length) { e.preventDefault(); ed.canvas.select([L[j].id]); announce(L[j]); }
        else if (i === -1 && L.length) { e.preventDefault(); ed.canvas.select([L[e.shiftKey ? L.length - 1 : 0].id]); announce(L[e.shiftKey ? L.length - 1 : 0]); }
        return;
    }
    if (mod && k.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
    if (mod && k.toLowerCase() === 'y') { e.preventDefault(); redo(); return; }
    if (mod && (k === '=' || k === '+')) { e.preventDefault(); ed.canvas.zoomBy(1.25); return; }
    if (mod && k === '-') { e.preventDefault(); ed.canvas.zoomBy(0.8); return; }
    if (mod && k === '0') { e.preventDefault(); ed.canvas.fit(); return; }
    if (!ls.length) return;
    if (mod && k.toLowerCase() === 'd') { e.preventDefault(); duplicate(); return; }
    if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); removeSelected(); return; }
    if (k === 'Escape') { ed.canvas.select([]); return; }
    if (k === 'Enter' && ls.length === 1 && ls[0].type === 'text') { e.preventDefault(); editTextInPlace(ls[0]); return; }
    const step = e.shiftKey ? 10 : 1;
    const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[k];
    if (d && !ls.some(l => l.locked)) {
        e.preventDefault();
        ls.forEach(l => { l.x += d[0]; l.y += d[1]; });
        commit();
        announce(ls[0], true);
    }
}

function announce(l, pos) {
    ed.saveState.textContent = pos ? `${layerLabel(l)}: X ${Math.round(l.x)}, Y ${Math.round(l.y)}` : `נבחר: ${layerLabel(l)}`;
}

// ═══ התחלה ════════════════════════════════════════════════════════════════

showHome();
