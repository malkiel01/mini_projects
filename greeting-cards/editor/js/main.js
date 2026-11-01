// העורך החדש: מסך הבית (העיצובים שלי) ומסך העריכה. ‏spec/03-editor.md.

import { log, logDump } from './log.js';
import { buildMbe, importMbe, isMbe, mbeJournal, restoreFromJournal } from './mbe-io.js';
import { setColorContext } from './colorpicker.js';
import { shortcuts } from './ui-core/keys.js';   // לפי המקש הפיזי — Ctrl+Z עובד גם במקלדת עברית (ui-core)
import { accountMenu, accountServer } from './ui-core/account.js';   // 👤 תפריט המשתמש — משותף לחבילה
import { openDocuments, suiteSource } from './ui-core/files-window.js';   // המקש הפיזי — Ctrl+Z עובד גם במקלדת עברית (ui-core)
import { TARGETS, openIn } from './handoff.js';
import { drives, aiCaps, aiMessage, designFromBrief, imageFromPrompt, removeBackground, decompose } from './ai.js';
import { h, $, toast, sheet, closeSheet, isSheetOpen, confirmDialog, field, rangeField, seg, colorField, toggle, pickFiles, download, timeAgo } from './ui.js';
import { SIZES, newDoc, textLayer, shapeLayer, imageLayer, backgroundLayer, iconLayer, elementLayer, layerLabel, uid, clone, normalizeDoc } from './doc.js';
import { ICONS, ICON_GROUPS } from './icons.js';
import { ELEMENTS, ELEMENT_GROUPS } from './elements.js';
import { TEXT_STYLES, SOLIDS, GRADIENTS, gradientCss, applyTextStyle } from './presets.js';
import { TEMPLATES, TEMPLATE_CATS } from './templates.js';
import { FONTS, ensureFont, ensureDocFonts, nearestWeight, loadMyFonts, myFonts, readFontInfo, addMyFont, removeMyFont, embedDocFonts, syncCloudFonts, saveFontToCloud } from './fonts.js';
import { snapshots, undoButtons } from './ui-core/history.js';   // ביטול / ביצוע — משותף לחבילה (שלב 6)
import { designs, assets } from './db.js';
import { Canvas } from './canvas.js';
import { ingestImage, importFromChat, expandSvgLayers, blobToImage, canvasToBlob, exportPdf, safeName, personalSkill, skillAsPrompt, skillZip } from './io.js';
import * as sync from './sync.js';
import { loadScript } from './io.js';
import { docVariables, readSheetFile, readPasted, readPhoneContacts, guessColumns, normalizePhone, fileNames, fillText } from './merge.js';

// הגרסה — מופיעה ביומן, כדי לדעת איזה קוד רץ בטלפון.
const BUILD = '2026-11-01.history-1';

const app = $('#app');
let ed = null;   // העיצוב הפתוח: { id, doc, history, canvas, dirty }

// ═══ מסך הבית ═════════════════════════════════════════════════════════════

async function showHome() {
    ed = null;
    document.title = 'סטודיו עיצוב';
    const [{ list, online }, pending] = await Promise.all([
        sync.listDesigns().catch(() => ({ list: [], online: false })),
        sync.pendingImports(),
    ]);
    const me = sync.currentUser();
    const tiles = h('div', { class: 'tiles' },
        tile('➕', 'עיצוב חדש', newDesignSheet),
        tile('✨', 'עצב לי', aiDesignSheet),
        tile('📥', 'ייבוא מהצ\'אט', importSheet),
        tile('🤖', 'סקיל לצ\'אט', skillSheet),
        tile('📂', 'פתיחת קובץ', async () => { const f = await pickFiles(''); if (f.length) runImport(f, ''); }));

    const rows = list.length
        ? h('ul', { class: 'list', 'aria-label': 'העיצובים שלי' }, list.map(d => h('li', { class: 'row' },
            h('button', { class: 'row-main', onclick: () => openDesign(d.id, null, d) },
                h('span', { class: 'thumb', style: { aspectRatio: `${d.w} / ${d.h}` } },
                    d.thumb ? h('img', { src: URL.createObjectURL(d.thumb), alt: '' })
                        : d.remoteThumb ? h('img', { src: sync.fileUrl(d.remoteThumb), alt: '', loading: 'lazy' }) : null),
                h('span', { class: 'row-text' },
                    h('span', { class: 'row-title' }, d.title),
                    h('span', { class: 'row-sub' }, `\u2066${d.w}×${d.h}\u2069 · ${timeAgo(d.updatedAt)}`,
                        d.remoteOnly ? ' · ☁️' : !d.synced && online ? ' · ⏳' : '')),
                h('span', { class: 'chev', 'aria-hidden': 'true' }, '‹')),
            h('button', { class: 'icon-btn', 'aria-label': `פעולות על ${d.title}`, onclick: () => designMenu(d) }, '⋯'))))
        : h('p', { class: 'empty' }, 'עוד אין עיצובים. התחילו מעיצוב חדש, או ייבאו רקע מהצ\'אט.');

    const bar = h('header', { class: 'topbar' }, h('h1', { style: { flex: '1' } }, '🎨 סטודיו עיצוב'));
    if (me) attachAccount(bar);
    app.replaceChildren(
        bar,
        h('main', { class: 'home' },
            online ? null : h('p', { class: 'hint warn' }, 'אין חיבור לשרת — העבודה נשמרת במכשיר, ותישלח כשהחיבור יחזור.'),
            pending.length ? h('ul', { class: 'list pending', 'aria-label': 'ממתינים מ-ChatGPT' }, pending.map(p => h('li', { class: 'row' },
                h('button', { class: 'row-main', onclick: () => openChatImport(p.code) },
                    h('span', { class: 'thumb', 'aria-hidden': 'true' }, '📥'),
                    h('span', { class: 'row-text' }, h('span', { class: 'row-title' }, p.title),
                        h('span', { class: 'row-sub' }, `מ-ChatGPT · ${p.files} תמונות · ${timeAgo(p.createdAt)}`)),
                    h('span', { class: 'chev', 'aria-hidden': 'true' }, '‹'))))) : null,
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
        h('h3', { class: 'section' }, `מתבנית (${TEMPLATES.length})`),
        tabs(['הכול', ...TEMPLATE_CATS], cat => templateGrid(cat === 'הכול' ? TEMPLATES : TEMPLATES.filter(t => t.cat === cat)), 'tpl'),
        h('h3', { class: 'section' }, 'דף ריק'),
        h('div', { class: 'tiles sizes' }, SIZES.map(s => h('button', { class: 'tile size', onclick: () => createDesign(s.w, s.h, s.label) },
            h('span', { class: 'size-box', style: { aspectRatio: `${s.w} / ${s.h}` }, 'aria-hidden': 'true' }),
            h('span', {}, s.label), h('span', { class: 'muted small', dir: 'ltr' }, `${s.w}×${s.h}`)))),
        h('h3', { class: 'section' }, 'גודל אחר'),
        h('div', { class: 'two' }, field('רוחב (px)', wIn), field('גובה (px)', hIn)),
        h('button', { class: 'btn primary block', onclick: () => createDesign(+wIn.value, +hIn.value, 'מותאם') }, 'יצירה')));
}

// ─── תבניות ──────────────────────────────────────────────────────────────

const thumbs = new Map();

/**
 * רשת תבניות. התצוגה המקדימה מצוירת רק כשהכרטיס נכנס למסך, ואחת אחרי השנייה — עשרות תבניות לא
 * תוקעות את הטלפון בפתיחת הגיליון.
 */
function templateGrid(list) {
    const io = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
        for (const e of entries) if (e.isIntersecting) { io.unobserve(e.target); e.target._load(); }
    }, { rootMargin: '200px' }) : null;
    return h('div', { class: 'tiles templates' }, list.map(t => {
        const img = h('img', { alt: '', class: 'tpl-img' });
        const frame = h('span', { class: 'tpl-frame', style: { aspectRatio: `${t.doc.page.width} / ${t.doc.page.height}` } }, img);
        frame._load = () => queueThumb(t).then(url => { img.src = url; }).catch(() => {});
        if (io) io.observe(frame); else frame._load();
        return h('button', { class: 'tile tpl', onclick: () => createFromTemplate(t) }, frame, h('span', {}, t.label));
    }));
}

let thumbQueue = Promise.resolve();
function queueThumb(t) {
    if (thumbs.has(t.id)) return thumbs.get(t.id);
    const p = thumbQueue.then(() => templateThumb(t));
    thumbQueue = p.catch(() => {}).then(() => new Promise(r => setTimeout(r, 0)));
    thumbs.set(t.id, p);
    return p;
}

/** תצוגה מקדימה של תבנית: מצוירת פעם אחת בקנבס נסתר, ונשמרת לכל הפעלה. */
async function templateThumb(t) {
    const { doc } = normalizeDoc(t.doc);
    await ensureDocFonts(doc);
    const holder = h('div', { style: { position: 'fixed', insetInlineStart: '-10000px', top: '0', width: '100px', height: '100px' }, 'aria-hidden': 'true' });
    document.body.append(holder);
    try {
        const c = new Canvas(holder, {});
        c.setDoc(doc);
        const url = c.pageCanvas(320 / Math.max(doc.page.width, doc.page.height)).toDataURL('image/jpeg', 0.82);
        c.stage.destroy();
        return url;
    } finally { holder.remove(); }
}

async function createFromTemplate(t) {
    closeSheet();
    const { doc } = normalizeDoc(t.doc);
    doc.source = { app: 'template' };
    const id = uid('d');
    await designs.put({ id, title: doc.title, doc, updatedAt: Date.now(), owner: sync.currentUser()?.id, synced: false });
    await openDesign(id);
    toast('מתבנית — כל טקסט נערך בהקשה כפולה');
}

async function createDesign(w, hgt, label) {
    w = Math.min(10000, Math.max(16, Math.round(w) || 1080));
    hgt = Math.min(10000, Math.max(16, Math.round(hgt) || 1350));
    closeSheet();
    const doc = newDoc(w, hgt, `${label} חדש`);
    const id = uid('d');
    await designs.put({ id, title: doc.title, doc, updatedAt: Date.now(), owner: sync.currentUser()?.id, synced: false });
    openDesign(id);
}

function designMenu(d) {
    sheet(d.title, h('div', { class: 'menu-list' },
        h('button', { class: 'menu-item', onclick: () => openDesign(d.id, null, d) }, '✏️ פתיחה'),
        h('button', { class: 'menu-item', onclick: async () => {
            if (d.remoteOnly) await sync.pull(d.id);
            const rec = await designs.get(d.id);
            const id = uid('d');
            await designs.put({ ...rec, id, title: rec.title + ' (עותק)', updatedAt: Date.now(), serverVersion: 0, synced: false });
            sync.push(id).catch(() => {});
            closeSheet(); showHome(); toast('שוכפל');
        } }, '⧉ שכפול'),
        h('button', { class: 'menu-item', onclick: async () => {
            // ‎.mbe נבנה מהקנבס (שכבות אפויות, תצוגה מקדימה) — פותחים את העיצוב ומורידים ממנו
            await openDesign(d.id, null, d);
            if (!ed) return;
            try { await download(await makeMbe(), safeName(ed.doc.title) + '.mbe'); } catch (e) { toast(e.message || 'הייצוא נכשל', 'error'); }
        } }, '⬇️ הורדה כ-‎.mbe'),
        h('button', { class: 'menu-item danger', onclick: async () => {
            if (!(await confirmDialog(`למחוק את "${d.title}"? אי אפשר לבטל.`, 'מחיקה', true))) return;
            closeSheet();
            await designs.remove(d.id);
            try { await sync.removeRemote(d.id); } catch { toast('נמחק מהמכשיר; מהשרת — כשיהיה חיבור', 'error'); }
            showHome(); toast('נמחק');
        } }, '🗑️ מחיקה')));
}

// ═══ בינה מלאכותית ישירה (מ4) — דרך ענן החבילה ══════════════════════════════
// בלי חיבור או בלי ספק — מסבירים, ומציעים את הצ'אט (סקיל). שום כפתור לא מוביל למבוי סתום.

/** מה לעשות כשהבינה לא זמינה: חיבור לענן, או הסבר — ותמיד גם הצ'אט. */
function aiUnavailable(caps, task) {
    const fallback = h('button', { class: 'btn ghost block', onclick: () => { closeSheet(); skillSheet(); } }, '🤖 דרך הצ\'אט (סקיל) — בלי עלות');
    if (!caps.connected) return h('div', {},
        h('p', { class: 'hint' }, caps.status === 'error' ? 'אין חיבור לשרת כרגע.'
            : 'הבינה המלאכותית עובדת דרך ענן החבילה. מתחברים פעם אחת, ואחר כך זה זמין בכל מכשיר שנכנסים בו לסטודיו.'),
        caps.status === 'error' ? null : h('button', { class: 'btn primary block', onclick: connectCloud }, '☁️ חיבור לענן החבילה'),
        fallback);
    return h('div', {},
        h('p', { class: 'hint' }, caps.error ? `הענן לא ענה: ${caps.error}`
            : `מחובר לענן${caps.name ? ` (${caps.name})` : ''}, אבל ${task ? 'לפעולה הזו' : 'לבינה המלאכותית'} עוד לא הוגדר ספק. בעל המערכת מגדיר ספק ומכסה בניהול ענן החבילה.`),
        fallback);
}

async function connectCloud() {
    try {
        // באפליקציה לאנדרואיד כתובת מחוץ לסטודיו נפתחת בדפדפן — החיבור נשמר בשרת לפי החשבון, ולכן עובד גם כך
        log('cloud-connect', { user: sync.currentUser()?.id, apk: window.GreetingCardsAndroid ? 1 : 0 });
        if (window.GreetingCardsAndroid) toast('נפתח בדפדפן — אחרי האישור חוזרים לכאן');
        await drives.connect();
    } catch (e) { toast(aiMessage(e), 'error'); }
}

function quotaLine(caps) {
    const q = caps.quota;
    return q && q.limit ? h('p', { class: 'hint' }, `מכסה: ⁦${q.used ?? 0}/${q.limit}⁩ ${q.unit || ''}`) : null;
}

/** "עצב לי": תיאור ← תוכנית עיצוב ← רקע ואלמנטים שקופים ← עיצוב חדש בשכבות, כמו ייבוא מהצ'אט. */
async function aiDesignSheet() {
    sheet('✨ עצב לי', h('p', { class: 'hint' }, 'בודק חיבור לענן…'));
    const caps = await aiCaps(true);
    if (!caps.tasks?.complete || !caps.tasks?.['generate-image']) { sheet('✨ עצב לי', aiUnavailable(caps)); return; }
    let size = SIZES[0];
    const brief = h('textarea', { class: 'input', rows: 5, 'aria-label': 'מה לעצב',
        placeholder: 'למשל: הזמנה לבר מצווה של יונתן, 12.3 בשעה 19:00 באולם הגן, רמת גן. זהב וכחול כהה, חגיגי ואלגנטי.' });
    const status = h('p', { class: 'hint', role: 'status' });
    const go = h('button', { class: 'btn primary block' }, '✨ עצב לי');
    let ctl = null;
    go.onclick = async () => {
        if (ctl) { ctl.abort(); return; }
        const text = brief.value.trim();
        if (text.length < 8) { toast('כתבו מה לעצב — מה, למי, איזה טקסט, איזה סגנון', 'error'); brief.focus(); return; }
        ctl = new AbortController();
        go.textContent = '✕ ביטול';
        brief.disabled = true;
        try {
            const files = await designFromBrief({ brief: text, w: size.w, h: size.h }, { signal: ctl.signal, onStep: t => { status.textContent = t; } });
            status.textContent = 'מרכיב את השכבות…';
            await runImport(files, '');
        } catch (e) {
            status.textContent = '';
            toast(aiMessage(e), 'error');
        } finally {
            ctl = null;
            go.textContent = '✨ עצב לי';
            brief.disabled = false;
        }
    };
    sheet('✨ עצב לי', h('div', {},
        h('p', { class: 'hint' }, 'כתבו מה לעצב. הבינה המלאכותית מתכננת, יוצרת רקע ואלמנטים בלי טקסט, ומוסיפה את הטקסטים כשכבות — הכול נשאר עריך.'),
        brief,
        seg('גודל', SIZES.slice(0, 6).map(s => ({ value: s.id || s.label, label: s.label })), size.id || size.label,
            v => { size = SIZES.find(s => (s.id || s.label) === v) || size; }),
        quotaLine(caps),
        go, status));
}

/** תמונת השכבה כמו שהיא בעיצוב — אחרי חיתוך. { blob, W, H } בפיקסלי המקור. */
async function layerImage(l) {
    const img = ed.canvas.images.get(l.asset);
    if (!img) throw new Error('התמונה עוד לא נטענה');
    const c = l.crop || { x: 0, y: 0, w: 1, h: 1 };
    const sx = c.x * img.naturalWidth, sy = c.y * img.naturalHeight;
    const W = Math.max(1, Math.round(c.w * img.naturalWidth)), H = Math.max(1, Math.round(c.h * img.naturalHeight));
    const cv = document.createElement('canvas');
    cv.width = W; cv.height = H;
    cv.getContext('2d').drawImage(img, sx, sy, W, H, 0, 0, W, H);
    return { blob: await canvasToBlob(cv), W, H };
}

async function addAsset(blob, name = 'ai.png') {
    const im = await ingestImage(new File([blob], name, { type: blob.type || 'image/png' }));
    const aid = uid('a');
    ed.doc.assets[aid] = { type: 'image', src: im.src };
    ed.canvas.setImage(aid, im.img);
    return { aid, ...im };
}

/** פעולת AI ארוכה על שכבה: חלון התקדמות עם ביטול; השינוי — צעד אחד ב"ביטול". */
async function aiBusy(title, work) {
    const ctl = new AbortController();
    const status = h('p', { class: 'hint', role: 'status' }, 'שולח לענן…');
    sheet(title, h('div', {}, status, h('button', { class: 'btn ghost block', onclick: () => ctl.abort() }, '✕ ביטול')));
    try {
        await work(ctl.signal, t => { status.textContent = t; });
        closeSheet();
    } catch (e) {
        closeSheet();
        toast(aiMessage(e), 'error');
    }
}

function aiRemoveBackground(l) {
    aiBusy('✂️ הסרת רקע', async (signal, step) => {
        const { blob } = await layerImage(l);
        step('מסיר את הרקע…');
        const png = await removeBackground(blob, { signal });
        const { aid } = await addAsset(png, 'no-background.png');
        l.asset = aid;
        delete l.crop;   // החיתוך כבר בתוך התמונה החדשה
        commit();
        toast('הרקע הוסר ✓ — ↶ מחזיר');
    });
}

/** פירוק: הרקע (מושלם מאחורי העצמים) נשאר בשכבה עצמה, וכל עצם — שכבה שקופה מעליה, בדיוק במקומו. */
function aiDecompose(l, caps) {
    aiBusy('🧩 פירוק לשכבות', async (signal, step) => {
        const { blob } = await layerImage(l);
        const r = await decompose(blob, { inpaint: !!caps.tasks.inpaint, signal, onStep: step });
        step('מוסיף את השכבות…');
        const kx = l.w / r.W, ky = l.h / r.H;
        const rad = (l.rotation || 0) * Math.PI / 180, cos = Math.cos(rad), sin = Math.sin(rad);
        const added = [];
        for (const [i, p] of r.parts.entries()) {
            let [bx, by, bw, bh] = p.box;
            if (l.flipX) bx = r.W - bx - bw;
            if (l.flipY) by = r.H - by - bh;
            const dx = bx * kx, dy = by * ky;   // היסט בתוך השכבה, לפני הסיבוב שלה (סביב הפינה)
            const { aid } = await addAsset(p.png, `part-${i + 1}.png`);
            added.push({ id: uid(), type: 'image', role: 'decoration', name: p.label || `עצם ${i + 1}`, asset: aid,
                x: l.x + dx * cos - dy * sin, y: l.y + dx * sin + dy * cos, w: bw * kx, h: bh * ky,
                rotation: l.rotation || 0, opacity: l.opacity ?? 1,
                ...(l.flipX ? { flipX: true } : {}), ...(l.flipY ? { flipY: true } : {}), ...(l.filters ? { filters: { ...l.filters } } : {}) });
        }
        if (r.background) { l.asset = (await addAsset(r.background, 'background-filled.png')).aid; delete l.crop; }
        const at = ed.doc.layers.indexOf(l);
        ed.doc.layers.splice(at + 1, 0, ...added);
        commit();
        ed.canvas.select(added.map(a => a.id));
        toast(`${added.length} שכבות חדשות ✓${r.background ? '' : ' (הרקע לא הושלם — אין ספק למילוי)'} — ↶ מחזיר`);
    });
}

/** כפתורי AI בתכונות התמונה — נטענים אחרי הבדיקה בענן, ומופיעים רק אם יש ספק. */
function imageAiRow(l) {
    const row = h('div', { class: 'row-actions' });
    aiCaps().then(caps => {
        if (!caps.connected) return;
        row.append(...[
            caps.tasks['remove-background'] ? h('button', { class: 'btn ghost', onclick: () => aiRemoveBackground(l) }, '✂️ הסרת רקע') : null,
            caps.tasks.segment ? h('button', { class: 'btn ghost', onclick: () => aiDecompose(l, caps) }, '🧩 פירוק לשכבות') : null,
        ].filter(Boolean));
    });
    return row;
}

/** לשונית "✨ AI" בהוספה: תמונה או אלמנט מתיאור. */
function aiAddPanel() {
    const box = h('div', {}, h('p', { class: 'hint' }, 'בודק חיבור לענן…'));
    aiCaps().then(caps => {
        if (!caps.tasks?.['generate-image']) { box.replaceChildren(aiUnavailable(caps, true)); return; }
        let kind = 'element';
        const prompt = h('textarea', { class: 'input', rows: 3, 'aria-label': 'מה ליצור', placeholder: 'למשל: רימון אדום מבריק עם עלה' });
        const status = h('p', { class: 'hint', role: 'status' });
        const go = h('button', { class: 'btn primary block' }, '✨ יצירה');
        go.onclick = async () => {
            const text = prompt.value.trim();
            if (!text) { prompt.focus(); return; }
            go.disabled = true;
            status.textContent = 'יוצר…';
            try {
                const { width: W, height: H } = ed.doc.page;
                const size = kind === 'background' ? `${W}x${H}` : '1024x1024';
                const png = await imageFromPrompt(text, { transparent: kind === 'element', size });
                const im = await addAsset(png, 'ai.png');
                closeSheet();
                if (kind === 'background') {
                    const bl = backgroundLayer(ed.doc, im.aid, im.w, im.h);
                    ed.doc.layers.unshift(bl);   // רקע — בתחתית
                    commit();
                    ed.canvas.select([bl.id]);
                } else addLayer({ ...imageLayer(ed.doc, im.aid, im.w, im.h, 'decoration'), name: text.slice(0, 40) });
            } catch (e) { status.textContent = ''; toast(aiMessage(e), 'error'); }
            finally { go.disabled = false; }
        };
        box.replaceChildren(
            seg('מה', [{ value: 'element', label: 'אלמנט שקוף' }, { value: 'background', label: 'רקע לעמוד' }], kind, v => { kind = v; }),
            prompt, h('p', { class: 'hint' }, 'בלי טקסט בתמונה — את הטקסט מוסיפים כשכבה, וכך הוא נשאר עריך.'),
            quotaLine(caps), go, status);
    });
    return box;
}

/** בחשבון: החיבור לענן החבילה. */
function cloudRow() {
    const row = h('div', {}, h('p', { class: 'hint' }, '☁️ ענן החבילה: בודק…'));
    aiCaps(true).then(caps => {
        if (caps.status === 'error') { row.replaceChildren(h('p', { class: 'hint' }, '☁️ ענן החבילה: אין חיבור לשרת')); return; }
        row.replaceChildren(caps.connected
            ? h('div', {}, h('p', { class: 'hint' }, `☁️ מחובר לענן החבילה${caps.name ? ` (${caps.name})` : ''}.`),
                h('button', { class: 'btn ghost block', onclick: async () => {
                    if (!await confirmDialog('לנתק את הסטודיו מענן החבילה?', 'ניתוק')) return;
                    try { await drives.disconnect(); await aiCaps(true); toast('נותק'); closeSheet(); } catch (e) { toast(aiMessage(e), 'error'); }
                } }, '⛓️ ניתוק מהענן'))
            : h('button', { class: 'btn ghost block', onclick: connectCloud }, '☁️ חיבור לענן החבילה (לבינה המלאכותית)'));
    });
    return row;
}

// ═══ ייבוא מהצ'אט וסקיל ═══════════════════════════════════════════════════

function importSheet() {
    const files = [];
    const list = h('ul', { class: 'file-list', 'aria-live': 'polite' });
    const text = h('textarea', { rows: 5, dir: 'ltr', placeholder: '{ "version": 1, "page": … }', class: 'mono' });
    const drop = h('button', { class: 'drop', onclick: async () => add(await pickFiles('', true)) },
        h('span', { class: 'tile-icon', 'aria-hidden': 'true' }, '📎'), h('span', {}, 'בחירת תמונות או קובץ מהצ\'אט'),
        h('span', { class: 'muted small' }, 'design.zip · ‎.mbe · תמונות · design.json'));
    const add = fs => { for (const f of fs) { files.push(f); list.append(h('li', {}, `${f.type.startsWith('image/') ? '🖼️' : '📄'} ${f.name}`)); } };
    drop.addEventListener('dragover', e => e.preventDefault());
    drop.addEventListener('drop', e => { e.preventDefault(); add([...e.dataTransfer.files]); });

    sheet('ייבוא מהצ\'אט', h('div', {},
        h('p', { class: 'hint' }, 'בוחרים את כל התמונות מהצ\'אט יחד (השמות לא משנים). אם הצ\'אט נתן גם בלוק JSON — מדביקים אותו, והטקסטים נכנסים כשכבות לעריכה. בלי JSON: התמונה הגדולה נכנסת כרקע, והשאר — כשכבות.'),
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
        // ‎.mbe של חבילת היצירה — נפתח דרך הליבה; הקובץ המקורי נשמר, כדי ששמירה חוזרת לא תאבד את מה שאינו שלנו
        const mbe = [];
        for (const f of files) if (await isMbe(f)) mbe.push(f);
        let doc, loaded, fixes;
        if (mbe.length) {
            const r = await importMbe(mbe[0]);
            ({ doc, loaded, fixes } = r);
            doc.mbeBase = await assets.put(new Blob([r.bytes], { type: 'application/vnd.mbe+zip' }));
            if (mbe.length < files.length) fixes.push('נפתח קובץ ‎.mbe — שאר הקבצים לא יובאו');
        } else ({ doc, loaded, fixes } = await importFromChat(files, pasted));
        const id = uid('d');
        await designs.put({ id, title: doc.title, doc, updatedAt: Date.now(), owner: sync.currentUser()?.id, synced: false });
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
    const pane = (name, steps, buttons) => h('section', { class: 'box' }, h('h3', {}, name), h('ol', { class: 'steps' }, steps.map(s => h('li', {}, s))), h('div', { class: 'row-actions' }, buttons));
    sheet('סקיל לצ\'אט', h('div', {},
        h('p', { class: 'hint' }, 'הסקיל מלמד את הצ\'אט ליצור רקע בלי טקסט, ולתת קובץ פריסה שהעורך הופך לשכבות. בלי טוקנים — על החשבון שלכם בצ\'אט.'),
        pane('ChatGPT', ['להעתיק את ההוראות', 'להדביק בתחילת שיחה חדשה (או ב-Project / GPT משלכם)', 'לבקש: "רקע למודעה של…" ולענות על השאלון', 'לבחור מבין התמונות (אפשר לבקש שינויים), ולכתוב "בחרתי 2"', 'להוריד את התמונה שנבחרה ולהעתיק את ה-JSON → "ייבוא מהצ\'אט"'],
            [h('button', { class: 'btn primary', onclick: () => copy(prompt, 'הסקיל') }, '📋 העתקה'), h('a', { class: 'btn ghost', href: 'https://chatgpt.com/', target: '_blank', rel: 'noopener' }, '↗ ChatGPT')]),
        pane('Gemini', ['להעתיק את ההוראות', 'ליצור Gem חדש ולהדביק, או להדביק בתחילת שיחה', 'לבקש רקע, ולייבא כמו למעלה'],
            [h('button', { class: 'btn primary', onclick: () => copy(prompt, 'הסקיל') }, '📋 העתקה'), h('a', { class: 'btn ghost', href: 'https://gemini.google.com/', target: '_blank', rel: 'noopener' }, '↗ Gemini')]),
        pane('Claude', ['להוריד את קובץ הסקיל', 'Settings ← Capabilities ← Skills ← העלאה', 'Claude לא יוצר תמונות — הוא נותן פריסה, ואת הרקע מעלים בנפרד'],
            [h('button', { class: 'btn primary', onclick: async () => download(await skillZip(skill), 'design-background-for-editor.zip').catch(e => toast(e.message, 'error')) }, '⬇️ הורדה'), h('a', { class: 'btn ghost', href: 'https://claude.ai/', target: '_blank', rel: 'noopener' }, '↗ Claude')]),
        h('details', {}, h('summary', {}, 'הטקסט המלא'), h('pre', { class: 'mono pre', dir: 'ltr', tabindex: 0 }, prompt))), { wide: true });
}

// ═══ מסך העריכה ═══════════════════════════════════════════════════════════

async function openDesign(id, preloaded, entry) {
    // עיצוב ממכשיר אחר, או שהשתנה שם — מורידים את הגרסה העדכנית לפני הפתיחה.
    if (entry?.remoteOnly || entry?.stale) {
        toast('מוריד מהשרת…');
        try { await sync.pull(id); } catch (e) { if (entry.remoteOnly) { toast('ההורדה נכשלה: ' + e.message, 'error'); return; } }
    }
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
    const previewBar = h('div', { class: 'previewbar', role: 'group', 'aria-label': 'תצוגה לפי נמען', hidden: true });

    app.replaceChildren(
        h('header', { class: 'topbar editor-top' },
            h('button', { class: 'icon-btn', 'aria-label': 'חזרה לעיצובים', onclick: async () => {
                const id = ed.id;
                await saveNow();
                showHome();
                sync.push(id, { withThumb: true }).catch(() => {});
            } }, '›'),
            titleBtn, saveState, undoBtn, redoBtn,
            h('button', { class: 'btn primary sm', onclick: exportSheet }, '⬇️ ייצוא')),
        h('main', { class: 'editor' },
            stageEl,
            h('div', { class: 'zoombar', role: 'group', 'aria-label': 'זום' },
                h('button', { class: 'icon-btn', 'aria-label': 'הקטנה', onclick: () => ed.canvas.zoomBy(1 / 1.25) }, '−'),
                zoomLabel,
                h('button', { class: 'icon-btn', 'aria-label': 'הגדלה', onclick: () => ed.canvas.zoomBy(1.25) }, '+')),
            previewBar,
            ctx),
        h('nav', { class: 'bottomnav', 'aria-label': 'כלים' },
            navBtn('➕', 'הוספה', addSheet),
            navBtn('🧱', 'שכבות', layersSheet),
            navBtn('🖼️', 'רקע', backgroundSheet),
            navBtn('👥', 'נמענים', recipientsSheet),
            navBtn('⋯', 'עוד', moreSheet)));

    // צילומי מצב של המסמך (JSON קטן — התמונות ב-IndexedDB). הכפתורים: מושבתים כשאין, ו-title עם שם הפעולה
    let syncButtons = () => {};
    const history = snapshots({
        get: () => ed.doc,
        set: d => { ed.doc = d; ed.canvas.setDoc(d); ed.canvas.select(ed.selection); scheduleSave(); },
        onChange: () => syncButtons(),
    });
    syncButtons = undoButtons(history, undoBtn, redoBtn, { undo: 'ביטול', redo: 'חזרה' });
    ed = { id, doc: rec.doc, history, ctx, saveState, titleBtn, zoomLabel, stageEl, previewBar, selection: [], preview: null };
    document.title = rec.title;

    ed.canvas = new Canvas(stageEl, {
        onSelect: ids => { ed.selection = ids; renderCtx(); },
        onCommit: () => commit('הזזה / שינוי גודל'),
        onEditText: l => editTextInPlace(l),
        onZoom: z => { zoomLabel.textContent = Math.round(z * 100) + '%'; ed.onView?.(); },
    });

    // תמונות: מה שכבר נטען בייבוא, והשאר מ-IndexedDB.
    for (const [aid, a] of Object.entries(ed.doc.assets)) {
        let img = preloaded?.get(aid);
        if (!img) { const blob = await assets.get(a.src); if (blob) img = await blobToImage(blob).catch(() => null); }
        if (img) ed.canvas.setImage(aid, img);
    }
    await ensureDocFonts(ed.doc);
    log('open', { w: ed.doc.page.width, h: ed.doc.page.height, layers: ed.doc.layers.length, sw: innerWidth, sh: innerHeight });
    ed.canvas.setDoc(ed.doc, { fit: true });
    history.reset();
    renderCtx();
    stageEl.addEventListener('keydown', onStageKey);
    // הגדלים של טקסט נגזרים בציור הראשון — שומרים אותם כמצב ההתחלה.
    scheduleSave(true);
}

function navBtn(icon, label, onclick) {
    return h('button', { class: 'nav-btn', onclick }, h('span', { 'aria-hidden': 'true', class: 'nav-icon' }, icon), h('span', {}, label));
}

/** סוף פעולה: רושם בהיסטוריה, מצייר ושומר. label — לכפתור ("ביטול: הזזה"); merge — שינויים רצופים כצעד אחד. */
function commit(label = '', opts) {
    ed.canvas.render();
    const changed = ed.history.commit(label, opts);
    log('commit', { changed: changed ? 1 : 0, sel: ed.selection.join(',') || '-' });
    if (changed) scheduleSave();
    renderCtx();
}

function undo() {
    const ok = !!ed?.history.undo();   // set (למעלה) מחליף את המסמך, מצייר ושומר
    log('undo', { ok: ok ? 1 : 0 });
    if (ok) renderCtx();
}

function redo() {
    const ok = !!ed?.history.redo();
    log('redo', { ok: ok ? 1 : 0 });
    if (ok) renderCtx();
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
        const prev = await designs.get(cur.id);
        embedDocFonts(cur.doc);   // גופן שהועלה ומשמש טקסט — עובר עם העיצוב (קובץ, מכשיר אחר)
        await designs.put({ ...prev, id: cur.id, title: cur.doc.title, doc: clone(cur.doc), updatedAt: Date.now(), thumb, owner: prev?.owner ?? sync.currentUser()?.id, synced: false });
        if (ed === cur) cur.saveState.textContent = 'נשמר ✓';
        pushSoon(cur.id);
    } catch (e) {
        cur.dirty = true;
        cur.saveState.textContent = 'לא נשמר';
        toast('השמירה במכשיר נכשלה: ' + (e.message || e), 'error');
    }
}

// שליחה לשרת: כמה שניות אחרי השמירה במכשיר, כדי לא לשלוח בכל תזוזה.
let pushTimer = null;
function pushSoon(id) {
    clearTimeout(pushTimer);
    pushTimer = setTimeout(() => sync.push(id).catch(() => { if (ed?.id === id) ed.saveState.textContent = 'נשמר במכשיר'; }), 3000);
}

sync.onSync((type, data) => {
    if (type === 'pushed' && ed?.id === data.id) {
        // מזהי התמונות בשרת — גם לעותק שפתוח בעורך, כדי שהשמירה הבאה לא תעלה אותן שוב.
        for (const [key, remote] of Object.entries(data.remotes)) if (ed.doc.assets[key]) ed.doc.assets[key].remote = remote;
        if (data.synced) ed.saveState.textContent = '☁️ נשמר';
    }
    if (type === 'conflict') toast('העיצוב השתנה במכשיר אחר — השינויים מכאן נשמרו כעותק', 'error');
});

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
        ls.length > 1 ? b('⊞', 'יישור', orderSheet) : null,
        ls.length > 1 && !(l.group && ls.every(x => x.group === l.group)) ? b('🔗', 'קיבוץ', groupSelected) : null,
        l.group && ls.every(x => x.group === l.group) ? b('⛓️', 'פירוק', ungroupSelected) : null,
        b(ls.every(x => x.locked) ? '🔒' : '🔓', ls.every(x => x.locked) ? 'נעול' : 'נעילה', toggleLock, { 'aria-pressed': String(ls.every(x => x.locked)) }),
        b('🗑️', 'מחיקה', removeSelected)].filter(Boolean));
}

function addLayer(l, { select = true } = {}) {
    ed.doc.layers.push(l);
    commit('הוספה');
    if (select) ed.canvas.select([l.id]);
}

/** קיבוץ: השכבות הנבחרות נבחרות ונעות יחד מעכשיו (הקשה על אחת בוחרת את כולן). */
function groupSelected() {
    const ls = sel();
    if (ls.length < 2) return;
    const g = uid('g');
    for (const l of ls) l.group = g;
    log('group', { n: ls.length });
    commit('קיבוץ');
    toast(`${ls.length} שכבות קובצו — הקשה על אחת בוחרת את כולן`);
}

function ungroupSelected() {
    for (const l of sel()) delete l.group;
    log('ungroup', {});
    commit('פירוק קבוצה');
}

function duplicate() {
    const ids = [];
    const regroup = new Map();   // לעותק של קבוצה — קבוצה חדשה, כדי שלא יתחבר למקור
    for (const l of sel()) {
        const c = clone(l);
        c.id = uid();
        if (c.group) { if (!regroup.has(c.group)) regroup.set(c.group, uid('g')); c.group = regroup.get(c.group); }
        c.x += 30; c.y += 30;
        delete c.locked;
        if (c.role === 'background') c.role = 'photo';
        ed.doc.layers.push(c);
        ids.push(c.id);
    }
    commit('שכפול');
    ed.canvas.select(ids);
}

function removeSelected() {
    const ids = new Set(ed.selection);
    if (!ids.size) return;
    ed.doc.layers = ed.doc.layers.filter(l => !ids.has(l.id));
    ed.canvas.select([]);
    commit('מחיקה');
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
    const many = sel().length > 1;
    const a = (icon, label, fn) => h('button', { class: 'tile small', onclick: fn, 'aria-label': label, title: label }, h('span', { class: 'tile-icon', 'aria-hidden': 'true' }, icon), h('span', { class: 'small' }, label));
    sheet('סדר ויישור', h('div', {},
        h('h3', { class: 'section' }, many ? `יישור ${sel().length} שכבות (זו לזו)` : 'יישור בדף'),
        h('div', { class: 'tiles six' },
            a('⇥', 'ימין', () => align('right')), a('↔', 'מרכז', () => align('cx')), a('⇤', 'שמאל', () => align('left')),
            a('⤒', 'למעלה', () => align('top')), a('↕', 'אמצע', () => align('cy')), a('⤓', 'למטה', () => align('bottom'))),
        sel().length > 2 ? h('div', { class: 'row-actions' },
            h('button', { class: 'btn ghost', onclick: () => distribute('x') }, '⟷ פיזור שווה לרוחב'),
            h('button', { class: 'btn ghost', onclick: () => distribute('y') }, '⟷ פיזור שווה לגובה')) : null,
        h('h3', { class: 'section' }, 'סדר'),
        h('div', { class: 'menu-list' },
            h('button', { class: 'menu-item', onclick: () => move(Infinity) }, '⤒ לראש'),
            h('button', { class: 'menu-item', onclick: () => move(1) }, '↑ קדימה'),
            h('button', { class: 'menu-item', onclick: () => move(-1) }, '↓ אחורה'),
            h('button', { class: 'menu-item', onclick: () => move(-Infinity) }, '⤓ לתחתית'))));
}

/**
 * יישור: שכבה אחת — לדף; כמה — למסגרת המשותפת שלהן. לפי x/y/w/h של המסמך (בלי סיבוב) — זה מה שהמשתמש
 * מצפה לו גם בשכבה מסובבת קלות.
 */
function align(how) {
    const ls = sel().filter(l => !l.locked);
    if (!ls.length) return;
    const box = ls.length > 1
        ? { x: Math.min(...ls.map(l => l.x)), y: Math.min(...ls.map(l => l.y)), r: Math.max(...ls.map(l => l.x + l.w)), b: Math.max(...ls.map(l => l.y + l.h)) }
        : { x: 0, y: 0, r: ed.doc.page.width, b: ed.doc.page.height };
    for (const l of ls) {
        if (how === 'left') l.x = box.x;
        if (how === 'right') l.x = box.r - l.w;
        if (how === 'cx') l.x = (box.x + box.r) / 2 - l.w / 2;
        if (how === 'top') l.y = box.y;
        if (how === 'bottom') l.y = box.b - l.h;
        if (how === 'cy') l.y = (box.y + box.b) / 2 - l.h / 2;
    }
    commit('יישור');
}

/** פיזור שווה: הראשונה והאחרונה במקומן, והרווחים ביניהן שווים. */
function distribute(axis) {
    const ls = sel().filter(l => !l.locked);
    if (ls.length < 3) return;
    const [p, s] = axis === 'x' ? ['x', 'w'] : ['y', 'h'];
    ls.sort((a, b) => a[p] - b[p]);
    const total = ls.reduce((t, l) => t + l[s], 0);
    const span = ls[ls.length - 1][p] + ls[ls.length - 1][s] - ls[0][p];
    const gap = (span - total) / (ls.length - 1);
    let at = ls[0][p];
    for (const l of ls) { l[p] = at; at += l[s] + gap; }
    commit();
}

// ─── הוספה ────────────────────────────────────────────────────────────────

function addSheet() {
    const t = (icon, label, fn) => tile(icon, label, () => { closeSheet(); fn(); });
    const basic = () => h('div', {},
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
            t('➡️', 'חץ', () => addLayer({ ...shapeLayer(ed.doc, 'arrow'), h: Math.min(ed.doc.page.width, ed.doc.page.height) * 0.08 }))));
    const styles = () => h('div', { class: 'style-grid' }, TEXT_STYLES.map(p => {
        ensureFont(p.font, p.weight);
        const light = p.dark || (parseInt(p.color.slice(1, 3), 16) * 0.3 + parseInt(p.color.slice(3, 5), 16) * 0.59 + parseInt(p.color.slice(5, 7), 16) * 0.11 > 170 && !p.background && !p.stroke);
        return h('button', { class: 'style-pick' + (light ? ' dark' : ''), onclick: () => { closeSheet(); addStyledText(p); } },
            h('span', { style: styleCss(p) }, p.sample));
    }));
    const elements = () => h('div', {}, Object.entries(ELEMENT_GROUPS).flatMap(([g, names]) => [
        h('h3', { class: 'section' }, g),
        h('div', { class: 'el-grid' }, names.map(name => h('button', { class: 'el-pick', 'aria-label': ELEMENTS[name].label, title: ELEMENTS[name].label,
            onclick: () => { closeSheet(); addLayer(elementLayer(ed.doc, name)); } }, elementSvg(ELEMENTS[name])))),
    ]));
    const icons = () => iconPicker(name => { closeSheet(); addLayer(iconLayer(ed.doc, name)); });
    const panels = { 'בסיסי': basic, 'טקסטים מעוצבים': styles, 'אלמנטים': elements, 'אייקונים': icons, '✨ AI': aiAddPanel };
    sheet('הוספה', tabs(Object.keys(panels), name => panels[name](), 'add'), { wide: true });
}

/**
 * לשוניות: שורת כפתורים מעל אזור אחד שמתמלא מחדש בבחירה. הלשונית האחרונה נזכרת (לפי key) —
 * מי שמוסיף אייקון אחרי אייקון לא צריך לחזור אליה כל פעם.
 */
const lastTab = {};
function tabs(names, render, key) {
    const panel = h('div', { class: 'tab-panel', role: 'tabpanel', tabindex: '-1' });
    const bar = h('div', { class: 'tabbar', role: 'tablist' });
    const btns = names.map((name, i) => h('button', { role: 'tab', class: 'tab', onclick: () => pick(i), onkeydown: e => {
        // חצים בין לשוניות, לפי כיוון הקריאה (ימין לשמאל: חץ שמאלה — הבאה).
        const d = { ArrowLeft: 1, ArrowRight: -1 }[e.key];
        if (d) { e.preventDefault(); const j = (i + d + names.length) % names.length; pick(j); btns[j].focus(); }
    } }, name));
    bar.append(...btns);
    const pick = i => {
        btns.forEach((b, j) => { b.setAttribute('aria-selected', String(i === j)); b.tabIndex = i === j ? 0 : -1; });
        panel.replaceChildren(render(names[i], i));
        if (key) lastTab[key] = i;
    };
    pick(Math.min(lastTab[key] || 0, names.length - 1));
    return h('div', { class: 'tabset' }, bar, panel);
}

/** בחירת אייקון: קבוצות וחיפוש (בעברית או באנגלית). ‏current — מסמן את הנוכחי (בעריכה). */
function iconPicker(onPick, current) {
    const grid = h('div', { class: 'icon-grid' });
    const show = names => {
        grid.replaceChildren(...(names.length ? names.filter(n => ICONS[n]).map(name =>
            h('button', { class: 'icon-pick' + (name === current ? ' on' : ''), 'aria-label': ICONS[name].label, title: ICONS[name].label,
                'aria-pressed': current === undefined ? undefined : String(name === current),
                onclick: e => {
                    if (current !== undefined) {
                        current = name;
                        grid.querySelectorAll('.icon-pick').forEach(b => { b.classList.toggle('on', b === e.currentTarget); b.setAttribute('aria-pressed', String(b === e.currentTarget)); });
                    }
                    onPick(name);
                } }, iconSvg(ICONS[name]))) : [h('p', { class: 'muted' }, 'לא נמצא אייקון כזה')]));
    };
    const groups = Object.keys(ICON_GROUPS);
    const chips = groups.map(g => h('button', { class: 'chip-btn', 'aria-pressed': 'false', onclick: () => choose(g) }, g));
    const choose = g => {
        search.value = '';
        chips.forEach((c, i) => c.setAttribute('aria-pressed', String(groups[i] === g)));
        show(ICON_GROUPS[g]);
    };
    const search = h('input', { type: 'search', class: 'search', placeholder: 'חיפוש: לב, עוגה, טלפון…', 'aria-label': 'חיפוש אייקון', oninput: () => {
        const q = search.value.trim().toLowerCase();
        if (!q) { choose(groups.find(g => ICON_GROUPS[g].includes(current)) || groups[0]); return; }
        chips.forEach(c => c.setAttribute('aria-pressed', 'false'));
        show(Object.keys(ICONS).filter(n => n.includes(q) || ICONS[n].label.toLowerCase().includes(q)));
    } });
    choose(groups.find(g => ICON_GROUPS[g].includes(current)) || groups[0]);
    return h('div', {}, search, h('div', { class: 'chips' }, chips), grid);
}

/** תצוגת אלמנט לכפתור — ‏SVG בצמתים, בצבע הטקסט. */
function elementSvg(el) {
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${el.vb[0]} ${el.vb[1]}`);
    svg.setAttribute('aria-hidden', 'true');
    const p = document.createElementNS(NS, 'path');
    p.setAttribute('d', el.d);
    if (el.stroke) { p.setAttribute('fill', 'none'); p.setAttribute('stroke', 'currentColor'); p.setAttribute('stroke-width', String(Math.max(...el.vb) * 0.035)); p.setAttribute('stroke-linecap', 'round'); }
    else p.setAttribute('fill', 'currentColor');
    svg.append(p);
    return svg;
}

/** סגנון טקסט מוכן בכפתור — אותו פונט וצבע, בגודל של כפתור. */
function styleCss(p) {
    const px = 21;
    const shadows = [];
    if (p.shadow) shadows.push(`${p.shadow.x * px}px ${p.shadow.y * px}px ${p.shadow.blur * px}px ${p.shadow.color}`);
    if (p.glow) for (let i = 0; i < (p.glow.strength || 1); i++) shadows.push(`0 0 ${p.glow.blur * px}px ${p.glow.color}`);
    const b = p.background;
    return {
        fontFamily: `"${p.font}"`, fontWeight: String(nearestWeight(p.font, p.weight)), fontStyle: p.italic ? 'italic' : 'normal',
        fontSize: px + 'px', color: p.color, lineHeight: '1.2',
        // מעבר צבעים בתוך האות — רקע שנחתך לפי האותיות
        ...(p.gradient ? { backgroundImage: gradientCss(p.gradient), WebkitBackgroundClip: 'text', backgroundClip: 'text', WebkitTextFillColor: 'transparent' } : {}),
        ...(b ? { backgroundColor: b.opacity === 0 ? 'transparent' : b.color, padding: '2px 10px', borderRadius: '999px',
            border: b.border ? `${Math.max(1, b.border.width * px)}px solid ${b.border.color}` : 'none' } : {}),
        textShadow: shadows.join(', ') || 'none',
        WebkitTextStroke: p.stroke ? `${Math.max(1, p.stroke.width * px)}px ${p.stroke.color}` : '0', paintOrder: 'stroke fill',
    };
}

/** טקסט בסגנון מוכן: כל המידות יחסיות לגודל הטקסט, שיחסי לדף. */
function addStyledText(p) {
    const { width: W, height: H } = ed.doc.page;
    const size = Math.round(Math.min(W, H) * p.size);
    const l = textLayer(ed.doc, size > Math.min(W, H) * 0.08 ? 'title' : 'body');
    l.text = p.sample;
    l.h = size * 1.3;
    l.y = H / 2 - size;
    l.style.size = size;
    applyTextStyle(l.style, p, size);
    addLayer(l);
    editTextInPlace(l, true);
    ensureFont(p.font, p.weight).then(() => ed?.canvas.render());
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
    const s = l.style;
    const ta = h('textarea', {
        class: 'inplace', value: l.text, dir: s.dir === 'ltr' ? 'ltr' : 'rtl', 'aria-label': 'עריכת טקסט',
        style: {
            fontFamily: `"${s.font}"`, fontWeight: nearestWeight(s.font, s.weight), fontStyle: s.italic ? 'italic' : 'normal',
            lineHeight: s.lineHeight || 1.2, color: s.color, textAlign: s.align,
        },
    });
    const grow = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; };
    // התיבה יושבת על הטקסט שבדף — וזזה איתו כשהתצוגה זזה או מתקרבת (מקלדת שנפתחת, זום)
    const place = () => {
        const r = ed.canvas.screenRect(l.id);
        if (!r) return;
        Object.assign(ta.style, {
            left: r.x + 'px', top: r.y + 'px', width: l.w * r.zoom + 'px', minHeight: s.size * (s.lineHeight || 1.2) * r.zoom + 'px',
            fontSize: s.size * r.zoom + 'px', transform: `rotate(${r.rotation}deg)`,
        });
        grow();
    };
    // נגיעה בקנבס מחוץ לתיבה מסיימת את העריכה — הזזת המסך או בחירת טקסט אחר לא "גוררות" איתן את התיבה
    const outside = e => { if (e.target !== ta) { log('inplace-end', { why: 'touch-outside' }); finish(true); } };
    let done = false;
    const finish = save => {
        if (done) return;
        done = true;
        if (save) l.text = ta.value;
        ed.stageEl.removeEventListener('pointerdown', outside, true);
        if (ed.onView === place) ed.onView = null;
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
    place();
    ed.onView = place;
    ed.stageEl.addEventListener('pointerdown', outside, true);
    log('inplace-start', { id: l.id });
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
    else if (l.type === 'icon') body = iconProps(l);
    else body = shapeProps(l);
    sheet({ text: 'עיצוב טקסט', image: 'תמונה', icon: 'אייקון' }[l.type] || 'צורה', body, { onClose: commit });
}

/** ‏SVG של אייקון לכפתור — נבנה בצמתים (לא innerHTML). */
function iconSvg(ic) {
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const p = document.createElementNS(NS, 'path');
    p.setAttribute('d', ic.d);
    svg.append(p);
    return svg;
}

function iconProps(l) {
    const ic = ICONS[l.icon];
    return h('div', {},
        iconPicker(name => {
            l.icon = name;
            if (!ICONS[name].filled) delete l.filled;
            commit();
        }, l.icon),
        colorField('צבע', l.color, live(v => { l.color = v; }), commit),
        ic?.filled ? toggle('מלא', !!l.filled, v => { if (v) l.filled = true; else delete l.filled; commit(); }) : null,
        rangeField('עובי קו', { min: 0.5, max: 4, step: 0.25, value: l.strokeWidth || 2, onInput: live(v => { l.strokeWidth = v; }), onCommit: commit }),
        rangeField('שקיפות', { min: 0, max: 100, value: Math.round((l.opacity ?? 1) * 100), unit: '%', onInput: live(v => { l.opacity = v / 100; }), onCommit: commit }),
        toggle('צל', !!l.shadow, v => { if (v) l.shadow = { x: 0, y: 6, blur: 16, color: '#00000055' }; else delete l.shadow; commit(); }));
}

/**
 * חיתוך תמונה: כמה לקצוץ מכל צד (באחוזים), או יחס מוכן. קנה המידה של התמונה נשמר — השכבה מתכווצת
 * סביב מה שנשאר, והחלק שנשאר לא זז בדף.
 */
function cropSheet(l) {
    const img = ed.canvas.images.get(l.asset);
    if (!img) { toast('התמונה עוד לא נטענה', 'error'); return; }
    const nw = img.naturalWidth, nh = img.naturalHeight;
    const c0 = l.crop || { x: 0, y: 0, w: 1, h: 1 };
    const scale = l.w / (c0.w * nw);           // פיקסלי דף לכל פיקסל של התמונה המקורית
    const cut = { top: c0.y, bottom: 1 - c0.y - c0.h, left: c0.x, right: 1 - c0.x - c0.w };
    const apply = () => {
        const c = { x: cut.left, y: cut.top, w: Math.max(0.02, 1 - cut.left - cut.right), h: Math.max(0.02, 1 - cut.top - cut.bottom) };
        const prev = l.crop || { x: 0, y: 0, w: 1, h: 1 };
        // ‏x/y — הפינה השמאלית-עליונה של מה שנשאר; היא זזה בדיוק כמו קצה החיתוך, כך שהתמונה לא "קופצת".
        l.x += (c.x - prev.x) * nw * scale;
        l.y += (c.y - prev.y) * nh * scale;
        l.w = c.w * nw * scale;
        l.h = c.h * nh * scale;
        if (c.x === 0 && c.y === 0 && c.w === 1 && c.h === 1) delete l.crop; else l.crop = c;
        ed.canvas.render();
    };
    const slider = (label, key) => rangeField(label, { min: 0, max: 90, value: Math.round(cut[key] * 100), unit: '%', onInput: v => { cut[key] = v / 100; apply(); }, onCommit: commit });
    /** יחס מוכן, במרכז התמונה. */
    const ratio = r => {
        let w = 1, hh = 1;
        if (nw / nh > r) w = (nh * r) / nw; else hh = nw / r / nh;
        Object.assign(cut, { left: (1 - w) / 2, right: (1 - w) / 2, top: (1 - hh) / 2, bottom: (1 - hh) / 2 });
        apply(); commit(); closeSheet(); cropSheet(l);
    };
    sheet('חיתוך', h('div', {},
        h('div', { class: 'row-actions' },
            h('button', { class: 'btn ghost', onclick: () => ratio(1) }, 'ריבוע'),
            h('button', { class: 'btn ghost', onclick: () => ratio(4 / 5) }, '4:5'),
            h('button', { class: 'btn ghost', onclick: () => ratio(9 / 16) }, '9:16'),
            h('button', { class: 'btn ghost', onclick: () => ratio(16 / 9) }, '16:9'),
            h('button', { class: 'btn ghost', onclick: () => { Object.assign(cut, { top: 0, bottom: 0, left: 0, right: 0 }); apply(); commit(); closeSheet(); cropSheet(l); } }, 'בלי חיתוך')),
        slider('מלמעלה', 'top'), slider('מלמטה', 'bottom'), slider('מימין', 'right'), slider('משמאל', 'left')), { onClose: commit });
}

const WEIGHT_NAMES = { 100: 'דק מאוד', 200: 'דק', 300: 'קל', 400: 'רגיל', 500: 'בינוני', 600: 'חצי מודגש', 700: 'מודגש', 800: 'עבה', 900: 'שחור' };

/**
 * אפקט שאפשר להדליק ולכבות: המתג, ומתחתיו הכוונונים — רק כשהוא דולק. הכוונונים נבנים מחדש בכל הדלקה,
 * כדי שיופיעו מיד (בלי לסגור ולפתוח את הגיליון).
 */
function effectBox(label, isOn, on, off, controls) {
    const body = h('div', { class: 'fx-body' });
    const draw = () => body.replaceChildren(...(isOn() ? [].concat(controls()).filter(Boolean) : []));
    draw();
    return h('div', { class: 'fx' }, toggle(label, isOn(), v => { if (v) on(); else off(); commit(); draw(); }), body);
}

/** צבע עם שקיפות (#rrggbbaa) → שני כוונונים: צבע ושקיפות. */
function colorAlphaFields(label, get, set) {
    const hex = () => (get() || '#000000').slice(0, 7);
    const alpha = () => { const v = get() || ''; return v.length === 9 ? Math.round(parseInt(v.slice(7), 16) / 2.55) : 100; };
    const join = (c, a) => c + (a >= 100 ? '' : Math.round(a * 2.55).toString(16).padStart(2, '0'));
    return h('div', { class: 'two' },
        colorField(label, hex(), live(v => set(join(v, alpha()))), commit),
        rangeField('שקיפות', { min: 0, max: 100, value: alpha(), unit: '%', onInput: live(v => set(join(hex(), v))), onCommit: commit }));
}

/** גרסאות קודמות מהשרת (אחת לכל היותר ל-10 דקות, עד 30): רשימה ושחזור. השחזור — צעד אחד ב"ביטול". */
async function versionsSheet() {
    if (!sync.currentUser()) { toast('גרסאות קודמות נשמרות בשרת — צריך להיות מחובר', 'error'); return; }
    const body = h('div', {}, h('p', { class: 'hint' }, 'טוען…'));
    sheet('🕘 גרסאות קודמות', body);
    try {
        await saveNow();
        await sync.push(ed.id).catch(() => {});
        const list = await sync.listVersions(ed.id);
        const when = at => new Date(at).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' });
        body.replaceChildren(
            h('p', { class: 'hint' }, 'נשמרת גרסה לכל היותר פעם ב-10 דקות של עבודה, עד 30 אחרונות. שחזור מחליף את העיצוב — ו-↶ מחזיר את מה שהיה.'),
            list.length ? h('ul', { class: 'my-fonts' }, list.map(v => h('li', {},
                h('span', {}, when(v.at)), h('span', { class: 'muted small' }, timeAgo(v.at)),
                h('button', { class: 'btn ghost sm', onclick: () => restoreVersion(v) }, 'שחזור'))))
                : h('p', { class: 'empty' }, 'עוד אין גרסאות קודמות — הן נוצרות תוך כדי עבודה, פעם ב-10 דקות.'));
    } catch (e) {
        body.replaceChildren(h('p', { class: 'empty' }, 'הגרסאות לא נטענו: ' + (e.message || e)));
    }
    body.append(await journalSection());
}

const APP_NAMES = { 'greeting-cards': 'סטודיו העיצוב', 'raster-studio': 'עיצוב רסטר', 'vector-studio': 'וקטורי', 'layout-studio': 'עימוד', 'word-processor': 'עורך התמלילים' };
const STAGE_LABEL = { create: 'נוצר', edit: 'עריכה', export: 'ייצוא', return: 'חזר', merge: 'מיזוג', restore: 'שחזור', handoff: 'הועבר', review: 'הגהה' };

/** 📜 שלבי הקובץ: היומן שהליבה כותבת בכל שמירה של ‎.mbe — מעברים בין אפליקציות ואנשים, עם עותק לכל גרסה. */
async function journalSection() {
    const base = ed?.doc.mbeBase ? await assets.get(ed.doc.mbeBase).catch(() => null) : null;
    if (!base) return h('div');
    let stages = [];
    try { stages = await mbeJournal(base); } catch { return h('div'); }
    if (!stages.length) return h('div');
    const when = at => at ? new Date(at).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
    return h('div', {},
        h('h3', { class: 'section' }, '📜 שלבי הקובץ (‎.mbe)'),
        h('p', { class: 'hint' }, 'כל פעם שהעיצוב נשמר כ-‎.mbe — בהורדה, בפתיחה ברסטר / בווקטורי ובחזרה — נרשם שלב, עם עותק של אותה גרסה.'),
        h('ul', { class: 'my-fonts' }, stages.slice().reverse().map((st, i) => h('li', {},
            h('span', {}, `${STAGE_LABEL[st.kind] || st.kind}${st.app ? ' · ' + (APP_NAMES[st.app] || st.app) : ''}${st.note ? ' — ' + st.note : ''}`),
            h('span', { class: 'muted small' }, when(st.at)),
            // השלב האחרון הוא הגרסה הנוכחית (שמירות רצופות באותה אפליקציה מעדכנות אותו)
            i === 0 ? h('span', { class: 'muted small' }, 'הנוכחית')
            : st.pruned || !st.result ? h('span', { class: 'muted small' }, 'נגזם')
                : h('button', { class: 'btn ghost sm', onclick: () => restoreStage(st, base) }, 'שחזור')))));
}

async function restoreStage(st, base) {
    const id = ed.id;
    try {
        toast('משחזר…');
        const r = await restoreFromJournal(base, st.id || st.result);
        if (ed?.id !== id) return;
        r.doc.title = ed.doc.title;
        r.doc.mbeBase = await assets.put(new Blob([r.bytes], { type: 'application/vnd.mbe+zip' }));
        for (const [aid, img] of r.loaded) ed.canvas.setImage(aid, img);
        await ensureDocFonts(r.doc);
        ed.doc = r.doc;
        ed.canvas.setDoc(r.doc);
        ed.canvas.select([]);
        commit();
        closeSheet();
        log('journal-restore', { stage: st.id || '', kind: st.kind });
        toast('שוחזר ✓ — ↶ מחזיר את מה שהיה');
    } catch (e) {
        toast('השחזור נכשל: ' + (e.message || e), 'error');
    }
}

async function restoreVersion(v) {
    const id = ed.id;
    try {
        toast('משחזר…');
        const doc = await sync.fetchVersion(id, v.id);
        if (ed?.id !== id) return;
        doc.title = ed.doc.title;
        for (const [aid, a] of Object.entries(doc.assets || {})) {
            const blob = await assets.get(a.src);
            if (blob) ed.canvas.setImage(aid, await blobToImage(blob).catch(() => null));
        }
        await ensureDocFonts(doc);
        ed.doc = doc;
        ed.canvas.setDoc(doc);
        ed.canvas.select([]);
        commit();
        closeSheet();
        log('version-restore', { v: v.id });
        toast('שוחזר ✓ — ↶ מחזיר את מה שהיה');
    } catch (e) {
        toast('השחזור נכשל: ' + (e.message || e), 'error');
    }
}

/**
 * העלאת גופנים (TTF, OTF, WOFF, WOFF2): השם והעובי נקראים מהקובץ. גשר עד ספריית הגופנים של ענן החבילה — הגופן נשמר
 * במכשיר, ומוטמע בכל עיצוב שמשתמש בו. מחזיר את מה שנוסף.
 */
/** ספריית הגופנים של ענן החבילה → "שלי" במכשיר. ברקע, פעם בטעינה; בלי חיבור (או בלי הרשאת libs) — שקט. */
let cloudFontsP = null;
function cloudFonts(force = false) {
    if (force || !cloudFontsP) cloudFontsP = (async () => {
        const caps = await aiCaps();
        if (!caps.connected) return false;
        try {
            const n = await syncCloudFonts(drives, caps.cloud);
            log('cloud-fonts', { down: n, total: myFonts().filter(m => m.lib).length });
            return caps;
        } catch (e) { log('cloud-fonts-fail', { code: e.code || '', msg: e.message }); return false; }
    })();
    return cloudFontsP;
}

async function uploadFonts() {
    const cloud = await cloudFonts();
    // אזהרת הרישיון — לפני ההעלאה (הכרעה 7 בחבילה): גופן שהועלה מוטמע בכל עיצוב, ונשמר בספרייה בענן
    let ack = false;
    try { ack = sessionStorage.getItem('gc-font-ack') === '1'; } catch { /* אין אחסון */ }
    if (!ack) {
        if (!await confirmDialog('ודאו שהרישיון של הגופן מתיר הטמעה בקבצים ושימוש בעיצובים שלכם.'
            + (cloud ? ' הגופן יישמר גם בספריית הגופנים של ענן החבילה — לכל האפליקציות.' : ''), 'הבנתי, להעלות')) return [];
        try { sessionStorage.setItem('gc-font-ack', '1'); } catch { /* אין אחסון */ }
    }
    // בלי סינון: באנדרואיד הסיומות של גופן לא ממופות לסוג שהבורר מכיר, והוא מציג "אין קבצים" (כמו ‎.gcard).
    // מה שאינו גופן נדחה לפי התוכן (addMyFont טוען אותו לפני שהוא נכנס לרשימה).
    const files = await pickFiles('', true);
    const added = [];
    for (const f of files) {
        if (f.size > 15 * 1024 * 1024) { toast(`"${f.name}" גדול מ-15MB`, 'error'); continue; }
        try {
            let e = await addMyFont(f, await readFontInfo(f));
            if (cloud) e = await saveFontToCloud(drives, cloud.cloud, e).catch(err => { toast(`"${e.family}" נשמר במכשיר; לספרייה בענן — לא: ${aiMessage(err)}`, 'error'); return e; });
            added.push(e);
            log('font-upload', { family: e.family, weight: e.weight, bytes: f.size, cloud: e.lib ? 1 : 0 });
        } catch { toast(`"${f.name}" אינו קובץ גופן תקין`, 'error'); }
    }
    if (added.length) toast(added.length === 1 ? `הגופן "${added[0].family}" נוסף ✓` : `${added.length} גופנים נוספו ✓`);
    return added;
}

const WEIGHT_LABEL = w => WEIGHT_NAMES[w] || String(w);

function myFontsSheet() {
    const draw = () => {
        const list = myFonts();
        const toCloud = async m => {
            try { await saveFontToCloud(drives, cloud.cloud, m); toast(`"${m.family}" נשמר בספרייה ✓`); }
            catch (e) { toast(aiMessage(e), 'error'); }
            body.replaceChildren(draw());
        };
        return h('div', {},
            h('p', { class: 'hint' }, 'גופנים שהעלית מופיעים ברשימת הפונטים של כל טקסט, ומוטמעים בכל עיצוב שמשתמש בהם — כך הוא נפתח נכון גם במכשיר אחר ובקובץ. ודאו שהרישיון של הגופן מתיר את השימוש.'),
            list.length ? h('ul', { class: 'my-fonts' }, list.map(m => h('li', {},
                h('span', { style: { fontFamily: `"${m.family}"`, fontWeight: String(m.weight), fontStyle: m.style } }, m.family),
                h('span', { class: 'muted small' }, WEIGHT_LABEL(m.weight) + (m.style === 'italic' ? ' · נטוי' : '') + (m.lib ? ' · ☁️' : '')),
                cloud && !m.lib ? h('button', { class: 'icon-btn', title: 'שמירה בספרייה בענן', 'aria-label': `שמירת ${m.family} ${WEIGHT_LABEL(m.weight)} בספרייה בענן`, onclick: () => toCloud(m) }, '☁️') : null,
                h('button', { class: 'icon-btn', 'aria-label': `הסרת ${m.family} ${WEIGHT_LABEL(m.weight)}`, onclick: () => { removeMyFont(m); body.replaceChildren(draw()); } }, '🗑️'))))
                : h('p', { class: 'empty' }, 'עוד לא הועלו גופנים.'),
            h('button', { class: 'btn primary block', onclick: async () => { if ((await uploadFonts()).length) body.replaceChildren(draw()); } }, '＋ העלאת גופן'),
            h('p', { class: 'hint' }, cloud ? 'מחובר לספריית הגופנים של ענן החבילה: מה שמסומן ☁️ זמין בכל אפליקציות החבילה. ☁️ ליד גופן — שמירה בספרייה.'
                : 'ספריית גופנים אחת לכל אפליקציות החבילה — אחרי חיבור לענן החבילה (👤 ← ☁️). עד אז הגופנים במכשיר הזה.'));
    };
    let cloud = null;
    const body = h('div', {}, draw());
    sheet('הגופנים שלי', body);
    // הספרייה בענן — ברקע: מה שחסר יורד, והרשימה מתעדכנת
    cloudFonts(true).then(c => { cloud = c; body.replaceChildren(draw()); });
}

/** צבע בהיר? — לברירות מחדל שמנוגדות לטקסט (רקע כהה לטקסט בהיר, ולהפך). */
const isLight = c => { const n = parseInt((c || '#000000').slice(1, 7), 16); return ((n >> 16) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 1000 > 150; };

function textProps(l) {
    const s = l.style;
    // ההעלאה והגופנים שלי — ראשונים, כדי שלא יתחבאו בתחתית הרשימה הנגללת
    const fontList = h('div', { class: 'font-list', role: 'radiogroup', 'aria-label': 'פונט' }, h('button', { class: 'font-opt add', onclick: async () => {
        const added = await uploadFonts();
        if (!added.length) return;
        s.font = added[0].family;
        s.weight = added[0].weight;
        commit();
        closeSheet();
        propsSheet(l);
    } }, '＋ העלאת גופן'), [...FONTS.filter(f => f.custom), ...FONTS.filter(f => !f.custom)].map(f => {
        const btn = h('button', { class: 'font-opt' + (f.family === s.font ? ' on' : '') + (f.custom ? ' mine' : ''), role: 'radio', 'aria-checked': String(f.family === s.font), style: { fontFamily: `"${f.family}"` },
            title: f.custom ? 'גופן שהעלית' : undefined,
            onclick: async () => {
                s.font = f.family;
                await ensureFont(s.font, s.weight);
                fontList.querySelectorAll('.font-opt').forEach(x => { x.classList.toggle('on', x === btn); x.setAttribute('aria-checked', String(x === btn)); });
                weightBox.replaceChildren(weightField());
                commit();
            } }, f.family);
        ensureFont(f.family, 400);
        return btn;
    }));
    // עובי האות: רק העוביים שיש לפונט (בלי "מודגש מזויף"); הסרגל קופץ ביניהם
    const weightField = () => {
        const ws = FONTS.find(f => f.family === s.font)?.weights || [400];
        if (ws.length === 1) return h('p', { class: 'hint' }, `לפונט ${s.font} יש עובי אחד בלבד`);
        const idx = Math.max(0, ws.indexOf(nearestWeight(s.font, s.weight)));
        const name = h('span', { class: 'muted' }, ' ' + WEIGHT_NAMES[ws[idx]]);
        const id = 'w' + l.id;
        const range = h('input', { type: 'range', id, min: 0, max: ws.length - 1, step: 1, value: idx, 'aria-valuetext': WEIGHT_NAMES[ws[idx]] });
        range.addEventListener('input', async () => {
            const w = ws[+range.value];
            s.weight = w;
            name.textContent = ' ' + WEIGHT_NAMES[w];
            range.setAttribute('aria-valuetext', WEIGHT_NAMES[w]);
            await ensureFont(s.font, w);
            ed.canvas.render();
        });
        range.addEventListener('change', commit);
        return h('div', { class: 'field' }, h('label', { for: id }, 'עובי האות', name), range);
    };
    const weightBox = h('div', {}, weightField());
    // מילוי: צבע אחד, או מעבר צבעים בתוך האותיות
    const fillBox = h('div', {});
    const drawFill = () => fillBox.replaceChildren(
        seg('מילוי האות', [{ value: 'solid', label: 'צבע אחד' }, { value: 'grad', label: 'מעבר צבעים' }], s.gradient ? 'grad' : 'solid', v => {
            if (v === 'grad') s.gradient = { type: 'linear', angle: 180, colors: [s.color, isLight(s.color) ? '#d98e04' : '#ffd166'] };
            else { s.color = s.gradient?.colors[0] || s.color; delete s.gradient; }
            commit(); drawFill();
        }),
        s.gradient ? gradientEditor(s.gradient) : colorField('צבע', s.color, live(v => { s.color = v; }), commit));
    drawFill();
    const cols = ed.doc.merge?.columns || [];
    const big = s.size;
    return h('div', {},
        cols.length ? h('div', { class: 'vars' }, h('span', { class: 'muted small' }, 'הוספת משתנה:'),
            cols.slice(0, 12).map(c => h('button', { class: 'chip-btn', onclick: () => { l.text = (l.text ? l.text + ' ' : '') + `{{${c}}}`; commit(); toast(`נוסף {{${c}}}`); } }, c)))
            : h('p', { class: 'hint' }, 'כרטיס לכל נמען: 👥 נמענים ← טעינת רשימה, ואז כאן "הוספת משתנה".'),
        fontList,
        rangeField('גודל', { log: true, min: 4, max: Math.round(Math.max(ed.doc.page.width, ed.doc.page.height) / 3), value: s.size, onInput: live(v => { s.size = v; }), onCommit: commit }),
        weightBox,
        fillBox,
        seg('יישור', [{ value: 'right', icon: '⇥', label: 'ימין' }, { value: 'center', icon: '↔', label: 'מרכז' }, { value: 'left', icon: '⇤', label: 'שמאל' }, { value: 'justify', icon: '☰', label: 'מיושר' }], s.align, v => { s.align = v; commit(); }),
        seg('גרירת פינה', [{ value: 'scale', label: 'מגדילה את האותיות' }, { value: 'box', label: 'רק את התיבה' }], s.boxResize ? 'box' : 'scale', v => {
            if (v === 'box') s.boxResize = true; else delete s.boxResize;
            commit();
            ed.canvas.select(ed.selection);   // הידיות מתעדכנות (בפינה חופשית — בלי יחס קבוע)
        }),
        rangeField('ריווח שורות', { min: 0.7, max: 3, step: 0.05, value: s.lineHeight || 1.2, onInput: live(v => { s.lineHeight = v; }), onCommit: commit }),
        rangeField('שקיפות', { min: 0, max: 100, value: Math.round((l.opacity ?? 1) * 100), unit: '%', onInput: live(v => { l.opacity = v / 100; }), onCommit: commit }),
        h('h3', { class: 'section' }, 'סגנון מוכן'),
        h('p', { class: 'hint' }, 'הטקסט והגודל נשארים; משם אפשר לכוונן כל אפקט למטה.'),
        h('div', { class: 'style-strip' }, TEXT_STYLES.filter(p => p.name).map(p => {
            ensureFont(p.font, p.weight);
            return h('button', { class: 'style-pick' + (p.dark ? ' dark' : ''), 'aria-label': p.name, title: p.name, onclick: async () => {
                applyTextStyle(s, p);
                await ensureFont(s.font, s.weight);
                commit();
                closeSheet();
                propsSheet(l);
            } }, h('span', { style: styleCss(p) }, p.name));
        })),
        h('h3', { class: 'section' }, 'אפקטים'),
        effectBox('קו מתאר (מסגרת לאותיות)', () => !!s.stroke,
            () => { s.stroke = { width: Math.max(1, Math.round(big * 0.04)), color: isLight(s.color) ? '#16192b' : '#ffffff' }; }, () => { delete s.stroke; },
            () => [colorField('צבע הקו', s.stroke.color, live(v => { s.stroke.color = v; }), commit),
                rangeField('עובי הקו', { min: 0.5, max: Math.max(4, Math.round(big * 0.3)), step: 0.5, value: s.stroke.width, onInput: live(v => { s.stroke.width = v; }), onCommit: commit })]),
        effectBox('צל', () => !!s.shadow,
            () => { s.shadow = { x: 0, y: Math.round(big * 0.06), blur: Math.round(big * 0.2), color: '#00000088' }; }, () => { delete s.shadow; },
            () => {
                const sh = s.shadow;
                const pol = () => ({ d: Math.round(Math.hypot(sh.x, sh.y)), a: Math.round(((Math.atan2(sh.y, sh.x) * 180) / Math.PI + 360) % 360) });
                const setPol = (d, a) => { const r = (a * Math.PI) / 180; sh.x = Math.round(d * Math.cos(r) * 10) / 10; sh.y = Math.round(d * Math.sin(r) * 10) / 10; };
                return [colorAlphaFields('צבע הצל', () => sh.color, v => { sh.color = v; }),
                    rangeField('מרחק', { min: 0, max: Math.max(20, Math.round(big * 0.5)), value: pol().d, onInput: live(v => setPol(v, pol().a)), onCommit: commit }),
                    rangeField('כיוון', { min: 0, max: 355, step: 5, value: pol().a, unit: '°', onInput: live(v => setPol(pol().d || 1, v)), onCommit: commit }),
                    rangeField('טשטוש', { min: 0, max: Math.max(20, Math.round(big)), value: sh.blur, onInput: live(v => { sh.blur = v; }), onCommit: commit })];
            }),
        effectBox('הילה (זוהר סביב האותיות)', () => !!s.glow,
            () => { s.glow = { color: isLight(s.color) ? '#ffe9a8' : '#ffffff', blur: Math.round(big * 0.35), strength: 2 }; }, () => { delete s.glow; },
            () => [colorAlphaFields('צבע ההילה', () => s.glow.color, v => { s.glow.color = v; }),
                rangeField('גודל', { min: 1, max: Math.max(20, Math.round(big * 1.5)), value: s.glow.blur, onInput: live(v => { s.glow.blur = v; }), onCommit: commit }),
                rangeField('עוצמה', { min: 1, max: 4, value: s.glow.strength || 1, onInput: live(v => { s.glow.strength = v; }), onCommit: commit })]),
        effectBox('רקע לטקסט', () => !!s.background,
            () => { s.background = { color: isLight(s.color) ? '#1b2a4a' : '#ffffff', radius: Math.round(big * 0.4), padding: Math.round(big * 0.25) }; }, () => { delete s.background; },
            () => {
                const b = s.background;
                return [h('div', { class: 'two' },
                    colorField('צבע הרקע', b.color, live(v => { b.color = v; }), commit),
                    rangeField('שקיפות', { min: 0, max: 100, value: Math.round((b.opacity ?? 1) * 100), unit: '%', onInput: live(v => { if (v >= 100) delete b.opacity; else b.opacity = v / 100; }), onCommit: commit })),
                    rangeField('עיגול פינות', { min: 0, max: Math.max(20, Math.round(big * 1.5)), value: b.radius || 0, onInput: live(v => { b.radius = v; }), onCommit: commit }),
                    rangeField('ריווח מהטקסט', { min: 0, max: Math.max(20, Math.round(big * 1.5)), value: b.padding || 0, onInput: live(v => { b.padding = v; }), onCommit: commit }),
                    effectBox('מסגרת לרקע', () => !!b.border,
                        () => { b.border = { color: isLight(b.color) ? '#2d4a8a' : '#ffd166', width: Math.max(1, Math.round(big * 0.05)) }; }, () => { delete b.border; },
                        () => [colorField('צבע המסגרת', b.border.color, live(v => { b.border.color = v; }), commit),
                            rangeField('עובי המסגרת', { min: 0.5, max: Math.max(4, Math.round(big * 0.3)), step: 0.5, value: b.border.width, onInput: live(v => { b.border.width = v; }), onCommit: commit })])];
            }));
}

function imageProps(l) {
    l.filters ||= { brightness: 0, contrast: 0, saturation: 0, blur: 0 };
    const f = l.filters;
    return h('div', {},
        h('div', { class: 'row-actions' },
            h('button', { class: 'btn ghost', 'aria-pressed': String(!!l.flipX), onclick: () => { l.flipX = !l.flipX; commit(); } }, '⇋ היפוך אופקי'),
            h('button', { class: 'btn ghost', 'aria-pressed': String(!!l.flipY), onclick: () => { l.flipY = !l.flipY; commit(); } }, '⇵ היפוך אנכי'),
            h('button', { class: 'btn ghost', onclick: () => replaceImage(l) }, '🔄 החלפה'),
            h('button', { class: 'btn ghost', onclick: () => { closeSheet(); cropSheet(l); } }, '✂️ חיתוך')),
        imageAiRow(l),
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
    const canGrad = ['rect', 'ellipse', 'element'].includes(l.shape);
    const noFill = /^#[0-9a-f]{6}00$/i.test(l.fill);
    return h('div', {},
        l.gradient ? null : colorField('מילוי', l.fill, live(v => { l.fill = v; }), commit),
        canGrad && !l.gradient ? toggle('בלי מילוי (רק קו)', noFill, v => {
            if (v) { l.fill = '#00000000'; l.stroke ||= { width: Math.max(2, Math.min(l.w, l.h) * 0.03), color: '#1b1e26' }; } else l.fill = '#2d4a8a';
            commit(); closeSheet(); propsSheet(l);
        }) : null,
        canGrad && !noFill ? toggle('מדרג', !!l.gradient, v => {
            if (v) l.gradient = { type: 'linear', angle: 180, colors: [l.fill.slice(0, 7), '#ffffff'] }; else delete l.gradient;
            commit(); closeSheet(); propsSheet(l);
        }) : null,
        l.gradient ? gradientEditor(l.gradient) : null,
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
            const icon = { text: '🔤', image: '🖼️', icon: '⭐' }[l.type] || '⬛';
            return h('li', { class: 'layer-row' + (on ? ' on' : ''), 'data-id': l.id },
                h('button', { class: 'layer-main', 'aria-pressed': String(on), onclick: () => {
                    // בחירה מרובה (גם בטלפון, שאין בו Shift): הקשה מוסיפה או מורידה מהבחירה.
                    if (multi.checked) ed.canvas.select(on ? ed.selection.filter(x => x !== l.id) : [...ed.selection, l.id]);
                    else ed.canvas.select([l.id]);
                    draw();
                } },
                    h('span', { 'aria-hidden': 'true' }, icon), h('span', { class: 'layer-name' }, layerLabel(l)),
                    l.group ? h('span', { class: 'muted small', title: 'בקבוצה' }, '🔗') : null),
                h('button', { class: 'icon-btn', 'aria-label': l.hidden ? `הצגת ${layerLabel(l)}` : `הסתרת ${layerLabel(l)}`, 'aria-pressed': String(!!l.hidden), onclick: () => { l.hidden = !l.hidden; if (!l.hidden) delete l.hidden; commit(); draw(); } }, l.hidden ? '🚫' : '👁️'),
                h('button', { class: 'icon-btn', 'aria-label': l.locked ? `שחרור ${layerLabel(l)}` : `נעילת ${layerLabel(l)}`, 'aria-pressed': String(!!l.locked), onclick: () => { if (l.locked) delete l.locked; else l.locked = true; commit(); draw(); } }, l.locked ? '🔒' : '🔓'),
                h('button', { class: 'icon-btn', 'aria-label': `העלאת ${layerLabel(l)}`, onclick: () => { ed.canvas.select([l.id]); move(1); draw(); } }, '▲'),
                h('button', { class: 'icon-btn', 'aria-label': `הורדת ${layerLabel(l)}`, onclick: () => { ed.canvas.select([l.id]); move(-1); draw(); } }, '▼'));
        }) : [h('li', { class: 'empty' }, 'אין שכבות עדיין — ➕ הוספה')]));
    };
    const multi = h('input', { type: 'checkbox' });
    draw();
    sheet('שכבות', h('div', {},
        h('label', { class: 'toggle' }, multi, h('span', {}, 'בחירה מרובה — ליישור, פיזור והזזה יחד')),
        ul));
}

// ─── רקע וגודל הדף ────────────────────────────────────────────────────────

/** עריכת מדרג קיים: הצבעים, הסוג והזווית. משנה את האובייקט במקום. */
function gradientEditor(g) {
    return h('div', { class: 'grad-edit' },
        h('div', { class: 'two' }, g.colors.slice(0, 2).map((c, i) => colorField(i ? 'צבע סיום' : 'צבע התחלה', c, live(v => { g.colors[i] = v; }), commit))),
        seg('סוג', [{ value: 'linear', label: 'קווי' }, { value: 'radial', label: 'מעגלי' }], g.type, v => { g.type = v; commit(); }),
        g.type === 'radial' ? null : rangeField('זווית', { min: 0, max: 360, step: 5, value: g.angle ?? 180, unit: '°', onInput: live(v => { g.angle = v; }), onCommit: commit }));
}

function backgroundSheet() {
    const bgLayer = ed.doc.layers.find(l => l.role === 'background' && l.type === 'image');
    const pg = ed.doc.page;
    const reopen = () => { commit(); closeSheet(); backgroundSheet(); };
    const swatch = (label, css, on, fn) => h('button', { class: 'swatch' + (on ? ' on' : ''), 'aria-label': label, title: label, 'aria-pressed': String(on), style: { background: css }, onclick: fn });
    sheet('רקע', h('div', {},
        h('h3', { class: 'section' }, 'צבע'),
        h('div', { class: 'swatches' }, SOLIDS.map(c => swatch(c, c, !pg.gradient && pg.background === c, () => { pg.background = c; delete pg.gradient; reopen(); }))),
        pg.gradient ? null : colorField('צבע אחר', pg.background, live(v => { pg.background = v; }), commit),
        h('h3', { class: 'section' }, 'מדרג'),
        h('div', { class: 'swatches grad' }, GRADIENTS.map(g => swatch(g.name, gradientCss(g), pg.gradient && JSON.stringify(pg.gradient.colors) === JSON.stringify(g.colors),
            () => { pg.gradient = { type: g.type, angle: g.angle ?? 180, colors: g.colors.slice() }; pg.background = g.colors[0]; reopen(); }))),
        pg.gradient ? gradientEditor(pg.gradient) : null,
        pg.gradient ? h('button', { class: 'btn ghost', onclick: () => { delete pg.gradient; reopen(); } }, 'בלי מדרג') : null,
        h('h3', { class: 'section' }, 'תמונה'),
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
        info.textContent = fmt === 'mbe' ? 'קובץ חבילת היצירה — כל שכבה נשמרת, ונפתח גם בעיצוב הרסטר ובווקטורי'
            : fmt === 'pdf' ? `PDF בגודל הדף, ${W >= 1700 ? 300 : 150}dpi`
                : `\u2066${Math.round(W * scale)}×${Math.round(H * scale)}\u2069 פיקסלים`;
    };
    upd();
    const go = async share => {
        const name = safeName(ed.doc.title);
        try {
            toast('מכין…');
            let blob, file;
            if (fmt === 'mbe') { blob = await makeMbe(); file = name + '.mbe'; }
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
        seg('פורמט', [{ value: 'png', label: 'PNG' }, { value: 'jpg', label: 'JPG' }, { value: 'pdf', label: 'PDF' }, { value: 'mbe', label: '‎.mbe' }], fmt, v => { fmt = v; upd(); }),
        seg('גודל', [{ value: 1, label: '×1' }, { value: 2, label: '×2' }, { value: 0.5, label: '×½' }], scale, v => { scale = +v; upd(); }),
        info,
        h('div', { class: 'row-actions' },
            h('button', { class: 'btn primary', onclick: () => go(false) }, '⬇️ הורדה'),
            canShare ? h('button', { class: 'btn ghost', onclick: () => go(true) }, '↗ שיתוף') : null),
        h('h3', { class: 'section' }, 'להמשיך לערוך באפליקציה אחרת'),
        h('p', { class: 'hint' }, 'הקובץ נפתח שם עם כל השכבות, ו"חזרה" מחזירה אותו לכאן.'),
        h('div', { class: 'row-actions' }, Object.entries(TARGETS).map(([k, t]) =>
            h('button', { class: 'btn ghost', onclick: () => openElsewhere(k) }, `${t.icon} פתיחה ${t.in}`)))));
}

/** ‎.mbe של העיצוב הפתוח. הקובץ שנכתב הוא הבסיס של השמירה הבאה (המזהה, היומן ומה ששייך לאפליקציות אחרות נשמרים). */
async function makeMbe() {
    await saveNow();
    embedDocFonts(ed.doc);
    const base = ed.doc.mbeBase ? await assets.get(ed.doc.mbeBase) : null;
    const r = await buildMbe(ed.doc, ed.canvas.images, { baseBytes: base ? new Uint8Array(await base.arrayBuffer()) : null });
    const blob = new Blob([r.bytes], { type: 'application/vnd.mbe+zip' });
    ed.doc.mbeBase = await assets.put(blob);
    scheduleSave();
    log('export-mbe', { bytes: r.bytes.length, gaps: r.gaps.join(',') || '-' });
    return blob;
}

/** "פתיחה ברסטר / בווקטורי": החלון נפתח מיד (בתוך הלחיצה), הקובץ נבנה בינתיים ונשלח כשהיעד מוכן. */
function openElsewhere(target) {
    const t = TARGETS[target];
    // הרסטר עורך פיקסלים: עיצוב בלי שכבת תמונה נפתח שם כ"אין קנבס רסטר" — אומרים כאן, לפני שפותחים חלון
    if (t.needsImage && !ed.doc.layers.some(l => l.type === 'image')) {
        sheet(`פתיחה ${t.in}`, h('div', {},
            h('p', {}, `אין בעיצוב שכבת תמונה — ${t.name} עורך תמונות, ואין לו מה לפתוח כאן.`),
            h('p', { class: 'hint' }, 'הוסיפו תמונה (➕ ← תמונה), או פתחו את העיצוב בווקטורי — שם הצורות והטקסט עריכים.'),
            h('button', { class: 'btn primary block', onclick: closeSheet }, 'הבנתי')));
        return;
    }
    const srcId = ed.id, name = safeName(ed.doc.title);
    const fallback = async why => {
        // אפליקציה שעוד לא מקבלת קובץ ישירות, חלון שנחסם, או האפליקציה לאנדרואיד — הקובץ יורד ופותחים אותו שם
        log('handoff-fallback', { to: target, why });
        try {
            await download(await built.then(r => new Blob([r.bytes], { type: 'application/vnd.mbe+zip' })), name + '.mbe');
            sheet(`פתיחה ${t.in}`, h('div', {},
                h('p', {}, why === 'blocked' ? 'הדפדפן חסם את החלון החדש.'
                    : why === 'android' ? `מהאפליקציה לאנדרואיד אי אפשר לפתוח את ${t.name} ישירות.`
                        : `${t.name} לא ענתה (אולי צריך להתחבר שם, או שאין לכם הרשאה אליה).`),
                h('p', { class: 'hint' }, `הקובץ ‎.mbe ירד — פותחים אותו ${t.in} מ-📂, ואחרי השמירה שם מייבאים אותו כאן בחזרה (📥).`),
                h('button', { class: 'btn primary block', onclick: closeSheet }, 'הבנתי')));
        } catch (e) { toast(e.message || 'הייצוא נכשל', 'error'); }
    };
    const built = makeMbe().then(async blob => ({ bytes: new Uint8Array(await blob.arrayBuffer()), name }));
    built.catch(() => {});
    if (window.GreetingCardsAndroid) { fallback('android'); return; }
    closeSheet();
    openIn(target, built, { onFallback: fallback, onReturn: bytes => receiveBack(srcId, t, bytes) });
}

/** הקובץ חזר מהאפליקציה האחרת: נכנס לעיצוב הפתוח כצעד שאפשר לבטל, או כעיצוב חדש אם בינתיים נפתח אחר. */
async function receiveBack(srcId, t, bytes) {
    try {
        const blob = new Blob([bytes], { type: 'application/vnd.mbe+zip' });
        const r = await importMbe(new File([blob], 'return.mbe', { type: blob.type }));
        r.doc.mbeBase = await assets.put(blob);
        if (ed?.id === srcId) {
            r.doc.title = ed.doc.title;
            for (const [aid, img] of r.loaded) ed.canvas.setImage(aid, img);
            await ensureDocFonts(r.doc);
            ed.doc = r.doc;
            ed.canvas.setDoc(ed.doc);
            ed.canvas.select([]);
            commit();
        } else {
            const id = uid('d');
            await designs.put({ id, title: r.doc.title, doc: r.doc, updatedAt: Date.now(), owner: sync.currentUser()?.id, synced: false });
            if (!ed) await openDesign(id, r.loaded);
        }
        focus();
        toast(`חזר ${t.from} ✓`);
    } catch (e) {
        toast('החזרה נכשלה: ' + (e.message || e), 'error');
    }
}

// ─── עוד ──────────────────────────────────────────────────────────────────

function moreSheet() {
    sheet('עוד', h('div', { class: 'menu-list' },
        h('button', { class: 'menu-item', onclick: pageSizeSheet }, '📐 גודל הדף'),
        h('button', { class: 'menu-item', onclick: versionsSheet }, '🕘 גרסאות קודמות'),
        h('button', { class: 'menu-item', onclick: myFontsSheet }, '🔤 הגופנים שלי'),
        h('button', { class: 'menu-item', onclick: skillSheet }, '🤖 סקיל לצ\'אט'),
        h('button', { class: 'menu-item', onclick: () => { closeSheet(); importSheet(); } }, '📥 ייבוא מהצ\'אט (עיצוב חדש)'),
        sync.currentUser()?.role === 'owner' ? h('button', { class: 'menu-item', onclick: devSheet }, '🛠️ אזור פיתוח') : null));
}

/**
 * אזור הפיתוח — רק למפתח (בעל המערכת): הגרסה שרצה, היומן של המכשיר הזה, ויומנים שנשלחו.
 * משתמש רגיל לא רואה אותו, והשרת מסרב לפעולות היומן בשבילו.
 */
async function devSheet() {
    closeSheet();
    const list = h('ul', { class: 'dev-logs' }, h('li', { class: 'muted' }, 'טוען…'));
    sheet('🛠️ אזור פיתוח', h('div', {},
        h('p', { class: 'hint' }, `גרסה \u2066${BUILD}\u2069 · ${logDump().rows.length} רשומות ביומן של המכשיר הזה (נגיעות, בחירות, גדלים וזום — בלי טקסטים ובלי תמונות).`),
        h('button', { class: 'btn primary block', onclick: sendLog }, '🐞 שליחת היומן לקלוד'),
        h('h3', { class: 'section' }, 'יומנים שנשלחו'),
        list));
    try {
        const { logs } = await sync.listLogs();
        list.replaceChildren(...(logs.length ? logs.map(l => h('li', { class: 'dev-log' },
            h('span', { class: 'dev-log-code', dir: 'ltr' }, l.id),
            h('span', { class: 'muted small' }, `${timeAgo(Date.parse(l.received))} · ${l.rows} רשומות · ${l.page || ''}${l.apk ? ' · APK' : ''}`),
            h('button', { class: 'icon-btn', 'aria-label': `העתקת הקוד ${l.id}`, onclick: () => copyText(l.id) }, '📋')))
            : [h('li', { class: 'muted' }, 'עוד לא נשלחו יומנים')]));
    } catch (e) { list.replaceChildren(h('li', { class: 'muted' }, 'הרשימה לא נטענה: ' + e.message)); }
}

async function copyText(t) {
    try { await navigator.clipboard.writeText(t); toast('הקוד הועתק'); } catch { toast('לא הצלחתי להעתיק — העתיקו ידנית', 'error'); }
}

/**
 * היומן (js/log.js) נשלח לשרת, והמשתמש מקבל קוד קצר להעביר לקלוד. ביומן אין טקסטים ואין תמונות —
 * רק נגיעות, בחירות, גדלים וזום.
 */
async function sendLog() {
    closeSheet();
    const v = ed?.canvas.view;
    const dump = logDump({ build: BUILD, page: ed ? `${ed.doc.page.width}x${ed.doc.page.height}` : '-', layers: ed?.doc.layers.length ?? 0,
        zoom: v ? Math.round(v.zoom * 1000) / 1000 : 0, apk: !!window.GreetingCardsAndroid });
    try {
        const r = await sync.sendLog(dump);
        sheet('היומן נשלח', h('div', {},
            h('p', {}, 'שלחו לקלוד את הקוד:'),
            h('p', { class: 'log-code', dir: 'ltr' }, r.id),
            h('div', { class: 'row-actions' },
                h('button', { class: 'btn primary', onclick: () => copyText(r.id) }, '📋 העתקת הקוד')),
            h('p', { class: 'hint' }, `${dump.rows.length} רשומות: נגיעות, בחירות, גדלים וזום — בלי טקסטים ובלי תמונות.`)));
    } catch (e) { toast('השליחה נכשלה: ' + e.message, 'error'); }
}

// ─── נמענים: כרטיס לכל שורה ───────────────────────────────────────────────

/** תצוגה לפי נמען: הקנבס מציג את הערכים של שורה אחת. null — חזרה לתבנית. */
function setPreview(index) {
    const m = ed.doc.merge;
    if (index == null || !m?.rows?.length) {
        ed.preview = null;
        ed.canvas.vars = null;
        ed.previewBar.hidden = true;
        ed.canvas.render();
        return;
    }
    const n = m.rows.length;
    ed.preview = ((index % n) + n) % n;
    ed.canvas.vars = m.rows[ed.preview];
    ed.canvas.render();
    const name = (m.nameColumn && m.rows[ed.preview][m.nameColumn]) || 'נמען';
    ed.previewBar.hidden = false;
    ed.previewBar.replaceChildren(
        h('button', { class: 'icon-btn', 'aria-label': 'הנמען הקודם', onclick: () => setPreview(ed.preview - 1) }, '›'),
        h('span', { class: 'pv-label', 'aria-live': 'polite' }, `${name} · ${ed.preview + 1}/${n}`),
        h('button', { class: 'icon-btn', 'aria-label': 'הנמען הבא', onclick: () => setPreview(ed.preview + 1) }, '‹'),
        h('button', { class: 'icon-btn', 'aria-label': 'סגירת התצוגה לפי נמען', onclick: () => setPreview(null) }, '✕'));
}

async function loadRecipients(read) {
    try {
        const t = await read();
        if (!t) return;
        ed.doc.merge = { ...t, ...guessColumns(t) };
        commit();
        closeSheet();
        recipientsSheet();
        toast(`נטענו ${t.rows.length} נמענים`);
    } catch (e) { toast(e.message || 'הטעינה נכשלה', 'error'); }
}

function recipientSources() {
    return h('div', { class: 'tiles' },
        tile('📊', 'אקסל / CSV', () => loadRecipients(async () => { const [f] = await pickFiles('.xlsx,.xls,.csv,.ods'); return f && readSheetFile(f); })),
        tile('📋', 'הדבקה מגיליון', pasteRecipientsSheet),
        window.GreetingCardsAndroid?.readContacts ? tile('📱', 'אנשי קשר', () => loadRecipients(async () => readPhoneContacts())) : null);
}

function pasteRecipientsSheet() {
    const ta = h('textarea', { rows: 8, placeholder: 'שם\tטלפון\nדנה\t050-1234567', autofocus: true });
    sheet('הדבקה מגיליון', h('div', {},
        h('p', { class: 'hint' }, 'מסמנים בגיליון (Excel / Google Sheets) את הטבלה כולל שורת הכותרות, מעתיקים ומדביקים כאן.'),
        field('הטבלה', ta),
        h('button', { class: 'btn primary block', onclick: () => loadRecipients(async () => readPasted(ta.value)) }, 'טעינה')));
}

function recipientsSheet() {
    const m = ed.doc.merge;
    if (!m?.rows?.length) {
        sheet('נמענים', h('div', {},
            h('p', { class: 'hint' }, 'כרטיס לכל נמען: טוענים רשימה (שורה ראשונה — כותרות), ובטקסט כותבים {{שם העמודה}} — למשל "שלום {{שם}}".'),
            recipientSources()));
        return;
    }
    const vars = docVariables(ed.doc);
    const missing = vars.filter(v => !m.columns.includes(v));
    const colSelect = (label, key) => {
        const sel = h('select', {}, h('option', { value: '' }, '—'), m.columns.map(c => h('option', { value: c, selected: m[key] === c }, c)));
        sel.addEventListener('change', () => { m[key] = sel.value || null; commit(); });
        return field(label, sel);
    };
    const withPhone = m.phoneColumn ? m.rows.filter(r => normalizePhone(r[m.phoneColumn])).length : 0;
    const shown = m.columns.slice(0, 4);
    const bridge = window.GreetingCardsAndroid;
    sheet('נמענים', h('div', {},
        h('div', { class: 'box tally' }, h('strong', {}, `${m.rows.length} נמענים`),
            h('span', { class: 'muted' }, ` · ${m.columns.length} עמודות${m.phoneColumn ? ` · ${withPhone} עם טלפון` : ''}`)),
        vars.length
            ? h('p', { class: 'hint' }, 'משתנים בעיצוב: ', h('bdi', {}, vars.map(v => `{{${v}}}`).join(' ')))
            : h('p', { class: 'hint warn' }, 'עוד אין משתנים בעיצוב. בחרו טקסט ← 🎨 עיצוב ← "הוספת משתנה", או כתבו {{שם}}.'),
        missing.length ? h('p', { class: 'hint warn' }, `אין ברשימה עמודה בשם: ${missing.join(', ')}`) : null,
        h('div', { class: 'two' }, colSelect('עמודת שם', 'nameColumn'), colSelect('עמודת טלפון', 'phoneColumn')),
        colSelect('שם הקובץ לפי', 'fileColumn'),
        h('div', { class: 'row-actions' },
            h('button', { class: 'btn primary', onclick: () => { closeSheet(); setPreview(0); } }, '👁️ תצוגה לפי נמען'),
            h('button', { class: 'btn ghost', onclick: batchExportSheet }, '⬇️ ייצוא לכולם'),
            bridge?.openWhatsAppChat ? h('button', { class: 'btn ghost', onclick: whatsappSheet }, '💬 וואטסאפ') : null),
        h('details', {}, h('summary', {}, `הרשימה (${m.rows.length})`),
            h('div', { class: 'table-wrap' }, h('table', { class: 'grid' },
                h('thead', {}, h('tr', {}, shown.map(c => h('th', { scope: 'col' }, c)))),
                h('tbody', {}, m.rows.slice(0, 200).map(r => h('tr', {}, shown.map(c => h('td', {}, r[c])))))))),
        h('h3', { class: 'section' }, 'החלפת הרשימה'),
        recipientSources(),
        h('button', { class: 'btn ghost danger block', onclick: async () => {
            if (!(await confirmDialog('למחוק את רשימת הנמענים מהעיצוב?', 'מחיקה', true))) return;
            delete ed.doc.merge;
            setPreview(null);
            commit();
            closeSheet();
            toast('הרשימה נמחקה');
        } }, '🗑️ מחיקת הרשימה')), { wide: true });
}

/**
 * טקסט שלא נכנס: לכל נמען ולכל טקסט עם משתנה — האם הטקסט המלא גבוה בהרבה מהתבנית (שבירה לעוד שורות).
 * מדידה בלבד (Konva.Text בלי ציור), ולכן מהירה גם לאלפי שורות.
 */
function overflowWarnings() {
    const m = ed.doc.merge;
    const out = [];
    const layers = ed.doc.layers.filter(l => l.type === 'text' && /\{\{/.test(l.text));
    for (const l of layers) {
        const s = l.style;
        const t = new Konva.Text({ width: l.w, fontFamily: `"${s.font}"`, fontStyle: String(nearestWeight(s.font, s.weight)), fontSize: s.size, lineHeight: s.lineHeight || 1.2, wrap: 'word' });
        const base = l.h || t.text(l.text).height();
        m.rows.forEach((r, i) => {
            if (t.text(fillText(l.text, r)).height() > base * 1.45) out.push(`שורה ${i + 1} (${(m.nameColumn && r[m.nameColumn]) || ''}): "${String(l.text).slice(0, 20)}…" יוצא ארוך — נשבר לעוד שורה`);
        });
        t.destroy();
    }
    return out;
}

/** ייצוא כרטיס לכל נמען, ב-ZIP אחד. נמען אחרי נמען, עם התקדמות ועצירה. */
function batchExportSheet() {
    const m = ed.doc.merge;
    let fmt = 'jpg', stop = false;
    const warns = overflowWarnings();
    const prog = h('progress', { max: m.rows.length, value: 0, 'aria-label': 'התקדמות הייצוא' });
    const status = h('p', { class: 'hint', role: 'status', 'aria-live': 'polite' }, `${m.rows.length} קבצים`);
    const go = async () => {
        stop = false;
        btn.disabled = true;
        const names = fileNames(m);
        const keep = ed.canvas.vars;
        ed.canvas.select([]);
        try {
            await loadScript('vendor/jszip.min.js');
            const zip = new window.JSZip();
            for (let i = 0; i < m.rows.length; i++) {
                if (stop) throw new Error('הייצוא נעצר');
                ed.canvas.vars = m.rows[i];
                ed.canvas.render();
                const c = ed.canvas.pageCanvas(1);
                const blob = fmt === 'pdf' ? await exportPdf(c, ed.doc) : await canvasToBlob(c, fmt === 'png' ? 'image/png' : 'image/jpeg', 0.9);
                zip.file(`${names[i]}.${fmt}`, blob);
                prog.value = i + 1;
                status.textContent = `${i + 1} מתוך ${m.rows.length}`;
                await new Promise(r => setTimeout(r, 0));   // נותנים לממשק לנשום בין כרטיס לכרטיס
            }
            status.textContent = 'אורז…';
            const out = await zip.generateAsync({ type: 'blob', mimeType: 'application/zip' });
            await download(out, safeName(ed.doc.title) + '.zip');
            status.textContent = `מוכן ✓ — ${m.rows.length} קבצים`;
        } catch (e) {
            status.textContent = e.message || 'הייצוא נכשל';
        } finally {
            ed.canvas.vars = keep;
            ed.canvas.render();
            btn.disabled = false;
        }
    };
    const btn = h('button', { class: 'btn primary', onclick: go }, `⬇️ ייצוא ${m.rows.length} קבצים`);
    sheet('ייצוא לכולם', h('div', {},
        seg('פורמט', [{ value: 'jpg', label: 'JPG' }, { value: 'png', label: 'PNG' }, { value: 'pdf', label: 'PDF' }], fmt, v => { fmt = v; }),
        warns.length ? h('details', { class: 'warn-box' }, h('summary', {}, `⚠️ ${warns.length} נמענים שהטקסט שלהם ארוך`),
            h('ul', { class: 'fixes' }, warns.slice(0, 30).map(w => h('li', {}, w)))) : null,
        prog, status,
        h('div', { class: 'row-actions' }, btn, h('button', { class: 'btn ghost', onclick: () => { stop = true; } }, '⏹ עצירה'))),
    { onClose: () => { stop = true; } });
}

/**
 * שליחה בוואטסאפ (רק באפליקציית האנדרואיד). חצי-אוטומטית, כמו בגרסה הקודמת: לכל נמען נפתח הצ'אט שלו עם
 * הכרטיס מצורף, והלחיצה על "שלח" — של המשתמש. החזרה לאפליקציה (onReturnedFromWhatsApp) מקדמת את התור.
 * שירות נגישות היה חוסך את הלחיצה, אבל גורר חסימות התקנה, אזהרות וסיכון לחשבון הוואטסאפ.
 */
function whatsappSheet() {
    const bridge = window.GreetingCardsAndroid;
    const m = ed.doc.merge;
    if (!m.phoneColumn) { toast('בחרו קודם עמודת טלפון', 'error'); return; }
    const items = m.rows.map(r => ({ row: r, phone: normalizePhone(r[m.phoneColumn]), name: (m.nameColumn && r[m.nameColumn]) || r[m.phoneColumn] })).filter(x => x.phone);
    if (!items.length) { toast('אין ברשימה מספרי טלפון תקינים', 'error'); return; }
    let pkgs = [];
    try { pkgs = JSON.parse(bridge.getWhatsAppPackages()); } catch { /* ישן */ }
    let pkg = pkgs[0]?.packageName || 'com.whatsapp';
    const q = { active: false, idx: 0, sent: 0, awaiting: false, timer: null };
    const delayIn = h('input', { type: 'number', min: 1, max: 30, value: 3, inputMode: 'numeric' });
    const prog = h('progress', { max: items.length, value: 0, 'aria-label': 'התקדמות השליחה' });
    const status = h('p', { class: 'lead', role: 'status', 'aria-live': 'polite' }, `${items.length} נמענים עם טלפון`);
    const keep = ed.canvas.vars;

    const finish = msg => {
        q.active = false;
        clearTimeout(q.timer);
        window.onReturnedFromWhatsApp = null;
        try { bridge.clearSentCards(); } catch { /* ישן */ }
        ed.canvas.vars = keep;
        ed.canvas.render();
        status.textContent = msg || `הסתיים — נפתחו ${q.sent} מתוך ${items.length}`;
        startBtn.disabled = false;
    };
    const sendCurrent = async () => {
        const it = items[q.idx];
        prog.value = q.idx;
        ed.canvas.vars = it.row;
        ed.canvas.render();
        const blob = await canvasToBlob(ed.canvas.pageCanvas(1), 'image/jpeg', 0.88);
        const b64 = await new Promise(res => { const r = new FileReader(); r.onload = () => res(String(r.result).split(',')[1]); r.readAsDataURL(blob); });
        const res = JSON.parse(bridge.openWhatsAppChat(JSON.stringify({ phone: it.phone, imageBase64: b64, imageFormat: 'jpg', whatsappPackage: pkg })));
        if (!res.ok) {
            status.textContent = `לא נפתח עבור ${it.name} (${res.error === 'bad_phone' ? 'מספר לא תקין' : res.error}) — ממשיכים`;
            return next();
        }
        q.awaiting = true;
        status.textContent = `נפתח וואטסאפ עבור ${it.name} — לחצו "שלח", ואז חזרה לכאן`;
    };
    const next = () => {
        q.idx++;
        if (q.idx >= items.length) return finish();
        let left = Math.max(1, +delayIn.value || 3);
        const tick = () => {
            if (!q.active) return;
            if (left <= 0) return sendCurrent();
            status.textContent = `הבא: ${items[q.idx].name} — בעוד ${left}`;
            left--;
            q.timer = setTimeout(tick, 1000);
        };
        tick();
    };
    // onResume באפליקציה קורא לזה גם בפתיחה רגילה — לכן רק כשמחכים לחזרה.
    window.onReturnedFromWhatsApp = () => {
        if (!q.active || !q.awaiting) return;
        q.awaiting = false;
        q.sent++;
        next();
    };
    const startBtn = h('button', { class: 'btn primary', onclick: () => {
        if (!bridge.isWhatsAppAvailable()) { toast('וואטסאפ אינו מותקן', 'error'); return; }
        Object.assign(q, { active: true, idx: 0, sent: 0, awaiting: false });
        startBtn.disabled = true;
        ed.canvas.select([]);
        sendCurrent();
    } }, '▶️ התחלה');
    sheet('שליחה בוואטסאפ', h('div', {},
        h('p', { class: 'hint' }, 'לכל נמען נפתח הצ\'אט שלו עם הכרטיס מצורף. לוחצים "שלח", חוזרים לאפליקציה — והבא נפתח לבד.'),
        pkgs.length > 1 ? seg('אפליקציה', pkgs.map(p => ({ value: p.packageName, label: p.label })), pkg, v => { pkg = v; }) : null,
        field('השהיה בין נמענים (שניות)', delayIn),
        prog, status,
        h('div', { class: 'row-actions' }, startBtn,
            h('button', { class: 'btn ghost', onclick: () => { if (q.active) { clearTimeout(q.timer); q.awaiting = false; next(); } } }, '⏭ דילוג'),
            h('button', { class: 'btn ghost danger', onclick: () => finish('נעצר') }, '⏹ עצירה'))),
    { onClose: () => { if (q.active) finish('נעצר'); } });
}

// ─── מקלדת ────────────────────────────────────────────────────────────────

/** Tab בתוך הקנבס — מעבר בין שכבות (רק כשהקנבס בפוקוס; בסוף הרשימה יוצאים ממנו כרגיל). */
function onStageKey(e) {
    if (isSheetOpen() || e.key !== 'Tab' || e.ctrlKey || e.metaKey) return;
    const L = ed.doc.layers.filter(l => !l.hidden);
    const i = L.findIndex(l => l.id === ed.selection[0]);
    const j = i + (e.shiftKey ? -1 : 1);
    if (j >= 0 && j < L.length) { e.preventDefault(); ed.canvas.select([L[j].id]); announce(L[j]); }
    else if (i === -1 && L.length) { e.preventDefault(); ed.canvas.select([L[e.shiftKey ? L.length - 1 : 0].id]); announce(L[e.shiftKey ? L.length - 1 : 0]); }
}

// ─── קיצורי מקלדת — shortcuts() של ui-core: לפי המקש הפיזי, לא בתוך שדה, ורשימה בהגדרות האישיות ───
const inEditor = () => !!ed && !isSheetOpen();
// מקשים שפועלים על הבחירה (חצים, מחיקה, Enter, Esc) — רק כשהפוקוס על הקנבס או על הדף, לא על כפתור או מחוון
const onCanvas = () => { const a = document.activeElement; return !a || a === document.body || !!a.closest?.('.stage'); };
const withSel = () => inEditor() && onCanvas() && sel().length > 0;
const nudge = (dx, dy) => () => {
    const ls = sel();
    if (ls.some(l => l.locked)) return;
    ls.forEach(l => { l.x += dx; l.y += dy; });
    commit();
    announce(ls[0], true);
};
const keys = shortcuts()
    .add('Mod+Z', () => undo(), { label: 'ביטול', when: inEditor })
    .add(['Mod+Shift+Z', 'Mod+Y'], () => redo(), { label: 'חזרה', when: inEditor })
    .add('Mod+Shift?+=', () => ed.canvas.zoomBy(1.25), { label: 'הגדלה', when: inEditor })
    .add('Mod+-', () => ed.canvas.zoomBy(0.8), { label: 'הקטנה', when: inEditor })
    .add('Mod+0', () => ed.canvas.fit(), { label: 'התאמה למסך', when: inEditor })
    .add('Mod+D', () => duplicate(), { label: 'שכפול', when: () => inEditor() && sel().length > 0 })
    .add(['Delete', 'Backspace'], () => removeSelected(), { label: 'מחיקה', when: withSel })
    .add('Escape', () => ed.canvas.select([]), { label: 'ביטול הבחירה', when: withSel })
    .add('Enter', () => editTextInPlace(sel()[0]), { label: 'עריכת הטקסט', when: () => withSel() && sel().length === 1 && sel()[0].type === 'text' })
    .add('ArrowLeft', nudge(-1, 0), { label: 'הזזה (Shift — 10)', when: withSel })
    .add('ArrowRight', nudge(1, 0), { when: withSel })
    .add('ArrowUp', nudge(0, -1), { when: withSel })
    .add('ArrowDown', nudge(0, 1), { when: withSel })
    .add('Shift+ArrowLeft', nudge(-10, 0), { when: withSel })
    .add('Shift+ArrowRight', nudge(10, 0), { when: withSel })
    .add('Shift+ArrowUp', nudge(0, -10), { when: withSel })
    .add('Shift+ArrowDown', nudge(0, 10), { when: withSel });

function announce(l, pos) {
    ed.saveState.textContent = pos ? `${layerLabel(l)}: X ${Math.round(l.x)}, Y ${Math.round(l.y)}` : `נבחר: ${layerLabel(l)}`;
}

// ═══ התחלה ════════════════════════════════════════════════════════════════

// ─── חשבון ────────────────────────────────────────────────────────────────

// ═══ 👤 תפריט המשתמש ו-📁 "המסמכים שלי" — מ-ui-core ═══════════════════════════════
// אותו תפריט בכל החבילה. כאן רק מה שהסטודיו נותן לו: המשתמש, ההגדרות שלנו, הספרייה (מתאם), הענן, יציאה.

let accountServerP = null;   // ההגדרות נטענות מהשרת פעם אחת לכל טעינה

async function attachAccount(bar) {
    const me = sync.currentUser();
    accountServerP ??= accountServer(sync.accountCall).catch(() => null);   // בלי שרת — ההגדרות במכשיר
    const server = await accountServerP;
    let cloudOn = false;   // התפריט קורא את הרשימה מיד; המצב מהענן מגיע ברקע
    aiCaps().then(c => { cloudOn = !!c.connected; acc.refresh(); });
    const acc = accountMenu(bar, {
        user: () => me && { name: me.name || me.email || 'משתמש', email: me.email, admin: me.role === 'owner' },
        server: server || undefined,
        docs: () => myDocuments(),
        drives: { list: () => [{ id: 'suite', name: 'ענן החבילה', icon: '☁️', connected: cloudOn }], manage: cloudSheet },
        sections: [{ id: 'studio', title: 'סטודיו העיצוב', render: body => body.append(
            h('p', { class: 'hint' }, me.platform ? 'מחובר דרך הכלים הדיגיטליים.' : 'מחובר עם קוד במייל.', me.role === 'owner' ? ' · מנהל המערכת' : ''),
            me.role === 'owner' ? h('button', { type: 'button', class: 'btn ghost block', onclick: gptSetupSheet }, '🤖 הגדרת ה-GPT ב-ChatGPT') : '',
            me.role === 'owner' ? h('button', { type: 'button', class: 'btn ghost block', onclick: devSheet }, '🛠️ אזור פיתוח') : '') }],
        admin: me.role === 'owner' ? '../admin.html' : undefined,
        shortcuts: keys,
        help: () => skillSheet(),
        // בתוך הכלים הדיגיטליים ההתחברות מגיעה מהשער — אין "יציאה" משלנו
        logout: me.platform ? undefined : async () => { await sync.logout(); showLogin(); },
    });
}

/** ☁️ עננים מחוברים: היום — ענן החבילה (מודול הכוננים). */
function cloudSheet() {
    sheet('☁️ ענן החבילה', h('div', {},
        h('p', { class: 'hint' }, 'דרכו עובדת הבינה המלאכותית (✨ עצב לי, הסרת רקע, פירוק לשכבות), וגם הקבצים ב"המסמכים שלי".'),
        cloudRow()));
}

/** 📁 המסמכים שלי: העיצובים בסטודיו, וענן החבילה כלשונית שנייה. */
function myDocuments() {
    const item = d => ({ id: d.id, name: d.title, kind: 'file', updated: d.updatedAt, icon: '🎨' });
    const entries = new Map();   // הרשומה המלאה — לעיצוב שעוד לא במכשיר (remoteOnly) openDesign צריך אותה
    const studio = {
        id: 'app', title: 'בסטודיו', icon: '🎨',
        async list() {
            const { list } = await sync.listDesigns();
            list.forEach(d => entries.set(d.id, d));
            return { items: list.map(item) };
        },
        async rename(id, name) {
            const rec = await designs.get(id) || (await sync.pull(id), await designs.get(id));
            await designs.put({ ...rec, title: name, doc: { ...rec.doc, title: name }, updatedAt: Date.now(), synced: false });
            sync.push(id).catch(() => {});
        },
        async copy(id) {
            const rec = await designs.get(id) || (await sync.pull(id), await designs.get(id));
            const nid = uid('d');
            await designs.put({ ...rec, id: nid, title: rec.title + ' (עותק)', updatedAt: Date.now(), serverVersion: 0, synced: false });
            sync.push(nid).catch(() => {});
            return item({ id: nid, title: rec.title + ' (עותק)', updatedAt: Date.now() });
        },
        async remove(id) {
            await designs.remove(id);
            await sync.removeRemote(id).catch(() => toast('נמחק מהמכשיר; מהשרת — כשיהיה חיבור', 'error'));
        },
    };
    let opened = false;   // נפתח עיצוב — לא לרענן את מסך הבית אחרי שהחלון נסגר (הוא היה דורס את העורך)
    openDocuments([studio, suiteSource(drives)], {
        onOpen: async (it, src) => {
            opened = true;
            if (src.id === 'app') return openDesign(it.id, null, entries.get(it.id));
            // מהענן: ‎.mbe או תמונה — נפתח כמו קובץ מהמכשיר, לעיצוב חדש
            try { runImport([new File([await src.read(it.id)], it.name)], ''); } catch (e) { toast(aiMessage(e), 'error'); }
        },
    }).then(() => { if (!opened && !ed) showHome(); });   // שינוי שם / מחיקה / שכפול — הרשימה בבית מתעדכנת
}

/** כניסה בלי סיסמה: קוד בן 6 ספרות במייל. בתוך הכלים הדיגיטליים המסך הזה לא מופיע — הכניסה אוטומטית. */
function showLogin() {
    document.title = 'כניסה — סטודיו עיצוב';
    const email = h('input', { type: 'email', autocomplete: 'email', inputMode: 'email', dir: 'ltr', required: true, autofocus: true });
    const code = h('input', { type: 'text', autocomplete: 'one-time-code', inputMode: 'numeric', maxLength: 6, dir: 'ltr' });
    const codeField = field('הקוד מהמייל', code);
    codeField.hidden = true;
    const btn = h('button', { class: 'btn primary block', type: 'submit' }, 'שליחת קוד');
    const msg = h('p', { class: 'hint', role: 'status', 'aria-live': 'polite' });
    let step = 'email';
    const form = h('form', { class: 'login', onsubmit: async e => {
        e.preventDefault();
        btn.disabled = true;
        try {
            if (step === 'email') {
                await sync.requestCode(email.value);
                step = 'code';
                codeField.hidden = false;
                email.readOnly = true;
                btn.textContent = 'כניסה';
                msg.textContent = `נשלח קוד ל-${email.value}. בדקו גם בספאם.`;
                code.focus();
            } else {
                await sync.verifyCode(email.value, code.value);
                start();   // כולל קישור ייבוא מ-ChatGPT שחיכה לכניסה
            }
        } catch (err) { msg.textContent = err.message; }
        btn.disabled = false;
    } }, field('מייל', email), codeField, btn, msg);
    app.replaceChildren(
        h('header', { class: 'topbar' }, h('h1', {}, '🎨 סטודיו עיצוב')),
        h('main', { class: 'home' }, h('h2', { class: 'section' }, 'כניסה'), h('p', { class: 'hint' }, 'בלי סיסמה: נשלח אליכם קוד במייל.'), form));
}

// ─── ייבוא מ-ChatGPT ("שלח לעורך") ──────────────────────────────────────

/** קישור מה-GPT: ‎#import=ABCD-EFGH. התמונות כבר בשרת; כאן הן יורדות, והעיצוב נפתח עם שכבות חיות. */
async function openChatImport(code) {
    toast('מקבל את העיצוב מ-ChatGPT…');
    try {
        const { design, files, blobs } = await sync.claimImport(code);
        const byName = new Map([...blobs].map(([n, b]) => [n.toLowerCase(), { blob: b, remote: files[n].id }]));
        let doc, fixes = [];
        const loaded = new Map();
        const ingest = async (key, item) => {
            const src = await assets.put(item.blob);
            loaded.set(key, await blobToImage(item.blob));
            return { type: 'image', src, remote: item.remote };
        };
        if (design) {
            const svgFixes = [];
            expandSvgLayers(design, new Map(), svgFixes);   // קישוטי svg מה-GPT → שכבות וקטור
            ({ doc, fixes } = normalizeDoc(design));
            fixes.unshift(...svgFixes);
            const left = [...byName.values()];
            for (const [key, a] of Object.entries(doc.assets)) {
                // לפי שם הקובץ; ‏ChatGPT לפעמים משנה שמות — אז תמונה יחידה לנכס יחיד.
                const item = byName.get((a.file || '').toLowerCase()) || (Object.keys(doc.assets).length === 1 && left.length === 1 ? left[0] : null);
                if (!item) { fixes.push(`חסרה התמונה "${a.file || key}"`); continue; }
                doc.assets[key] = await ingest(key, item);
            }
        } else {
            const first = [...byName.values()][0];
            if (!first) throw new Error('לא התקבלו תמונות');
            const img = await blobToImage(first.blob);
            doc = newDoc(img.naturalWidth, img.naturalHeight, 'רקע מ-ChatGPT');
            doc.assets.bg = await ingest('bg', first);
            doc.layers.push(backgroundLayer(doc, 'bg', img.naturalWidth, img.naturalHeight));
        }
        doc.source = { app: 'chat' };
        const id = uid('d');
        await designs.put({ id, title: doc.title, doc, updatedAt: Date.now(), owner: sync.currentUser()?.id, synced: false });
        history.replaceState(null, '', location.pathname + location.search);
        await openDesign(id, loaded);
        if (fixes.length) {
            sheet(`${fixes.length} תיקונים בייבוא`, h('div', {}, h('ul', { class: 'fixes' }, fixes.map(f => h('li', {}, f))),
                h('button', { class: 'btn primary block', onclick: closeSheet }, 'הבנתי')));
        } else toast('העיצוב מ-ChatGPT נפתח ✓');
    } catch (e) {
        history.replaceState(null, '', location.pathname + location.search);
        toast(e.message || 'הייבוא נכשל', 'error');
        showHome();
    }
}

/** הגדרת ה-GPT (לבעלים): כל מה שמדביקים ב-GPT Builder, עם כפתורי העתקה. */
async function gptSetupSheet(regenerate = false) {
    let cfg, instructions = '';
    try {
        cfg = await sync.gptSetup(regenerate === true);
        instructions = (await (await fetch('gpt/instructions.md?v=' + Date.now(), { cache: 'no-store' })).text()).replace(/<!--[\s\S]*?-->\n*/, '');
    } catch (e) { toast(e.message, 'error'); return; }
    const base = new URL('.', location.href).href;
    const copyRow = (label, value, secret) => h('div', { class: 'field' },
        h('label', {}, label),
        h('div', { class: 'copy-row' },
            h('input', { type: 'text', value, readOnly: true, dir: 'ltr', class: 'mono' + (secret ? ' secret' : ''), 'aria-label': label }),
            h('button', { class: 'icon-btn', 'aria-label': `העתקת ${label}`, onclick: async () => {
                try { await navigator.clipboard.writeText(value); toast('הועתק ✓'); } catch { toast('ההעתקה נחסמה — סמנו והעתיקו', 'error'); }
            } }, '📋')));
    sheet('הגדרת ה-GPT', h('div', {},
        h('p', { class: 'hint' }, 'ב-ChatGPT: Explore GPTs ← Create ← Configure. ממלאים לפי הסדר. הסבר מלא: spec/04-ai-chat-skill.md.'),
        h('ol', { class: 'steps' },
            h('li', {}, 'Name: מעצב הכרטיסים · Description: רקעים ועיצובים לסטודיו עיצוב'),
            h('li', {}, 'Instructions — להדביק את הטקסט (כפתור 📋 למטה)'),
            h('li', {}, 'Capabilities: Image Generation ✓, Code Interpreter ✓'),
            h('li', {}, 'Actions ← Create new action ← Import from URL: כתובת הסכמה'),
            h('li', {}, 'Authentication: None — כל אחד מקבל קישור עם קוד. או OAuth — מתחברים פעם אחת, והעיצובים נכנסים ישר לחשבון (פרטים למטה)'),
            h('li', {}, 'Privacy policy: הכתובת למטה'),
            h('li', {}, 'Share: Anyone with the link (לא ב-GPT Store, לפי ההחלטה)')),
        copyRow('Instructions', instructions),
        copyRow('כתובת הסכמה (Import from URL)', base + 'gpt/openapi.json'),
        copyRow('Privacy policy', new URL('../privacy.html', location.href).href),
        h('h3', { class: 'section' }, 'OAuth (לא חובה)'),
        copyRow('Client ID', cfg.client_id),
        cfg.client_secret ? copyRow('Client Secret — מוצג רק עכשיו, שמרו אותו', cfg.client_secret, true)
            : h('p', { class: 'hint' }, 'ה-Client Secret הוצג כשנוצר. איבדתם? ', h('button', { class: 'btn ghost sm', onclick: async () => {
                if (await confirmDialog('ליצור סוד חדש? ב-GPT Builder יהיה צריך להדביק אותו מחדש.', 'יצירה')) gptSetupSheet(true);
            } }, 'סוד חדש')),
        copyRow('Authorization URL', base + 'oauth/authorize.php'),
        copyRow('Token URL', base + 'oauth/token.php'),
        copyRow('Scope', 'designs'),
        h('p', { class: 'hint' }, 'Token Exchange Method: Default (POST request). אחרי השמירה, ChatGPT מציג Callback URL — אין צורך להעתיק אותו לכאן.')), { wide: true });
}

/**
 * "שיתוף" מאפליקציה אחרת לאפליקציית האנדרואיד (למשל תמונה ו-JSON מ-ChatGPT): הגשר מחזיק את מה ששותף,
 * וזה נכנס בדיוק כמו "ייבוא מהצ'אט". מחזיר true כשהיה מה לייבא.
 */
function takeShared() {
    const bridge = window.GreetingCardsAndroid;
    if (!bridge?.takeShared) return false;
    let data;
    try { data = JSON.parse(bridge.takeShared() || 'null'); } catch { return false; }
    if (location.hash === '#shared') history.replaceState(null, '', location.pathname + location.search);
    if (!data || (!data.files?.length && !data.text?.trim())) return false;
    const files = (data.files || []).map(f => {
        const bin = atob(f.base64);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return new File([bytes], f.name, { type: f.mime });
    });
    // בלי תמונות — רק טקסט (JSON): פותחים את מסך הייבוא כדי שיוסיפו את התמונה.
    if (!files.some(f => f.type.startsWith('image/') || /\.(gcard|zip|json)$/i.test(f.name))) {
        showHome().then(() => { importSheet(); const ta = document.querySelector('.sheet textarea'); if (ta) ta.value = data.text; });
        return true;
    }
    showHome().then(() => runImport(files, data.text || ''));
    return true;
}

/** דגימת צבע מהעיצוב: הגיליון מוסתר עד שנוגעים בנקודה בקנבס. ביטול — נגיעה מחוץ לדף, או 15 שניות. */
function sampleColor() {
    if (!ed) return Promise.resolve(null);
    const root = document.querySelector('.sheet-root');
    root?.classList.add('sampling');
    toast('💧 הקישו על נקודה בעיצוב');
    return new Promise(resolve => {
        let timer;
        const done = v => { clearTimeout(timer); ed?.stageEl.removeEventListener('pointerdown', onDown, true); root?.classList.remove('sampling'); resolve(v); };
        // הגיליון חוזר רק אחרי שהנגיעה נגמרה, וה-click שלה נבלע — אחרת הוא נוחת על הרקע המעומעם וסוגר את הגיליון
        const swallow = ev => { ev.preventDefault(); ev.stopPropagation(); };
        const onDown = e => {
            e.preventDefault(); e.stopPropagation();
            const v = ed.canvas.colorAt(e.clientX, e.clientY);
            log('color-sample', { ok: v ? 1 : 0 });
            if (!v) toast('מחוץ לדף — לא נדגם צבע');
            ed.stageEl.removeEventListener('pointerdown', onDown, true);
            clearTimeout(timer);
            window.addEventListener('click', swallow, true);
            const finish = () => { window.removeEventListener('pointerup', finish, true); setTimeout(() => { window.removeEventListener('click', swallow, true); done(v); }, 350); };
            window.addEventListener('pointerup', finish, true);
        };
        timer = setTimeout(() => done(null), 15000);
        ed.stageEl.addEventListener('pointerdown', onDown, true);
    });
}

/** כל הצבעים שבעיצוב — לשורה "בעיצוב" בבורר. */
function docColors() {
    if (!ed) return [];
    const out = [ed.doc.page.background, ...(ed.doc.page.gradient?.colors || [])];
    for (const l of ed.doc.layers) {
        const st = l.style || {};
        out.push(st.color, ...(st.gradient?.colors || []), st.stroke?.color, st.shadow?.color, st.glow?.color, st.background?.color, st.background?.border?.color,
            l.fill, l.color, l.stroke?.color, ...(l.gradient?.colors || []), l.shadow?.color);
    }
    return out.filter(Boolean);
}

setColorContext({ docColors, presets: SOLIDS, sample: sampleColor });

async function start() {
    try {
        await loadMyFonts().catch(() => {});   // הגופנים שהועלו במכשיר — לפני שעיצוב נפתח ומצויר
        cloudFonts();                            // ספריית הגופנים בענן — ברקע
        const { user } = await sync.whoami();
        // חזרה מאישור החיבור בענן החבילה (?cloud=connected | denied)
        const cloud = new URLSearchParams(location.search).get('cloud');
        if (cloud) {
            history.replaceState(null, '', location.pathname + location.hash);
            const msg = { connected: 'מחובר לענן החבילה ✓ — ✨ עצב לי זמין', denied: 'החיבור לענן בוטל', error: 'החיבור לענן לא הושלם — נסו שוב (👤 ← ☁️ חיבור לענן החבילה)' }[cloud] || 'החיבור לענן לא הושלם — נסו שוב';
            log('cloud-return', { result: cloud });
            setTimeout(() => toast(msg, cloud === 'connected' ? undefined : 'error'), 300);
        }
        const importCode = /^#import=([A-Za-z0-9-]{9})$/.exec(location.hash)?.[1];
        if (user && importCode) return openChatImport(importCode);
        if (user && takeShared()) return;
        if (user) return showHome();
        showLogin();
    } catch (e) {
        app.replaceChildren(h('main', { class: 'home' }, h('p', { class: 'empty' }, 'השרת לא זמין: ' + e.message),
            h('button', { class: 'btn primary block', onclick: start }, 'ניסיון נוסף')));
    }
}

start();
