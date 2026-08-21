/**
 * טיפול בתמונות ובסרטונים: יצירת תמונה ממוזערת, כיווץ תמונות גדולות
 * וזיהוי סוג הקובץ. הכול קורה בדפדפן — הקבצים לא עוזבים את המכשיר
 * אלא אם המשתמש הגדיר שרת סנכרון.
 */

const THUMB_MAX = 320;
const THUMB_QUALITY = 0.72;

/** ברירת מחדל לכיווץ תמונות מצלמה — 12MP הופכים ל~1MB בלי לאבד פרטים חשובים. */
export const COMPRESS_MAX = 1600;
const COMPRESS_QUALITY = 0.82;

export const isImage = (mime) => /^image\//.test(mime || '');
export const isVideo = (mime) => /^video\//.test(mime || '');

/** מזהה סוג גם כשה-MIME ריק (קורה בקבצים מסוימים מאנדרואיד). */
export function kindOf(file) {
  if (isVideo(file.type)) return 'video';
  if (isImage(file.type)) return 'image';
  return /\.(mp4|mov|m4v|webm|avi|3gp)$/i.test(file.name || '') ? 'video' : 'image';
}

export function formatBytes(n) {
  if (!n && n !== 0) return '';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
  if (n < 1024 * 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`;
  return `${(n / 1024 / 1024 / 1024).toFixed(2)} GB`;
}

/** מחשב מידות יעד תוך שמירת יחס, בלי להגדיל תמונות קטנות. */
function fit(width, height, max) {
  const scale = Math.min(1, max / Math.max(width, height));
  return [Math.max(1, Math.round(width * scale)), Math.max(1, Math.round(height * scale))];
}

function canvasToBlob(canvas, quality) {
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
}

function draw(source, width, height) {
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, width, height);
  ctx.drawImage(source, 0, 0, width, height);
  return canvas;
}

/** טוען תמונה מ-Blob. createImageBitmap מכבד EXIF ולכן עדיף על <img>. */
async function loadImage(blob) {
  if (window.createImageBitmap) {
    try {
      return await createImageBitmap(blob, { imageOrientation: 'from-image' });
    } catch { /* נופלים ל-<img> */ }
  }
  const url = URL.createObjectURL(blob);
  try {
    return await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('טעינת התמונה נכשלה'));
      img.src = url;
    });
  } finally {
    URL.revokeObjectURL(url);
  }
}

const dimsOf = (source) => [
  source.naturalWidth || source.width,
  source.naturalHeight || source.height,
];

/**
 * שולף פריים יחיד מסרטון ומחזיר אותו כתמונה ממוזערת.
 * הציור לקנבס חייב לקרות לפני שחרור ה-object URL, ולכן הוא נעשה כאן
 * ולא אצל הקורא. הדילוג ל-0.1 שנייה נחוץ כי הפריים ב-0 יוצא לא פעם שחור.
 */
function videoThumb(blob) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(blob);
    const video = document.createElement('video');
    let settled = false;

    const cleanup = () => { URL.revokeObjectURL(url); video.removeAttribute('src'); video.load(); };
    const fail = (err) => { if (settled) return; settled = true; cleanup(); reject(err); };

    const capture = async () => {
      if (settled) return;
      settled = true;
      const width = video.videoWidth;
      const height = video.videoHeight;
      try {
        if (!width || !height) throw new Error('אין מימדי וידאו');
        const [tw, th] = fit(width, height, THUMB_MAX);
        const thumb = await canvasToBlob(draw(video, tw, th), THUMB_QUALITY);
        resolve({ thumb, width, height });
      } catch (err) {
        reject(err);
      } finally {
        cleanup();
      }
    };

    video.muted = true;
    video.playsInline = true;
    video.preload = 'metadata';

    video.onloadeddata = () => {
      const duration = Number.isFinite(video.duration) ? video.duration : 0;
      const target = Math.min(0.1, duration / 2);
      // סרטון קצר מדי לדילוג — הפריים שכבר נטען הוא הטוב ביותר שיש.
      if (!target) capture();
      else video.currentTime = target;
    };
    video.onseeked = capture;
    video.onerror = () => fail(new Error('טעינת הסרטון נכשלה'));
    setTimeout(() => fail(new Error('פסק זמן בקריאת הסרטון')), 15000);

    video.src = url;
    video.load();
  });
}

/**
 * הכנת קובץ להוספה: מחזיר blob (אולי מכווץ), תמונה ממוזערת ומידות.
 * כישלון ביצירת התמונה הממוזערת אינו עוצר את ההוספה — עדיף פריט
 * בלי תצוגה מקדימה מאשר לאבד תיעוד של ליקוי.
 */
export async function prepare(file, { compress = true } = {}) {
  const kind = kindOf(file);
  const base = {
    kind,
    mime: file.type || (kind === 'video' ? 'video/mp4' : 'image/jpeg'),
    name: file.name || (kind === 'video' ? 'video' : 'photo'),
    blob: file,
    size: file.size,
    width: 0,
    height: 0,
    thumb: null,
  };

  if (kind === 'video') {
    try {
      const shot = await videoThumb(file);
      base.thumb = shot.thumb;
      base.width = shot.width;
      base.height = shot.height;
    } catch { /* סרטון בלי תצוגה מקדימה — ההוספה נמשכת בכל מקרה */ }
    return base;
  }

  let bitmap;
  try {
    bitmap = await loadImage(file);
  } catch {
    return base;
  }

  const [width, height] = dimsOf(bitmap);
  base.width = width;
  base.height = height;

  const [tw, th] = fit(width, height, THUMB_MAX);
  base.thumb = await canvasToBlob(draw(bitmap, tw, th), THUMB_QUALITY);

  if (compress && Math.max(width, height) > COMPRESS_MAX) {
    const [cw, ch] = fit(width, height, COMPRESS_MAX);
    const smaller = await canvasToBlob(draw(bitmap, cw, ch), COMPRESS_QUALITY);
    // כיווץ שלא הקטין (למשל PNG של צילום מסך) לא שווה את איבוד האיכות.
    if (smaller && smaller.size < file.size) {
      base.blob = smaller;
      base.size = smaller.size;
      base.mime = 'image/jpeg';
      base.width = cw;
      base.height = ch;
    }
  }

  bitmap.close?.();
  return base;
}

/**
 * מנהל כתובות blob: object URL שלא משוחרר דולף זיכרון, וכאן נוצרות
 * כתובות בכל רינדור מחדש של הרשימה.
 */
export function urlPool() {
  const urls = new Set();
  return {
    make(blob) {
      if (!blob) return '';
      const url = URL.createObjectURL(blob);
      urls.add(url);
      return url;
    },
    release() {
      urls.forEach((url) => URL.revokeObjectURL(url));
      urls.clear();
    },
  };
}
