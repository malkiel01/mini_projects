/**
 * ui-core/fonts.js — קטלוג גופנים אחד, טעינה, גופנים שהמשתמש העלה, ספריית הגופנים של ענן החבילה, ובורר גופנים.
 * אחד לכל האפליקציות. המקור: fonts.js של סטודיו העיצוב (#904, #1005). הקבצים — src/fonts/ (סוג "fonts" ב-apps.json),
 * ה-@font-face — fonts.css שם. הכרעת בעל המערכת: "רשימה אחת וקבצים אחד לכולן".
 *
 *   FONTS, DEFAULT_FONT, nearestWeight(family, w)
 *   await ensureFont('Rubik', 700)               // לפני ציור בקנבס — אחרת הקנבס מצייר בגופן חלופי ולא מתעדכן
 *   await registerFont({ family, weight, style, blob })   // גופן מקובץ → FontFace בדף ושורה ב-FONTS (custom)
 *   await readFontInfo(file) → { family, weight, style, bytes }   // מטבלאות name / OS/2 (TTF, OTF, WOFF), אחרת משם הקובץ
 *   fontMime(bytes, ext)
 *
 *   const mine = userFonts({ key: 'gc-my-fonts', assets: { get: id => blob, put: blob => id } });   // "שלי" — האחסון של האפליקציה
 *   await mine.load()                            // בהפעלה: כל מה שהועלה במכשיר
 *   await mine.add(file)  mine.list()  mine.remove(entry)
 *   await mine.syncCloud(drives, cloudId)        // ענן החבילה → המכשיר (drives.fonts.list / load), לפי rev
 *   await mine.saveToCloud(drives, cloudId, entry)   // המכשיר → הספרייה (drives.fonts.add); אזהרת הרישיון — של האפליקציה
 *
 *   field.append(fontPicker('גופן', 'Heebo', family => apply(family), { sample: 'שלום עולם', onUpload: file => … }))
 *     רשימה שבה כל גופן כתוב בגופן עצמו, חיפוש, "שלי" (★) למעלה, והעלאה. בורר אחד פתוח בכל רגע.
 *
 * הגופנים — OFL (רישיון חופשי, אפשר להטמיע ב-PDF וב-.mbe). גופן שהמשתמש העלה — באחריותו (האפליקציה מזהירה).
 */
import { h } from './dom.js';

export const FONTS = [
    { family: 'Heebo', weights: [100, 200, 300, 400, 500, 600, 700, 800, 900] },
    { family: 'Assistant', weights: [200, 300, 400, 500, 600, 700, 800] },
    { family: 'Rubik', weights: [300, 400, 500, 600, 700, 800, 900] },
    { family: 'Secular One', weights: [400] },
    { family: 'Frank Ruhl Libre', weights: [300, 400, 500, 600, 700, 800, 900], serif: true },
    { family: 'Suez One', weights: [400], serif: true },
    { family: 'Varela Round', weights: [400] },
    { family: 'Alef', weights: [400, 700] },
    { family: 'Karantina', weights: [400, 700] },
    { family: 'Amatic SC', weights: [400, 700] },
];

export const DEFAULT_FONT = 'Heebo';

/** CSS font-family עם חלופה מתאימה */
export const fontStack = family => `"${family}", ${FONTS.find(f => f.family === family)?.serif ? 'serif' : 'system-ui, sans-serif'}`;

/** המשקל הקרוב ביותר שיש לגופן — כדי שלא יצויר "מודגש מזויף". */
export function nearestWeight(family, weight) {
    const f = FONTS.find(x => x.family === family);
    if (!f || !f.weights.length) return 400;
    return f.weights.reduce((a, b) => (Math.abs(b - weight) < Math.abs(a - weight) ? b : a));
}

const loaded = new Map();

/** טוען גופן ומשקל (עברית ולטינית). ההבטחה נפתרת גם בכישלון — אז מצויר הגופן החלופי. */
export function ensureFont(family, weight = 400) {
    const w = nearestWeight(family, weight);
    const key = family + ':' + w;
    if (!loaded.has(key)) {
        const spec = `${w} 32px "${family}"`;
        loaded.set(key, Promise.all([document.fonts.load(spec, 'אב'), document.fonts.load(spec, 'Ab')]).catch(() => {}));
    }
    return loaded.get(key);
}

const registered = new Set();
export const faceKey = a => `${a.family}|${a.weight || 400}|${a.style || 'normal'}`;
export const FONT_MIME = { woff2: 'font/woff2', woff: 'font/woff', ttf: 'font/ttf', otf: 'font/otf' };

/** סוג הקובץ לפי החתימה (ואם לא — לפי הסיומת). */
export function fontMime(bytes, ext = '') {
    const head = String.fromCharCode(...bytes.subarray(0, 4));
    return head === 'wOF2' ? FONT_MIME.woff2 : head === 'wOFF' ? FONT_MIME.woff : head === 'OTTO' ? FONT_MIME.otf : FONT_MIME[ext] || FONT_MIME.ttf;
}

/** גופן (Blob) → FontFace בדף, ושורה ב-FONTS (custom: true). קובץ פגום — נזרק כאן, לפני שהוא נכנס לרשימה. */
export async function registerFont({ family, weight = 400, style = 'normal', blob }) {
    const k = faceKey({ family, weight, style });
    if (registered.has(k)) return;
    const face = new FontFace(family, await blob.arrayBuffer(), { weight: String(weight), style });
    await face.load();
    document.fonts.add(face);
    registered.add(k);
    let f = FONTS.find(x => x.family === family);
    if (!f) FONTS.push(f = { family, weights: [], custom: true });
    if (!f.weights.includes(weight)) { f.weights.push(weight); f.weights.sort((a, b) => a - b); }
    loaded.delete(family + ':' + weight);
}

const WEIGHT_WORDS = [[/hairline|thin/i, 100], [/extra-?light|ultra-?light/i, 200], [/light/i, 300], [/medium/i, 500],
    [/semi-?bold|demi-?bold/i, 600], [/extra-?bold|ultra-?bold|heavy/i, 800], [/black/i, 900], [/bold/i, 700]];

/** קובץ גופן → { family, weight, style, bytes }: מטבלאות name ו-OS/2 (TTF, OTF, WOFF), ואם אין — משם הקובץ. */
export async function readFontInfo(file) {
    const bytes = new Uint8Array(await file.arrayBuffer());
    const base = file.name.replace(/\.[^.]+$/, '');
    let info = null;
    try { info = await sfntInfo(bytes); } catch { /* WOFF2 או קובץ לא רגיל — לפי השם */ }
    const byName = WEIGHT_WORDS.find(([re]) => re.test(base))?.[1] || 400;
    const family = (info?.family || base.replace(/[-_ ]*(hairline|thin|extra-?light|ultra-?light|light|regular|book|normal|medium|semi-?bold|demi-?bold|extra-?bold|ultra-?bold|heavy|black|bold|italic|oblique)*$/gi, '').replace(/[-_]+/g, ' ')).trim().slice(0, 60) || 'הגופן שלי';
    return { family, weight: info?.weight || byName, style: info?.italic || /italic|oblique/i.test(base) ? 'italic' : 'normal', bytes };
}

async function sfntInfo(b) {
    const dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
    const tag = String.fromCharCode(...b.subarray(0, 4));
    const tables = {};
    if (tag === 'wOFF') {
        const n = dv.getUint16(12);
        for (let i = 0; i < n; i++) {
            const o = 44 + i * 20, t = String.fromCharCode(...b.subarray(o, o + 4));
            if (t !== 'name' && t !== 'OS/2') continue;
            const off = dv.getUint32(o + 4), comp = dv.getUint32(o + 8), orig = dv.getUint32(o + 12);
            let data = b.subarray(off, off + comp);
            if (comp < orig) data = new Uint8Array(await new Response(new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate'))).arrayBuffer());
            tables[t] = data;
        }
    } else if (tag === 'OTTO' || tag === 'true' || dv.getUint32(0) === 0x10000) {
        const n = dv.getUint16(4);
        for (let i = 0; i < n; i++) {
            const o = 12 + i * 16, t = String.fromCharCode(...b.subarray(o, o + 4));
            if (t === 'name' || t === 'OS/2') tables[t] = b.subarray(dv.getUint32(o + 8), dv.getUint32(o + 8) + dv.getUint32(o + 12));
        }
    } else return null;
    const out = {};
    if (tables['OS/2']?.length >= 64) {
        const os = new DataView(tables['OS/2'].buffer, tables['OS/2'].byteOffset, tables['OS/2'].byteLength);
        const w = os.getUint16(4);
        if (w >= 100 && w <= 900) out.weight = Math.round(w / 100) * 100;
        out.italic = !!(os.getUint16(62) & 1);
    }
    const nm = tables.name;
    if (nm) {
        const ndv = new DataView(nm.buffer, nm.byteOffset, nm.byteLength);
        const count = ndv.getUint16(2), strOff = ndv.getUint16(4);
        let best = null, score = -1;
        for (let i = 0; i < count; i++) {
            const r = 6 + i * 12;
            const plat = ndv.getUint16(r), lang = ndv.getUint16(r + 4), id = ndv.getUint16(r + 6), len = ndv.getUint16(r + 8), off = ndv.getUint16(r + 10);
            if (id !== 1 && id !== 16) continue;
            const raw = nm.subarray(strOff + off, strOff + off + len);
            const text = plat === 3 || plat === 0 ? new TextDecoder('utf-16be').decode(raw) : String.fromCharCode(...raw);
            // שם המשפחה הטיפוגרפי (16) לפני הרגיל (1); עברית, ואז אנגלית, ואז כל שפה
            const sc = (id === 16 ? 4 : 0) + (lang === 0x040d ? 2 : lang === 0x0409 || lang === 0 ? 1 : 0) + (plat === 3 ? 0.5 : 0);
            if (text.trim() && sc > score) { score = sc; best = text.trim(); }
        }
        if (best) out.family = best;
    }
    return out;
}

/**
 * "הגופנים שלי" — מה שהמשתמש העלה, במכשיר. key — רשימה ב-localStorage; assets — האחסון של האפליקציה לקבצים
 * ({ get(id) → Blob, put(blob) → id }; למשל kv() של store.js). גשר עד שהספרייה בענן היא המקור; כשהיא מחוברת —
 * syncCloud מוריד ממנה, ו-saveToCloud שולח אליה.
 */
export function userFonts({ key = 'uic-my-fonts', assets }) {
    const list = () => { try { return JSON.parse(localStorage.getItem(key)) || []; } catch { return []; } };
    const save = l => { try { localStorage.setItem(key, JSON.stringify(l)); } catch { /* אין אחסון — לשיחה הזו */ } };
    return {
        list,
        /** בהפעלה: כל מה שהועלה במכשיר הזה. */
        async load() {
            for (const m of list()) {
                const blob = await assets.get(m.src);
                if (blob) await registerFont({ ...m, blob }).catch(() => {});
            }
        },
        /** העלאה: הקובץ נבדק (הדפדפן טוען אותו), נשמר במכשיר ונכנס לרשימה. מחזיר את השורה. */
        async add(file, info) {
            info ??= await readFontInfo(file);
            const ext = (/\.([a-z0-9]+)$/i.exec(file.name)?.[1] || '').toLowerCase();
            const blob = new Blob([info.bytes], { type: fontMime(info.bytes, ext) });
            const entry = { family: info.family, weight: info.weight, style: info.style };
            await registerFont({ ...entry, blob });
            entry.src = await assets.put(blob);
            save([...list().filter(m => faceKey(m) !== faceKey(entry)), entry]);
            return entry;
        },
        /** מסמכים שמשתמשים בגופן שומרים עותק משלהם, ולכן נשארים כמו שהם. */
        remove(entry) { save(list().filter(m => faceKey(m) !== faceKey(entry))); },
        /** ענן החבילה → המכשיר: מה שחסר או שהתעדכן (rev אחר). מחזיר כמה ירדו. */
        async syncCloud(drives, cloudId = '') {
            const { fonts = [] } = await drives.fonts.list();
            let n = 0;
            for (const item of fonts) {
                const have = list().find(m => m.lib?.item === item.id);
                if (have && have.lib.rev === item.rev) continue;
                const { bytes } = await drives.fonts.load(item);
                const blob = new Blob([bytes], { type: fontMime(bytes, item.format) });
                const entry = { family: item.family, weight: item.weight || 400, style: item.style || 'normal', lib: { cloud: cloudId, item: item.id, rev: item.rev } };
                await registerFont({ ...entry, blob });
                entry.src = await assets.put(blob);
                save([...list().filter(m => faceKey(m) !== faceKey(entry) && m.lib?.item !== item.id), entry]);
                n++;
            }
            return n;
        },
        /** המכשיר → הספרייה בענן (drives.fonts.add). האפליקציה הציגה את אזהרת הרישיון לפני כן. */
        async saveToCloud(drives, cloudId, entry) {
            const blob = await assets.get(entry.src);
            if (!blob) throw new Error('הקובץ של הגופן לא נמצא במכשיר');
            const ext = Object.entries(FONT_MIME).find(([, m]) => m === blob.type)?.[0] || 'ttf';
            const item = await drives.fonts.add(new File([blob], `${entry.family}-${entry.weight}.${ext}`, { type: blob.type }),
                { family: entry.family, weight: entry.weight, style: entry.style });
            const next = { ...entry, lib: { cloud: cloudId || '', item: item.id, rev: item.rev } };
            save(list().map(m => faceKey(m) === faceKey(entry) ? next : m));
            return next;
        },
    };
}

let openOne = null;

/**
 * בורר גופנים: כפתור עם שם הגופן (כתוב בו), ומתחתיו רשימה — כל גופן כתוב בגופן עצמו, עם דוגמה.
 * opts: { sample = 'אבג דהו Abc', fonts = () => FONTS, onUpload: file => …, accept = '.ttf,.otf,.woff,.woff2' }
 * גופנים מותאמים (custom) — למעלה, עם ★. onChange(family) — בבחירה (צעד ביטול אחד).
 */
export function fontPicker(label, value, onChange, opts = {}) {
    const { sample = 'אבג דהו Abc', fonts = () => FONTS, onUpload = null, accept = '.ttf,.otf,.woff,.woff2' } = opts;
    let current = value || DEFAULT_FONT;
    const id = 'fp' + Math.random().toString(36).slice(2, 8);
    const name = h('span.fp-name');
    const trigger = h('button.fp-trigger', { type: 'button', id, 'aria-expanded': 'false', 'aria-controls': id + '-p', 'aria-haspopup': 'listbox' }, name, h('span.fp-caret', { 'aria-hidden': 'true' }, '▾'));
    const search = h('input.fp-search', { type: 'search', placeholder: 'חיפוש גופן', 'aria-label': 'חיפוש גופן', autocomplete: 'off' });
    const list = h('ul.fp-list', { role: 'listbox', 'aria-label': label });
    const file = onUpload ? h('input', { type: 'file', accept, hidden: true, onchange: async () => { const f = file.files[0]; file.value = ''; if (f) { await onUpload(f); render(); } } }) : null;
    const upload = onUpload ? h('button.btn.ghost.sm.fp-upload', { type: 'button', onclick: () => file.click() }, '＋ העלאת גופן') : null;
    const panel = h('div.fp-panel', { id: id + '-p', hidden: true }, search, list, upload, file);
    const paint = () => { name.textContent = current; name.style.fontFamily = fontStack(current); trigger.setAttribute('aria-label', `${label}: ${current}`); };

    const pick = family => { current = family; paint(); setOpen(false); ensureFont(family).then(() => onChange(family)); trigger.focus(); };
    function render() {
        const q = search.value.trim().toLowerCase();
        const all = [...fonts()].sort((a, b) => (b.custom ? 1 : 0) - (a.custom ? 1 : 0));
        const shown = all.filter(f => !q || f.family.toLowerCase().includes(q));
        list.replaceChildren(...(shown.length ? shown.map(f => {
            ensureFont(f.family);   // רק כשהרשימה פתוחה — הדפדפן מוריד את הקבצים לפי הצורך
            return h('li.fp-opt', { role: 'option', tabindex: '-1', 'aria-selected': String(f.family === current), 'data-family': f.family,
                onclick: () => pick(f.family), onkeydown: e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(f.family); } } },
                h('span.fp-fam', { style: { fontFamily: fontStack(f.family) } }, f.custom ? '★ ' : '', f.family),
                h('span.fp-sample', { style: { fontFamily: fontStack(f.family) } }, sample));
        }) : [h('li.empty', 'לא נמצא')]));
    }
    search.addEventListener('input', render);
    list.addEventListener('keydown', e => {
        const opts = [...list.querySelectorAll('.fp-opt')], i = opts.indexOf(document.activeElement);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); opts[Math.max(0, Math.min(opts.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))]?.focus(); }
        if (e.key === 'Escape') { setOpen(false); trigger.focus(); }
    });
    search.addEventListener('keydown', e => { if (e.key === 'ArrowDown') { e.preventDefault(); list.querySelector('.fp-opt')?.focus(); } if (e.key === 'Escape') setOpen(false); });

    function setOpen(open) {
        if (open && openOne && openOne !== setOpen) openOne(false);
        panel.hidden = !open;
        trigger.setAttribute('aria-expanded', String(open));
        trigger.classList.toggle('open', open);
        openOne = open ? setOpen : openOne === setOpen ? null : openOne;
        if (open) { search.value = ''; render(); (list.querySelector('[aria-selected="true"]') || list.firstElementChild)?.scrollIntoView?.({ block: 'nearest' }); }
    }
    trigger.addEventListener('click', () => setOpen(panel.hidden));
    paint();
    const el = h('div.f.fp-field', h('label', { for: id }, label), trigger, panel);
    el.setValue = v => { current = v; paint(); };
    return el;
}
