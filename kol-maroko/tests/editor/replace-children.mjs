/**
 * T2: replaceChildren(…) עם ארגומנט שעשוי להיות null / false כותב "null" / "false" על המסך (E2E ⚪9, V15 בסרגל הבחירה).
 * בדיקה סטטית על assets/js: ארגומנט שהביטוי העליון שלו הוא `? … : null`, `&&` או `|| null` — חייב לעבור דרך …[…].filter(…).
 *   node kol-maroko/tests/editor/replace-children.mjs
 */
import fs from 'node:fs'; import path from 'node:path'; import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'js');
const files = []; (function walk(d) { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); if (fs.statSync(p).isDirectory()) { if (!/vendor|spike/.test(f)) walk(p); } else if (p.endsWith('.js')) files.push(p); } })(root);
const strip = x => { let y = x.replace(/(["'`])(?:\\.|(?!\1).)*\1/g, '""'), z; do { z = y; y = y.replace(/\([^()]*\)|\[[^[\]]*\]|\{[^{}]*\}/g, '_'); } while (y !== z); return y; };
const bad = []; let calls = 0;
for (const f of files) {
  const s = fs.readFileSync(f, 'utf8'); let i = 0;
  while ((i = s.indexOf('replaceChildren(', i)) >= 0) {
    let d = 0, j = i + 15; for (; j < s.length; j++) { const c = s[j]; if (c === '(') d++; else if (c === ')') { d--; if (!d) break; } }
    calls++;
    const args = s.slice(i + 16, j); let dep = 0, cur = '', parts = [];
    for (const c of args) { if ('([{'.includes(c)) dep++; if (')]}'.includes(c)) dep--; if (c === ',' && !dep) { parts.push(cur); cur = ''; } else cur += c; }
    parts.push(cur);
    for (const p of parts) { const t = strip(p).trim(); if (!t.startsWith('...') && (t.includes('?') ? /\?[^:]*:\s*(null|false|undefined)\b|\?\s*(null|false|undefined)\b/.test(t) : /&&|\|\|\s*null\b/.test(t))) bad.push(path.relative(root, f) + ':' + s.slice(0, i).split('\n').length + '  ' + p.trim().replace(/\s+/g, ' ').slice(0, 80)); }
    i = j;
  }
}
if (bad.length) { console.log('❌ replaceChildren עם ארגומנט שעשוי להיות null / false:\n  ' + bad.join('\n  ')); process.exit(1); }
console.log('✅ replaceChildren: ' + calls + ' קריאות ב-' + files.length + ' קבצים — אין ארגומנט שעשוי להיות null / false');
