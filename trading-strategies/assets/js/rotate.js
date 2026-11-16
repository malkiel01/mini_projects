// שכבה מסובבת (spec-stage-a 18.1.5, L1 סעיף 2.2): "לרוחב" בלי נעילת כיוון — השכבה מסובבת ב-CSS ב-90° עם כיוון השעון,
// ומגע/עכבר בתוכה מומרים לקואורדינטות של השכבה לפני שהם מגיעים לגרף.
//
// למה: Lightweight Charts ו-drawings.js ממירים אירוע לנקודה ב-`clientX − rect.left`. בשכבה מסובבת `getBoundingClientRect`
// מחזיר את המלבן החיצוני על המסך, ו-clientX הוא ציר המסך — כך הסמן נופל במקום אחר מהאצבע, וגרירה "לאורך הגרף" (לאורך
// הצד הארוך של המסך) לא מזיזה את ציר הזמן. כאן, כל עוד שכבה מסובבת פעילה, ורק לאירועים ולאלמנטים שבתוכה:
//   - `getBoundingClientRect()` מחזיר את המלבן במערכת של השכבה (השכבה ב-(0,0), לא מסובבת).
//   - `clientX/clientY`, `pageX/pageY`, `x/y` של אירוע עכבר/מצביע ושל Touch — באותה מערכת.
// כך כל מי שמחסר את השניים מקבל את הנקודה הנכונה — בלי לגעת בספרייה או ב-drawings.js.
//
// הסיבוב: `transform-origin: 0 0; transform: translateX(<רוחב המסך>) rotate(90deg)`. נקודה (lx, ly) בשכבה נמצאת על המסך ב-
// (R − ly, T + lx), כש-R ו-T הם הימין והעליון של המלבן החיצוני. ההפוך: lx = sy − T, ly = R − sx.

let layer = null;
let installed = false;
let nativeRect = null;

const inside = n => !!(layer && n && n.nodeType && layer.contains(n));

function frame() { return nativeRect.call(layer); }
function toLocal(sx, sy) { const r = frame(); return [sy - r.top, r.right - sx]; }

function patchXY(proto, xs, ys, targetOf) {
  for (const [kx, ky] of xs.map((k, i) => [k, ys[i]])) {
    const dx = Object.getOwnPropertyDescriptor(proto, kx), dy = Object.getOwnPropertyDescriptor(proto, ky);
    if (!dx?.get || !dy?.get) continue;
    Object.defineProperty(proto, kx, { configurable: true, enumerable: dx.enumerable, get() {
      const v = dx.get.call(this);
      return layer && inside(targetOf(this)) ? toLocal(v, dy.get.call(this))[0] : v;
    } });
    Object.defineProperty(proto, ky, { configurable: true, enumerable: dy.enumerable, get() {
      const v = dy.get.call(this);
      return layer && inside(targetOf(this)) ? toLocal(dx.get.call(this), v)[1] : v;
    } });
  }
}

function install() {
  if (installed) return;
  installed = true;
  nativeRect = Element.prototype.getBoundingClientRect;
  Element.prototype.getBoundingClientRect = function () {
    const b = nativeRect.call(this);
    if (!layer || !inside(this)) return b;
    const r = frame();
    return new DOMRect(b.top - r.top, r.right - b.right, b.height, b.width);
  };
  patchXY(MouseEvent.prototype, ['clientX', 'pageX', 'x'], ['clientY', 'pageY', 'y'], e => e.target);
  if (window.Touch) patchXY(Touch.prototype, ['clientX', 'pageX'], ['clientY', 'pageY'], t => t.target);
}

/** el — השכבה שמסובבת עכשיו (null — אין). אחת לכל היותר */
export function setTurned(el) {
  if (el) install();
  layer = el || null;
}

/** המלבן האמיתי על המסך (לבדיקות ולמי שצריך את המסך) */
export function screenRect(el) { return (nativeRect || Element.prototype.getBoundingClientRect).call(el); }
