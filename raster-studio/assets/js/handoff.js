// "פתיחה מאפליקציה אחרת" וחזרה אליה — פרוטוקול mbe-handoff גרסה 1 (greeting-cards/spec/13-open-in.md).
// אנחנו היעד: אפליקציה אחרת פותחת אותנו ב-?mbe-handoff=<id>, שולחת ‎.mbe, ומקבלת אותו בחזרה אחרי העריכה.
//
// אבטחה: מקבלים רק מ-window.opener, רק ממקור מוכר (היום — אותו דומיין: location.origin), ורק עם proto / v / id
// תואמים; שולחים רק ל-opener עם targetOrigin מפורש — לעולם לא "*". הקובץ עצמו נפתח דרך הליבה, כמו קובץ מהמכשיר.
// בין דף הפתיחה לעורך (ניווט באותו חלון — opener נשאר) הפרטים עוברים ב-sessionStorage.

export const PROTO = 'mbe-handoff';
const V = 1;
const KEY = 'mbe-handoff';
const ORIGINS = () => [location.origin];

const param = () => new URLSearchParams(location.search).get(PROTO);
const valid = (e, id) => ORIGINS().includes(e.origin) && e.source === window.opener && e.source !== null
  && e.data?.proto === PROTO && e.data?.v === V && e.data?.id === id;
const send = (msg, transfer) => window.opener.postMessage({ proto: PROTO, v: V, ...msg }, ORIGINS()[0], transfer);

/**
 * דף הפתיחה: אם נפתחנו להעברה — שולחים ready מיד (לפני כל דבר כבד), ומחכים ל-open.
 * onOpen({ id, name, file: Blob, from }) — כשהקובץ מגיע. מחזיר true אם זו העברה.
 */
export function acceptHandoff(onOpen) {
  const id = param();
  if (!id || !window.opener) return false;
  const onMessage = e => {
    if (!valid(e, id) || e.data.type !== 'open' || !(e.data.file instanceof ArrayBuffer)) return;
    window.removeEventListener('message', onMessage);
    const from = { app: String(e.data.from?.app ?? ''), name: String(e.data.from?.name ?? '').slice(0, 60) || 'האפליקציה הקודמת' };
    const name = String(e.data.name || 'עיצוב.mbe');
    sessionStorage.setItem(KEY, JSON.stringify({ id, from }));
    onOpen({ id, name, file: new File([e.data.file], /\.mbe$/i.test(name) ? name : name + '.mbe', { type: 'application/vnd.mbe+zip' }), from });
  };
  window.addEventListener('message', onMessage);
  send({ type: 'ready', id });
  return true;
}

/** העורך: ההעברה הפעילה (אם הגענו ממנה ועדיין יש opener) — { id, from } או null */
export function activeHandoff() {
  const id = param();
  if (!id || !window.opener) return null;
  try {
    const h = JSON.parse(sessionStorage.getItem(KEY) ?? 'null');
    return h && h.id === id ? h : null;
  } catch { return null; }
}

/**
 * מחזיר את הקובץ לאפליקציה שפתחה אותנו ומחכה לאישור (returned). bytes — ה-‎.mbe (נשלח עותק: המקור נשאר
 * אצלנו כבסיס לשמירה הבאה). מחזיר Promise שמתממש באישור, או נדחה ('timeout' / 'closed').
 */
export function returnHandoff(h, bytes, timeout = 10000) {
  if (!window.opener || window.opener.closed) return Promise.reject(new Error('closed'));
  return new Promise((resolve, reject) => {
    const done = (fn, v) => { clearTimeout(t); window.removeEventListener('message', onMessage); fn(v); };
    const onMessage = e => { if (valid(e, h.id) && e.data.type === 'returned') done(resolve); };
    const t = setTimeout(() => done(reject, new Error('timeout')), timeout);
    window.addEventListener('message', onMessage);
    const copy = bytes.slice().buffer;
    send({ type: 'return', id: h.id, file: copy }, [copy]);
  });
}

/** ההעברה הסתיימה — לא מציעים "חזרה" שוב (למשל אחרי טעינה מחדש) */
export const endHandoff = () => sessionStorage.removeItem(KEY);
