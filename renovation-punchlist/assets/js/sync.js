/**
 * סנכרון אופציונלי מול api.php.
 *
 * האפליקציה עובדת במלואה בלי השרת הזה; מי שמגדיר כתובת ואסימון מקבל
 * שיתוף בין מכשירים ובין אנשים (בעל הבית, הקבלן, בעלי המקצוע).
 *
 * ההכרעה בהתנגשות היא "המאוחר ביותר מנצח" לפי `updatedAt`. זה מספיק כאן
 * כי כל שדה נערך בפועל ע"י אדם אחד בכל רגע, ומחיקות עוברות כ-tombstones
 * ולא כהיעדר רשומה — כך שמחיקה לא "מתבטלת" בסנכרון הבא.
 */

import * as db from './db.js';

const TIMEOUT_MS = 30000;

export class SyncError extends Error {}

/** מנרמל כתובת שרת: מקבל גם "example.com/punchlist" וגם נתיב מלא ל-api.php. */
export function normalizeUrl(raw) {
  let url = String(raw || '').trim();
  if (!url) return '';
  if (!/^https?:\/\//i.test(url)) url = `https://${url}`;
  url = url.replace(/\/+$/, '');
  return /\/api\.php$/i.test(url) ? url : `${url}/api.php`;
}

async function withTimeout(promise, ms = TIMEOUT_MS) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), ms);
  try {
    return await promise(controller.signal);
  } catch (err) {
    if (err.name === 'AbortError') throw new SyncError('השרת לא הגיב בזמן');
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

async function call(config, action, payload = {}) {
  const res = await withTimeout((signal) => fetch(config.url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, token: config.token, ...payload }),
    signal,
  })).catch((err) => {
    if (err instanceof SyncError) throw err;
    throw new SyncError('לא ניתן להגיע לשרת — בדקו את הכתובת ואת החיבור');
  });

  let data;
  try {
    data = await res.json();
  } catch {
    throw new SyncError(`השרת החזיר תשובה לא תקינה (${res.status})`);
  }
  if (!data.success) throw new SyncError(data.error || `שגיאת שרת (${res.status})`);
  return data;
}

/** בדיקת חיבור — משמשת את כפתור "בדיקת חיבור" בהגדרות. */
export async function ping(config) {
  const data = await call(config, 'ping');
  return { version: data.version, tasks: data.tasks ?? 0, media: data.media ?? 0 };
}

async function uploadFile(config, item) {
  const form = new FormData();
  form.append('token', config.token);
  form.append('id', item.id);
  form.append('taskId', item.taskId);
  form.append('mime', item.mime || '');
  if (item.blob) form.append('file', item.blob, item.name || item.id);
  if (item.thumb) form.append('thumb', item.thumb, `${item.id}-thumb.jpg`);

  const res = await withTimeout((signal) => fetch(`${config.url}?action=media-upload`, {
    method: 'POST',
    body: form,
    signal,
  }), 120000).catch((err) => {
    if (err instanceof SyncError) throw err;
    throw new SyncError('העלאת הקובץ נכשלה');
  });

  const data = await res.json().catch(() => ({}));
  if (!data.success) throw new SyncError(data.error || 'העלאת הקובץ נכשלה');
}

async function downloadFile(config, id, which) {
  const params = new URLSearchParams({
    action: 'media-file', id, which, token: config.token,
  });
  const res = await withTimeout((signal) => fetch(`${config.url}?${params}`, { signal }), 120000)
    .catch((err) => {
      if (err instanceof SyncError) throw err;
      throw new SyncError('הורדת הקובץ נכשלה');
    });
  if (!res.ok) return null;
  const blob = await res.blob();
  return blob.size ? blob : null;
}

/** ממזג רשומת השלמה מרוחקת לתוך המקומית לפי חותמת הזמן. */
function mergeTask(local, remote) {
  if (!local) return remote;
  return remote.updatedAt > local.updatedAt ? remote : null;
}

/**
 * ממזג רשומת מדיה מרוחקת. הקבצים המקומיים נשמרים תמיד — המטא-דאטה
 * בשרת לא מכילה אותם, ומיזוג נאיבי היה מוחק תמונות שכבר יש במכשיר.
 */
function mergeMedia(local, remote) {
  if (local && remote.updatedAt <= local.updatedAt) return null;
  return {
    ...remote,
    blob: remote.deleted ? null : (local?.blob ?? null),
    thumb: remote.deleted ? null : (local?.thumb ?? null),
    uploaded: true,
  };
}

/** מסיר את ה-Blobs לפני שליחה — השרת מקבל אותם בנפרד כקבצים. */
const stripBlobs = (item) => {
  const { blob, thumb, uploaded, ...meta } = item;
  return meta;
};

/**
 * מחזור סנכרון מלא: דחיפת שינויים, העלאת קבצים חדשים, משיכת שינויים
 * והורדת קבצים חסרים. מחזיר סיכום להצגה למשתמש.
 */
export async function run(config, onProgress = () => {}) {
  if (!config.url) throw new SyncError('לא הוגדרה כתובת שרת');

  /*
   * שתי חותמות זמן ולא אחת, כי הן נמדדות בשני שעונים שונים:
   * `lastPush` מסננת רשומות מקומיות ולכן חייבת להיות בשעון המכשיר,
   * ו-`lastPull` מסננת רשומות מהשרת ולכן חייבת להיות בשעון השרת.
   * ערבוב ביניהן גורם לכך שמכשיר עם שעון מפגר מדלג על שינויים משלו.
   */
  const lastPush = await db.getMeta('lastPush', 0);
  const lastPull = await db.getMeta('lastPull', 0);
  const pushMark = Date.now();

  const summary = { pushed: 0, pulled: 0, uploaded: 0, downloaded: 0, failed: 0 };

  onProgress('שולח שינויים…');
  const local = await db.changedSince(lastPush);
  if (local.tasks.length || local.media.length) {
    await call(config, 'push', {
      tasks: local.tasks,
      media: local.media.map(stripBlobs),
    });
    summary.pushed = local.tasks.length + local.media.length;
  }
  await db.setMeta('lastPush', pushMark);

  onProgress('מעלה קבצים…');
  for (const item of await db.pendingUploads()) {
    try {
      await uploadFile(config, item);
      await db.putMedia({ ...item, uploaded: true });
      summary.uploaded += 1;
    } catch {
      // קובץ שנכשל יעלה בסנכרון הבא; שאר המחזור לא צריך ליפול בגללו.
      summary.failed += 1;
    }
  }

  onProgress('מקבל עדכונים…');
  const remote = await call(config, 'pull', { since: lastPull });

  for (const task of remote.tasks || []) {
    const merged = mergeTask(await db.getTask(task.id), task);
    if (merged) { await db.saveTask(merged, false); summary.pulled += 1; }
  }
  for (const item of remote.media || []) {
    const merged = mergeMedia(await db.getMedia(item.id), item);
    if (merged) { await db.putMedia(merged); summary.pulled += 1; }
  }

  onProgress('מוריד קבצים…');
  for (const item of await db.allMediaRaw()) {
    if (item.deleted || item.blob) continue;
    try {
      const [blob, thumb] = await Promise.all([
        downloadFile(config, item.id, 'file'),
        downloadFile(config, item.id, 'thumb'),
      ]);
      if (!blob && !thumb) continue;
      await db.putMedia({ ...item, blob, thumb, uploaded: true });
      summary.downloaded += 1;
    } catch {
      summary.failed += 1;
    }
  }

  await db.setMeta('lastPull', remote.now || 0);
  return summary;
}
