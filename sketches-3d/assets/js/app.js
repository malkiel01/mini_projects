// תצוגה מקדימה: קובייה בקווים, הטלה בפרספקטיבה, בלי שום ספרייה.
// כשיגיע האפיון ייקבע כאן המנוע האמיתי; הקובץ הזה נועד להיות מוחלף.

const canvas = document.getElementById('stage');
const ctx = canvas.getContext('2d');

// שמונה קודקודים של קובייה סביב הראשית.
const VERTICES = [
  [-1, -1, -1], [1, -1, -1], [1, 1, -1], [-1, 1, -1],
  [-1, -1, 1], [1, -1, 1], [1, 1, 1], [-1, 1, 1],
];

// שתים־עשרה צלעות: הפאה האחורית, הקדמית, והקשרים ביניהן.
const EDGES = [
  [0, 1], [1, 2], [2, 3], [3, 0],
  [4, 5], [5, 6], [6, 7], [7, 4],
  [0, 4], [1, 5], [2, 6], [3, 7],
];

const DISTANCE = 4;   // מרחק הצופה מהראשית, ביחידות המודל
const SCALE = 78;     // פיקסלים ליחידה במישור ההטלה

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

  const tilt = 0.5;
  const cx = Math.cos(tilt), sx = Math.sin(tilt);
  const y1 = y * cx - z1 * sx;
  const z2 = y * sx + z1 * cx;

  return [x1, y1, z2];
}

function project([x, y, z], size) {
  const depth = DISTANCE / (DISTANCE - z);
  return [size / 2 + x * SCALE * depth, size / 2 + y * SCALE * depth];
}

function draw(angle) {
  const size = resize();
  ctx.clearRect(0, 0, size, size);

  const points = VERTICES.map((v) => project(rotate(v, angle), size));

  ctx.strokeStyle = '#818cf8';
  ctx.lineWidth = 1.6;
  ctx.lineJoin = 'round';
  ctx.beginPath();
  for (const [a, b] of EDGES) {
    ctx.moveTo(points[a][0], points[a][1]);
    ctx.lineTo(points[b][0], points[b][1]);
  }
  ctx.stroke();

  ctx.fillStyle = '#f472b6';
  for (const [px, py] of points) {
    ctx.beginPath();
    ctx.arc(px, py, 2.6, 0, Math.PI * 2);
    ctx.fill();
  }
}

const still = window.matchMedia('(prefers-reduced-motion: reduce)');

let start = null;
function frame(now) {
  if (start === null) start = now;
  draw((now - start) / 2600);
  requestAnimationFrame(frame);
}

if (still.matches) {
  draw(0.6);
  window.addEventListener('resize', () => draw(0.6));
} else {
  requestAnimationFrame(frame);
}
