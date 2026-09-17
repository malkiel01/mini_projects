// תצוגה מקדימה: סוכה בקווים — ארבע דפנות ומעליהן סכך, בלי שום ספרייה.
// זהו מציין מקום בלבד. כשתיכנס לכאן תיקיית הפרויקט, הקובץ הזה מוחלף.

const canvas = document.getElementById('stage');
const ctx = canvas.getContext('2d');

// המודל ביחידות "רוחב הסוכה". ציר Y חיובי כלפי מטה, כמו במסך:
// ‏y=1 הרצפה, y=-0.6 גובה הדפנות.
const FLOOR = 1;
const TOP = -0.6;

// ארבע פינות הרצפה, בסדר היקפי.
const CORNERS = [
  [-1, 1], [1, 1], [1, -1], [-1, -1],
];

// צלעות בקווי עץ: מסגרת הרצפה, מסגרת הראש, וארבעה עמודי פינה.
const WOOD = [];
for (let i = 0; i < 4; i++) {
  const [ax, az] = CORNERS[i];
  const [bx, bz] = CORNERS[(i + 1) % 4];
  WOOD.push([[ax, FLOOR, az], [bx, FLOOR, bz]]);   // רצפה
  WOOD.push([[ax, TOP, az], [bx, TOP, bz]]);       // ראש הדפנות
  WOOD.push([[ax, FLOOR, az], [ax, TOP, az]]);     // עמוד פינה
}

// הסכך: קנים נפרדים מעל מסגרת הראש. קווים בודדים ולא משטח אטום —
// שכך גם נראית סוכה, וגם ברור שאין כאן מנוע מודלים.
const SCHACH = [];
const BEAMS = 7;
for (let i = 0; i < BEAMS; i++) {
  const z = -1 + (2 * i) / (BEAMS - 1);
  SCHACH.push([[-1.12, TOP - 0.06, z], [1.12, TOP - 0.06, z]]);
}

const DISTANCE = 4;   // מרחק הצופה מהראשית, ביחידות המודל
const SCALE = 72;     // פיקסלים ליחידה במישור ההטלה

// הקנבס מוצג ב-CSS ביחידות לוגיות; ללא כפל ב-devicePixelRatio
// הקווים יוצאים מטושטשים במסכים צפופים.
function resize() {
  const dpr = window.devicePixelRatio || 1;
  const size = canvas.clientWidth;
  canvas.width = canvas.height = Math.round(size * dpr);
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  return size;
}

function rotate([x, y, z], angle) {
  // סיבוב סביב Y ואז הטיה קבועה סביב X — מספיק כדי שייראה תלת־ממדי.
  const cy = Math.cos(angle), sy = Math.sin(angle);
  const x1 = x * cy - z * sy;
  const z1 = x * sy + z * cy;

  const tilt = 0.42;
  const cx = Math.cos(tilt), sx = Math.sin(tilt);
  const y1 = y * cx - z1 * sx;
  const z2 = y * sx + z1 * cx;

  return [x1, y1, z2];
}

function project([x, y, z], size) {
  const depth = DISTANCE / (DISTANCE - z);
  return [size / 2 + x * SCALE * depth, size / 2 + y * SCALE * depth];
}

function strokeSegments(segments, angle, size, color, width) {
  ctx.strokeStyle = color;
  ctx.lineWidth = width;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.beginPath();
  for (const [a, b] of segments) {
    const [ax, ay] = project(rotate(a, angle), size);
    const [bx, by] = project(rotate(b, angle), size);
    ctx.moveTo(ax, ay);
    ctx.lineTo(bx, by);
  }
  ctx.stroke();
}

function draw(angle) {
  const size = resize();
  ctx.clearRect(0, 0, size, size);
  strokeSegments(WOOD, angle, size, '#fbbf24', 1.6);
  strokeSegments(SCHACH, angle, size, '#34d399', 2.2);
}

const still = window.matchMedia('(prefers-reduced-motion: reduce)');

let start = null;
function frame(now) {
  if (start === null) start = now;
  draw((now - start) / 3200);
  requestAnimationFrame(frame);
}

if (still.matches) {
  draw(0.6);
  window.addEventListener('resize', () => draw(0.6));
} else {
  requestAnimationFrame(frame);
}
