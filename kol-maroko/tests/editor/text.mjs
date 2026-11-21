/**
 * טקסט במקום (V2) — החלק הטהור של assets/js/editor/text.js, על המודל לבדו:   node kol-maroko/tests/editor/text.mjs
 * K2 לכל אלמנט טקסט בקובץ האמיתי (lexicon.html) ובקובץ שנכתב ביד (handwritten.html): שינוי טקסט ← ה-diff בתוך שורות
 * הצומת בלבד, ביטול מחזיר קובץ זהה בייט בבייט, והקובץ החדש נקרא (ניתוח מלא) עם הטקסט החדש. ועוד: מקרי הקצה של התיקון.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/text.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;', { filename: f });
const K = globalThis.KolSrc, T = globalThis.KolText;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
/** ה-view של אלמנט כמו שהוא במקור — מה שהדפדפן מציג לפני שנגעו בו. */
const viewOf = n => n.nodes.map(e => e.type === 'text' ? { o: e, type: 'text', data: e.value } : e.type === 'el' ? { o: e, type: 'el', tag: e.node.tag, attrs: e.node.attrs.map(a => [a.name, a.value]), kids: e.node.tag === 'template' ? null : viewOf(e.node) } : { o: e, type: 'comment' });
const fresh = (type, x) => type === 'text' ? { o: null, type, data: x } : { o: null, type: 'el', tag: x.tag, attrs: x.attrs || [], kids: x.kids || [] };
const edit = (src, sel, fn) => { const m = K.parseHtml(src), n = K.q(m, sel), v = viewOf(n); fn(v, n); const ps = T.plan(m, n, v); return { ps, out: K.applyPatches(src, ps), back: K.applyPatches(K.applyPatches(src, ps), K.invertPatches(src, ps)), m, n }; };

// ── K2 על הקבצים ──
for (const f of ['lexicon.html', 'handwritten.html']) {
  const src = fs.readFileSync(path.join(root, 'dev/spike/fixtures', f), 'utf8'), m = K.parseHtml(src);
  let n = 0, bad = [];
  const cand = K.all(m).filter(x => T.editable(x) && T.ownText(x) && !T.blocker(m, x)), step = Math.max(1, Math.floor(cand.length / 80));   // עד כ-80 אלמנטים, בפיזור אחיד — ניתוח מלא לכל אחד
  for (let ci = 0; ci < cand.length; ci += step) {
    const x = cand[ci];
    n++;
    const v = viewOf(x), t = v.find(k => k.type === 'text' && /\S/.test(k.data));
    const was = t.data; t.data = was.replace(/\S+/, w => w + 'ת');                 // מוסיפים אות למילה הראשונה
    let ps; try { ps = T.plan(m, x, v); } catch (e) { bad.push(f + ' ' + x.tag + ': ' + e.message); continue; }
    const out = K.applyPatches(src, ps), back = K.applyPatches(out, K.invertPatches(src, ps));
    const inNode = ps.length === 1 && ps[0].from >= x.st[1] && ps[0].to <= x.et[0] && ps[0].text.length <= 12 && ps[0].to - ps[0].from <= 12;
    const m2 = K.parseHtml(out), x2 = K.byPath(m2, K.pathOf(x)), t2 = x2 && x2.nodes[x.nodes.indexOf(t.o)];
    if (!inNode || back !== src || !t2 || t2.value !== t.data || m2.count !== m.count) bad.push(f + ' <' + x.tag + '> שורה ' + K.lineOf(src, x.from) + ': ' + JSON.stringify(ps).slice(0, 120));
  }
  ok(n > 5 && !bad.length, 'K2 · ' + f + ': ' + n + ' אלמנטי טקסט (מתוך ' + cand.length + ') — תיקון אחד קטן בתוך הצומת, ביטול זהה בייט בבייט, והקובץ החדש נקרא עם הטקסט החדש', bad.slice(0, 5));
}

// ── מקרי הקצה ──
const P = '<div>\n  <p id="a">פסקה עם <b>מודגש</b> ועוד <a href="x.html" class="l">קישור</a> ו<b>שוב</b> סוף.</p>\n  <p id="e">&copy; 2024&nbsp;קול &amp; &lt;x&gt; &notit; a &amp b</p>\n</div>\n';
{
  const r = edit(P, '#a', v => { v[4].data = ' ו'; v[6].data = ' סיום.'; });
  ok(r.out === P.replace(' סוף.', ' סיום.') && r.back === P, 'שינוי מילה בפסקה עם קישור ושני <b>: רק המילה; התגיות, התכונות וההזחה זהות', r.out);
  const r2 = edit(P, '#a', v => { v[1].kids[0].data = 'מודגש מאוד'; });
  ok(r2.out === P.replace('<b>מודגש</b>', '<b>מודגש מאוד</b>'), 'שינוי בתוך תגית פנימית: רק הטקסט שבתוכה', r2.out);
}
{
  const r = edit(P, '#e', v => { v[0].data = v[0].data.replace('2024', '2025 <b> & "x"'); });
  ok(r.out.includes('<p id="e">&copy; 2025 &lt;b&gt; &amp; "x"&nbsp;קול &amp; &lt;x&gt; &notit; a &amp b</p>') && r.back === P, 'ישויות שלא נגעו בהן נשארות כמו שנכתבו (גם &notit; ו-&amp בלי נקודה-פסיק); < > & חדשים מקודדים', r.out);
  const r2 = edit(P, '#e', v => { v[0].data = 'X' + v[0].data.slice(1); });
  ok(r2.out.includes('<p id="e">X 2024&nbsp;קול'), 'ישות שנערכה חוזרת כתו שהוקלד; השאר נשאר', r2.out);
  const r3 = edit(P, '#e', v => { v[0].data = v[0].data.replace(' ', ' ') + ' '; });
  ok(r3.out.includes('2024&nbsp;קול') && !/&nbsp;<\/p>/.test(r3.out) && / <\/p>|b<\/p>/.test(r3.out), 'רווח קשיח שהדפדפן החליף ברווח רגיל נשאר כמו שנכתב בקובץ; רווח קשיח שהדפדפן הוסיף בסוף אינו נכתב', r3.out);
}
{
  const src = '<h1 id="t">כותרת ראשית</h1>';
  let r = edit(src, '#t', v => { v.push(fresh('el', { tag: 'br' }), fresh('text', 'שורה'), fresh('el', { tag: 'br' }), fresh('el', { tag: 'br' })); });
  ok(r.out === '<h1 id="t">כותרת ראשית<br>שורה<br></h1>' && r.back === src, '<br> חדש נכתב; <br> כפול בסוף (מציין המיקום של הדפדפן) — אחד בלבד', r.out);
  r = edit(src, '#t', v => { v.push(fresh('el', { tag: 'div', kids: [fresh('text', 'בתוך div')] }), fresh('el', { tag: 'div', kids: [fresh('el', { tag: 'br' })] }), fresh('el', { tag: 'span', attrs: [['style', 'font-size: 12px']], kids: [fresh('text', 'x ')] })); });
  ok(r.out === '<h1 id="t">כותרת ראשית<br>בתוך div<br>x </h1>', '<div> של הדפדפן נכתב כ-<br>; <span style> בלי מחלקה — רק התוכן; &nbsp; חדש — רווח', r.out);
  r = edit(src, '#t', v => { v.length = 0; v.push(fresh('el', { tag: 'br' })); });
  ok(r.out === '<h1 id="t"></h1>' && r.back === src, 'אלמנט שהתרוקן: ה-<br> שהדפדפן השאיר אינו נכתב', r.out);
  r = edit(src, '#t', v => { const t = v[0]; t.data = 'כותרת '; v.push(fresh('el', { tag: 'b', kids: [fresh('text', 'ראשית')] }), fresh('el', { tag: 'i', attrs: [['onclick', 'x()'], ['class', 'k']], kids: [fresh('text', '!')] }), fresh('el', { tag: 'script', kids: [fresh('text', 'alert(1)')] })); });
  ok(r.out === '<h1 id="t">כותרת <strong>ראשית</strong><em class="k">!</em>alert(1)</h1>', 'b ← strong, i ← em; תכונת on* אינה נכתבת; תגית שאינה ברשימה (script) — רק הטקסט שלה, מקודד', r.out);
}
{
  const src = '<p id="m">א <b class="x">ב</b> ג <i>ד</i> ה</p>';
  let r = edit(src, '#m', v => { v.splice(1, 1); });
  ok(r.out === '<p id="m">א  ג <i>ד</i> ה</p>' && r.back === src, 'אלמנט פנימי שנמחק: רק הוא יוצא', r.out);
  r = edit(src, '#m', v => { const b = v[1]; v[1] = fresh('el', { tag: 'u', kids: [b] }); });
  ok(r.out === '<p id="m">א <u><b class="x">ב</b></u> ג <i>ד</i> ה</p>', 'אלמנט מקורי שנעטף: נכתב חזרה מהמקור, אות באות', r.out);
  r = edit(src, '#m', v => { const b = v[1]; v.splice(1, 1, ...b.kids); });
  ok(r.out === '<p id="m">א ב ג <i>ד</i> ה</p>', 'תגית שפורקה ("הסר עיצוב"): התוכן נשאר', r.out);
}
{
  const src = '<p id="c">א<!-- הערה -->ב\r\nג</p>';
  const r = edit(src, '#c', v => { v[2].data = 'ב\nגד'; });
  ok(r.out === '<p id="c">א<!-- הערה -->ב\r\nגד</p>' && r.back === src, 'הערה נשמרת, ו-CRLF שלא נגעו בו נשאר CRLF', r.out);
}
{
  const m = K.parseHtml('<section id="s"><div><p id="p">טקסט</p></section>'), p = K.q(m, '#p');
  ok(!!T.blocker(m, p) && T.blocker(m, p).code === 'broken', 'אזור שבור: העריכה נדחית (blocker)');
  const m2 = K.parseHtml('<div id="d"><h1><em>א</em> <strong>ב</strong></h1><div id="box"><span>x</span></div><ul><li>פריט</li></ul></div>');
  ok(T.hostFor(K.q(m2, 'em')).tag === 'em' && T.hostFor(K.q(m2, 'h1')).tag === 'h1' && T.hostFor(K.q(m2, '#box')).tag === 'span' && T.hostFor(K.q(m2, 'ul')).tag === 'li', 'האלמנט שנערך: תגית טקסט שכל תוכנה עטוף — היא עצמה; מכל — הילד הראשון שיש בו טקסט');
  const m3 = K.parseHtml('<p id="p">א <b>ב</b></p>');
  ok(T.hostFor(K.q(m3, 'b')).tag === 'p', 'לחיצה כפולה על <b> שבתוך פסקה עורכת את הפסקה');
}
console.log(`\n${ran - failed}/${ran} עברו`);
process.exit(failed ? 1 : 0);
