// מיזוג נתונים: כרטיס לכל נמען (spec/03-editor.md, "מיזוג נתונים").
//
// בטקסט כותבים משתנה בסוגריים כפולים — {{שם}} — וכל עמודה ברשימה היא משתנה. הרשימה נשמרת בתוך העיצוב
// (doc.merge), כך שהיא עוברת בין מכשירים יחד איתו.

import { loadScript } from './io.js';

export const VAR_RE = /\{\{\s*([^{}]+?)\s*\}\}/g;

/** הטקסט עם הערכים של שורה אחת. משתנה שאין לו עמודה — נשאר כמו שהוא, כדי שיהיה ברור מה חסר. */
export function fillText(text, row) {
    if (!row) return text;
    return String(text).replace(VAR_RE, (m, name) => (name in row ? String(row[name] ?? '') : m));
}

/** כל המשתנים שמופיעים בטקסטים של העיצוב. */
export function docVariables(doc) {
    const out = new Set();
    for (const l of doc.layers) if (l.type === 'text') for (const m of String(l.text).matchAll(VAR_RE)) out.add(m[1]);
    return [...out];
}

// ─── קריאת רשימה ──────────────────────────────────────────────────────────

const clean = v => String(v ?? '').replace(/\s+/g, ' ').trim();

/** טבלה (מערך של מערכים) → עמודות ושורות. השורה הראשונה היא הכותרות; שורות ריקות — מדולגות. */
function fromTable(table) {
    const [head = [], ...body] = table;
    const columns = [];
    head.forEach((h, i) => {
        let name = clean(h) || `עמודה ${i + 1}`;
        while (columns.includes(name)) name += '׳';
        columns.push(name);
    });
    const rows = body
        .map(r => Object.fromEntries(columns.map((c, i) => [c, clean(r[i])])))
        .filter(r => Object.values(r).some(Boolean));
    if (!columns.length || !rows.length) throw new Error('לא נמצאו שורות. בשורה הראשונה — כותרות, ומתחתיה הנמענים');
    if (rows.length > 5000) throw new Error('יותר מ-5,000 שורות — פצלו את הקובץ');
    return { columns, rows };
}

/** קובץ אקסל או CSV. ספריית האקסל נטענת רק כשצריך אותה. */
export async function readSheetFile(file) {
    await loadScript('vendor/xlsx.mini.min.js');
    const wb = window.XLSX.read(await file.arrayBuffer(), { type: 'array', codepage: 65001 });
    const ws = wb.Sheets[wb.SheetNames[0]];
    return fromTable(window.XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '' }));
}

/** הדבקה מגיליון (טאבים) או CSV פשוט. */
export function readPasted(text) {
    const lines = String(text).split(/\r?\n/).filter(l => l.trim());
    const sep = lines[0]?.includes('\t') ? '\t' : ',';
    return fromTable(lines.map(l => l.split(sep)));
}

/** אנשי קשר מאפליקציית האנדרואיד (הגשר). */
export function readPhoneContacts() {
    const bridge = window.GreetingCardsAndroid;
    const res = JSON.parse(bridge.readContacts());
    if (res.error) throw new Error(res.error === 'permission_denied' ? 'אין הרשאה לאנשי הקשר' : res.error);
    return fromTable([['שם', 'טלפון'], ...res.contacts.map(c => [c.name, c.phone])]);
}

// ─── עמודות מיוחדות ───────────────────────────────────────────────────────

/** ניחוש עמודות השם והטלפון לפי הכותרת, או לפי התוכן. */
export function guessColumns({ columns, rows }) {
    const find = re => columns.find(c => re.test(c));
    let phone = find(/טלפון|נייד|פלאפון|phone|mobile|tel/i);
    if (!phone) phone = columns.find(c => rows.slice(0, 20).filter(r => normalizePhone(r[c])).length >= Math.min(3, rows.length));
    const name = find(/^שם|name|נמען/i) || columns.find(c => c !== phone);
    return { nameColumn: name || null, phoneColumn: phone || null, fileColumn: name || null };
}

/** מספר טלפון לצורה בינלאומית בלי +, או null. מספר ישראלי מקומי (05x, 0x) — עם 972. */
export function normalizePhone(v) {
    let d = String(v ?? '').replace(/[^\d+]/g, '');
    if (!d) return null;
    if (d.startsWith('+')) d = d.slice(1);
    else if (d.startsWith('00')) d = d.slice(2);
    else if (/^0[2-9]/.test(d)) d = '972' + d.slice(1);
    return /^\d{9,15}$/.test(d) ? d : null;
}

/** שם קובץ בטוח, ייחודי בתוך הרשימה. */
export function fileNames(merge) {
    const used = new Map();
    return merge.rows.map((r, i) => {
        let base = String((merge.fileColumn && r[merge.fileColumn]) || `כרטיס ${i + 1}`).replace(/[\\/:*?"<>|]+/g, ' ').trim().slice(0, 60) || `כרטיס ${i + 1}`;
        const n = (used.get(base) || 0) + 1;
        used.set(base, n);
        return n > 1 ? `${base} (${n})` : base;
    });
}
