/**
 * מבנה (V4) — החלק הטהור של assets/js/editor/structure.js:   node kol-maroko/tests/editor/structure.mjs
 * K2 לכל סוג כתיבה חדש: הזזה בתוך הורה; בין הורים; הוספה (לפני / אחרי / ראשון ברובריקה ריקה); שכפול; עטיפה; פירוק;
 * החלפת תגית; הדבקה; עסקה בשני קבצים (רובריקה + כלל); ואלמנט בלי תגית סגירה. בכל אחת: ביטול מחזיר קובץ זהה בייט
 * בבייט, ושקילות המודל (עדכון הדרגתי מול ניתוח מלא). ועוד: מה שהדפדפן היה קורא אחרת — נדחה, עם הצעה; קלט עוין.
 * הקובץ: index.html של העמוד המוסב (F3), כפי שההסבה כותבת אותו.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/style.js', 'assets/js/editor/structure.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;', { filename: f });
const K = globalThis.KolSrc, X = globalThis.KolStruct, ST = globalThis.KolStyle;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };
const page = fs.readFileSync(path.join(here, 'fixtures/index.html'), 'utf8');
const q = (m, sel, nth = 0) => K.q(m, sel, nth);
/** K2: ביטול בייט בבייט + שקילות המודל ההדרגתי לניתוח מלא + הבדיקה של התוצאה. */
function k2(name, src, res, want) {
  const out = K.applyPatches(src, res.patches), back = K.applyPatches(out, K.invertPatches(src, res.patches));
  const inc = K.update(K.parseHtml(src), res.patches).model, eq = K.sameModel(inc, K.parseHtml(out));
  const good = back === src && out === res.src && !eq && want(out, K.parseHtml(out));
  ok(good, name, good ? undefined : { back: back === src, same: out === res.src, eq, out: out.slice(0, 2400) });
  return out;
}
const err = (fn, code, re) => { try { fn(); return false; } catch (e) { return e instanceof K.SrcError && (!code || e.code === code) && (!re || re.test(e.message)) ? e : false; } };

let m = K.parseHtml(page);
const inner = q(m, '.kadisha-hero-inner'), h1 = q(m, 'h1'), fr = q(m, '.kadisha-hero-eyebrow-line');

// ── 1. הזזה ──
let r = X.move(m, [fr], inner, h1);
k2('בין הורים: הכותרת בצרפתית (span בתוך ה-eyebrow) אל לפני הכותרת בעברית — שורה משלה, בהזחה של השכנים', page, r, (out, m2) => {
  const k = q(m2, '.kadisha-hero-inner').kids; return k[0].tag === 'span' && K.textOf(m2, k[0]).trim() === 'La Voix du Maroc' && k[1].tag === 'h1'
    && out.includes('\n      <span class="kadisha-hero-eyebrow-line">La Voix du Maroc</span>\n      <h1') && out.includes('dir="ltr"><span class="kadisha-hero-eyebrow-line">The Voice');
});
r = X.move(m, [q(m, '.kadisha-hero-subtitle-main')], inner, h1);
k2('בתוך הורה: תת-הכותרת אל לפני h1 — הזזת טווח, שאר הקובץ זהה', page, r, (out, m2) => q(m2, '.kadisha-hero-inner').kids[0].tag === 'p' && out.length === page.length);
const hero = q(m, 'section.kadisha-hero'), ctrls = q(m, '.kadisha-lexicon-controls'), cont = ctrls.parent;
r = X.move(m, [hero], cont, cont.kids[cont.kids.indexOf(ctrls) + 1]);
let heroOut = k2('הפס כולו (section.kadisha-hero) אל מתחת לשורת החיפוש — עם 13 השורות שלו, מוזח מחדש לשכניו', page, r, (out, m2) => {
  const c2 = q(m2, '#lexicon-main').kids[0].kids, i = c2.findIndex(k => k.tag === 'section');
  return i > 0 && c2[i - 1].attrs.some(a => a.value === 'kadisha-lexicon-controls') && out.includes('\n    <section class="kadisha-hero kadisha-hero--has-image"') && out.includes('\n      <div class="kadisha-container">\n        <div class="kadisha-hero-inner">')
    && !out.includes('<div class="kadisha-page kadisha-lexicon-page" dir="rtl" lang="he">\n<section');
});
ok(K.parseHtml(heroOut).count === m.count, 'אחרי הזחה מחדש — מספר האלמנטים זהה, ואין קוד שבור', K.parseHtml(heroOut).count);
const two = [q(m, 'p.kadisha-hero-subtitle'), q(m, 'h1')];
r = X.move(m, two, q(m, '.kadisha-lex-hero-actions'), null);
k2('בחירה מרובה: שני אלמנטים נוחתים ברצף, בסדר המקור (h1 ואז p), בסוף רובריקה אחרת — תיקון אחד', page, r, (out, m2) => { const k = q(m2, '.kadisha-lex-hero-actions').kids.map(x => x.tag); return k.slice(-2).join() === 'h1,p' && r.patches.length === 1; });

// ── 2. אלמנט בלי תגית סגירה ──
const ul = '<!doctype html><html><body>\n<ul>\n  <li>א\n  <li>ב\n  <li>ג\n</ul>\n<div class="x">\n  <p>ראשון\n  <p>שני\n</div>\n</body></html>\n';
let mu = K.parseHtml(ul), lis = q(mu, 'ul').kids;
r = X.move(mu, [lis[2]], q(mu, 'ul'), lis[0]);
k2('<li> בלי </li>: ההזזה כותבת את הטווח שהמודל מגדיר (בלי רווחי ההזחה של האח) — הסדר ג, א, ב', ul, r, (out, m2) => q(m2, 'ul').kids.map(k => K.textOf(m2, k).trim()).join() === 'ג,א,ב' && out.includes('<ul>\n  <li>ג\n  <li>א\n  <li>ב\n</ul>'));
const ps = q(mu, 'div.x').kids;
r = X.move(mu, [ps[1]], q(mu, 'div.x'), ps[0]);
k2('<p> בלי </p> בתוך div — לפני האח', ul, r, (out, m2) => q(m2, 'div.x').kids.map(k => K.textOf(m2, k).trim()).join() === 'שני,ראשון');

// ── 3. שורה ריקה צמודה ──
const blank = '<!doctype html><html><body>\n<main>\n  <h2>א</h2>\n\n  <h2>ב</h2>\n  <h2>ג</h2>\n</main>\n</body></html>\n';
let mb = K.parseHtml(blank); const hs = q(mb, 'main').kids;
r = X.move(mb, [hs[1]], q(mb, 'main'), null);
k2('שורה ריקה צמודה נעה עם האלמנט — אין שורה ריקה יתומה', blank, r, out => out.includes('<main>\n  <h2>א</h2>\n  <h2>ג</h2>\n\n  <h2>ב</h2>\n</main>'));

// ── 4. הוספה ──
r = X.insert(m, inner, 0, '<h2>כותרת</h2>');
k2('הוספה: ראשון ברובריקה (לפני h1), בהזחה של השכנים', page, r, (out, m2) => q(m2, '.kadisha-hero-inner').kids[0].tag === 'h2' && out.includes('<div class="kadisha-hero-inner">\n      <h2>כותרת</h2>\n      <h1'));
r = X.insert(m, inner, inner.kids.length, '<div></div>');
let withBox = k2('הוספה: רובריקה בסוף', page, r, (out, m2) => q(m2, '.kadisha-hero-inner').kids.slice(-1)[0].tag === 'div' && K.classesOf(q(m2, '.kadisha-hero-inner').kids.slice(-1)[0]).length === 0);
let mx = K.parseHtml(withBox), box = q(mx, '.kadisha-hero-inner').kids.slice(-1)[0];
r = X.insert(mx, box, 0, '<p>טקסט</p>');
k2('הוספה: ראשון ברובריקה ריקה', withBox, r, (out, m2) => { const b = q(m2, '.kadisha-hero-inner').kids.slice(-1)[0]; return b.kids.length === 1 && b.kids[0].tag === 'p'; });
for (const it of X.LIB) {
  const res = X.insert(m, inner, 2, it.html);
  k2('ספרייה: ' + it.label + ' — ' + it.html, page, res, (out, m2) => q(m2, '.kadisha-hero-inner').kids[2].tag === X.topTags(it.html)[0] && !/class=|style=/.test(it.html));
}

// ── 5. עסקה בשני קבצים: רובריקת-שורה עם 2 עמודות (HTML + כלל CSS) ──
const css = fs.readFileSync(path.join(here, 'fixtures/page.css'), 'utf8');
r = X.insert(m, inner, inner.kids.length, '<div class="row-1"><div class="row-1-col"></div><div class="row-1-col"></div></div>');
const cm = K.parseCss(css), c1 = ST.planNewRule(cm, '.row-1', [['display', 'flex'], ['flex-direction', 'row'], ['flex-wrap', 'wrap']], true);
const css1 = K.applyPatches(css, c1.patches), c2 = ST.planNewRule(K.parseCss(css1), '.row-1-col', [['flex', '1 1 0'], ['min-width', '0']], true), css2 = K.applyPatches(css1, c2.patches);
const cssDiff = X.diff(css, css2);
k2('שני קבצים — HTML: רובריקת-שורה ובתוכה שתי רובריקות', page, r, (out, m2) => K.classesOf(q(m2, '.kadisha-hero-inner').kids.slice(-1)[0])[0] === 'row-1');
ok(K.applyPatches(css2, K.invertPatches(css, cssDiff)) === css && /\.row-1 \{[^}]*display: flex;[^}]*flex-direction: row;[^}]*flex-wrap: wrap;/.test(css2) && /\.row-1-col \{[^}]*flex: 1 1 0;[^}]*min-width: 0;/.test(css2) && !K.cssAll(K.parseCss(css2)).some(n => n.broken),
  'שני קבצים — CSS: .row-1 (flex, row, wrap) ו-.row-1-col (flex: 1 1 0; min-width: 0), ביטול מחזיר בייט בבייט');

// ── 6. שכפול ──
r = X.duplicate(m, h1);
k2('שכפול: העתק מיד אחרי המקור, עם המחלקות; id כפול ← -2', page, r, (out, m2) => { const k = q(m2, '.kadisha-hero-inner').kids; return k[1].tag === 'h1' && K.attrOf(k[1], 'id').value === 'lexicon-hero-title-2' && K.attrOf(k[0], 'id').value === 'lexicon-hero-title' && K.classesOf(k[1])[0] === 'kadisha-hero-title'; });
ok(r.changes.length === 1 && r.changes[0][2] === 'lexicon-hero-title-2', 'שכפול: העורך יודע מה שונה', r.changes);
r = X.duplicate(m, hero);
k2('שכפול של הפס: aria-labelledby בתוך ההעתק מפנה ל-id של ההעתק; המקור לא השתנה', page, r, (out, m2) => {
  const s = [...m2.root.kids[1].kids[0].kids].filter(k => k.tag === 'section'); return s.length === 3 && K.attrOf(s[1], 'aria-labelledby').value === 'lexicon-hero-title-2' && K.attrOf(s[0], 'aria-labelledby').value === 'lexicon-hero-title';
});
const lab = '<!doctype html><html><body>\n<div class="f">\n  <label for="n">שם</label>\n  <input id="n">\n  <a href="#n">אל</a>\n</div>\n<p id="n-2">כבר תפוס</p>\n</body></html>\n';
let ml = K.parseHtml(lab);
r = X.duplicate(ml, q(ml, 'div.f'));
k2('שכפול: for ו-href="#" בתוך ההעתק מתעדכנים; -2 תפוס ← -3', lab, r, out => out.includes('<label for="n-3">שם</label>\n  <input id="n-3">\n  <a href="#n-3">אל</a>') && out.includes('<label for="n">שם</label>'));

// ── 7. עטיפה ופירוק ──
const subs = [q(m, 'p.kadisha-hero-subtitle'), q(m, 'p.kadisha-hero-credit')];
r = X.wrap(m, subs);
let wrapped = k2('עטיפה של שני אחים רצופים: <div> סביב, בתוך השורה', page, r, (out, m2) => { const d = q(m2, '.kadisha-hero-inner').kids[3]; return d.tag === 'div' && d.kids.length === 2 && out.includes('<div><p class="kadisha-hero-subtitle">'); });
ok(err(() => X.wrap(m, [h1, q(m, 'p.kadisha-hero-subtitle')]), 'gap'), 'עטיפה: בחירה שאינה רצופה — נדחית עם קוד gap (העורך מציע "כל אחד בנפרד" / "לרובריקה אחת")');
let mw = K.parseHtml(wrapped);
r = X.unwrap(mw, q(mw, '.kadisha-hero-inner').kids[3]);
k2('פירוק: הרובריקה מוסרת, הילדים נשארים — וזה בדיוק הקובץ המקורי', wrapped, r, out => out === page);

// ── 8. החלפת תגית ──
r = X.retag(m, q(m, 'p.kadisha-hero-subtitle-main'), 'h2');
k2('החלפת תגית: p ← h2, בפתיחה ובסגירה בלבד', page, r, out => out.includes('<h2 class="kadisha-hero-subtitle-main">מילון הערבית־היהודית של מרוקו</h2>'));
r = X.retag(m, inner, 'my-box');
k2('החלפת תגית: הקלדה חופשית של שם תקני (my-box)', page, r, out => out.includes('<my-box class="kadisha-hero-inner">') && out.includes('</my-box>'));
for (const bad of ['div onclick=alert(1)', 'x><script>alert(1)</script', 'h1"', 'DIV/', '', 'שלום', 'a b']) ok(err(() => X.retag(m, h1, bad), 'invalid'), 'קלט עוין לתגית נדחה: ' + JSON.stringify(bad));
ok(err(() => X.retag(m, h1, 'img'), 'invalid') && err(() => X.retag(m, q(m, 'body'), 'div'), 'root'), 'תגית: לא void לאלמנט עם תוכן; body אינו מוחלף');

// ── 9. הדבקה ──
r = X.paste(m, inner, 1, '<p class="kadisha-hero-subtitle">הודבק</p>');
k2('הדבקה: אחרי הנבחר', page, r, (out, m2) => K.textOf(m2, q(m2, '.kadisha-hero-inner').kids[1]).trim() === 'הודבק');
ok(err(() => X.paste(m, inner, 1, '</div></div><script>x()</script><div>'), 'parse') && err(() => X.paste(m, inner, 1, '<p>a</p></div><p>b'), 'parse'), 'הדבקה עוינת: קטע שסוגר את הרובריקה — נדחה, הקובץ לא משתנה');
ok(err(() => X.paste(m, inner, 1, '<html><body>x</body></html>'), 'invalid'), 'הדבקה של עמוד שלם — נדחית');

// ── 10. מה שהדפדפן אינו מרשה — נאמר, ומוצעת דרך ──
const pp = q(m, 'p.kadisha-hero-subtitle');
let e = err(() => X.insert(m, pp, 0, '<div></div>'), 'invalid', /<p> אינו יכול להכיל רובריקה — הפוך אותו ל-<div> והכנס/);
ok(e && e.fix && e.fix.kind === 'retag', 'רובריקה בתוך <p> — נדחה, עם ההצעה "הפוך ל-<div> והכנס"');
r = X.withFix(m, e.fix, pp, (mm, par) => X.insert(mm, par, 0, '<div></div>'));
k2('ההצעה: <p> ← <div> ואז ההכנסה — תיקון אחד', page, r, out => out.includes('<div class="kadisha-hero-subtitle">מילים, פתגמים וברכות — עם ההגייה<div></div></div>'));
const aa = '<!doctype html><html><body>\n<a href="/x">קישור</a>\n<a href="/y">שני</a>\n<ul><li>א</li></ul>\n<img src="a.png" alt="">\n<template><b>t</b></template>\n<h2>כ</h2>\n</body></html>\n';
let ma = K.parseHtml(aa), as = ma.root.kids[1].kids;
ok(err(() => X.move(ma, [as[1]], as[0], null), 'invalid', /<a> בתוך <a>/), 'קישור בתוך קישור — נדחה');
e = err(() => X.move(ma, [as[5]], q(ma, 'ul'), null), 'invalid', /בתוך <ul> רק <li>/); ok(e && e.fix.kind === 'wrap', '<h2> ישר בתוך <ul> — נדחה, עם "עטוף ב-<li>"');
ok(err(() => X.insert(ma, q(ma, 'img'), 0, '<b>x</b>'), 'invalid', /<img> אינו יכול להכיל/), 'תוכן ב-<img> — נדחה');
ok(err(() => X.move(ma, [as[5]], q(ma, 'template'), null), 'template', /V11/) && err(() => X.move(ma, [q(ma, 'b')], q(ma, 'body'), null), 'template'), 'V11: אלמנט מהעמוד אל תוך <template>, וחלק של התבנית אל מחוצה לה — לא, עם ההסבר');
{ const mt = K.parseHtml('<!doctype html><html><body><template><article><h3>a</h3><p>b</p></article></template></body></html>'), art = q(mt, 'article'); const r2 = X.move(mt, [q(mt, 'p')], art, q(mt, 'h3')); const o2 = K.applyPatches(mt.src, r2.patches); ok(/<article><p>b<\/p><h3>a<\/h3><\/article>/.test(o2.replace(/\s+/g, '')), 'V11: גרירה בתוך הכרטיס (אותה תבנית) — מותרת', o2); }
ok(err(() => X.move(ma, [q(ma, 'body')], q(ma, 'ul'), null), 'root') && err(() => X.duplicate(ma, q(ma, 'body')), 'root') && err(() => X.wrap(ma, [q(ma, 'body')]), 'root'), 'html / head / body — אינם נגררים, משוכפלים או נעטפים');
ok(err(() => X.move(m, [inner], q(m, 'h1'), null), 'cycle') || err(() => X.move(m, [inner], h1, null)), 'אלמנט אל תוך צאצא שלו — נדחה');
const list = q(m, '#kadisha-lex-grid');
r = X.move(m, [list], inner, null);
k2('רובריקה עם data-kol-list נגררת כולה, עם ה-<template> שבתוכה', page, r, (out, m2) => { const g = q(m2, '#kadisha-lex-grid'); return g.parent.attrs.some(a => a.value === 'kadisha-hero-inner') && g.kids[0].tag === 'template' && g.kids[0].kids[0].tag === 'article'; });

// ── 11. מה נשען על האלמנט ──
const js = [{ path: 'js/page.js', text: fs.existsSync(path.join(root, 'assets/project-seed/page.js')) ? fs.readFileSync(path.join(root, 'assets/project-seed/page.js'), 'utf8') : '' }];
const d1 = X.deps(m, [q(m, '#kadisha-lex-search')], js), d2 = X.deps(m, [q(m, '#kadisha-lex-panel-0')], js), d3 = X.deps(m, [h1], []);
ok(d1.some(x => x.kind === 'data' && x.text.includes('החיפוש')), 'אזהרה: data-kol-search — "החיפוש" יפסיק לעבוד', d1);
ok(d2.some(x => x.kind === 'id' && /aria-controls|data-panel/.test(x.text)), 'אזהרה: id שכפתור מפנה אליו (aria-controls)', d2);
ok(d3.some(x => x.kind === 'id' && x.text.includes('aria-labelledby')), 'אזהרה: id שה-section מפנה אליו', d3);

// ── T2 (E2E 🟡1): כותרת / רובריקה אינה נכנסת לתוך <span> (שורה) ──
{ const sp = q(m, '.kadisha-hero-eyebrow'), w = X.why(sp, ['h2'], null), w2 = X.why(sp, ['span'], null), w3 = X.why(sp.parent, ['h2'], null);
  ok(sp && w && w.code === 'inline' && !w2 && !w3 && X.blockyIn(sp, ['div']) && !X.blockyIn(sp, ['b']) && !X.blockyIn(q(m, 'a') || { tag: 'a' }, ['div']), '<h2> / <div> לתוך <span> — נדחה ("לפניו או אחריו"); <span> לתוכו — מותר; <h2> לצידו — מותר; <a> אינו נחסם', { w, w2, w3 }); }

// ── T2 (ביקורת): כרטיס שהוא <button> / <label> / <span> עם בלוקים בפנים — הסידור מחדש בתוכו נשאר מותר ──
{ const card = '<!doctype html><html><body><button class="card" id="cb"><div id="ca">A</div><div id="cbb">B</div></button><div id="out">חוץ</div><span id="sp"><span>x</span></span></body></html>';
  const mc = K.parseHtml(card), cb = q(mc, '#cb'), A = q(mc, '#ca'), B = q(mc, '#cbb'), out = q(mc, '#out'), sp = q(mc, '#sp');
  let err = ''; let res = null; try { res = X.move(mc, [B], cb, A); } catch (e) { err = e.message; }
  ok(!err && res && /<button class="card" id="cb"><div id="cbb">B<\/div><div id="ca">A<\/div><\/button>/.test(res.src), 'סידור מחדש של בלוקים בתוך <button> שכבר מכיל אותם — מותר (כמו ב-main)', err || (res && res.src));
  ok(!X.why(cb, ['div'], [B]) && !X.blockyIn(cb, ['div'], [B]), 'why / blockyIn: אותו הורה — מותר');
  ok(!X.why(cb, ['div'], [out]), 'בלוק מבחוץ אל <button> שכבר מכיל בלוקים — מותר (המבנה כבר כזה)');
  ok(!!X.why(sp, ['div'], [out]) && X.why(sp, ['div'], [out]).code === 'inline' && !!X.why(sp, ['h2'], null), 'בלוק מבחוץ אל <span> שאין בו בלוקים — עדיין נדחה (E2E 🟡1)'); }

console.log('\n' + (ran - failed) + '/' + ran + (failed ? ' — נכשלו ' + failed : ' — הכול עבר'));
process.exit(failed ? 1 : 0);
