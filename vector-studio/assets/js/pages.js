// עמודי המקור של מסמך מאפליקציה אחרת (מעבד תמלילים, עימוד): ה-PDF שהאפליקציה ההיא הכינה בתוך ה-‎.mbe —
// מוצג כרקע מדויק (עם הלוגו, הטבלאות והעמודים), במקום לנסות לפרוס את הטקסט כאן. pdf.js (Apache-2.0,
// assets/js/vendor/pdfjs) נטען רק כשיש מסמך כזה.

const OWN = 'vector-studio';
export const KEEP = 'background.pdf'; // עותק בתוך apps/vector-studio/ — כדי שאחרי הורדה מכאן הרקע לא יאבד

/** האם יש במסמך משהו שוקטורי לא מצייר בעצמו (מסגרות טקסט, רסטר) — רק אז צריך את עמודי המקור. */
export function needsPages(doc) {
  const foreign = (layers) => (layers || []).some(l => (l.type === 'group' ? foreign(l.children) : l.type !== 'vector'));
  return (doc?.surfaces || []).some(s => foreign(s.layers));
}

/** ה-PDF של המקור: ייצוג שאפליקציה אחרת הכינה, ואם אין — העותק שוקטורי שמר. f — קובץ פתוח (mbe.js). */
export function sourcePdf(f) {
  const r = f.renditions().find(x => x.path === 'renditions/document.pdf' && x.by && x.by !== OWN);
  return r?.data || f.apps(OWN).get(KEEP) || null;
}

let lib = null;
async function pdfjs() {
  if (!lib) {
    lib = await import('./vendor/pdfjs/pdf.min.mjs');
    lib.GlobalWorkerOptions.workerSrc = new URL('./vendor/pdfjs/pdf.worker.min.mjs', import.meta.url).href;
  }
  return lib;
}

/**
 * כל עמוד → { bmp, w, h } (w/h ב-pt). רזולוציה: עד ~2 פיקסלים ל-pt, ולא יותר מ-2400 פיקסלים לצלע (זיכרון בטלפון).
 */
export async function renderPages(bytes) {
  const { getDocument } = await pdfjs();
  const pdf = await getDocument({ data: bytes.slice(), isEvalSupported: false }).promise;
  const out = [];
  try {
    for (let i = 1; i <= pdf.numPages; i++) {
      const page = await pdf.getPage(i);
      const base = page.getViewport({ scale: 1 });
      const k = Math.min(2, 2400 / Math.max(base.width, base.height));
      const vp = page.getViewport({ scale: k });
      const c = document.createElement('canvas');
      c.width = Math.ceil(vp.width); c.height = Math.ceil(vp.height);
      const ctx = c.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
      await page.render({ canvasContext: ctx, viewport: vp }).promise;
      out.push({ bmp: await createImageBitmap(c), w: base.width, h: base.height });
      page.cleanup();
    }
  } finally {
    pdf.destroy();
  }
  return out;
}

/**
 * העמודים מול הלוחות: עמוד i — רקע של הלוח i; עמודים עודפים (טקסט שגלש לעמוד נוסף) — עמודי תצוגה אחרי הלוחות.
 * מחזיר { bySurface: Map(id → page), extra: [page] }.
 */
export function assign(doc, pages) {
  const bySurface = new Map();
  doc.surfaces.forEach((s, i) => { if (pages[i]) bySurface.set(s.id, pages[i]); });
  return { bySurface, extra: pages.slice(doc.surfaces.length) };
}
