/**
 * גרסת המבנה של מסמך (attrs.schema בצומת השורש) והמרה מגרסאות ישנות.
 * אותו כלל בשרת: doc_migrate ב-lib/docs.php. כל שינוי מבנה = מספר חדש + צעד המרה בשני המקומות.
 *
 *   0 → 1  מסמך בלי attrs. הגדרות העמוד עוברות לתוך המסמך (attrs.page) — כאן רק מוסיפים אותן אם נמסרו.
 */
import { SCHEMA_VERSION } from './extensions.js';

export { SCHEMA_VERSION };

/** מחזיר עותק בגרסה הנוכחית. page — הגדרות העמוד מהשרת, אם הן לא בתוך המסמך. */
export function migrate(json, page = null) {
    const doc = structuredClone(json && json.type === 'doc' ? json : { type: 'doc', content: [{ type: 'paragraph' }] });
    doc.attrs = { ...(doc.attrs || {}) };
    let v = Number(doc.attrs.schema) || 0;
    if (v > SCHEMA_VERSION) throw Object.assign(new Error('המסמך נשמר בגרסה חדשה יותר של העורך — רעננו את הדף'), { code: 'schema_newer' });
    // if (v < 2) { …; v = 2; }   — צעדי המרה עתידיים
    if (v < 1) v = 1;
    doc.attrs.schema = v;
    if (page && !doc.attrs.page) doc.attrs.page = page;
    return doc;
}
