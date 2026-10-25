// משותף לדף הפתיחה ולעורך: קריאה לשרת, הודעות שגיאה וטוסט.

const ERRORS = {
  title_required: 'צריך שם לתמונה',
  title_too_long: 'השם ארוך מדי',
  bad_size: 'גודל הקנבס לא תקין — מספר שלם בין 1 ל-30000 פיקסלים',
  no_user: 'אין משתמש — צריך להיכנס דרך הכלים הדיגיטליים',
  not_found: 'התמונה לא נמצאה',
  network: 'אין חיבור לשרת',
  decode: 'לא הצלחתי לפתוח את הקובץ — PNG, JPG, WebP, ORA או ‎.mbe',
  'mbe-invalid': 'קובץ ה-‎.mbe פגום ולא נפתח',
  'mbe-not-raster': 'בקובץ ה-‎.mbe הזה אין קנבס רסטר',
  'mbe-base': 'הקובץ שנפתח השתנה ואי אפשר לשמור מעליו',
  mbe_invalid: 'השרת דחה את הקובץ — הוא לא עבר את בדיקת ה-‎.mbe',
  not_raster: 'בקובץ אין קנבס רסטר',
  too_big: 'הקובץ גדול מדי לשמירה בשרת',
  conflict: 'בינתיים נשמרה גרסה אחרת',
  'zip-unsafe': 'הקובץ נראה פגום או מסוכן — לא נפתח',
  'zip-too-big': 'הקובץ גדול מדי לפתיחה',
  no_webgl2: 'הדפדפן לא תומך בגרפיקה שהעורך צריך (WebGL2)',
  no_float_target: 'כרטיס המסך לא תומך בצבע של 16 ביט שהעורך צריך',
};
export const errText = code => ERRORS[code] || 'משהו השתבש (' + code + ')';

export async function api(action, data = {}) {
  let res;
  try {
    res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Raster-Studio': '1' },
      body: JSON.stringify({ action, ...data }),
      credentials: 'same-origin',
    });
  } catch {
    throw new Error('network');
  }
  const out = await res.json().catch(() => ({ ok: false, error: 'server' }));
  if (!out.ok) throw new Error(out.error || 'server');
  return out;
}

/** העלאת ‎.mbe לשרת: הקובץ עצמו בגוף. base — הגרסה שהעורך התחיל ממנה (409 conflict אם בינתיים נשמרה אחרת) */
export async function uploadMbe(id, bytes, base, force = false) {
  let res;
  try {
    res = await fetch(`api.php?action=doc_upload&id=${id}&base=${base ?? ''}${force ? '&force=1' : ''}`, {
      method: 'POST', headers: { 'Content-Type': 'application/vnd.mbe+zip', 'X-Raster-Studio': '1' }, body: bytes, credentials: 'same-origin',
    });
  } catch {
    throw new Error('network');
  }
  const out = await res.json().catch(() => ({ ok: false, error: 'server' }));
  if (!out.ok) {
    const e = new Error(out.error || 'server');
    e.issues = out.issues;
    throw e;
  }
  return out;
}

/** הקובץ של גרסה (ברירת מחדל — האחרונה) */
export async function fetchMbe(id, v = null) {
  let res;
  try {
    res = await fetch('api.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Raster-Studio': '1' },
      body: JSON.stringify({ action: 'doc_file', id, ...(v ? { v } : {}) }), credentials: 'same-origin',
    });
  } catch {
    throw new Error('network');
  }
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'server');
  return new Uint8Array(await res.arrayBuffer());
}

export function toast(msg, err = false) {
  const el = document.querySelector('.toast');
  el.textContent = msg;
  el.classList.toggle('err', err);
  el.hidden = false;
  clearTimeout(toast.t);
  toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}

// "1920×1080" בתוך טקסט עברי מתהפך ל-"1080×1920" — מבודדים אותו באלמנט LTR
export function dims([w, h]) {
  const el = document.createElement('bdi');
  el.dir = 'ltr';
  el.textContent = w + '×' + h;
  return el;
}
