// הגישה לשרת (api.php). כל קריאה היא POST עם JSON; התשובה { success, ... }
// או { success: false, error }. כשל הופך לשגיאה עם ההודעה למשתמש.
//
// עד שלב 3 הקובץ הזה אחסן בדפדפן; עכשיו הכול בשרת, ובדפדפן נשאר רק מה
// שנוח לזכור מקומית: הפרויקט האחרון שנפתח והלשונית.

const API = './api.php';
const LS_LAST = 'carpentry-3d:last';

export class ApiError extends Error {
  constructor(message, status) { super(message); this.status = status; }
}

export async function api(action, data = {}) {
  let res;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action, ...data }),
    });
  } catch {
    throw new ApiError('אין חיבור לשרת', 0);
  }
  let body = null;
  try { body = await res.json(); } catch { /* תשובה שאינה JSON — כנראה שגיאת שרת גולמית */ }
  if (!body) throw new ApiError(`השרת החזיר תשובה לא תקינה (${res.status})`, res.status);
  if (!body.success) throw new ApiError(body.error || 'שגיאה', res.status);
  return body;
}

/** צפיית לקוח: GET לפי אסימון, בלי עוגייה. */
export async function viewByToken(token) {
  const res = await fetch(`${API}?action=view&t=${encodeURIComponent(token)}`, { credentials: 'omit' });
  const body = await res.json().catch(() => null);
  if (!body || !body.success) throw new ApiError(body?.error || 'הקישור אינו תקף', res.status);
  return body;
}

export function loadLast() {
  try { return JSON.parse(localStorage.getItem(LS_LAST) || 'null'); } catch { return null; }
}
export function saveLast(v) {
  try { localStorage.setItem(LS_LAST, JSON.stringify(v)); } catch { /* אחסון חסום — לא נורא */ }
}
