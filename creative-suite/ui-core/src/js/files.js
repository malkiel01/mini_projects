/**
 * ui-core/files.js — הורדה, בחירת קבצים וגרירת קבצים לדף.
 * מקור: download של סטודיו העיצוב (גשר אנדרואיד) ושל הווקטורי (בתוך <dialog> פתוח), fileName של הרסטר,
 * pickFiles של סטודיו העיצוב, והגרירה של ענן החבילה.
 *
 *   await download(blob, fileName('כרטיס ברכה', 'png'))
 *   const files = await pickFiles({ accept: 'image/*', multiple: true })
 *   const off = dropZone(stage, { accept: ['image/', '.mbe'], onFiles: files => … })
 *   setSaveBridge(window.GreetingCardsAndroid)      // אפליקציית אנדרואיד (WebView) — פעם אחת
 */
import { h } from './dom.js';

let bridge = null;

/** באפליקציית אנדרואיד (WebView) קישור blob: לא יורד. גשר עם saveFile(base64, name, mime) → '' או הודעת שגיאה. */
export function setSaveBridge(b) { bridge = b?.saveFile ? b : null; }

export async function download(blob, name) {
    if (bridge) {
        const b64 = await new Promise((res, rej) => {
            const r = new FileReader();
            r.onload = () => res(String(r.result).split(',')[1] || '');
            r.onerror = () => rej(r.error);
            r.readAsDataURL(blob);
        });
        const err = bridge.saveFile(b64, name, blob.type || 'application/octet-stream');
        if (err) throw new Error(err);
        return { bridge: true };
    }
    const a = h('a', { href: URL.createObjectURL(blob), download: name, hidden: true });
    // בתוך <dialog> פתוח — שם, אחרת בחלק מהדפדפנים הלחיצה לא עוברת מתחת לשכבה העליונה.
    ([...document.querySelectorAll('dialog[open]')].pop() || document.body).append(a);
    a.click();
    a.remove();
    // שחרור מאוחר: דפדפנים מסוימים מתחילים את ההורדה רק אחרי שהלחיצה הסתיימה.
    setTimeout(() => URL.revokeObjectURL(a.href), 60000);
    return { bridge: false };
}

/** שם קובץ בטוח מכותרת: בלי תווים אסורים ותווי בקרה, עד 80 תווים, עם סיומת. */
export function fileName(title, ext) {
    const base = String(title || '').replace(/[\u0000-\u001f\u007f<>:"/\\|?*‎‏⁦-⁩]+/g, ' ')
        .replace(/\s+/g, ' ').trim().replace(/^\.+/, '').slice(0, 80).trim() || 'ללא שם';
    return ext ? `${base}.${String(ext).replace(/^\./, '')}` : base;
}

/** בחירת קבצים. ביטול → מערך ריק (בדפדפנים שמודיעים על ביטול; באחרים ההבטחה פשוט לא נפתרת). */
export function pickFiles({ accept = '', multiple = false } = {}) {
    return new Promise(resolve => {
        const input = h('input', { type: 'file', accept, multiple, hidden: true });
        input.addEventListener('change', () => { resolve([...input.files]); input.remove(); });
        input.addEventListener('cancel', () => { resolve([]); input.remove(); });
        document.body.append(input);
        input.click();
    });
}

/**
 * גרירת קבצים מהמחשב לאלמנט. accept: מערך של 'image/' (קידומת סוג) או '.mbe' (סיומת); ריק — הכול.
 * בזמן גרירה — מחלקה dropping על האלמנט. מחזיר פונקציה שמנתקת.
 */
export function dropZone(el, { accept = [], onFiles, onReject = null } = {}) {
    let depth = 0;
    const ok = f => !accept.length || accept.some(a => a.startsWith('.') ? f.name.toLowerCase().endsWith(a.toLowerCase()) : (f.type || '').startsWith(a));
    const hasFiles = e => [...(e.dataTransfer?.types || [])].includes('Files');
    const enter = e => { if (!hasFiles(e)) return; e.preventDefault(); depth++; el.classList.add('dropping'); };
    const over = e => { if (!hasFiles(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; };
    const leave = () => { if (--depth <= 0) { depth = 0; el.classList.remove('dropping'); } };
    const drop = e => {
        if (!hasFiles(e)) return;
        e.preventDefault();
        depth = 0;
        el.classList.remove('dropping');
        const all = [...e.dataTransfer.files], good = all.filter(ok), bad = all.filter(f => !ok(f));
        if (bad.length) onReject?.(bad);
        if (good.length) onFiles(good);
    };
    el.addEventListener('dragenter', enter);
    el.addEventListener('dragover', over);
    el.addEventListener('dragleave', leave);
    el.addEventListener('drop', drop);
    return () => {
        el.removeEventListener('dragenter', enter);
        el.removeEventListener('dragover', over);
        el.removeEventListener('dragleave', leave);
        el.removeEventListener('drop', drop);
    };
}
