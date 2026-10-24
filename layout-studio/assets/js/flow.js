// מנוע העימוד של הטקסט: שטף → שורות בתוך מסגרות. בלי DOM (נבדק ב-tests/flow.test.mjs).
//
//   1. מילים נמדדות ב-HarfBuzz (shaper.js), במטמון לפי גופן ומילה.
//   2. שבירת שורות חמדנית לפי רוחב הטור; שורה ארוכה ממילה אחת — לבד בשורה.
//   3. השורה הסופית מעוצבת שלמה (bidi נכון בין מילים), ויישור לשני הצדדים מרחיב את הרווחים בלבד.
//   4. טורים מימין לשמאל; מסגרת אחרי מסגרת לפי סדר השרשור. מה שלא נכנס — "גלישה" (overset).
//
// הפלט — במידות העמוד (pt), מוכן לציור (view.js) ובהמשך ל-PDF: אותן שורות, אותם גליפים.

import { shapeLine, advance } from './shaper.js';
import { thread, bodyStyle, docParagraphs } from './frames.js';

const wordCache = new Map();   // `${font}\u0000${word}` → רוחב ביחידות הגופן

function wordWidth(fonts, word) {
  const key = fonts.map(x => x.name).join('+') + '\u0000' + word;
  let w = wordCache.get(key);
  if (w === undefined) { w = advance(shapeLine(fonts, word)); wordCache.set(key, w); }
  return w;
}

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
 *   fontsFor(family) → [גופן ראשי, חלופי…] טעונים (shaper.loadFont, עם name — מפתח המטמון).
 * מחזיר { frames: Map(frameId → { lines: [{ x, y, glyphs, size }], overset }), flows: Map(flowId → { overset, rest }) }
 *   y — קו הבסיס; x — תחילת השורה משמאל; rest — כמה פסקאות (וחלק) לא נכנסו.
 */
export function layoutFlows(model, fontsFor) {
  const frames = new Map(), flows = new Map();
  for (const [flowId, flow] of Object.entries(model.flows || {})) {
    if (flow.engine !== 'prosemirror' || !Array.isArray(flow.frames)) continue;
    const st = bodyStyle(model);
    const fonts = fontsFor(st.font);
    const res = layoutFlow(docParagraphs(flow.doc), thread(model, flowId).map(t => t.frame), st, fonts);
    res.frames.forEach((v, k) => frames.set(k, v));
    flows.set(flowId, { overset: res.overset, rest: res.rest });
  }
  return { frames, flows };
}

/** פריסת שטף אחד במסגרות שלו. */
export function layoutFlow(paragraphs, frameList, st, fonts) {
  const f = fonts[0];
  const k = st.size / f.upem;                       // יחידות גופן → pt
  const spaceW = wordWidth(fonts, ' ') * k;
  const ascent = st.size * 0.9, descent = st.size * 0.3;

  // תור של שורות לוגיות שמחכות למקום: נבנה לפי רוחב הטור הנוכחי, לכן נשבר עצלנית
  const out = new Map(frameList.map(fr => [fr.id, { lines: [], overset: false }]));
  const slots = [];   // טורים בסדר הזרימה
  for (const fr of frameList) for (const c of columnsOf(fr)) slots.push({ frameId: fr.id, ...c });

  let si = 0, y = null;   // טור נוכחי, וקו הבסיס הבא בו
  const nextSlot = () => { si++; y = null; };
  const place = (lineH) => {   // מחזיר את הטור והבסיס לשורה הבאה, או null אם נגמר המקום
    while (si < slots.length) {
      const s = slots[si];
      const base = y === null ? s.y + ascent : y + lineH;
      if (base + descent <= s.y + s.h + 0.01) { y = base; return s; }
      nextSlot();
    }
    return null;
  };

  let pi = 0;
  for (; pi < paragraphs.length; pi++) {
    const words = paragraphs[pi].split(/ +/).filter(Boolean);
    if (!words.length) {   // פסקה ריקה — שורה ריקה
      if (!place(st.leading)) break;
      y += st.spaceAfter;
      continue;
    }
    let wi = 0, cut = false;
    while (wi < words.length) {
      const s = place(st.leading);
      if (!s) { cut = true; break; }
      // שבירה: כמה מילים נכנסות ברוחב הטור
      let width = wordWidth(fonts, words[wi]) * k, j = wi + 1;
      while (j < words.length) {
        const ww = width + spaceW + wordWidth(fonts, words[j]) * k;
        if (ww > s.w + 0.01) break;
        width = ww; j++;
      }
      const text = words.slice(wi, j).join(' ');
      const glyphs = shapeLine(fonts, text);
      const natural = advance(glyphs) * k;
      const last = j >= words.length;
      let x;
      if (st.align === 'justify' && !last && j - wi > 1) {
        const spaces = glyphs.filter(g => text[g.cluster] === ' ');
        const extra = (s.w - natural) / k / spaces.length;
        for (const g of spaces) g.ax += extra;
        x = s.x;
      } else if (st.align === 'left') x = s.x;
      else if (st.align === 'center') x = s.x + (s.w - natural) / 2;
      else x = s.x + s.w - natural;   // ימין — ובסוף פסקה מיושרת
      out.get(s.frameId).lines.push({ x, y, glyphs, size: st.size, font: f });
      wi = j;
    }
    if (cut) {
      return finish(out, frameList, { paragraph: pi, word: wi, of: paragraphs.length });
    }
    y += st.spaceAfter;
  }
  return finish(out, frameList, pi < paragraphs.length ? { paragraph: pi, word: 0, of: paragraphs.length } : null);
}

function finish(out, frameList, rest) {
  if (rest && frameList.length) out.get(frameList[frameList.length - 1].id).overset = true;
  return { frames: out, overset: !!rest, rest };
}
