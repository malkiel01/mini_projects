// פירוק PDF — שלב 10. קורא מתוך ה-PDF עצמו (pdf.js, בדפדפן — הקובץ לא יוצא
// לשום שירות): שדות טופס מובנים, ושכבת הטקסט — ממנה מציעים מקומות מילוי.
//
// כל המידות כשבר מגודל הדף (0..1), כמו שדות העורך.
(function () {
  'use strict';

  const HEB = /[֐-׿]/;
  const BLANK = /_{3,}|\.{4,}|…{2,}|-{5,}/g;          // "שם: ______"
  const HAS_BLANK = new RegExp(BLANK.source);
  const BOX = /[☐□▢❑]/g;                              // "☐ מסכים"
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  /** ניחוש סוג לפי התווית — כמו בתבנית Word. */
  function guessType(label) {
    const s = String(label).toLowerCase();
    if (/תאריך|date/.test(s)) return 'date';
    if (/חתימ|signature/.test(s)) return 'signature';
    if (/סכום|מחיר|כמות|סה"כ|amount|price|total|sum\b/.test(s)) return 'number';
    if (/הערות|תיאור|פירוט|notes|comments|description/.test(s)) return 'textarea';
    return 'text';
  }
  const prettify = (name) => String(name || '').split('.').pop().replace(/[_\-]+/g, ' ').replace(/\s+/g, ' ').trim();
  const cleanLabel = (s) => String(s).replace(BLANK, ' ').replace(BOX, ' ').replace(/\s+/g, ' ').replace(/^[\s:.\-–]+|[\s:.\-–]+$/g, '').trim();

  /** כמה מהקטן מבין שני מלבנים מכוסה על ידי השני. */
  function overlap(a, b) {
    if (a.page !== b.page) return 0;
    const w = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x);
    const h = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);
    if (w <= 0 || h <= 0) return 0;
    return (w * h) / Math.max(1e-9, Math.min(a.w * a.h, b.w * b.h));
  }

  // ── שדות טופס מובנים (AcroForm) ──
  function widgetField(a, vp, page) {
    if (a.subtype !== 'Widget' || a.hidden || a.readOnly) return null;
    let type;
    if (a.fieldType === 'Tx') type = a.multiLine ? 'textarea' : 'text';
    else if (a.fieldType === 'Btn') { if (a.pushButton) return null; type = 'checkbox'; }
    else if (a.fieldType === 'Sig') type = 'signature';
    else if (a.fieldType === 'Ch') type = 'text';
    else return null;
    const [x1, y1, x2, y2] = vp.convertToViewportRectangle(a.rect);
    const W = vp.width, H = vp.height;
    const r = { x: Math.min(x1, x2) / W, y: Math.min(y1, y2) / H, w: Math.abs(x2 - x1) / W, h: Math.abs(y2 - y1) / H };
    if (r.w <= 0 || r.h <= 0) return null;

    let label = (a.alternativeText || prettify(a.fieldName) || 'שדה').slice(0, 100);
    if (a.radioButton) label += ': ' + prettify(a.buttonValue);
    if (type === 'text') { const g = guessType(label); if (g === 'date' || g === 'number') type = g; }
    const size = a.defaultAppearanceData && a.defaultAppearanceData.fontSize;
    return {
      type, label, page, ...r,
      help: a.fieldType === 'Ch' && a.options ? 'אפשרויות: ' + a.options.map(o => o.displayValue).join(' / ').slice(0, 280) : '',
      required: !!(a.required || (a.fieldFlags & 2)),
      default: '',
      fontSize: Math.round(size > 0 ? size : clamp(r.h * H * 0.7, 8, 12)),
      align: a.textAlignment === 1 ? 'center' : a.textAlignment === 2 ? 'left' : 'right',
      overflow: 'shrink', role: 'owner', pdfName: String(a.fieldName || '').slice(0, 120),
    };
  }

  // ── שכבת טקסט: מקומות מילוי מוצעים ──
  async function textSpots(pg, vp, page) {
    const tc = await pg.getTextContent();
    const W = vp.width, H = vp.height;
    const items = [];
    let chars = 0;
    for (const it of tc.items) {
      if (!it.str || !it.transform) continue;
      chars += it.str.replace(/\s/g, '').length;
      const [, , c, d, e, f] = it.transform;
      const fh = Math.hypot(c, d) || it.height || 10;
      const [px, py] = vp.convertToViewportPoint(e, f);
      const [qx, qy] = vp.convertToViewportPoint(e + (it.width || 0), f + fh);
      items.push({ str: it.str, x0: Math.min(px, qx), x1: Math.max(px, qx), y0: Math.min(py, qy), y1: Math.max(py, qy),
        // dir: pdf.js מחזיר "rtl" כשהמחרוזת בסדר לוגי עברי; שורה מעורבת ("____ :טלפון")
        // מגיעה כ-"ltr", בסדר חזותי משמאל לימין.
        fh, logical: it.dir === 'rtl', rtl: it.dir === 'rtl' || HEB.test(it.str) });
    }
    if (!items.length) return { spots: [], chars };
    const sameLine = (a, b) => Math.min(a.y1, b.y1) - Math.max(a.y0, b.y0) > Math.min(a.y1 - a.y0, b.y1 - b.y0) * 0.4;
    const left = Math.min(...items.map(i => i.x0)), right = Math.max(...items.map(i => i.x1));

    /** התווית לשטח ריק: הטקסט שלפניו בכיוון הקריאה, באותה שורה. */
    function labelFor(it, xs, xe, before) {
      const own = cleanLabel(before);
      if (own) return own.slice(-60);
      const line = items.filter(o => o !== it && sameLine(o, it) && cleanLabel(o.str));
      const onRight = line.filter(o => o.x0 >= xe - 1).sort((a, b) => a.x0 - b.x0)[0];
      const onLeft = line.filter(o => o.x1 <= xs + 1).sort((a, b) => b.x1 - a.x1)[0];
      const pick = onRight && HEB.test(onRight.str) ? onRight : onLeft && !HEB.test(onLeft.str) ? onLeft : onRight || onLeft;
      return pick ? cleanLabel(pick.str).slice(-60) : '';
    }
    // מיקום של תת-מחרוזת בתוך פריט טקסט — יחסי לאורך (בעברית — מימין).
    const span = (it, i0, i1) => {
      const L = it.str.length, w = it.x1 - it.x0;
      return it.logical ? [it.x1 - (i1 / L) * w, it.x1 - (i0 / L) * w] : [it.x0 + (i0 / L) * w, it.x0 + (i1 / L) * w];
    };
    const spots = [];
    const push = (s) => {
      const r = { page, x: s.x / W, y: s.y / H, w: s.w / W, h: s.h / H, label: s.label || '', type: s.type };
      r.x = clamp(r.x, 0, 1 - r.w); r.y = clamp(r.y, 0, 1 - r.h);
      if (r.w > 0.005 && r.h > 0.003 && !spots.some(o => overlap(o, r) > 0.5)) spots.push(r);
    };

    for (const it of items) {
      // קווים ונקודות: "______"
      for (const m of it.str.matchAll(BLANK)) {
        const [xs, xe] = span(it, m.index, m.index + m[0].length);
        // התווית בתוך אותו פריט: בסדר לוגי — מה שלפני הקו; בסדר חזותי — עברית
        // שמימין לקו (אחריו במחרוזת), אחרת מה שמשמאל לו.
        const before = it.str.slice(0, m.index).split(BLANK).pop();
        const after = it.str.slice(m.index + m[0].length).split(BLANK)[0];
        const own = it.logical ? before : HEB.test(after) && cleanLabel(after) ? after : before;
        const label = labelFor(it, xs, xe, own);
        push({ x: xs, y: it.y0 - it.fh * 0.15, w: xe - xs, h: it.fh * 1.25, label, type: guessType(label) });
      }
      // תיבות סימון מצוירות כתו: "☐ מסכים"
      for (const m of it.str.matchAll(BOX)) {
        const [xs, xe] = span(it, m.index, m.index + 1);
        // "☐ מסכים": התווית אחרי התיבה בסדר הקריאה — בעברית חזותית היא משמאל לה
        const next = it.str.slice(m.index + 1).split(BOX)[0], prev = it.str.slice(0, m.index).split(BOX).pop();
        const near = !it.logical && HEB.test(prev) ? prev : next;
        const label = cleanLabel(near) || cleanLabel(it.str) || 'סימון';
        const sz = Math.max(xe - xs, it.fh * 0.8);
        push({ x: xs, y: it.y0, w: sz, h: sz, label: label.slice(0, 60), type: 'checkbox' });
      }
      // "שם:" — ואחריו שטח ריק (למשל קו מצויר, שאינו טקסט)
      const t = it.str.trim();
      if (/[:：]$/.test(t) && !HAS_BLANK.test(t) && cleanLabel(t)) {
        const line = items.filter(o => o !== it && sameLine(o, it));
        const label = cleanLabel(t).slice(-60);
        if (it.rtl) {
          const next = line.filter(o => o.x1 <= it.x0 + 1).sort((a, b) => b.x1 - a.x1)[0];
          const gap = it.x0 - (next ? next.x1 : left);
          if (gap >= 50) { const w = Math.min(gap - 8, 220); push({ x: it.x0 - 4 - w, y: it.y0 - it.fh * 0.15, w, h: it.fh * 1.25, label, type: guessType(label) }); }
        } else {
          const next = line.filter(o => o.x0 >= it.x1 - 1).sort((a, b) => a.x0 - b.x0)[0];
          const gap = (next ? next.x0 : right) - it.x1;
          if (gap >= 50) { const w = Math.min(gap - 8, 220); push({ x: it.x1 + 4, y: it.y0 - it.fh * 0.15, w, h: it.fh * 1.25, label, type: guessType(label) }); }
        }
      }
    }
    return { spots, chars };
  }

  /**
   * מנתח את כל הדפים. מחזיר: fields — שדות הטופס המובנים, spots — מקומות מילוי
   * מוצעים מהטקסט, chars — כמה תווי טקסט נמצאו (0 = כנראה סריקה).
   */
  async function analyze(pdf) {
    const fields = [], spots = [];
    let chars = 0;
    for (let i = 1; i <= pdf.numPages; i++) {
      const pg = await pdf.getPage(i);
      const vp = pg.getViewport({ scale: 1 });
      for (const a of await pg.getAnnotations()) {
        const f = widgetField(a, vp, i - 1);
        if (f) fields.push(f);
      }
      const t = await textSpots(pg, vp, i - 1);
      chars += t.chars;
      spots.push(...t.spots.filter(s => !fields.some(f => overlap(f, s) > 0.3)));
    }
    return { fields, spots, chars };
  }

  window.DTParse = { analyze, overlap, guessType };
})();
