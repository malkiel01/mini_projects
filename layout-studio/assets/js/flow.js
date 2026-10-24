// מנוע העימוד של הטקסט: שטף → שורות בתוך מסגרות. בלי DOM (נבדק ב-tests/flow.test.mjs).
//
//   1. כל פסקה לפי הסגנון שלה (styles.js: גוף, כותרות): גופן, משקל, גודל, מרווח, יישור, רווח לפני ואחרי, הזחה.
//   2. מילים נמדדות ב-HarfBuzz (shaper.js), במטמון; מילה עם חלק מודגש — כל חלק בגופן שלו.
//   3. שבירת שורות חמדנית לפי רוחב הטור; מילה ארוכה מהטור — לבד בשורה.
//   4. השורה הסופית מעוצבת שלמה (bidi נכון בין מילים), ויישור לשני הצדדים מרחיב את הרווחים בלבד.
//   5. טורים מימין לשמאל; מסגרת אחרי מסגרת לפי סדר השרשור. מה שלא נכנס — "גלישה" (overset).
//
// הפלט — במידות העמוד (pt), מוכן לציור (view.js) ובהמשך ל-PDF: אותן שורות, אותם גליפים.

import { shapeLine, advance } from './shaper.js';
import { thread, styleOf, docBlocks } from './frames.js';

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
 * מחזיר { frames: Map(frameId → { lines: [{ x, y, glyphs, size, font }], overset }), flows: Map(flowId → { overset, rest }) }
 *   y — קו הבסיס; x — תחילת השורה משמאל; rest — מאיזו פסקה (ומילה) לא נכנס, ומתוך כמה.
 */
export function layoutFlows(model, fontsFor) {
  const frames = new Map(), flows = new Map();
  for (const [flowId, flow] of Object.entries(model.flows || {})) {
    if (flow.engine !== 'prosemirror' || !Array.isArray(flow.frames)) continue;
    const res = layoutFlow(docBlocks(flow.doc), thread(model, flowId).map(t => t.frame), key => styleOf(model, key), fontsFor);
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
    for (const ch of r.text.replace(/\s/g, ' ')) {
      if (ch === ' ' && (text === '' || text.endsWith(' '))) continue;
      if (r.bold) {
        const last = bold[bold.length - 1];
        if (last && last.end === text.length) last.end += ch.length;
        else bold.push({ start: text.length, end: text.length + ch.length });
      }
      text += ch;
    }
  }
  return { text: text.trimEnd(), bold };
}

// טווחי הדגשה בתוך קטע [a, b) — במיקומים יחסיים, עם הגופנים המודגשים
const spansIn = (bold, a, b, fontsB) => bold.filter(s => s.end > a && s.start < b)
  .map(s => ({ start: Math.max(0, s.start - a), end: Math.min(b, s.end) - a, fonts: fontsB }));

/**
 * פריסת שטף אחד במסגרות שלו.
 *   blocks — docBlocks(doc); styleFor(key) — סגנון פסקה; fontsFor(family, weight).
 */
export function layoutFlow(blocks, frameList, styleFor, fontsFor) {
  const out = new Map(frameList.map(fr => [fr.id, { lines: [], overset: false }]));
  const slots = [];   // טורים בסדר הזרימה
  for (const fr of frameList) for (const c of columnsOf(fr)) slots.push({ frameId: fr.id, ...c });

  let si = 0, y = null;   // טור נוכחי, וקו הבסיס האחרון בו (null — עוד לא נכתב בו כלום)
  // מקום לשורה הבאה: הטור והבסיס, או null אם נגמר המקום. before — רווח לפני פסקה (לא בראש טור).
  const place = (st, before) => {
    while (si < slots.length) {
      const s = slots[si];
      const base = y === null ? s.y + st.size * 0.9 : y + before + st.leading;
      if (base + st.size * 0.3 <= s.y + s.h + 0.01) { y = base; return s; }
      si++; y = null; before = 0;
    }
    return null;
  };

  for (let bi = 0; bi < blocks.length; bi++) {
    const st = styleFor(blocks[bi].style);
    const fontsR = fontsFor(st.font, st.weight);
    const fontsB = fontsFor(st.font, Math.min(900, Math.max(700, st.weight + 300)));
    const f = fontsR[0], k = st.size / f.upem;
    const { text, bold } = blockText(blocks[bi]);
    const key = fontsR.map(x => x.name).join('+') + '|' + fontsB[0].name;

    // מילים: [start, end) בטקסט
    const words = [];
    for (const m of text.matchAll(/[^ ]+/g)) words.push([m.index, m.index + m[0].length]);
    const wordW = ([a, b]) => {
      const sp = spansIn(bold, a, b, fontsB);
      const ck = key + '|' + sp.map(s => s.start + '-' + s.end).join(',') + '|' + text.slice(a, b);
      let w = wordCache.get(ck);
      if (w === undefined) { w = advance(shapeLine(fontsR, text.slice(a, b), 'rtl', sp.length ? sp : null)); wordCache.set(ck, w); }
      return w * k;
    };
    const spaceW = (wordCache.get(key + '| ') ?? (wordCache.set(key + '| ', advance(shapeLine(fontsR, ' '))), wordCache.get(key + '| '))) * k;

    if (!words.length) {   // פסקה ריקה — שורה ריקה
      if (!place(st, st.spaceBefore)) return finish(out, frameList, { paragraph: bi, word: 0, of: blocks.length });
      y += st.spaceAfter;
      continue;
    }
    let wi = 0;
    while (wi < words.length) {
      const s = place(st, wi === 0 ? st.spaceBefore : 0);
      if (!s) return finish(out, frameList, { paragraph: bi, word: wi, of: blocks.length });
      const indent = wi === 0 ? Math.min(st.indent || 0, s.w / 2) : 0;   // הזחת שורה ראשונה — מימין
      const avail = s.w - indent;
      let width = wordW(words[wi]), j = wi + 1;
      while (j < words.length) {
        const ww = width + spaceW + wordW(words[j]);
        if (ww > avail + 0.01) break;
        width = ww; j++;
      }
      const a = words[wi][0], b = words[j - 1][1];
      const lineText = text.slice(a, b);
      const sp = spansIn(bold, a, b, fontsB);
      const glyphs = shapeLine(fontsR, lineText, 'rtl', sp.length ? sp : null);
      const natural = advance(glyphs) * k;
      const last = j >= words.length;
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
      out.get(s.frameId).lines.push({ x, y, glyphs, size: st.size, font: f });
      wi = j;
    }
    y += st.spaceAfter;
  }
  return finish(out, frameList, null);
}

function finish(out, frameList, rest) {
  if (rest && frameList.length) out.get(frameList[frameList.length - 1].id).overset = true;
  return { frames: out, overset: !!rest, rest };
}
