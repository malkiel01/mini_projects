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
  // perf — מקדם מהמדידה במכשיר (perfFactor): מכשיר איטי מקבל תמונות קטנות יותר, כדי שהעבודה תישאר חלקה
  const guessed = !(dev.deviceMemory > 0);
  // בלי מידע: טלפון — 3GB, מחשב — 8GB. הערכה זהירה: Safari באייפון לא מגלה, וזה המכשיר שקורס ראשון.
  const memGB = guessed ? (dev.mobile ? 3 : 8) : dev.deviceMemory;
  // רבע מהזיכרון לעורך (הדפדפן, מערכת ההפעלה ושאר הלשוניות צריכים את השאר), ובטלפון — חמישית.
  const share = dev.mobile ? 0.2 : 0.25;
  const bytes = Math.floor(memGB * 1024 ** 3 * share);
  const perf = dev.perf ?? 1;
  const maxPixels = (layers = PLAN_LAYERS) =>
    Math.floor(bytes / (BYTES_PER_PIXEL * (layers + COMPOSITE_FACTOR)) * perf);
  return { bytes, maxPixels, memGB, guessed, perf };
}

/** אם (w, h) גדול מהתקציב — הגודל המומלץ באותו יחס; אחרת null. */
export function suggestSize(w, h, maxPixels) {
  if (w * h <= maxPixels) return null;
  const k = Math.sqrt(maxPixels / (w * h));
  return [Math.max(1, Math.floor(w * k)), Math.max(1, Math.floor(h * k))];
}

export const megapixels = (w, h) => Math.round(w * h / 1e5) / 10;

// ---- מדידת ביצועים במכשיר ----
// הציון: כמה פעולות מיזוג של אריח (256×256) הכרטיס עושה במילישנייה. נמדד פעם אחת ונשמר (perfCache).
// הספים — מכרטיס משולב של מחשב נייד (מהיר) ועד מנוע תוכנה (SwiftShader, איטי מאוד).
export const PERF_FAST = 2;     // ≥ 2 אריחים במ"ש: מחשב / טלפון חדש — תקציב מלא
export const PERF_SLOW = 0.25;  // < 0.25: מנוע תוכנה / טלפון ישן — חצי תקציב

/** ציון → מקדם תקציב, בין 0.5 ל-1 (לינארי בין הספים, בסולם לוגריתמי) */
export function perfFactor(score) {
  if (!(score > 0)) return 1;   // לא נמדד — לא מקטינים
  if (score >= PERF_FAST) return 1;
  if (score <= PERF_SLOW) return 0.5;
  const t = Math.log(score / PERF_SLOW) / Math.log(PERF_FAST / PERF_SLOW);
  return Math.round((0.5 + 0.5 * t) * 100) / 100;
}

export const perfLabel = score => (!(score > 0) ? 'לא נמדד' : score >= PERF_FAST ? 'מהיר' : score <= PERF_SLOW ? 'איטי' : 'בינוני');

/** המטמון: { v, key, score, at } — נמדד מחדש כשהדפדפן / הכרטיס השתנו, או אחרי 30 יום */
export function perfCacheValid(c, key, now = Date.now()) {
  return !!(c && c.v === 1 && c.key === key && c.score > 0 && now - c.at < 30 * 864e5);
}
