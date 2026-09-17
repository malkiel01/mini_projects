// תצוגה מקדימה: סיר על אש ומעליו אדים — ציור קווים בלבד, בלי שום ספרייה.
// זהו מציין מקום. כשהאפליקציה תיכנס לכאן, הקובץ הזה מוחלף.

const canvas = document.getElementById('stage');
const ctx = canvas.getContext('2d');

// כל הקואורדינטות ביחידות של ריבוע 100x100, ונמתחות לגודל הקנבס בפועל.
// כך הציור נראה זהה בכל רוחב, ואין מספרי פיקסלים מפוזרים בקוד.
const UNIT = 100;

// דופן הסיר, מכסה הבסיס והידית — קווים נפרדים, כדי שכל אחד ייצבע לעצמו.
const POT = [
  [[24, 46], [24, 70]],
  [[76, 46], [76, 70]],
  [[24, 70], [34, 78]],
  [[76, 70], [66, 78]],
  [[34, 78], [66, 78]],
  [[18, 46], [82, 46]],
];
const HANDLE = [
  [[82, 52], [90, 52]],
  [[90, 52], [90, 60]],
  [[90, 60], [82, 60]],
];

// האש: שלוש להבות קטנות מתחת לסיר, כקווים שבורים.
const FLAMES = [
  [[40, 86], [43, 81], [46, 86]],
  [[48, 88], [51, 82], [54, 88]],
  [[56, 86], [59, 81], [62, 86]],
];

// שני עמודי אדים. כל אחד גל סינוס שמטפס מעל שפת הסיר,
// והפאזה נעה בזמן — זה כל ה"אנימציה" כאן.
const STEAM_X = [42, 58];

// הקנבס מוצג ב-CSS ביחידות לוגיות; ללא כפל ב-devicePixelRatio
// הקווים יוצאים מטושטשים במסכים צפופים.
function resize() {
  const dpr = window.devicePixelRatio || 1;
  const size = canvas.clientWidth;
  canvas.width = canvas.height = Math.round(size * dpr);
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  return size;
}

function scalePoint([x, y], size) {
  return [(x / UNIT) * size, (y / UNIT) * size];
}

function strokePath(points, size, color, width) {
  ctx.strokeStyle = color;
  ctx.lineWidth = width;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.beginPath();
  points.forEach((point, i) => {
    const [x, y] = scalePoint(point, size);
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.stroke();
}

function drawSteam(x, phase, size) {
  // מ-y=40 (מעל שפת הסיר) ועד y=10, ברזולוציה של 2 יחידות.
  const points = [];
  for (let y = 40; y >= 10; y -= 2) {
    const sway = Math.sin((y / 8) + phase) * 4;
    points.push([x + sway, y]);
  }
  strokePath(points, size, '#fcd34d', size * 0.022);
}

function draw(phase) {
  const size = resize();
  ctx.clearRect(0, 0, size, size);

  const wood = size * 0.018;
  for (const segment of POT) strokePath(segment, size, '#f97316', wood);
  for (const segment of HANDLE) strokePath(segment, size, '#f97316', wood);
  for (const flame of FLAMES) strokePath(flame, size, '#f97316', size * 0.016);

  STEAM_X.forEach((x, i) => drawSteam(x, phase + i * 1.4, size));
}

const still = window.matchMedia('(prefers-reduced-motion: reduce)');

let start = null;
function frame(now) {
  if (start === null) start = now;
  draw((now - start) / 700);
  requestAnimationFrame(frame);
}

if (still.matches) {
  draw(0.6);
  window.addEventListener('resize', () => draw(0.6));
} else {
  requestAnimationFrame(frame);
}
