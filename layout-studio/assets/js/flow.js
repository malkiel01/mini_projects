// מנוע העימוד של הטקסט: שטף → שורות בתוך מסגרות. בלי DOM (נבדק ב-tests/flow.test.mjs).
//
//   1. כל פסקה לפי הסגנון שלה (styles.js: גוף, כותרות): גופן, משקל, גודל, מרווח, יישור, רווח לפני ואחרי, הזחה.
//   2. מילים נמדדות ב-HarfBuzz (shaper.js), במטמון; מילה עם חלק מודגש — כל חלק בגופן שלו.
//   3. שבירת שורות חמדנית לפי רוחב הטור; מילה ארוכה מהטור — לבד בשורה.
//   4. השורה הסופית מעוצבת שלמה (bidi נכון בין מילים), ויישור לשני הצדדים מרחיב את הרווחים בלבד.
//   5. טורים מימין לשמאל; מסגרת אחרי מסגרת לפי סדר השרשור. מה שלא נכנס — "גלישה" (overset).
//   5א. כללי ספר: בלי שורה יתומה (ראשונה לבד בתחתית טור) ובלי אלמנה (אחרונה לבד בראש טור); כותרת עוברת עם הפסקה שאחריה.
//   6. גלישה סביב תמונה (wrap): כל שורה ברווח הפנוי הרחב ביותר לצד התמונה; אין מקום — השורה מדלגת מטה.
//
// הפלט — במידות העמוד (pt), מוכן לציור (view.js) ובהמשך ל-PDF: אותן שורות, אותם גליפים.

import { shapeLine, advance } from './shaper.js';
import { thread, styleOf, docBlocks, wrapRects } from './frames.js';

const wordCache = new Map();   // מפתח: גופנים + מסכת הדגשה + מילה → רוחב ביחידות הגופן

/** הטורים של מסגרת, מימין לשמאל: [{ x, y, w, h }] בקואורדינטות העמוד. */
export function columnsOf(frame) {
  const [x, y, w, h] = frame.box;
  const n = Math.max(1, frame.columns || 1), g = n > 1 ? frame.gutter || 0 : 0;
  const cw = (w - g * (n - 1)) / n;
  const out = [];
  for (let i = 0; i < n; i++) out.push({ x: x + w - cw - i * (cw + g), y, w: cw, h });
  return out;
}

/**
 * פריסת כל השטפים.
 *   fontsFor(family, weight) → [גופן ראשי, חלופי…] טעונים (shaper.loadFont / fontVariant, עם name — מפתח המטמון).
 * מחזיר { frames: Map(frameId → { lines: [{ x, y, glyphs, size, font, text }], overset, heads: [{ level, y, text }] }),
 *          flows: Map(flowId → { overset, rest }) }
 *   y — קו הבסיס; x — תחילת השורה משמאל; text — הטקסט הלוגי של השורה (ל-PDF: חיפוש והעתקה); rest — מאיזו פסקה (ומילה) לא נכנס, ומתוך כמה.
 */
export function layoutFlows(model, fontsFor) {
  const frames = new Map(), flows = new Map();
  for (const [flowId, flow] of Object.entries(model.flows || {})) {
    if (flow.engine !== 'prosemirror' || !Array.isArray(flow.frames)) continue;
    const th = thread(model, flowId);
    const page = new Map(th.map(t => [t.frame.id, t.pageIndex]));
    // מה שהטקסט עוקף בעמוד של המסגרת — חוץ מהמסגרת עצמה
    const avoid = id => page.has(id) ? wrapRects(model, page.get(id)).filter(r => r.id !== id) : [];
    const res = layoutFlow(docBlocks(flow.doc), th.map(t => t.frame), key => styleOf(model, key), fontsFor, avoid);
    res.frames.forEach((v, k) => frames.set(k, v));
    flows.set(flowId, { overset: res.overset, rest: res.rest });
  }
  return { frames, flows };
}

// בלוק → טקסט אחיד (רווחים כפולים מתכווצים) + טווחי הדגשה במיקומים שלו
function blockText(block) {
  let text = '';
  const bold = [];
  for (const r of block.runs) {
    for (const ch of r.text.replace(/[^\S\n]/g, ' ')) {   // רווחים מכל סוג → רווח; \n — שבירת שורה, נשארת
      if (ch === ' ' && (text === '' || text.endsWith(' ') || text.endsWith('\n'))) continue;
      if (r.bold) {
        const last = bold[bold.length - 1];
        if (last && last.end === text.length) last.end += ch.length;
        else bold.push({ start: text.length, end: text.length + ch.length });
      }
      text += ch;
    }
  }
  return { text: text.replace(/\s+$/, ''), bold };
}

// טווחי הדגשה בתוך קטע [a, b) — במיקומים יחסיים, עם הגופנים המודגשים
const spansIn = (bold, a, b, fontsB) => bold.filter(s => s.end > a && s.start < b)
  .map(s => ({ start: Math.max(0, s.start - a), end: Math.min(b, s.end) - a, fonts: fontsB }));

/**
 * פריסת שטף אחד במסגרות שלו.
 *   blocks — docBlocks(doc); styleFor(key) — סגנון פסקה; fontsFor(family, weight).
 */
export function layoutFlow(blocks, frameList, styleFor, fontsFor, avoid = () => []) {
  const out = new Map(frameList.map(fr => [fr.id, { lines: [], overset: false, heads: [] }]));
  const slots = [];   // טורים בסדר הזרימה, כל אחד עם מה שהוא עוקף
  for (const fr of frameList) {
    const rects = avoid(fr.id);
    for (const c of columnsOf(fr)) slots.push({ frameId: fr.id, ...c, rects });
  }
  const MIN_W = 24;   // רווח צר מזה לצד תמונה — לא כותבים בו

  let si = 0, y = null;   // טור נוכחי, וקו הבסיס האחרון בו (null — עוד לא נכתב בו כלום)
  /**
   * מקום לשורה הבאה: { s: הטור, x, w: הקטע הפנוי בשורה }, או null אם נגמר המקום. before — רווח לפני פסקה
   * (לא בראש טור). שורה שתמונה חוסמת — מדלגים עליה מטה.
   */
  const place = (st, before) => {
    while (si < slots.length) {
      const s = slots[si];
      const base = y === null ? s.y + st.size * 0.9 : y + before + st.leading;
      if (base + st.size * 0.3 > s.y + s.h + 0.01) { si++; y = null; before = 0; continue; }
      y = base;
      const seg = freeSegment(s, base - st.size * 0.9, base + st.size * 0.3, MIN_W);
      if (seg) return { s, ...seg };
      before = 0;   // חסום — השורה הבאה למטה
    }
    return null;
  };

  // תמונת מצב לחזרה אחורה (שורות יתומות / אלמנות, כותרת שנשארה לבד) — כמה שורות בכל מסגרת, ואיפה עומדים
  const snapshot = () => ({ si, y, lens: new Map([...out].map(([id, v]) => [id, [v.lines.length, v.heads.length]])) });
  const restore = sn => { si = sn.si; y = sn.y; for (const [id, [n, h]] of sn.lens) { out.get(id).lines.length = n; out.get(id).heads.length = h; } };
  // מעבר לטור הבא — רק אם בטור הנוכחי כבר יש משהו ויש טור אחריו (אחרת אין מה להרוויח, ולא נכנסים ללולאה)
  const canBreak = () => y !== null && si + 1 < slots.length;
  const forceBreak = () => { si++; y = null; };

  /**
   * פריסת פסקה אחת. מחזיר את הטור של כל שורה (מערך אינדקסים), או null כשנגמר המקום.
   * breakAt — לפני השורה הזו עוברים לטור הבא (לתיקון שורה אלמנה).
   */
  const layBlock = (bi, breakAt = -1) => {
    const st = styleFor(blocks[bi].style);
    const fontsR = fontsFor(st.font, st.weight);
    const fontsB = fontsFor(st.font, Math.min(900, Math.max(700, st.weight + 300)));
    const f = fontsR[0], k = st.size / f.upem;
    const { text, bold } = blockText(blocks[bi]);
    const key = fontsR.map(x => x.name).join('+') + '|' + fontsB[0].name;
    const slotsOf = [];

    // מילים: [start, end) בטקסט; '\n' — אסימון של שבירת שורה (hardBreak)
    const words = [];
    for (const m of text.matchAll(/[^ \n]+|\n/g)) words.push([m.index, m.index + m[0].length]);
    const isBreak = i => i < words.length && text[words[i][0]] === '\n';
    const wordW = ([a, b]) => {
      const sp = spansIn(bold, a, b, fontsB);
      const ck = key + '|' + sp.map(s => s.start + '-' + s.end).join(',') + '|' + text.slice(a, b);
      let w = wordCache.get(ck);
      if (w === undefined) { w = advance(shapeLine(fontsR, text.slice(a, b), 'rtl', sp.length ? sp : null)); wordCache.set(ck, w); }
      return w * k;
    };
    const spaceW = (wordCache.get(key + '| ') ?? (wordCache.set(key + '| ', advance(shapeLine(fontsR, ' '))), wordCache.get(key + '| '))) * k;

    if (!words.length) {   // פסקה ריקה — שורה ריקה
      if (!place(st, st.spaceBefore)) return null;
      slotsOf.push(si);
      y += st.spaceAfter;
      return slotsOf;
    }
    let wi = 0;
    while (wi < words.length) {
      if (slotsOf.length === breakAt && canBreak()) forceBreak();
      const p = place(st, wi === 0 ? st.spaceBefore : 0);
      if (!p) return null;
      slotsOf.push(si);
      if (isBreak(wi)) { wi++; continue; }   // שבירה בתחילת שורה — שורה ריקה
      const s = { frameId: p.s.frameId, x: p.x, w: p.w };   // הקטע הפנוי בשורה הזו
      const indent = wi === 0 ? Math.min(st.indent || 0, s.w / 2) : 0;   // הזחת שורה ראשונה — מימין
      const avail = s.w - indent;
      let width = wordW(words[wi]), j = wi + 1;
      while (j < words.length && !isBreak(j)) {
        const ww = width + spaceW + wordW(words[j]);
        if (ww > avail + 0.01) break;
        width = ww; j++;
      }
      const a = words[wi][0], b = words[j - 1][1];
      const lineText = text.slice(a, b);
      const sp = spansIn(bold, a, b, fontsB);
      const glyphs = shapeLine(fontsR, lineText, 'rtl', sp.length ? sp : null);
      const natural = advance(glyphs) * k;
      const last = j >= words.length || isBreak(j);   // סוף פסקה או שבירת שורה — לא מיושרת לשני הצדדים
      const right = s.x + s.w - indent;
      let x;
      if (st.align === 'justify' && !last && j - wi > 1) {
        const spaces = glyphs.filter(g => lineText[g.cluster] === ' ');
        const extra = (avail - natural) / k / spaces.length;
        for (const g of spaces) g.ax += extra;
        x = s.x;
      } else if (st.align === 'left') x = s.x;
      else if (st.align === 'center') x = s.x + (avail - natural) / 2;
      else x = right - natural;   // ימין — ובסוף פסקה מיושרת
      out.get(s.frameId).lines.push({ x, y, glyphs, size: st.size, font: f, text: lineText });
      // כותרת — נרשמת בשורה הראשונה שלה, לכותרת הרצה ("{כותרת 1}" בעמוד האב)
      if (wi === 0 && /^h[1-3]$/.test(blocks[bi].style)) {
        out.get(s.frameId).heads.push({ level: Number(blocks[bi].style[1]), y, text: text.replace(/\s+/g, ' ').trim() });
      }
      wi = isBreak(j) ? j + 1 : j;   // השבירה שסגרה את השורה — נבלעת
    }
    y += st.spaceAfter;
    return slotsOf;
  };

  // כללי ספר: שורה ראשונה לא נשארת לבד בתחתית טור (יתומה), שורה אחרונה לא עוברת לבד לטור הבא (אלמנה),
  // וכותרת לא נשארת לבד בתחתית טור — עוברת עם הפסקה שאחריה.
  const isHead = bi => /^h[1-3]$/.test(blocks[bi]?.style || '');
  const snaps = [];          // תמונת מצב לפני כל פסקה
  const lastSlot = [];       // הטור של השורה האחרונה בכל פסקה
  const pushed = new Set();  // פסקאות שכבר הועברו לטור הבא (פעם אחת — כדי לא להיכנס ללולאה)
  for (let bi = 0; bi < blocks.length; bi++) {
    const sn = snapshot();
    snaps[bi] = sn;
    let r = layBlock(bi);
    if (!r) return finish(out, frameList, { paragraph: bi, word: 0, of: blocks.length });
    const n = r.length;
    const atTop = sn.y === null && sn.si === r[0];   // הפסקה התחילה בראש טור — אין לאן להקדים
    if (n >= 2 && r[0] !== r[1] && !atTop && !pushed.has(bi)) {
      // יתומה: השורה הראשונה לבד בתחתית — כל הפסקה עוברת לטור הבא
      pushed.add(bi);
      restore(sn); if (canBreak()) forceBreak();
      r = layBlock(bi);
      if (!r) return finish(out, frameList, { paragraph: bi, word: 0, of: blocks.length });
    } else if (n >= 3 && r[n - 1] !== r[n - 2] && r[n - 2] === r[n - 3]) {
      // אלמנה: השורה האחרונה לבד בראש הטור הבא — מעבירים איתה גם את השורה שלפניה
      restore(sn);
      r = layBlock(bi, n - 2);
      if (!r) return finish(out, frameList, { paragraph: bi, word: 0, of: blocks.length });
    }
    lastSlot[bi] = r[r.length - 1];
    // כותרת שנשארה בתחתית טור, והפסקה שאחריה התחילה בטור הבא — הכותרת עוברת איתה
    if (bi > 0 && isHead(bi - 1) && !isHead(bi) && r[0] !== lastSlot[bi - 1] && !pushed.has(bi - 1)) {
      const hs = snaps[bi - 1];
      const headTop = hs.y === null && hs.si === lastSlot[bi - 1];
      if (!headTop) {
        pushed.add(bi - 1);
        restore(hs); if (canBreak()) forceBreak();
        bi -= 2;   // הכותרת ואחריה הפסקה — מחדש, מראש הטור
        continue;
      }
    }
  }
  return finish(out, frameList, null);
}

/**
 * הקטע הפנוי הרחב ביותר בשורה [top, bottom] של הטור, אחרי שמורידים את מה שהיא עוקפת. שוויון — הימני (עברית).
 * מחזיר { x, w } או null (אין קטע ברוחב min).
 */
export function freeSegment(s, top, bottom, min) {
  let segs = [[s.x, s.x + s.w]];
  for (const r of s.rects || []) {
    if (r.y >= bottom || r.y + r.h <= top) continue;
    const a = r.x, b = r.x + r.w;
    segs = segs.flatMap(([x0, x1]) => (b <= x0 || a >= x1) ? [[x0, x1]] : [[x0, Math.max(x0, a)], [Math.min(x1, b), x1]].filter(([p, q]) => q - p > 0.01));
  }
  let best = null;
  for (const [x0, x1] of segs) if (x1 - x0 >= min && (!best || x1 - x0 > best.w + 0.01 || (Math.abs(x1 - x0 - best.w) <= 0.01 && x0 > best.x))) best = { x: x0, w: x1 - x0 };
  return best;
}

function finish(out, frameList, rest) {
  if (rest && frameList.length) out.get(frameList[frameList.length - 1].id).overset = true;
  return { frames: out, overset: !!rest, rest };
}
