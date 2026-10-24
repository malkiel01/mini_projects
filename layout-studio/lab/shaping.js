// ניסוי: ניקוד וטעמים ב-HarfBuzz על קנבס, מול fillText של הדפדפן, בשני גופנים — ומהירות העיצוב במכשיר.

import { loadFont, shapeLine as shapeBidi, shape, advance, drawGlyphs } from '../assets/js/shaper.js';

const FONTS = [
  { name: 'Noto Serif Hebrew', file: 'NotoSerifHebrew.ttf' },
  { name: 'Frank Ruhl Libre', file: 'FrankRuhlLibre.ttf' },
];
const SAMPLES = [
  { title: 'ניקוד — ברכה', text: 'בָּרוּךְ אַתָּה ה׳ אֱלֹהֵינוּ מֶלֶךְ הָעוֹלָם' },
  { title: 'ניקוד וטעמים — בראשית א, א', text: 'בְּרֵאשִׁ֖ית בָּרָ֣א אֱלֹהִ֑ים אֵ֥ת הַשָּׁמַ֖יִם וְאֵ֥ת הָאָֽרֶץ׃' },
  { title: 'שורוק, חולם, דגש וקמץ קטן', text: 'וַיֹּ֣אמֶר אֱלֹהִ֔ים יְהִ֣י א֑וֹר וַֽיְהִי־אֽוֹר׃ כָּל־עַצְמוֹתַי' },
  { title: 'עברית, מספרים ואנגלית', text: 'פרק 12: ספר Genesis, עמוד 3–4' },
];
const SIZE = 26;   // px — קטן יותר אם השורה לא נכנסת

const $ = s => document.querySelector(s);

function canvasFor(box, h) {
  const c = document.createElement('canvas');
  box.append(c);
  const dpr = devicePixelRatio || 1;
  const w = box.clientWidth - 16;
  c.style.height = h + 'px';
  c.width = Math.round(w * dpr);
  c.height = Math.round(h * dpr);
  const ctx = c.getContext('2d');
  ctx.scale(dpr, dpr);
  return { ctx, w };
}

(async () => {
  const stats = $('#stats');
  try {
    const t0 = performance.now();
    const fonts = [];
    for (const F of FONTS) fonts.push({ ...F, f: await loadFont('../assets/fonts/' + F.file) });
    // רישום הגופן גם לדפדפן — לשורת ההשוואה
    for (const F of FONTS) {
      const ff = new FontFace(F.name, `url(../assets/fonts/${F.file})`);
      document.fonts.add(await ff.load());
    }
    const tLoad = performance.now() - t0;

    const lines = [];
    for (const S of SAMPLES) {
      for (const F of fonts) {
        const box = document.createElement('div');
        box.className = 'sample';
        box.innerHTML = '<h3></h3>';
        box.firstChild.textContent = `${S.title} · ${F.name}`;
        $('#samples').append(box);
        const g = shapeBidi(F.f, S.text, 'rtl');
        const missing = g.filter(x => x.gid === 0).length;
        const lbl = (t) => { const d = document.createElement('div'); d.className = 'row-lbl'; d.textContent = t; box.append(d); };
        // HarfBuzz — מיושר לימין
        lbl('HarfBuzz' + (missing ? ` — ⚠ ${missing} סימנים חסרים בגופן` : ''));
        const a = canvasFor(box, SIZE * 1.9);
        const size = Math.min(SIZE, SIZE * (a.w - 8) / (advance(g) * SIZE / F.f.upem));   // שהשורה תיכנס כולה
        const wHb = advance(g) * size / F.f.upem;
        a.ctx.fillStyle = '#16192b';
        drawGlyphs(a.ctx, F.f, g, a.w - wHb - 4, SIZE * 1.25, size);
        // הדפדפן
        lbl('הדפדפן');
        const b = canvasFor(box, SIZE * 1.9);
        b.ctx.fillStyle = '#16192b';
        b.ctx.font = `${size}px '${F.name}'`;
        b.ctx.direction = 'rtl';
        b.ctx.textAlign = 'right';
        b.ctx.fillText(S.text, b.w - 4, SIZE * 1.25);
        const wBr = b.ctx.measureText(S.text).width;
        lines.push(`${F.name.split(' ')[0]}: רוחב ${wHb.toFixed(1)} מול ${wBr.toFixed(1)}, חסרים ${missing}`);
      }
    }

    // מהירות: ~2,800 מילים מנוקדות עם טעמים
    const big = (SAMPLES[1].text + ' ').repeat(400);
    const t1 = performance.now();
    for (let i = 0; i < 3; i++) shape(fonts[0].f, big, 'rtl');
    const tShape = (performance.now() - t1) / 3;

    stats.innerHTML = '';
    const add = (t) => { const d = document.createElement('div'); d.className = 'line'; d.textContent = t; stats.append(d); };
    add(`טעינת HarfBuzz ושני גופנים: ${Math.round(tLoad)} מ"ש`);
    add(`עיצוב כ-2,800 מילים מנוקדות עם טעמים: ${tShape.toFixed(1)} מ"ש`);
    add('HarfBuzz ' + (await import('../assets/vendor/harfbuzzjs/index.mjs')).versionString());
    window.__lab = { ok: true, tLoad, tShape, lines };
  } catch (e) {
    stats.textContent = 'שגיאה: ' + e.message;
    window.__lab = { ok: false, error: e.message };
  }
})();
