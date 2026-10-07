// תצוגה מקדימה: ספרייה סכמטית בקווים, הטלה בפרספקטיבה, בלי שום ספרייה.
// מציין מקום למנוע three.js של שלב 1. הקובץ הזה נועד להיות מוחלף.
//
// גם כאן, כבר בשלד, הקווים נגזרים מפרמטרים — רוחב, גובה, עומק, עמודות
// ומדפים — ולא מצוירים ביד. זה הרעיון של הכלי כולו, בזעיר אנפין.

const canvas = document.getElementById('stage');
const ctx = canvas.getContext('2d');

// מידות הספרייה בסכמה, ביחידות מודל (היחסים הם מה שחשוב, לא המספרים).
const BOOKCASE = { width: 1.6, height: 2.0, depth: 0.5, columns: 3, shelves: 4 };

// בונה את רשימת הקטעים (זוגות נקודות תלת־ממדיות) של הספרייה סביב הראשית.
function buildEdges({ width, height, depth, columns, shelves }) {
  const x0 = -width / 2, x1 = width / 2;
  const y0 = -height / 2, y1 = height / 2;
  const z0 = -depth / 2, z1 = depth / 2;
  const edges = [];
  const seg = (a, b) => edges.push([a, b]);

  // התיבה החיצונית: שתי מסגרות (קדמית ואחורית) והקשרים ביניהן.
  for (const z of [z0, z1]) {
    seg([x0, y0, z], [x1, y0, z]); seg([x1, y0, z], [x1, y1, z]);
    seg([x1, y1, z], [x0, y1, z]); seg([x0, y1, z], [x0, y0, z]);
  }
  for (const [x, y] of [[x0, y0], [x1, y0], [x1, y1], [x0, y1]]) seg([x, y, z0], [x, y, z1]);

  // מחיצות בין העמודות, ומדפים בכל עמודה — מלבן קדמי וקו עומק בכל קצה.
  const colW = width / columns;
  for (let c = 1; c < columns; c++) {
    const x = x0 + c * colW;
    seg([x, y0, z1], [x, y1, z1]); seg([x, y0, z0], [x, y1, z0]);
  }
  const gap = height / (shelves + 1);
  for (let c = 0; c < columns; c++) {
    const xa = x0 + c * colW, xb = xa + colW;
    for (let s = 1; s <= shelves; s++) {
      const y = y0 + s * gap;
      seg([xa, y, z1], [xb, y, z1]);
      seg([xa, y, z0], [xa, y, z1]); seg([xb, y, z0], [xb, y, z1]);
    }
  }
  return edges;
}

const EDGES = buildEdges(BOOKCASE);

const DISTANCE = 4.2;  // מרחק הצופה מהראשית, ביחידות המודל
const SCALE = 95;      // פיקסלים ליחידה במישור ההטלה

// הקנבס מוצג ב-CSS ביחידות לוגיות; ללא כפל ב-devicePixelRatio
// הקווים יוצאים מטושטשים במסכים צפופים.
function resize() {
  const dpr = window.devicePixelRatio || 1;
  const size = canvas.clientWidth;
  canvas.width = canvas.height = Math.round(size * dpr);
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  return size;
}

// סיבוב סביב Y (הצגה מכל הצדדים) ונטייה קלה סביב X (מבט מעט מלמעלה).
function rotate([x, y, z], ay, ax) {
  const cy = Math.cos(ay), sy = Math.sin(ay);
  const x1 = x * cy + z * sy, z1 = -x * sy + z * cy;
  const cx = Math.cos(ax), sx = Math.sin(ax);
  const y2 = y * cx - z1 * sx, z2 = y * sx + z1 * cx;
  return [x1, y2, z2];
}

function project([x, y, z], size) {
  const f = DISTANCE / (DISTANCE + z);
  return [size / 2 + x * f * SCALE, size / 2 - y * f * SCALE];
}

function frame(t) {
  const size = resize();
  ctx.clearRect(0, 0, size, size);
  ctx.strokeStyle = '#d6a35c';
  ctx.lineWidth = 1.6;
  ctx.lineJoin = 'round';
  const ay = t / 2600, ax = 0.28;
  ctx.beginPath();
  for (const [a, b] of EDGES) {
    const [ax1, ay1] = project(rotate(a, ay, ax), size);
    const [bx1, by1] = project(rotate(b, ay, ax), size);
    ctx.moveTo(ax1, ay1);
    ctx.lineTo(bx1, by1);
  }
  ctx.stroke();
  requestAnimationFrame(frame);
}

requestAnimationFrame(frame);
