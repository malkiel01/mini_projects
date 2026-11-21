/**
 * ספייק V0 — מריץ את רתמת הבדיקה ב-Chromium בלי ראש, מוסיף קלט אמיתי של עכבר ומגע (K5), מדידה עם מעבד מואט (K6)
 * ומשקל הספריות (K7), וכותב את התוצאות ל-dev/spike/results.json.
 *
 *   node kol-maroko/dev/spike/run.mjs
 *
 * בפריסה dev/ חסומה מהדפדפן; כאן שרת מקומי מגיש את התיקייה רק לצורך הבדיקה.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
const { chromium, devices } = createRequire(import.meta.url)('playwright');
const TYPES = { '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html; charset=utf-8', '.json': 'application/json' };
const PAGE = `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>spike</title>
<link rel="stylesheet" href="/assets/css/app.css"><style>body{margin:0;padding:8px}#host{height:640px}</style></head><body><div id="host"></div>
<script src="/assets/vendor/spike-parsers.js"></script><script src="/assets/js/editor/model.js"></script><script src="/assets/js/editor/canvas.js"></script><script src="/assets/js/editor/equiv.js"></script><script src="/assets/js/spike/harness.js"></script></body></html>`;
const server = http.createServer((req, res) => {
  const u = decodeURIComponent(req.url.split('?')[0]);
  if (u === '/__spike') { res.writeHead(200, { 'content-type': TYPES['.html'] }); return res.end(PAGE); }
  const f = path.join(root, u);
  if (!f.startsWith(root) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('404'); }
  res.writeHead(200, { 'content-type': TYPES[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f));
});
await new Promise(r => server.listen(0, '127.0.0.1', r));
const base = 'http://127.0.0.1:' + server.address().port;
const FIX = () => { window.__fixture = async n => new Uint8Array(await (await fetch('/dev/spike/fixtures/' + n)).arrayBuffer()); };
const browser = await chromium.launch();
const out = { node: process.version, chromium: browser.version(), date: new Date().toISOString() };
const errors = [];

async function open(ctxOpt) {
  const ctx = await browser.newContext(ctxOpt), page = await ctx.newPage();
  page.on('pageerror', e => errors.push(String(e)));
  await page.goto(base + '/__spike'); await page.evaluate(FIX);
  return { ctx, page };
}
/** נקודת האמצע של אלמנט בקנבס, בקואורדינטות של הדף. */
const pt = (page, sel, fx = 0.5, fy = 0.5) => page.evaluate(([sel, fx, fy]) => {
  const c = window.__spike.canvas, d = c.doc.querySelector(sel); d.scrollIntoView({ block: 'center' });
  const r = d.getBoundingClientRect(), f = c.frame.getBoundingClientRect();
  return { x: f.left + r.left + r.width * fx, y: f.top + r.top + r.height * fy };
}, [sel, fx, fy]);
const state = page => page.evaluate(() => { const c = window.__spike.canvas, id = n => n ? n.tag + ((window.KolSrc.attrOf(n, 'id') || {}).value ? '#' + window.KolSrc.attrOf(n, 'id').value : '') : null;
  return { selected: id(c.selected), hovered: id(c.hovered), selBox: !c.boxSel.hidden, hovBox: !c.boxHov.hidden, pwn: window.__spike.pwn.slice(), events: [...new Set(c.events)], title: c.doc.title,
    order: [...c.doc.querySelectorAll('.cards > .card')].map(x => x.id).join(','), srcOrder: (c.src.match(/id="c\d"/g) || []).join(','), frames: window.frames.length, href: location.href }; });
const loadHw = page => page.evaluate(async () => { const s = window.__spike; s.pwn.length = 0; await s.canvas.load(s.text['handwritten.html'], s.text['handwritten.css']); s.canvas.events.length = 0; });

// ── 1. הרתמה המלאה, במסך שולחני ──────────────────────────────
{
  const { ctx, page } = await open({ viewport: { width: 1280, height: 800 } });
  page.on('console', m => { if (m.type() === 'log' && m.text().startsWith('·')) process.stdout.write(m.text() + '\n'); });
  out.harness = await page.evaluate(async () => {
    const r = await window.KolSpikeHarness.run({ fixture: window.__fixture, host: document.getElementById('host'), progress: m => console.log('· ' + m) });
    delete r._pwn; return r;
  });
  // ── 2. K5: עכבר אמיתי ──────────────────────────────────────
  const k5 = { engine: 'Chromium ' + browser.version() + ' (headless)', mouse: {} };
  await loadHw(page);
  let p = await pt(page, 'h1.title'); await page.mouse.move(p.x, p.y, { steps: 3 }); let s = await state(page);
  k5.mouse.hover = { got: s.hovered, box: s.hovBox, pass: s.hovered === 'h1' && s.hovBox };
  await page.mouse.click(p.x, p.y); s = await state(page);
  k5.mouse.click = { got: s.selected, box: s.selBox, pass: s.selected === 'h1' && s.selBox };
  p = await pt(page, '#evil-h2'); await page.mouse.click(p.x, p.y); await page.waitForTimeout(300); s = await state(page);
  k5.mouse.clickOnclick = { got: s.selected, pwn: s.pwn, pass: s.selected === 'h2#evil-h2' && s.pwn.length === 0 };
  p = await pt(page, '#evil-link'); await page.mouse.click(p.x, p.y); await page.waitForTimeout(300); s = await state(page);
  k5.mouse.clickJavascriptHref = { got: s.selected, pwn: s.pwn, title: s.title, pass: s.selected === 'a#evil-link' && s.pwn.length === 0 && !s.title.includes('PWNED') && s.href.endsWith('/__spike') };
  const a = await pt(page, '#c1'), b = await pt(page, '#c3', 0.2, 0.8);   // RTL: החצי השמאלי = "אחרי"
  // דרך CDP ישירות: page.mouse.move עם כפתור לחוץ נתקע ביירוט הגרירה של Playwright כשהיעד בתוך מסגרת
  const cdpM = await ctx.newCDPSession(page), ev = (type, x, y, buttons) => cdpM.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', buttons, clickCount: 1, pointerType: 'mouse' });
  await ev('mouseMoved', a.x, a.y, 0); await ev('mousePressed', a.x, a.y, 1);
  for (let i = 1; i <= 8; i++) await ev('mouseMoved', a.x + (b.x - a.x) * i / 8, a.y + (b.y - a.y) * i / 8, 1);
  await ev('mouseReleased', b.x, b.y, 0); await page.waitForTimeout(100);
  s = await state(page);
  k5.mouse.drag = { domOrder: s.order, srcOrder: s.srcOrder, pass: s.order === 'c2,c3,c1' && s.srcOrder === 'id="c2",id="c3",id="c1"' };
  k5.mouse.events = s.events;
  k5.attackAfterInput = s.pwn.length === 0;
  out.k5 = k5;
  // ── 3. K6 עם מעבד מואט פי 4 (קירוב לטלפון — לא מכשיר אמיתי) ──
  const cdp = await ctx.newCDPSession(page);
  const measure = () => page.evaluate(async () => {
    const s = window.__spike, K = window.KolSrc, c = s.canvas, med = a => a.slice().sort((x, y) => x - y)[Math.floor(a.length / 2)], loads = [];
    for (let i = 0; i < 3; i++) loads.push((await c.load(s.text['lexicon.html'], s.text['lexicon.css'])).total);
    const ops = [];
    const g = () => K.q(c.m, '#kadisha-lex-grid');
    ops.push(['הזזת כרטיס', c.apply(m => K.html.move(m, K.q(m, '.kadisha-word-card', 700), g(), 701)).timing]);
    ops.push(['שינוי תכונה', c.apply(m => K.html.setAttr(m, K.q(m, '.kadisha-word-card', 700), 'data-id', 'x')).timing]);
    ops.push(['שינוי טקסט', c.apply(m => K.html.setText(m, K.q(m, '.kadisha-word-title', 700), 'חדש')).timing]);
    ops.push(['מחיקת כרטיס', c.apply(m => K.html.remove(m, K.q(m, '.kadisha-word-card', 700))).timing]);
    ops.push(['שינוי CSS', c.applyCss(m => K.css.setDecl(m, K.cssFind(m, '.kadisha-word-card', null), 'padding', '2rem')).timing]);
    const r = x => Math.round(x * 10) / 10;
    return { loadMedianMs: r(med(loads)), ops: ops.map(([n, t]) => ({ name: n, totalMs: r(t.total), reparseMs: r(t.reparse), domMs: r(t.dom), mapMs: r(t.map), layoutMs: r(t.layout) })) };
  });
  out.k6 = { desktop: await measure() };
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  out.k6.cpu4x = await measure();
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 1 });
  await ctx.close();
}
// ── 4. K5: מגע, בהדמיית טלפון של Chromium ───────────────────
for (const dev of ['Pixel 7', 'iPhone 14']) {
  const d = { ...devices[dev] }; delete d.defaultBrowserType;
  const { ctx, page } = await open(d);
  await page.evaluate(async () => {
    const C = window.KolCanvas, text = {}, bytes = {};
    for (const f of ['handwritten.html', 'handwritten.css']) { bytes[f] = await window.__fixture(f); text[f] = C.decode(bytes[f]); }
    const pwn = []; window.addEventListener('message', e => { if (typeof e.data === 'string' && e.data.startsWith('kol-spike-pwned')) pwn.push(e.data); });
    window.__spike = { canvas: new C.Canvas(document.getElementById('host')), pwn, text, bytes };
  });
  await loadHw(page);
  const t = { emulated: dev + ' ב-Chromium — לא מכשיר אמיתי ולא Safari' };
  let p = await pt(page, 'h1.title'); await page.touchscreen.tap(p.x, p.y); let s = await state(page);
  t.tap = { got: s.selected, box: s.selBox, pass: s.selected === 'h1' && s.selBox };
  p = await pt(page, '#evil-h2'); await page.touchscreen.tap(p.x, p.y); await page.waitForTimeout(300); s = await state(page);
  t.tapOnclick = { got: s.selected, pwn: s.pwn, pass: s.selected === 'h2#evil-h2' && s.pwn.length === 0 };
  // גרירה במגע: קודם בוחרים (נגיעה), ואז גוררים את האלמנט הנבחר
  p = await pt(page, '#c1'); await page.touchscreen.tap(p.x, p.y);
  const a = await pt(page, '#c1'), b = await pt(page, '#c3', 0.2, 0.8), cdp = await ctx.newCDPSession(page);
  const touch = (type, x, y) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x: Math.round(x), y: Math.round(y), id: 1 }] });
  await touch('touchStart', a.x, a.y);
  for (let i = 1; i <= 8; i++) { await touch('touchMove', a.x + (b.x - a.x) * i / 8, a.y + (b.y - a.y) * i / 8); await page.waitForTimeout(20); }
  await touch('touchEnd'); await page.waitForTimeout(100); s = await state(page);
  t.touchDrag = { domOrder: s.order, srcOrder: s.srcOrder, pass: s.order === 'c2,c3,c1' && s.srcOrder === 'id="c2",id="c3",id="c1"' };
  t.events = s.events; t.pwn = s.pwn;
  (out.k5.touch = out.k5.touch || {})[dev] = t;
  await ctx.close();
}
await browser.close(); server.close();

// ── 5. K7: משקל ──────────────────────────────────────────────
const size = f => { const b = fs.readFileSync(path.join(root, f)); return { file: f, bytes: b.length, gzip: zlib.gzipSync(b, { level: 9 }).length, brotli: zlib.brotliCompressSync(b).length }; };
out.k7 = { parsers: size('assets/vendor/spike-parsers.js'), ours: ['assets/js/editor/model.js', 'assets/js/editor/canvas.js'].map(size), limitGzip: 400 * 1024 };
out.k7.pass = out.k7.parsers.gzip <= out.k7.limitGzip;
out.pageErrors = errors;

const h = out.harness, m = out.k5.mouse, tt = Object.values(out.k5.touch);
out.summary = {
  K1: h.k1.pass, K2: h.k2.pass, K3: h.k3.pass, K4: h.k4.pass,
  K5_chromium: h.k5.attackPassStatic && out.k5.attackAfterInput && m.hover.pass && m.click.pass && m.clickOnclick.pass && m.clickJavascriptHref.pass && m.drag.pass && tt.every(t => t.tap.pass && t.tapOnclick.pass && t.touchDrag.pass),
  K5_other_browsers: 'לא נבדק — בסביבה מותקן Chromium בלבד',
  K6_load: h.k6.loadPass, K6_op: h.k6.opPass, K7: out.k7.pass,
  V1_equiv_60_ops: h.equiv.ops60.inequivalent === 0, V1_equiv_random_200: Object.values(h.equiv.random).every(x => x.pass),
  V1_op_html_median_ms: h.k6.opHtml.medianMs, V1_op_html_max_ms: h.k6.opHtml.maxMs, V1_op_html_median_le_100: h.k6.opHtmlMedianPass,
};
fs.writeFileSync(path.join(here, 'results.json'), JSON.stringify(out, null, 1) + '\n');
console.log(JSON.stringify(out.summary, null, 1));
if (errors.length) console.log('page errors:', errors.slice(0, 5));
process.exit(['K1', 'K2', 'K3', 'K4', 'K5_chromium', 'V1_equiv_60_ops', 'V1_equiv_random_200'].every(k => out.summary[k] === true) ? 0 : 1);
