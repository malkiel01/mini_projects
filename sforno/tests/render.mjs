/*
 * בדיקת מנוע התצוגה (קריטריון 10) — מורץ מ-tests/e-render.test.php: node tests/render.mjs <source.html> <cases.json>
 * בלי דפדפן ובלי תלויות: DOM זעיר (רק מה ש-render.js משתמש בו) ומפרש ל-HTML המנוקה של השרת. מדפיס JSON:
 * לכל מקרה — ה-DOM שהתקבל, מסודר כטקסט. ההשוואה ל-snapshot — בצד ה-PHP.
 */
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const VOID = new Set(['br', 'img']);

class Node_ { constructor(type) { this.nodeType = type; this.childNodes = []; } appendChild(c) { this.childNodes.push(c); return c; } }
class Text_ extends Node_ { constructor(v) { super(3); this.nodeValue = v; } }
class El_ extends Node_ {
    constructor(name) { super(1); this.localName = name; this.attrs = new Map(); }
    setAttribute(k, v) { this.attrs.set(k, String(v)); }
    getAttribute(k) { return this.attrs.has(k) ? this.attrs.get(k) : null; }
    hasAttribute(k) { return this.attrs.has(k); }
}
const doc = { createElement: n => new El_(n), createTextNode: v => new Text_(v) };

const ENT = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };
const decode = s => s.replace(/&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi, (m, e) =>
    e[0] === '#' ? String.fromCodePoint(e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10)) : (ENT[e] ?? m));

/** מפרש ל-HTML המנוקה (מבנה תקין, תכונות במרכאות) — לא מפרש כללי. */
function parse(html) {
    const root = new El_('#root');
    const stack = [root];
    const re = /<\/([a-z0-9-]+)\s*>|<([a-z0-9-]+)((?:\s+[a-z-]+(?:="[^"]*"|='[^']*')?)*)\s*>|([^<]+)/gi;
    let m;
    while ((m = re.exec(html))) {
        const top = stack[stack.length - 1];
        if (m[1]) { if (top.localName === m[1].toLowerCase()) stack.pop(); else throw new Error('סגירה לא צפויה: ' + m[1]); continue; }
        if (m[4] !== undefined) { top.appendChild(new Text_(decode(m[4]))); continue; }
        const el = new El_(m[2].toLowerCase());
        for (const a of m[3].matchAll(/([a-z-]+)(?:="([^"]*)"|='([^']*)')?/gi)) el.setAttribute(a[1].toLowerCase(), decode(a[2] ?? a[3] ?? ''));
        top.appendChild(el);
        if (!VOID.has(el.localName)) stack.push(el);
    }
    if (stack.length !== 1) throw new Error('רכיב לא נסגר: ' + stack[stack.length - 1].localName);
    return root;
}

const esc = (s, q) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(q ? /"/g : /$^/, '&quot;');
function serialize(n) {
    if (n.nodeType === 3) return esc(n.nodeValue, false);
    const a = [...n.attrs].map(([k, v]) => ` ${k}="${esc(v, true)}"`).join('');
    return `<${n.localName}${a}>` + (VOID.has(n.localName) ? '' : n.childNodes.map(serialize).join('') + `</${n.localName}>`);
}

const [, , srcFile, casesFile] = process.argv;
const source = readFileSync(srcFile, 'utf8');
const cases = JSON.parse(readFileSync(casesFile, 'utf8'));
const path = new URL('../assets/js/render.js', import.meta.url).pathname;
const load = () => { delete require.cache[require.resolve(path)]; return require(path); };
const preview = load();   // "התצוגה המקדימה" ו"הקורא" — שני מופעים נפרדים של אותו מודול
const reader = load();
const out = { cases: {}, same: {}, norm: (cases.norm || []).map(s => preview.normalize(s)) };
for (const [name, settings] of Object.entries(cases.render)) {
    const a = serialize(preview.render(source, settings, { document: doc, parse }));
    const b = serialize(reader.render(source, settings, { document: doc, parse }));
    out.cases[name] = a;
    out.same[name] = a === b;
}
process.stdout.write(JSON.stringify(out));
