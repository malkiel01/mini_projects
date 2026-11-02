/*
 * Markdown לבועות הצ'אט — כדי שמה שהצ'אט כותב (כותרות, הדגשות, ציטוטים, רשימות,
 * טבלאות, קוד) ייראה בלוח כמו בצ'אט, ולא כסולמיות וכוכביות.
 *
 * בטיחות: אין כאן innerHTML בכלל. parseMarkdown מחזיר עץ נתונים פשוט, ו-renderMarkdown
 * בונה ממנו צמתים עם createElement ו-textContent — כך שטקסט מהצ'אט לעולם לא מתפרש כ-HTML.
 * קישורים — רק http/https (javascript: ב-href הוא הרצת קוד אצל מי שלוחץ).
 *
 * parseMarkdown לא נוגע ב-DOM, ולכן נבדק ב-node (tests/markdown.mjs).
 */

const FENCE = /^\s*(```+|~~~+)\s*([\w+-]*)\s*$/;
const HEADING = /^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/;
const HR = /^\s{0,3}([-*_])(\s*\1){2,}\s*$/;
const QUOTE = /^\s{0,3}>\s?(.*)$/;
const ITEM = /^(\s*)([-*+]|\d{1,9}[.)])\s+(.*)$/;
const TABLE_SEP = /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/;

const isBlank = (l) => l.trim() === '';
const isTableRow = (l) => l.includes('|') && l.trim() !== '|';

/** שורת טבלה ← תאים. ‏\| נשאר תו רגיל. */
function cells(line) {
  let s = line.trim();
  if (s.startsWith('|')) s = s.slice(1);
  if (s.endsWith('|') && !s.endsWith('\\|')) s = s.slice(0, -1);
  return s.split(/(?<!\\)\|/).map((c) => c.trim().replace(/\\\|/g, '|'));
}

function align(sep) {
  return cells(sep).map((c) => (c.startsWith(':') && c.endsWith(':') ? 'center'
    : c.endsWith(':') ? 'end' : c.startsWith(':') ? 'start' : ''));
}

/** האם השורה פותחת בלוק שאינו פסקה — כדי לדעת איפה פסקה נגמרת. */
function startsBlock(lines, i) {
  const l = lines[i];
  return FENCE.test(l) || HEADING.test(l) || HR.test(l) || QUOTE.test(l) || ITEM.test(l)
    || (isTableRow(l) && i + 1 < lines.length && TABLE_SEP.test(lines[i + 1]));
}

/* ── בלוקים ─────────────────────────────────────────────────── */

export function parseMarkdown(src) {
  return blocks(String(src ?? '').replace(/\r\n?/g, '\n').split('\n'), 0);
}

function blocks(lines, depth) {
  const out = [];
  let i = 0;
  while (i < lines.length) {
    const line = lines[i];
    if (isBlank(line)) { i++; continue; }

    let m = line.match(FENCE);
    if (m) {
      const close = m[1][0];
      const body = [];
      for (i++; i < lines.length && !new RegExp(`^\\s*\\${close}{${m[1].length},}\\s*$`).test(lines[i]); i++) body.push(lines[i]);
      i++;   // הגדר הסוגר (או סוף הטקסט — בלוק פתוח נסגר בסוף)
      out.push({ t: 'code', lang: m[2] || '', text: body.join('\n') });
      continue;
    }

    m = line.match(HEADING);
    if (m) { out.push({ t: 'h', level: m[1].length, c: inline(m[2]) }); i++; continue; }

    if (HR.test(line)) { out.push({ t: 'hr' }); i++; continue; }

    if (QUOTE.test(line)) {
      const inner = [];
      while (i < lines.length && !isBlank(lines[i])) {
        const q = lines[i].match(QUOTE);
        if (q) inner.push(q[1]);
        else if (startsBlock(lines, i)) break;
        else inner.push(lines[i]);   // המשך עצל של הציטוט
        i++;
      }
      out.push({ t: 'quote', c: depth < 8 ? blocks(inner, depth + 1) : [{ t: 'p', c: inline(inner.join('\n')) }] });
      continue;
    }

    if (ITEM.test(line)) { i = list(lines, i, out, depth); continue; }

    if (isTableRow(line) && i + 1 < lines.length && TABLE_SEP.test(lines[i + 1])) {
      const head = cells(line);
      const al = align(lines[i + 1]);
      const rows = [];
      for (i += 2; i < lines.length && isTableRow(lines[i]) && !isBlank(lines[i]); i++) {
        const r = cells(lines[i]);
        rows.push(head.map((_, k) => inline(r[k] ?? '')));
      }
      out.push({ t: 'table', align: head.map((_, k) => al[k] || ''), head: head.map((h) => inline(h)), rows });
      continue;
    }

    // פסקה: עד שורה ריקה או בלוק אחר. שורה חדשה נשמרת כשבירת שורה — כך כותבים בצ'אט.
    const para = [];
    while (i < lines.length && !isBlank(lines[i]) && (para.length === 0 || !startsBlock(lines, i))) para.push(lines[i++].trim());
    out.push({ t: 'p', c: inline(para.join('\n')) });
  }
  return out;
}

/** רשימה: פריט, ושורות ההמשך המוזחות שלו (כולל תת-רשימה). */
function list(lines, i, out, depth) {
  const first = lines[i].match(ITEM);
  const indent = first[1].length;
  const ordered = /\d/.test(first[2]);
  const node = { t: ordered ? 'ol' : 'ul', start: ordered ? parseInt(first[2], 10) : 1, items: [] };
  while (i < lines.length) {
    // פריט באותה רמה ומאותו סוג ממשיך; אחר — הרשימה נגמרת (סוג אחר פותח רשימה חדשה)
    const m = lines[i].match(ITEM);
    if (!m || m[1].length !== indent || /\d/.test(m[2]) !== ordered) break;
    const body = [m[3]];
    let task = null;
    const t = m[3].match(/^\[([ xX])\]\s+(.*)$/);
    if (t) { task = t[1] !== ' '; body[0] = t[2]; }
    for (i++; i < lines.length; i++) {
      const l = lines[i];
      if (isBlank(l)) {
        // שורה ריקה בתוך פריט — רק אם מה שאחריה מוזח פנימה
        const next = lines[i + 1];
        if (next !== undefined && next.match(/^(\s*)/)[1].length > indent && !isBlank(next)) { body.push(''); continue; }
        break;
      }
      const lead = l.match(/^(\s*)/)[1].length;
      const sub = l.match(ITEM);
      if (sub && lead <= indent) break;
      if (lead > indent) body.push(l.slice(Math.min(lead, indent + 2)));
      else if (!startsBlock(lines, i)) body.push(l.trim());   // המשך עצל
      else break;
    }
    const kids = depth < 8 ? blocks(body, depth + 1) : [{ t: 'p', c: inline(body.join('\n')) }];
    // פריט של פסקה אחת — בלי <p> סביבו (כמו בצ'אט: צפוף)
    node.items.push({ task, c: kids.length === 1 && kids[0].t === 'p' ? kids[0].c : null, blocks: kids.length === 1 && kids[0].t === 'p' ? null : kids });
  }
  out.push(node);
  return i;
}

/* ── בתוך שורה ──────────────────────────────────────────────── */

const SAFE_URL = /^https?:\/\/[^\s<>"']+$/i;
const INLINE = new RegExp([
  /(`+)([^`]|[^`][\s\S]*?[^`])\1(?!`)/.source,                       // 1,2 קוד
  /\*\*(?=\S)([\s\S]*?\S)\*\*/.source,                                 // 3 מודגש
  /(?<![\p{L}\p{N}_])__(?=\S)([\s\S]*?\S)__(?![\p{L}\p{N}_])/.source,  // 4 מודגש
  /~~(?=\S)([\s\S]*?\S)~~/.source,                                     // 5 קו חוצה
  /\*(?=[^\s*])([^*\n]*?[^\s*])\*/.source,                             // 6 נטוי
  /(?<![\p{L}\p{N}_])_(?=[^\s_])([^_\n]*?[^\s_])_(?![\p{L}\p{N}_])/.source,  // 7 נטוי (לא snake_case)
  /\[([^\]\n]+)\]\(\s*<?([^\s)>]+)>?\s*\)/.source,                     // 8,9 קישור
  /(https?:\/\/[^\s<>"']*[^\s<>"'.,;:!?)\]])/.source,                  // 10 כתובת חשופה
  /\\([\\`*_{}[\]()#+\-.!|~>])/.source,                                 // 11 תו מוגן
  /( {2,}|\\)\n/.source,                                                // 12 בירת שורה קשה
].join('|'), 'gu');

export function inline(text, depth = 0) {
  const out = [];
  const push = (s) => {
    if (!s) return;
    // שורה חדשה בתוך פסקה — בירת שורה (בצ'אט כל Enter נראה)
    s.split('\n').forEach((part, k) => {
      if (k) out.push({ t: 'br' });
      if (part) {
        if (typeof out[out.length - 1] === 'string') out[out.length - 1] += part;
        else out.push(part);
      }
    });
  };
  if (depth > 6) { push(text); return out; }
  let last = 0;
  for (const m of text.matchAll(INLINE)) {
    push(text.slice(last, m.index));
    last = m.index + m[0].length;
    if (m[2] !== undefined) out.push({ t: 'code', text: m[2].length > 2 && m[2].startsWith(' ') && m[2].endsWith(' ') ? m[2].slice(1, -1) : m[2] });
    else if (m[3] !== undefined || m[4] !== undefined) out.push({ t: 'b', c: inline(m[3] ?? m[4], depth + 1) });
    else if (m[5] !== undefined) out.push({ t: 's', c: inline(m[5], depth + 1) });
    else if (m[6] !== undefined || m[7] !== undefined) out.push({ t: 'i', c: inline(m[6] ?? m[7], depth + 1) });
    else if (m[8] !== undefined) {
      out.push(SAFE_URL.test(m[9]) ? { t: 'a', href: m[9], c: inline(m[8], depth + 1) } : { t: 'span', c: inline(m[8], depth + 1) });
    } else if (m[10] !== undefined) out.push({ t: 'a', href: m[10], c: [m[10]] });
    else if (m[11] !== undefined) push(m[11]);
    else if (m[12] !== undefined) out.push({ t: 'br' });
  }
  push(text.slice(last));
  return out;
}

/* ── עץ ← DOM ──────────────────────────────────────────────── */

function make(tag, attrs = {}, kids = []) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) if (v !== '' && v !== null && v !== undefined) n.setAttribute(k, v);
  for (const k of kids) n.append(k);
  return n;
}

function inlineDom(nodes) {
  return nodes.map((x) => {
    if (typeof x === 'string') return document.createTextNode(x);
    switch (x.t) {
      case 'br': return make('br');
      case 'code': { const c = make('code', { dir: 'ltr' }); c.textContent = x.text; return c; }
      case 'b': return make('strong', {}, inlineDom(x.c));
      case 'i': return make('em', {}, inlineDom(x.c));
      case 's': return make('del', {}, inlineDom(x.c));
      case 'a': return make('a', { href: x.href, target: '_blank', rel: 'noopener noreferrer nofollow' }, inlineDom(x.c));
      default: return make('span', {}, inlineDom(x.c || []));
    }
  });
}

function blockDom(b) {
  switch (b.t) {
    case 'h': return make(`h${Math.min(6, b.level + 2)}`, { dir: 'auto', class: `md-h md-h${b.level}` }, inlineDom(b.c));
    case 'hr': return make('hr');
    case 'quote': return make('blockquote', { dir: 'auto' }, b.c.map(blockDom));
    case 'code': {
      const code = make('code', b.lang ? { 'data-lang': b.lang } : {});
      code.textContent = b.text;
      return make('pre', { dir: 'ltr', tabindex: '0' }, [code]);
    }
    case 'ul': case 'ol':
      return make(b.t, { dir: 'auto', start: b.t === 'ol' && b.start !== 1 ? String(b.start) : '' }, b.items.map((it) => {
        const kids = it.c ? inlineDom(it.c) : it.blocks.map(blockDom);
        if (it.task !== null) kids.unshift(make('span', { class: 'md-task', 'aria-label': it.task ? 'בוצע' : 'פתוח' }, [it.task ? '☑' : '☐']));
        return make('li', { class: it.task !== null ? 'md-task-item' : '' }, kids);
      }));
    case 'table': {
      const th = b.head.map((c, k) => make('th', { dir: 'auto', style: b.align[k] ? `text-align:${b.align[k]}` : '' }, inlineDom(c)));
      const rows = b.rows.map((r) => make('tr', {}, r.map((c, k) => make('td', { dir: 'auto', style: b.align[k] ? `text-align:${b.align[k]}` : '' }, inlineDom(c)))));
      // טבלה רחבה גוללת בתוך הבועה, לא מותחת את המסך
      return make('div', { class: 'md-table', tabindex: '0' }, [make('table', {}, [make('thead', {}, [make('tr', {}, th)]), make('tbody', {}, rows)])]);
    }
    default: return make('p', { dir: 'auto' }, inlineDom(b.c));
  }
}

/** טקסט Markdown ← DocumentFragment בטוח להצגה. */
export function renderMarkdown(src) {
  const frag = document.createDocumentFragment();
  for (const b of parseMarkdown(src)) frag.append(blockDom(b));
  return frag;
}
