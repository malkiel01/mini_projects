/**
 * DOM מינימלי ל-Node — רק מה ש-js/kol-data.js ו-assets/js/editor/bind.js נוגעים בו (V11, bind-parity.mjs).
 * אלמנטים, טקסט, <template>.content, תכונות לפי סדר ההוספה, classList, innerHTML (מנתח פשוט לקטעים תקינים), textContent,
 * querySelector(All) לבוררים פשוטים (תגית, .מחלקה, [תכונה], [תכונה="ערך"], [תכונה^="ערך"], :not בלי), ו-outerHTML לסידור.
 * אינו מנסה להיות דפדפן: שני המימושים רצים על אותו DOM, ולכן ההשוואה ביניהם הוגנת.
 */
const VOID = new Set('area base br col embed hr img input link meta source track wbr'.split(' '));
const ENT = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };
export const decode = s => String(s).replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e) => e[0] === '#' ? String.fromCodePoint(e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : +e.slice(1)) : (ENT[e.toLowerCase()] ?? m));
const escText = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/ /g, '&nbsp;');
const escAttr = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/ /g, '&nbsp;');

class Node {
  constructor(doc) { this.ownerDocument = doc; this.parentNode = null; this.childNodes = []; }
  get firstChild() { return this.childNodes[0] || null; }
  get children() { return this.childNodes.filter(c => c.nodeType === 1); }
  get parentElement() { return this.parentNode && this.parentNode.nodeType === 1 ? this.parentNode : null; }
  appendChild(c) { return this.insertBefore(c, null); }
  insertBefore(c, ref) {
    if (c.nodeType === 11) { for (const k of c.childNodes.slice()) this.insertBefore(k, ref); return c; }
    if (c.parentNode) c.parentNode.removeChild(c);
    const i = ref ? this.childNodes.indexOf(ref) : -1;
    if (i < 0) this.childNodes.push(c); else this.childNodes.splice(i, 0, c);
    c.parentNode = this; return c;
  }
  removeChild(c) { const i = this.childNodes.indexOf(c); if (i >= 0) this.childNodes.splice(i, 1); c.parentNode = null; return c; }
  get textContent() { return this.childNodes.map(c => c.nodeType === 3 ? c.data : c.textContent).join(''); }
  set textContent(v) { for (const c of this.childNodes) c.parentNode = null; this.childNodes = []; if (v !== '' && v != null) this.appendChild(this.ownerDocument.createTextNode(String(v))); }
}
class Text extends Node { constructor(doc, data) { super(doc); this.nodeType = 3; this.data = String(data); } cloneNode() { return new Text(this.ownerDocument, this.data); } get nodeValue() { return this.data; } }
class Fragment extends Node {
  constructor(doc) { super(doc); this.nodeType = 11; }
  cloneNode(deep) { const f = new Fragment(this.ownerDocument); if (deep) for (const c of this.childNodes) f.appendChild(c.cloneNode(true)); return f; }
  querySelectorAll(sel) { return qsa(this, sel); }
  querySelector(sel) { return qsa(this, sel)[0] || null; }
}
class ClassList {
  constructor(el) { this.el = el; }
  list() { return (this.el.getAttribute('class') || '').split(/\s+/).filter(Boolean); }
  contains(c) { return this.list().includes(c); }
  add(...cs) { const l = this.list(); let ch = false; for (const c of cs) if (!l.includes(c)) { l.push(c); ch = true; } if (ch || this.el.hasAttribute('class')) this.el.setAttribute('class', l.join(' ')); }
  remove(...cs) { if (!this.el.hasAttribute('class')) return; this.el.setAttribute('class', this.list().filter(c => !cs.includes(c)).join(' ')); }
  toggle(c, on) { const has = this.contains(c), want = on === undefined ? !has : !!on; if (want && !has) this.add(c); if (!want && has) this.remove(c); return want; }
}
class Element extends Node {
  constructor(doc, tag) { super(doc); this.nodeType = 1; this.localName = tag.toLowerCase(); this.attributes = []; this.classList = new ClassList(this); this.style = { display: '' }; if (this.localName === 'template') this.content = new Fragment(doc); }
  get tagName() { return this.localName.toUpperCase(); }
  getAttribute(n) { const a = this.attributes.find(x => x.name === n.toLowerCase()); return a ? a.value : null; }
  hasAttribute(n) { return this.attributes.some(x => x.name === n.toLowerCase()); }
  setAttribute(n, v) { n = n.toLowerCase(); if (!/^[^\s"'>\/=]+$/.test(n)) throw new Error('InvalidCharacterError: ' + n); const a = this.attributes.find(x => x.name === n); if (a) a.value = String(v); else this.attributes.push({ name: n, value: String(v) }); }
  removeAttribute(n) { this.attributes = this.attributes.filter(x => x.name !== n.toLowerCase()); }
  get hidden() { return this.hasAttribute('hidden'); }
  set hidden(v) { if (v) this.setAttribute('hidden', ''); else this.removeAttribute('hidden'); }
  get id() { return this.getAttribute('id') || ''; }
  cloneNode(deep) {
    const e = new Element(this.ownerDocument, this.localName);
    e.attributes = this.attributes.map(a => ({ name: a.name, value: a.value }));
    if (deep) { for (const c of this.childNodes) e.appendChild(c.cloneNode(true)); if (this.content) e.content = this.content.cloneNode(true); }
    return e;
  }
  get innerHTML() { return (this.content ? this.content.childNodes : this.childNodes).map(serialize).join(''); }
  set innerHTML(html) {
    for (const c of this.childNodes) c.parentNode = null; this.childNodes = [];
    const host = this.content || this; if (this.content) this.content.childNodes = [];
    parseInto(this.ownerDocument, host, String(html));
  }
  get outerHTML() { return serialize(this); }
  get value() { return this.localName === 'textarea' ? decode(this.innerHTML) : (this._value ?? this.getAttribute('value') ?? ''); }
  set value(v) { this._value = String(v); }
  matches(sel) { return matchSel(this, sel); }
  closest(sel) { for (let e = this; e && e.nodeType === 1; e = e.parentNode) if (matchSel(e, sel)) return e; return null; }
  querySelectorAll(sel) { return qsa(this, sel); }
  querySelector(sel) { return qsa(this, sel)[0] || null; }
  addEventListener() {}
}
export function serialize(n) {
  if (n.nodeType === 3) return escText(n.data);
  if (n.nodeType === 11) return n.childNodes.map(serialize).join('');
  const a = n.attributes.map(x => ' ' + x.name + (x.value === '' ? '=""' : '="' + escAttr(x.value) + '"')).join('');
  if (VOID.has(n.localName)) return '<' + n.localName + a + '>';
  const kids = n.content ? n.content.childNodes : n.childNodes;
  return '<' + n.localName + a + '>' + kids.map(serialize).join('') + '</' + n.localName + '>';
}
// ── מנתח HTML פשוט: קטעים תקינים (כמו בתבניות ובנתונים של הבדיקה) ──
function parseInto(doc, host, html) {
  const stack = [host]; let i = 0;
  const top = () => { const t = stack[stack.length - 1]; return t.content || t; };
  while (i < html.length) {
    if (html.startsWith('<!--', i)) { const e = html.indexOf('-->', i); i = e < 0 ? html.length : e + 3; continue; }
    if (html[i] === '<' && html[i + 1] === '/') {
      const e = html.indexOf('>', i), name = html.slice(i + 2, e).trim().toLowerCase(); i = e + 1;
      for (let k = stack.length - 1; k > 0; k--) if (stack[k].localName === name) { stack.length = k; break; }
      continue;
    }
    if (html[i] === '<' && /[a-z]/i.test(html[i + 1] || '')) {
      const m = /^<([a-z][\w-]*)/i.exec(html.slice(i)); let j = i + m[0].length; const el = new Element(doc, m[1]);
      for (;;) {
        while (/\s/.test(html[j])) j++;
        if (html[j] === '>' || html[j] === '/' || j >= html.length) break;
        const am = /^([^\s"'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/.exec(html.slice(j));
        if (!am) { j++; continue; }
        if (!el.hasAttribute(am[1])) el.attributes.push({ name: am[1].toLowerCase(), value: decode(am[2] ?? am[3] ?? am[4] ?? '') });
        j += am[0].length;
      }
      const self = html[j] === '/'; j = html.indexOf('>', j) + 1; i = j;
      top().appendChild(el);
      if (!VOID.has(el.localName) && !self) stack.push(el);
      continue;
    }
    const e = html.indexOf('<', i + 1), end = e < 0 ? html.length : e;
    top().appendChild(new Text(doc, decode(html.slice(i, end)))); i = end;
  }
}
// ── בוררים ──
function parts(sel) {
  const out = []; const re = /([a-z][\w-]*)|\.([\w-]+)|\[([^\]=^~*$|\s]+)\s*(?:([\^~*$|]?=)\s*(?:"([^"]*)"|'([^']*)'|([^\]\s]+)))?\s*\]|(\*)/gyi; let m;
  re.lastIndex = 0; sel = sel.trim();
  while (re.lastIndex < sel.length && (m = re.exec(sel))) {
    if (m[1]) out.push(e => e.localName === m[1].toLowerCase());
    else if (m[2]) out.push(e => e.classList.contains(m[2]));
    else if (m[3]) { const n = m[3].toLowerCase(), op = m[4], v = m[5] ?? m[6] ?? m[7]; out.push(e => { const x = e.getAttribute(n); if (x == null) return false; if (!op) return true; if (op === '=') return x === v; if (op === '^=') return x.startsWith(v); if (op === '*=') return x.includes(v); if (op === '~=') return x.split(/\s+/).includes(v); return false; }); }
  }
  if (re.lastIndex !== sel.length && sel.length) throw new Error('בורר שאינו נתמך ב-mini-dom: ' + sel);
  return out;
}
function matchSel(e, sel) { return sel.split(',').some(s => { const ws = s.trim().split(/\s+/); const last = parts(ws[ws.length - 1]); if (!last.every(f => f(e))) return false; let p = e.parentNode; for (let k = ws.length - 2; k >= 0; k--) { const f = parts(ws[k]); while (p && p.nodeType === 1 && !f.every(x => x(p))) p = p.parentNode; if (!p || p.nodeType !== 1) return false; p = p.parentNode; } return true; }); }
function qsa(root, sel) { const out = []; const go = n => { for (const c of n.childNodes) if (c.nodeType === 1) { if (matchSel(c, sel)) out.push(c); go(c); } }; go(root); return out; }

export class Document extends Node {
  constructor() { super(null); this.ownerDocument = this; this.nodeType = 9; this.readyState = 'complete'; this.currentScript = null; this.documentElement = new Element(this, 'html'); this.appendChild(this.documentElement); this.body = new Element(this, 'body'); this.documentElement.appendChild(this.body); this.listeners = {}; }
  createElement(t) { return new Element(this, t); }
  createTextNode(t) { return new Text(this, t); }
  createDocumentFragment() { return new Fragment(this); }
  querySelectorAll(sel) { return qsa(this, sel); }
  querySelector(sel) { return qsa(this, sel)[0] || null; }
  addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
}
/** מסמך שגופו הוא ה-HTML הנתון. */
export function docFrom(bodyHtml) { const d = new Document(); d.body.innerHTML = bodyHtml; return d; }
