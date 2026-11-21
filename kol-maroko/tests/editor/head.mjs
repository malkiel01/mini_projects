/**
 * הגדרות העמוד (V15 §2.1–§2.2) — החלק הטהור של assets/js/editor/head.js:
 *   node kol-maroko/tests/editor/head.mjs
 * כל שדה: קריאה, יצירה במקום הצפוי, עריכה במקום, מחיקה; כפילות ("הראשונה קובעת"); property מול name; קוד מותאם בין
 * הערות הסימון; ביטול מחזיר קובץ זהה; העדכון ההדרגתי שקול לניתוח מלא; והזרקה — כל ערך נכתב מקודד ונדחה כשהוא קוד.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/head.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;');
const K = globalThis.KolSrc, H = globalThis.KolHead;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };
const throws = (fn, what, re) => { try { fn(); ok(false, what, 'לא נזרק'); } catch (e) { ok(!re || re.test(e.message), what, e.message); } };

/** מחיל, בודק ביטול זהה ושקילות של העדכון ההדרגתי מול ניתוח מלא. */
function step(src, plan) {
  const m = K.parseHtml(src), r = plan(m), out = K.applyPatches(src, r.patches), back = K.applyPatches(out, K.invertPatches(src, r.patches));
  const inc = K.update(m, r.patches).model, full = K.parseHtml(out), diff = K.sameModel(inc, full);
  return { out, undoOk: back === src, eq: !diff, diff, m: full };
}

const PAGE = `<!doctype html>
<html lang="he" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>קול מרוקו</title>
  <link rel="stylesheet" href="css/page.css">
  <script src="js/kol-data.js" defer></script>
</head>
<body>
  <h1>שלום</h1>
</body>
</html>
`;

// ── קריאה ──
{
  const s = H.scan(K, K.parseHtml(PAGE));
  ok(s.values.title === 'קול מרוקו' && s.values.lang === 'he' && s.values.dir === 'rtl' && s.values.description === '' && !s.values.noindex, 'קריאה: כותרת, שפה, כיוון; שדה בלי תגית — ריק');
  ok(s.others.length === 4 && s.others.map(n => n.tag).join(',') === 'meta,meta,link,script', 'עוד בראש העמוד: charset, viewport, link, script — כל תגית שהטופס אינו מכיר', s.others.map(n => n.tag));
}

// ── יצירה במקום הצפוי, עריכה, מחיקה ──
let src = PAGE;
{
  const r = step(src, m => H.planSet(K, m, 'description', 'מילון של המילים "שלנו" <b>'));
  ok(r.undoOk && r.eq, 'תיאור חדש: ביטול זהה, עדכון הדרגתי שקול', r.diff);
  ok(/<title>קול מרוקו<\/title>\n  <meta name="description" content="מילון של המילים &quot;שלנו&quot; &lt;b&gt;">/.test(r.out), 'נוצר אחרי <title>, באותה הזחה, מקודד (" < >)', r.out.slice(0, 400));
  ok(H.scan(K, r.m).values.description === 'מילון של המילים "שלנו" <b>', 'נקרא בחזרה כמו שנכתב');
  src = r.out;
}
{
  const r = step(src, m => H.planSet(K, m, 'title', 'מילון מרוקאי</title><script>alert(1)</script>'));
  ok(r.undoOk && r.eq && !/<script>alert/.test(r.out) && H.scan(K, r.m).values.title === 'מילון מרוקאי</title><script>alert(1)</script>', 'כותרת: נערכת במקום, וניסיון לסגור את <title> נכתב כטקסט', r.out.slice(0, 300));
}
{
  const r = step(src, m => H.planSet(K, m, 'theme', '#0b5'));
  ok(r.undoOk && r.eq && /<meta name="description"[^>]*>\n  <meta name="theme-color" content="#0b5">/.test(r.out), 'צבע הדפדפן — אחרי התיאור (הסדר הצפוי)', r.out.slice(0, 500));
  const r2 = step(r.out, m => H.planSet(K, m, 'icon', 'media/icon.png'));
  ok(r2.undoOk && r2.eq && /theme-color[^>]*>\n  <link rel="icon" href="media\/icon.png">\n  <link rel="stylesheet"/.test(r2.out), 'סמל — אחרי צבע הדפדפן, לפני קובץ העיצוב', r2.out.slice(0, 600));
  const r3 = step(r2.out, m => H.planSet(K, m, 'theme', ''));
  ok(r3.undoOk && r3.eq && !/theme-color/.test(r3.out) && /description[^>]*>\n  <link rel="icon"/.test(r3.out), 'ריקון — התגית נמחקת עם השורה שלה', r3.out.slice(0, 500));
}
{
  const r = step(src, m => H.planNoindex(K, m, true));
  ok(r.undoOk && r.eq && /<meta name="robots" content="noindex">/.test(r.out) && H.scan(K, r.m).values.noindex, 'לא להופיע בחיפוש ← meta robots noindex');
  const r2 = step(r.out, m => H.planNoindex(K, m, false));
  ok(r2.out === src, 'ביטול הסימון — התגית נמחקת והקובץ חוזר לקודמו');
  const r3 = step(src.replace('<title>', '<meta name="robots" content="nofollow">\n  <title>'), m => H.planNoindex(K, m, true));
  ok(/content="noindex, nofollow"/.test(r3.out), 'robots קיים — noindex מתווסף לתוכן, nofollow נשאר', r3.out.slice(0, 300));
}
{
  const r = step(src, m => H.planSet(K, m, 'lang', 'ar'));
  const r2 = step(r.out, m => H.planSet(K, m, 'dir', ''));
  ok(r.undoOk && r.eq && /<html lang="ar" dir="rtl">/.test(r.out) && /<html lang="ar">/.test(r2.out) && r2.eq, 'שפה וכיוון — על <html>; ריקון מסיר את התכונה', r2.out.slice(0, 80));
}
// ── property מול name, כפילות ──
{
  const two = src.replace('<title>', '<meta name="og:image" content="a.png">\n  <meta property="og:image" content="b.png">\n  <title>');
  const s = H.scan(K, K.parseHtml(two));
  ok(s.fields.ogImage.length === 2 && s.values.ogImage === 'a.png' && s.dups.includes('ogImage'), 'og:image בשתי צורות (name / property) — שתיהן מזוהות, הראשונה קובעת');
  const r = step(two, m => H.planSet(K, m, 'ogImage', 'media/c.png'));
  ok(r.eq && /name="og:image" content="media\/c.png"/.test(r.out) && /property="og:image" content="b.png"/.test(r.out), 'עריכה — במקום, בצורה שבה נכתבה (name); השנייה לא נגעה', r.out.slice(0, 400));
}
// ── הזרקה ──
throws(() => H.planSet(K, K.parseHtml(src), 'ogImage', 'javascript:alert(1)'), 'og:image: javascript: נדחה', /javascript/);
throws(() => H.planSet(K, K.parseHtml(src), 'canonical', 'https://a.b/" onload="x'), 'כתובת קנונית עם מרכאות / רווח — נדחית');
throws(() => H.planSet(K, K.parseHtml(src), 'theme', 'red;}</style>'), 'צבע: לא צבע — נדחה');
throws(() => H.planSet(K, K.parseHtml(src), 'lang', 'he" onx="1'), 'שפה: רק קוד שפה');
throws(() => H.planSet(K, K.parseHtml(src), 'dir', 'up'), 'כיוון: rtl / ltr / auto');
{
  const r = step(src, m => H.planSet(K, m, 'canonical', 'https://example.com/lexicon/'));
  ok(r.eq && /<link rel="canonical" href="https:\/\/example.com\/lexicon\/">/.test(r.out), 'כתובת קנונית תקינה נכתבת');
}
// ── קוד מותאם ──
{
  const code = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>\n<script>window.dataLayer=[];</script>';
  const r = step(src, m => H.planCode(K, m, 'head', code));
  ok(r.undoOk && r.eq && /<!-- kol:head -->\n<script async[\s\S]*<!-- \/kol:head -->\n<\/head>/.test(r.out), 'קוד בראש העמוד — נוצר אזור בין הערות הסימון, בסוף ה-<head>', r.out.slice(250, 800));
  const s = H.scan(K, r.m);
  ok(s.headCode && s.headCode.code === code && s.others.every(n => n.tag !== 'script' || /kol-data/.test(n.attrs.map(a => a.value).join())), 'האזור נקרא בחזרה; התגיות שבתוכו אינן ב"עוד"', s.headCode && s.headCode.code);
  const r2 = step(r.out, m => H.planCode(K, m, 'head', '<meta name="x" content="1">'));
  ok(r2.undoOk && r2.eq && H.scan(K, r2.m).headCode.code === '<meta name="x" content="1">', 'עריכה — רק התוכן שבין ההערות מוחלף');
  const r3 = step(r2.out, m => H.planCode(K, m, 'head', ''));
  ok(r3.out === src, 'ריקון — האזור וההערות נמחקים, הקובץ חוזר לקודמו', r3.out.slice(200, 500));
  const b = step(src, m => H.planCode(K, m, 'body', '<script>fbq("init")</script>'));
  ok(b.undoOk && b.eq && /<h1>שלום<\/h1>\n  <!-- kol:body-end -->\n<script>fbq\("init"\)<\/script>\n<!-- \/kol:body-end -->\n<\/body>/.test(b.out), 'קוד בסוף העמוד — לפני </body>', b.out.slice(-200));
  throws(() => H.planCode(K, K.parseHtml(src), 'head', '<!-- /kol:head --><x>'), 'קוד שמכיל הערת סימון — נדחה (לא בורח מהאזור)');
  const o = H.origins(code + ' fetch("//connect.facebook.net/x.js")');
  ok(o.includes('www.googletagmanager.com') && o.includes('connect.facebook.net') && o.length === 2, 'מקורות חיצוניים מהקוד', o);
}
// ── עמוד בלי <head> כתוב ──
throws(() => H.planSet(K, K.parseHtml('<p>x</p>'), 'description', 'a'), 'עמוד בלי <head> — "פתח בקוד", לא כתיבה עיוורת', /head/);

console.log((failed ? '❌ ' : '✅ ') + (ran - failed) + '/' + ran);
process.exit(failed ? 1 : 0);
