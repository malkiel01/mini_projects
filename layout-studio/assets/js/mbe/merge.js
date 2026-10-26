// מיזוג פנים שחזרו (docs/spec/hub-api.md 2.3–2.4, סעיף 3): reconcile מחזיר תוכנית ולא נוגע ב-f; applyMerge מחיל אותה.
// אותו קוד ב-PHP (src/php/merge.php).
//
//   const id = await identify(bytes);                               // face.js
//   const plan = await reconcile(f, { docId: id.docId, base: id.base, baseDocument: id.document,
//                                      flows: { f1: pmDocFromWord }, app: 'external:word', who: 'המגיה' });
//   plan.conflicts → שאלה למשתמש
//   applyMerge(f, plan, { 'f1/p17': 'theirs' });                   // ואחר כך save — נרשמים return ו-merge
//
// יחידה = בלוק ברמה העליונה של השטף; המפתח שלו — attrs.id שלו, או של הפסקה הראשונה שבתוכו (רשימה, טבלה).
// בלוק שחזר בלי מזהה (Word איבד את הסימנייה, או פסקה חדשה) — מותאם לפי תוכן מול בלוקי הבסיס שלא חזרו:
// עוגנים של טקסט זהה וייחודי (patience), LCS מדויק ביניהם, ופסקה שנערכה — Dice על מילים ≥ 0.6.

import { canonicalJson, utf8 } from './canonical.js';
import { sha256, ensureIds, MbeError } from './mbe.js';

export const MERGE_SPAN = 5000;   // קטע בלי עוגן גדול מזה — רק עוגנים (hub-api.md סעיף 6)
const DICE_WINDOW = 50;            // כמה בלוקים קדימה מחפשים פסקה שנערכה
const WS = /[\s\u00a0\u1680\u2000-\u200b\u2028\u2029\u202f\u205f\u3000\ufeff]+/;

const clone = v => JSON.parse(JSON.stringify(v));
const same = (a, b) => canonicalJson(a) === canonicalJson(b);
const isObj = v => v && typeof v === 'object' && !Array.isArray(v);
const issue = (code, message) => ({ level: 'error', code, message });

/** המפתח של בלוק: המזהה הראשון בסדר המסמך (הבלוק עצמו, או הפסקה הראשונה שבתוכו). */
function blockKey(node, depth = 0) {
    if (!isObj(node) || depth > 200) return null;
    if ((node.type === 'paragraph' || node.type === 'heading') && typeof node.attrs?.id === 'string') return node.attrs.id;
    if (Array.isArray(node.content)) for (const c of node.content) { const k = blockKey(c, depth + 1); if (k !== null) return k; }
    return null;
}

/** נותן לבלוק מפתח: לפסקה — attrs.id; לבלוק מכיל — לפסקה הראשונה שבתוכו. */
function setKey(node, key, depth = 0) {
    if (!isObj(node) || depth > 200) return false;
    if (node.type === 'paragraph' || node.type === 'heading') { node.attrs = isObj(node.attrs) ? node.attrs : {}; node.attrs.id = key; return true; }
    if (Array.isArray(node.content)) for (const c of node.content) if (setKey(c, key, depth + 1)) return true;
    return false;
}

function textOf(node, depth = 0) {
    if (!isObj(node) || depth > 200) return '';
    let s = typeof node.text === 'string' ? node.text : '';
    if (Array.isArray(node.content)) for (const c of node.content) { const t = textOf(c, depth + 1); if (t) s += (s ? ' ' : '') + t; }
    return s;
}

const content = flow => (isObj(flow) && isObj(flow.doc) && Array.isArray(flow.doc.content)) ? flow.doc.content : null;

/**
 * רצף בלוקים → { seq: [key], map: key → block }. בלוק בלי פסקה בכלל (קו מפריד, תמונה) — מפתח לפי התוכן שלו
 * ("~" + JSON קנוני + מספר המופע), כך שאותו בלוק בשלושת הצדדים מזוהה כאחד, ואף בלוק לא הולך לאיבוד.
 */
function index(blocks) {
    const seq = [], map = new Map(), n = new Map();
    for (const b of blocks) {
        let k = blockKey(b);
        if (k === null || map.has(k)) {
            const c = '~' + canonicalJson(b);
            n.set(c, (n.get(c) || 0) + 1);
            k = c + '#' + n.get(c);
        }
        seq.push(k); map.set(k, b);
    }
    return { seq, map };
}

// ---- התאמה לפי תוכן ----

function words(t) {
    const m = new Map();
    let n = 0;
    for (const w of t.split(WS)) if (w) { m.set(w, (m.get(w) || 0) + 1); n++; }
    return { m, n };
}

function similar(a, b) {
    if (a.n === 0 || b.n === 0) return false;
    if (5 * Math.min(a.n, b.n) < 2 * Math.max(a.n, b.n)) return false; // Dice ≥ 0.6 דורש יחס אורכים ≥ 3/7
    let common = 0;
    for (const [w, c] of a.m) { const d = b.m.get(w); if (d) common += Math.min(c, d); }
    return 10 * common >= 3 * (a.n + b.n); // 2·common / (na + nb) ≥ 0.6
}

/** LIS על ia (רצף ממוין לפי ib) — העוגנים. */
function lis(pairs) {
    const tails = [], prev = new Array(pairs.length);
    for (let i = 0; i < pairs.length; i++) {
        let lo = 0, hi = tails.length;
        while (lo < hi) { const mid = (lo + hi) >> 1; if (pairs[tails[mid]][0] < pairs[i][0]) lo = mid + 1; else hi = mid; }
        prev[i] = lo > 0 ? tails[lo - 1] : -1;
        tails[lo] = i;
    }
    const out = [];
    for (let i = tails.length ? tails[tails.length - 1] : -1; i >= 0; i = prev[i]) out.push(pairs[i]);
    return out.reverse();
}

/**
 * A, B — טקסטים. מחזיר { pairs: [[ia, ib]], limited } בסדר עולה. limited — קטע בלי עוגן מעל MERGE_SPAN, שבו רק עוגנים.
 */
export function alignByContent(A, B) {
    const pairs = [];
    let limited = false;
    const wa = A.map(words), wb = B.map(words);
    const code = new Map();
    const ca = A.map(t => { if (!code.has(t)) code.set(t, code.size); return code.get(t); });
    const cb = B.map(t => { if (!code.has(t)) code.set(t, code.size); return code.get(t); });

    const dice = (a0, a1, b0, b1) => { // פסקאות שנערכו, לפי הסדר, בחלון קדימה
        let a = a0;
        for (let j = b0; j < b1 && a < a1; j++) {
            for (let i = a; i < Math.min(a1, a + DICE_WINDOW); i++) {
                if (similar(wa[i], wb[j])) { pairs.push([i, j]); a = i + 1; break; }
            }
        }
    };
    const lcs = (a0, a1, b0, b1) => {
        const n = a1 - a0, m = b1 - b0;
        const dir = new Uint8Array(n * m);
        let next = new Uint16Array(m + 1), row = new Uint16Array(m + 1);
        for (let i = n - 1; i >= 0; i--) {
            row[m] = 0;
            for (let j = m - 1; j >= 0; j--) {
                if (ca[a0 + i] === cb[b0 + j]) { row[j] = next[j + 1] + 1; dir[i * m + j] = 1; }
                else if (next[j] >= row[j + 1]) { row[j] = next[j]; dir[i * m + j] = 2; }
                else { row[j] = row[j + 1]; dir[i * m + j] = 3; }
            }
            [next, row] = [row, next];
        }
        let i = 0, j = 0, la = 0, lb = 0;
        while (i < n && j < m) {
            const d = dir[i * m + j];
            if (d === 1) { dice(a0 + la, a0 + i, b0 + lb, b0 + j); pairs.push([a0 + i, b0 + j]); i++; j++; la = i; lb = j; }
            else if (d === 2) i++; else j++;
        }
        dice(a0 + la, a1, b0 + lb, b1);
    };
    const rec = (a0, a1, b0, b1) => {
        while (a0 < a1 && b0 < b1 && ca[a0] === cb[b0]) pairs.push([a0++, b0++]);
        while (a1 > a0 && b1 > b0 && ca[a1 - 1] === cb[b1 - 1]) pairs.push([--a1, --b1]);
        if (a0 >= a1 || b0 >= b1) return;
        const cnt = new Map(); // code → [בכמה ב-A, בכמה ב-B, אינדקס ב-A]
        for (let i = a0; i < a1; i++) { const e = cnt.get(ca[i]) || [0, 0, i]; e[0]++; cnt.set(ca[i], e); }
        for (let j = b0; j < b1; j++) { const e = cnt.get(cb[j]); if (e) e[1]++; }
        const cand = [];
        for (let j = b0; j < b1; j++) { const e = cnt.get(cb[j]); if (e && e[0] === 1 && e[1] === 1) cand.push([e[2], j]); }
        const anchors = lis(cand);
        if (anchors.length) {
            let pa = a0, pb = b0;
            for (const [i, j] of anchors) { rec(pa, i, pb, j); pairs.push([i, j]); pa = i + 1; pb = j + 1; }
            rec(pa, a1, pb, b1);
            return;
        }
        if (a1 - a0 > MERGE_SPAN || b1 - b0 > MERGE_SPAN) { limited = true; return; }
        lcs(a0, a1, b0, b1);
    };
    rec(0, A.length, 0, B.length);
    // פסקה שהוזזה ממקומה — לא נכנסת לרצף; מעבר שני: טקסט זהה, בלי קשר לסדר
    const usedA = new Set(pairs.map(p => p[0])), usedB = new Set(pairs.map(p => p[1]));
    const free = new Map();
    for (let i = 0; i < A.length; i++) if (!usedA.has(i)) { if (!free.has(ca[i])) free.set(ca[i], []); free.get(ca[i]).push(i); }
    for (let j = 0; j < B.length; j++) {
        const q = usedB.has(j) ? null : free.get(cb[j]);
        if (q && q.length && A[q[0]] !== '') pairs.push([q.shift(), j]);
    }
    pairs.sort((x, y) => x[0] - y[0]);
    return { pairs, limited };
}

// ---- reconcile ----

/** תוכנית מיזוג. לא משנה את f. */
export async function reconcile(f, { docId = null, base = null, baseDocument = null, flows = {}, styles = null, app = 'external', who = null } = {}) {
    const plan = { status: 'diverged', docId, base, app, who, changes: [], conflicts: [], gaps: [], issues: [], flows: {} };
    if (!docId || docId !== f.id) {
        plan.status = 'other-doc';
        plan.issues.push(issue('face-unknown-doc', 'זה מסמך אחר — המזהה שבפנים אינו של הקובץ הזה'));
        return plan;
    }
    const ours = f.document;
    const ourSha = await sha256(utf8(canonicalJson(ours)));
    let baseDoc = null;
    if (base && base === ourSha) baseDoc = ours;
    else if (base) baseDoc = f.version(base);
    if (!baseDoc && isObj(baseDocument) && base && await sha256(utf8(canonicalJson(baseDocument))) === base) {
        baseDoc = baseDocument;
        plan.baseDocument = clone(baseDocument); // לא בזיכרון — נשמר כגרסה עם שלב return
    }
    if (!baseDoc) {
        plan.issues.push(issue('face-base-missing', 'הבסיס של מה שחזר לא נמצא — לא בהיסטוריה ולא בפנים; ממשיכים כייבוא רגיל'));
        delete plan.flows;
        return plan;
    }
    const ff = base === ourSha;
    const returned = clone(baseDoc);
    if (!isObj(returned.flows)) returned.flows = {};
    let byContent = 0;

    for (const fid of Object.keys(flows).sort()) {
        const tdoc = flows[fid];
        const oursFlow = ours.flows?.[fid], baseFlow = baseDoc.flows?.[fid];
        if (!isObj(tdoc) || !Array.isArray(tdoc.content)) { plan.gaps.push(`flow:${fid} — מה שחזר אינו מסמך ProseMirror`); continue; }
        if (!content(oursFlow)) { plan.gaps.push(`flow:${fid} — ${baseFlow ? 'נמחק אצלנו' : 'לא קיים במסמך'}; לא מוזג`); continue; }
        const B = index(content(baseFlow) || []), O = index(content(oursFlow));
        const theirs = clone(tdoc.content);

        // מפתחות שחזרו: כפול — לא נחשב; בלי מפתח — התאמה לפי תוכן מול בלוקי הבסיס שלא חזרו
        const seen = new Set(), loose = [];
        theirs.forEach((b, i) => { const k = blockKey(b); if (k !== null && !seen.has(k)) seen.add(k); else loose.push(i); });
        const missing = B.seq.filter(k => !seen.has(k));
        if (loose.length && missing.length) {
            const { pairs, limited } = alignByContent(missing.map(k => textOf(B.map.get(k))), loose.map(i => textOf(theirs[i])));
            for (const [ia, ib] of pairs) if (setKey(theirs[loose[ib]], missing[ia])) { seen.add(missing[ia]); byContent++; }
            if (limited) plan.gaps.push(`merge:${fid} — קטע של יותר מ-${MERGE_SPAN} פסקאות בלי עוגן; הותאם רק לפי טקסט זהה`);
        }
        const tdocFixed = { ...clone(tdoc), content: theirs };
        ensureIds(tdocFixed); // חסר / כפול → מזהה חדש (כפול מהדבקה — חדש)
        const T = index(theirs);
        returned.flows[fid] = { ...(isObj(returned.flows[fid]) ? returned.flows[fid] : clone(oursFlow)), doc: tdocFixed };

        // השוואה לכל מפתח
        const keep = new Map(); // key → block (לא התנגשות)
        const conflict = new Set();
        const all = [...new Set([...B.seq, ...O.seq, ...T.seq])];
        for (const k of all) {
            const b = B.map.get(k), o = O.map.get(k), t = T.map.get(k);
            const change = op => plan.changes.push({ flow: fid, para: k, op, side: 'theirs' });
            const clash = kind => { conflict.add(k); plan.conflicts.push({ flow: fid, para: k, kind, base: b ?? null, ours: o ?? null, theirs: t ?? null }); };
            if (b && o && t) {
                if (same(t, b) || same(o, t)) keep.set(k, o);
                else if (same(o, b)) { keep.set(k, t); change('edit'); }
                else clash('both-edited');
            } else if (b && o) {
                if (same(o, b)) change('delete'); else clash('edited-deleted');
            } else if (b && t) {
                if (!same(t, b)) clash('edited-deleted');
            } else if (o && t) {
                if (same(o, t)) keep.set(k, o); else clash('both-edited');
            } else if (o) keep.set(k, o);
            else if (t) { keep.set(k, t); change('insert'); }
        }

        // הסדר: לפי הצד שהזיז; הזזה בשני הצדדים — שלנו, ו-gaps
        const inAll = k => B.map.has(k) && O.map.has(k) && T.map.has(k);
        const bc = B.seq.filter(inAll), oc = O.seq.filter(inAll), tc = T.seq.filter(inAll);
        const ourMoved = oc.join('\n') !== bc.join('\n'), theirMoved = tc.join('\n') !== bc.join('\n');
        const primary = theirMoved && !ourMoved ? T.seq : O.seq, other = primary === T.seq ? O.seq : T.seq;
        if (theirMoved && ourMoved && oc.join('\n') !== tc.join('\n')) plan.gaps.push(`order:${fid} — הסדר השתנה בשני הצדדים; נשאר הסדר שלנו`);
        if (theirMoved && !ourMoved) {
            const pos = new Map(bc.map((k, i) => [k, i]));
            const stay = new Set(lis(tc.map((k, j) => [pos.get(k), j])).map(([, j]) => tc[j]));
            for (const k of tc) if (!stay.has(k)) plan.changes.push({ flow: fid, para: k, op: 'move', side: 'theirs' });
        }
        const live = k => keep.has(k) || conflict.has(k);
        const order = primary.filter(live);
        const placed = new Set(order);
        let last = null;
        for (const k of other) {
            if (placed.has(k)) { last = k; continue; }
            if (!live(k)) continue;
            order.splice(last === null ? 0 : order.indexOf(last) + 1, 0, k);
            placed.add(k);
            last = k;
        }
        plan.flows[fid] = { order, blocks: Object.fromEntries([...keep].map(([k, b]) => [k, b])) };
    }
    if (byContent) plan.gaps.push(`merge:by-content — ${byContent} פסקאות הותאמו לפי תוכן (בלי סימנייה)`);

    // סגנונות שזוהו בחוץ ואינם במסמך; משאבים שאינם בזיכרון (קובץ שנבנה מ-customXml)
    const styleNames = Array.isArray(styles) ? styles : isObj(styles) ? Object.keys(styles) : [];
    for (const s of styleNames) if (typeof s === 'string' && !Object.hasOwn(isObj(ours.styles) ? ours.styles : {}, s)) plan.gaps.push(`style:${s} — לא קיים במסמך`);
    const walk = (layers, depth = 0) => {
        if (!Array.isArray(layers) || depth > 200) return;
        for (const l of layers) {
            if (!isObj(l)) continue;
            for (const ref of [l.src, l.source, l.mask?.src]) {
                if (typeof ref === 'string' && !f.files.has(ref)) plan.gaps.push(`media:${l.type ?? 'layer'} ${l.id} — לא בזיכרון`);
            }
            walk(l.children, depth + 1);
        }
    };
    for (const s of Array.isArray(ours.surfaces) ? ours.surfaces : []) walk(s?.layers);

    plan.returned = returned;
    plan.status = !ff ? 'diverged' : plan.changes.length ? 'fast-forward' : 'same';
    return plan;
}

// ---- applyMerge ----

/** מחיל תוכנית: answers — { 'f1/p17': 'ours' | 'theirs' | 'both' } לכל התנגשות. משנה את f.document; return ו-merge — בשמירה הבאה. */
export async function applyMerge(f, plan, answers = {}) {
    const errors = (plan.issues || []).filter(i => i.level === 'error');
    if (plan.status === 'other-doc' || errors.length) throw new MbeError('אי אפשר למזג: ' + errors.map(e => e.code).join(', '), errors);
    const missing = plan.conflicts.filter(c => !['ours', 'theirs', 'both'].includes(answers[`${c.flow}/${c.para}`]));
    if (missing.length) {
        const list = missing.map(c => `${c.flow}/${c.para}`);
        throw new MbeError('יש התנגשויות בלי תשובה: ' + list.join(', '), list.map(p => issue('merge-unanswered', `אין תשובה להתנגשות ${p}`)));
    }
    if (plan.status === 'same') return f;
    const byKey = new Map(plan.conflicts.map(c => [`${c.flow}/${c.para}`, c]));
    for (const [fid, { order, blocks }] of Object.entries(plan.flows)) {
        const out = [];
        for (const k of order) {
            const c = byKey.get(`${fid}/${k}`);
            if (!c) { out.push(clone(blocks[k])); continue; }
            const a = answers[`${fid}/${k}`];
            if (a !== 'theirs' && c.ours) out.push(clone(c.ours));
            if (a !== 'ours' && c.theirs) out.push(clone(c.theirs));
        }
        const flow = f.document.flows[fid];
        flow.doc = { ...flow.doc, content: out };
        ensureIds(flow.doc); // 'both' על אותה פסקה — השנייה מקבלת מזהה חדש
    }
    const retBytes = utf8(canonicalJson(plan.returned));
    const ret = await sha256(retBytes);
    const snapshots = { [ret]: retBytes };
    if (plan.baseDocument) snapshots[plan.base] = utf8(canonicalJson(plan.baseDocument));
    f.pending.push({ kind: 'return', app: plan.app, who: plan.who, note: null, base: plan.base, result: ret, snapshots });
    f.pending.push({ kind: 'merge', who: plan.who, note: plan.conflicts.length ? `${plan.conflicts.length} התנגשויות` : null });
    return f;
}
