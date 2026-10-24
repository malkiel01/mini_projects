// תקציב הזיכרון של המכשיר (הכרעה 2026-10-24: אין תקרת פיקסלים קבועה — התקרה נגזרת מהמכשיר).
// חשבון טהור: מקבל את מה שהדפדפן מגלה, ומחזיר כמה פיקסלים אפשר להחזיק. נבדק ב-node.

// כל פיקסל בשכבה: RGBA ב-float16 = 8 בתים. ועוד העותק המורכב לתצוגה (עם mipmaps: ×4/3).
export const BYTES_PER_PIXEL = 8;
const COMPOSITE_FACTOR = 4 / 3;
// כמה שכבות מחשבים מראש כשבודקים אם תמונה "נכנסת" — מסמך טיפוסי בתחילת העבודה
export const PLAN_LAYERS = 4;

/**
 * @param {{deviceMemory?: number, mobile?: boolean, maxTexture?: number}} dev
 *   deviceMemory — ב-GB, כפי ש-navigator.deviceMemory מחזיר (Chrome מעגל ומגביל ל-8; Safari — אין).
 * @returns {{bytes: number, maxPixels: (layers?: number) => number, memGB: number, guessed: boolean}}
 */
export function deviceBudget(dev = {}) {
  const guessed = !(dev.deviceMemory > 0);
  // בלי מידע: טלפון — 3GB, מחשב — 8GB. הערכה זהירה: Safari באייפון לא מגלה, וזה המכשיר שקורס ראשון.
  const memGB = guessed ? (dev.mobile ? 3 : 8) : dev.deviceMemory;
  // רבע מהזיכרון לעורך (הדפדפן, מערכת ההפעלה ושאר הלשוניות צריכים את השאר), ובטלפון — חמישית.
  const share = dev.mobile ? 0.2 : 0.25;
  const bytes = Math.floor(memGB * 1024 ** 3 * share);
  const maxPixels = (layers = PLAN_LAYERS) =>
    Math.floor(bytes / (BYTES_PER_PIXEL * (layers + COMPOSITE_FACTOR)));
  return { bytes, maxPixels, memGB, guessed };
}

/** אם (w, h) גדול מהתקציב — הגודל המומלץ באותו יחס; אחרת null. */
export function suggestSize(w, h, maxPixels) {
  if (w * h <= maxPixels) return null;
  const k = Math.sqrt(maxPixels / (w * h));
  return [Math.max(1, Math.floor(w * k)), Math.max(1, Math.floor(h * k))];
}

export const megapixels = (w, h) => Math.round(w * h / 1e5) / 10;
