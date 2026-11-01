// צבע בעורך — הבורר של ui-core (color.js, שלב 7) במקום <input type="color"> של הדפדפן.
// ה-<input> נשאר (מוסתר) ומחזיק את הערך: הבורר כותב אליו ומשגר input, וכתיבה ל-value מבחוץ (איפוס לוח, ביטול)
// מעדכנת את הבורר. כך הקוד של הלוחות (adjust / fx / tools) לא משתנה.
//
// - bindColorInput(input, label) — שדה צבע במקום ה-input (מילוי בלוח התיקונים, צבע הסגנון).
// - openColorSheet(input, label) — חלון עם הבורר פתוח (הצבע של הכלים, מהעיגול בסרגל).
// - initColor({ sample }) — הקשר: האחרונים והמועדפים במכשיר (raster-colors), והטפטפת על הקנבס.

import { colorPicker, setColorContext } from './ui-core/color.js';
import { sheet } from './ui-core/dialog.js';

const VALUE = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

export function initColor({ sample }) {
  setColorContext({ prefix: 'raster-colors', sample });
}

/** כתיבה ל-input.value (גם מקוד אחר) → הבורר מתעדכן; הבורר → input.value + אירוע input */
function wire(input, label, extra = {}) {
  const field = colorPicker(label, VALUE.get.call(input) || '#000000', hex => {
    VALUE.set.call(input, hex.slice(0, 7));
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }, () => input.dispatchEvent(new Event('change', { bubbles: true })), extra);
  Object.defineProperty(input, 'value', {
    configurable: true,
    get() { return VALUE.get.call(this); },
    set(v) { VALUE.set.call(this, v); field.setValue(v); },
  });
  return field;
}

export function bindColorInput(input, label) {
  const field = wire(input, label);
  input.hidden = true;
  input.after(field);
  return field;
}

/** הבורר בחלון, פתוח מיד; הטפטפת מסתירה את החלון בזמן הדגימה */
export function openColorSheet(input, label) {
  const field = wire(input, label);
  const s = sheet({ title: '🎨 ' + label, body: field, label });
  field.querySelector('.cp-trigger')?.click();
  s.el.addEventListener('close', () => { delete input.value; }, { once: true });   // חזרה ל-value של הדפדפן
  return s;
}
