// קלט ופלט: תמונות, ייבוא מהצ'אט (design.json, ‏design.gcard), ייצוא PNG/JPG/PDF, והסקיל.
// הספריות הכבדות (JSZip, pdf-lib) נטענות רק כשצריך אותן.

import { normalizeDoc, extractJson, newDoc, backgroundLayer, clone } from './doc.js';
import { assets } from './db.js';
import { FONTS } from './fonts.js';
import { SIZES } from './doc.js';

const MAX_IMAGE_SIDE = 4096;      // תמונה גדולה מזה מוקטנת בכניסה — אין טעם לשמור 8000px בטלפון
const MAX_FILE = 25 * 1024 * 1024;

const scripts = new Map();
export function loadScript(src) {
    if (!scripts.has(src)) {
        scripts.set(src, new Promise((res, rej) => {
            const s = document.createElement('script');
            s.src = src; s.onload = res; s.onerror = () => rej(new Error('טעינה נכשלה: ' + src));
            document.head.append(s);
        }));
    }
    return scripts.get(src);
}

// ─── תמונות ────────────────────────────────────────────────────────────────

export function blobToImage(blob) {
    return new Promise((res, rej) => {
        const img = new Image();
        img.onload = () => res(img);
        img.onerror = () => rej(new Error('התמונה לא נפתחה'));
        img.src = URL.createObjectURL(blob);
    });
}

/**
 * מכניס תמונה מהמשתמש: פענוח (בדיקה שזו באמת תמונה), הקטנה אם ענקית, ושמירה ב-IndexedDB.
 * @returns {{src:string, img:HTMLImageElement, w:number, h:number}}
 */
export async function ingestImage(file) {
    if (file.size > MAX_FILE) throw new Error(`"${file.name}" גדול מ-25MB`);
    let img = await blobToImage(file);
    let blob = file;
    const big = Math.max(img.naturalWidth, img.naturalHeight);
    if (big > MAX_IMAGE_SIDE) {
        const k = MAX_IMAGE_SIDE / big;
        const c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * k);
        c.height = Math.round(img.naturalHeight * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        const png = /png|webp|gif/.test(file.type);
        blob = await new Promise(r => c.toBlob(r, png ? 'image/png' : 'image/jpeg', 0.92));
        URL.revokeObjectURL(img.src);
        img = await blobToImage(blob);
    }
    const src = await assets.put(blob);
    return { src, img, w: img.naturalWidth, h: img.naturalHeight };
}

// ─── ייבוא מהצ'אט ──────────────────────────────────────────────────────────

/**
 * מקבל את כל מה שיש למשתמש: קבצים (תמונות, design.json, ‏design.gcard/zip) וטקסט שהודבק.
 * מחזיר מסמך חדש, התמונות שלו, ורשימת תיקונים. בלי JSON — התמונה הראשונה נכנסת כרקע.
 */
export async function importFromChat(files, pastedText) {
    const images = new Map();       // שם קובץ (באותיות קטנות) → File/Blob
    let json = null;
    const fixes = [];

    for (const f of files) {
        const name = f.name.toLowerCase();
        if (name.endsWith('.gcard') || name.endsWith('.zip')) {
            await loadScript('vendor/jszip.min.js');
            const zip = await window.JSZip.loadAsync(f);
            let total = 0;
            for (const entry of Object.values(zip.files)) {
                // בלי נתיבים: רק שם הקובץ. ‏"../" וכדומה פשוט לא מגיעים לשום מקום.
                if (entry.dir) continue;
                const base = entry.name.split('/').pop().toLowerCase();
                if (!base || base.startsWith('.')) continue;
                if (base === 'design.json') { json = extractJson(await entry.async('string')); continue; }
                if (!/\.(png|jpe?g|webp|gif)$/.test(base)) continue;
                const blob = await entry.async('blob');
                total += blob.size;
                if (total > 60 * 1024 * 1024) throw new Error('הקובץ גדול מדי אחרי פריסה');
                images.set(base, new File([blob], base, { type: base.endsWith('png') ? 'image/png' : base.endsWith('webp') ? 'image/webp' : 'image/jpeg' }));
            }
        } else if (name.endsWith('.json') || f.type === 'application/json') {
            json = extractJson(await f.text());
        } else if (f.type.startsWith('image/')) {
            images.set(name, f);
        }
    }
    if (!json && pastedText && pastedText.trim()) {
        // טקסט בלי JSON (למשל כיתוב ששותף יחד עם התמונה) — לא חוסם, כל עוד יש תמונה.
        try { json = extractJson(pastedText); } catch (e) { if (!images.size) throw e; }
    }

    if (!json) {
        const first = [...images.values()][0];
        if (!first) throw new Error('לא נבחרו תמונה או קובץ עיצוב');
        const im = await ingestImage(first);
        const doc = newDoc(im.w, im.h, 'רקע מהצ\'אט');
        doc.source = { app: 'chat' };
        doc.assets.bg = { type: 'image', src: im.src };
        doc.layers.push(backgroundLayer(doc, 'bg', im.w, im.h));
        if (images.size > 1) fixes.push(`בלי קובץ עיצוב — נכנסה רק התמונה הראשונה (${first.name})`);
        return { doc, loaded: new Map([['bg', im.img]]), fixes };
    }

    const { doc, fixes: f2 } = normalizeDoc(json);
    fixes.push(...f2);
    const loaded = new Map();
    const unnamed = [...images.values()];
    for (const [id, a] of Object.entries(doc.assets)) {
        // התאמה לפי שם הקובץ; אם הצ'אט שינה את השם — תמונה אחת שנשארה תותאם לנכס אחד שנשאר.
        let file = images.get((a.file || '').toLowerCase());
        if (!file && Object.keys(doc.assets).length === 1 && unnamed.length === 1) file = unnamed[0];
        if (!file) { fixes.push(`חסרה התמונה "${a.file || id}"`); continue; }
        const im = await ingestImage(file);
        a.src = im.src;
        delete a.file;
        loaded.set(id, im.img);
    }
    // שכבות שהתמונה שלהן חסרה — נשארות כמקום שמור (מלבן מקווקו), כדי שאפשר יהיה להחליף.
    return { doc, loaded, fixes };
}

// ─── ייצוא ─────────────────────────────────────────────────────────────────

export function canvasToBlob(canvas, type = 'image/png', quality = 0.92) {
    return new Promise((res, rej) => canvas.toBlob(b => (b ? res(b) : rej(new Error('הייצוא נכשל — התמונה גדולה מדי למכשיר'))), type, quality));
}

/**
 * PDF בגודל הדף (לפי 300dpi), עם התמונה של הדף. טקסט וקטורי עם פונט מוטמע — בשלב הבא
 * (spec/09-roadmap.md); כרגע הרזולוציה מספיקה להדפסה חדה.
 */
export async function exportPdf(canvas, doc) {
    await loadScript('vendor/pdf-lib.min.js');
    const { PDFDocument } = window.PDFLib;
    const pdf = await PDFDocument.create();
    pdf.setTitle(doc.title || 'עיצוב');
    pdf.setCreator('כרטיסי ברכה — העורך');
    const jpg = await canvasToBlob(canvas, 'image/jpeg', 0.95);
    const image = await pdf.embedJpg(new Uint8Array(await jpg.arrayBuffer()));
    const dpi = doc.page.width >= 1700 ? 300 : 150;
    const w = (doc.page.width / dpi) * 72, h = (doc.page.height / dpi) * 72;
    const page = pdf.addPage([w, h]);
    page.drawImage(image, { x: 0, y: 0, width: w, height: h });
    return new Blob([await pdf.save()], { type: 'application/pdf' });
}

/** design.gcard — עיצוב שלם להעברה: design.json + assets/. אותו פורמט שהצ'אט מייצר. */
export async function exportGcard(doc) {
    await loadScript('vendor/jszip.min.js');
    const zip = new window.JSZip();
    const out = clone(doc);
    for (const [id, a] of Object.entries(out.assets)) {
        const blob = await assets.get(a.src);
        if (!blob) continue;
        const ext = blob.type === 'image/png' ? 'png' : blob.type === 'image/webp' ? 'webp' : 'jpg';
        a.file = `${id}.${ext}`;
        delete a.src;
        zip.file('assets/' + a.file, blob);
    }
    zip.file('design.json', JSON.stringify(out, null, 2));
    return zip.generateAsync({ type: 'blob', mimeType: 'application/zip' });
}

export function safeName(s) {
    return (String(s || 'עיצוב').replace(/[\\/:*?"<>|]+/g, ' ').trim() || 'עיצוב').slice(0, 60);
}

// ─── הסקיל ─────────────────────────────────────────────────────────────────

/** הסקיל הבסיסי (skill/SKILL.md), עם הפונטים והגדלים של העורך. */
export async function personalSkill() {
    const res = await fetch('skill/SKILL.md', { cache: 'no-cache' });
    if (!res.ok) throw new Error('הסקיל לא נטען');
    const base = await res.text();
    return base
        .replace(/<!--[\s\S]*?-->\n*/, '')
        .replaceAll('{{FONTS}}', FONTS.map(f => f.family).join(', '))
        .replaceAll('{{SIZES}}', SIZES.map(s => `${s.label} ${s.w}×${s.h}`).join(' · '))
        .replaceAll('{{BRAND}}', '');
}

/** לצ'אטים שאין בהם העלאת סקיל: בלי ה-frontmatter. */
export function skillAsPrompt(skill) {
    return skill.replace(/^---[\s\S]*?---\s*/, '');
}

/** ל-Claude: תיקייה מכווצת עם SKILL.md. */
export async function skillZip(skill) {
    await loadScript('vendor/jszip.min.js');
    const zip = new window.JSZip();
    zip.file('design-background-for-editor/SKILL.md', skill);
    return zip.generateAsync({ type: 'blob', mimeType: 'application/zip' });
}
