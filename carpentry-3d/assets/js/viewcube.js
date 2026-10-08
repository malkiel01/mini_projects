// קוביית ניווט לתלת מימד: קובייה קטנה בפינה שמסתובבת יחד עם המצלמה.
// הקשה על פאה (חזית, צד, עליון…), על קצה או על פינה מסובבת את התצוגה לזווית
// הזו; גרירה על הקובייה מסובבת את התצוגה כמו גרירה על המודל; ⌂ מחזיר לתצוגה
// ההתחלתית. כל 26 האזורים יוצאים מקובייה אחת: נקודת הפגיעה על פני הקובייה
// מסווגת לכל ציר — קרוב לשפה = קצה/פינה, באמצע = הפאה עצמה.
// `THREE` גלובלי (נטען לפני האפליקציה). אין כאן תלות בצופה עצמו.

const LABELS = [   // סדר הפאות של BoxGeometry: +x, -x, +y, -y, +z, -z
  'ימין', 'שמאל', 'עליון', 'תחתון', 'חזית', 'אחור',
];
const EDGE = 0.28;   // מחצי צלע של 0.5: מעבר לזה — אזור קצה/פינה

/**
 * @param container  המכל שהקובייה נכנסת אליו (position: relative)
 * @param onPick(dir)     dir = [x,y,z] ב-{-1,0,1}: הכיוון שממנו מביטים
 * @param onOrbit(dx,dy)  גרירה על הקובייה (פיקסלים)
 * @param onHome()        ⌂
 */
export function createViewCube(container, { onPick, onOrbit, onHome }) {
  const THREE = window.THREE;
  const wrap = document.createElement('div');
  wrap.className = 'viewcube';
  wrap.innerHTML = '<canvas class="viewcube__canvas" aria-label="קוביית ניווט: הקשה על פאה, קצה או פינה מסובבת את התצוגה"></canvas><button type="button" class="viewcube__home" title="חזרה לתצוגה ההתחלתית" aria-label="חזרה לתצוגה ההתחלתית">⌂</button>';
  container.appendChild(wrap);
  const canvas = wrap.querySelector('canvas');
  wrap.querySelector('.viewcube__home').addEventListener('click', () => onHome());

  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(32, 1, 0.1, 20);
  scene.add(new THREE.HemisphereLight(0xffffff, 0xd9cdb8, 1.1));

  const css = getComputedStyle(document.documentElement);
  const color = (name, fb) => css.getPropertyValue(name).trim() || fb;
  const faceTex = (text) => {
    const c = document.createElement('canvas'); c.width = c.height = 128;
    const g = c.getContext('2d');
    g.fillStyle = color('--panel', '#fffdf9'); g.fillRect(0, 0, 128, 128);
    g.strokeStyle = color('--line', '#d6cbb8'); g.lineWidth = 6; g.strokeRect(3, 3, 122, 122);
    g.fillStyle = color('--accent-deep', '#7a4a17');
    g.font = 'bold 30px Heebo, Arial, sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
    g.direction = 'rtl'; g.fillText(text, 64, 66);
    const t = new THREE.CanvasTexture(c);
    t.anisotropy = 4;
    return t;
  };
  const cube = new THREE.Mesh(new THREE.BoxGeometry(1, 1, 1), LABELS.map((l) => new THREE.MeshLambertMaterial({ map: faceTex(l) })));
  scene.add(cube);
  cube.add(new THREE.LineSegments(new THREE.EdgesGeometry(cube.geometry), new THREE.LineBasicMaterial({ color: color('--accent', '#b8742a') })));
  // הדגשת האזור שמתחת לסמן
  const hl = new THREE.Mesh(new THREE.BoxGeometry(1, 1, 1), new THREE.MeshBasicMaterial({ color: color('--accent', '#b8742a'), transparent: true, opacity: 0.45, depthWrite: false }));
  hl.visible = false;
  scene.add(hl);

  const ray = new THREE.Raycaster();
  /** הכיוון של האזור שמתחת לנקודה, או null. */
  function dirAt(cx, cy) {
    const r = canvas.getBoundingClientRect();
    ray.setFromCamera(new THREE.Vector2((cx - r.left) / r.width * 2 - 1, -(cy - r.top) / r.height * 2 + 1), camera);
    const h = ray.intersectObject(cube, false)[0];
    if (!h) return null;
    return [h.point.x, h.point.y, h.point.z].map((v) => (v > EDGE ? 1 : v < -EDGE ? -1 : 0));
  }
  function highlight(dir) {
    hl.visible = !!dir;
    if (!dir) return;
    // אזור על פני הקובייה: בציר שבו הכיוון 0 — רצועת האמצע; בציר שאינו 0 — השפה, מעט בולטת
    const size = dir.map((d) => (d ? 0.5 - EDGE + 0.02 : 2 * EDGE));
    const pos = dir.map((d) => (d ? d * (EDGE + (0.5 - EDGE) / 2 + 0.01) : 0));
    hl.scale.set(size[0], size[1], size[2]);
    hl.position.set(pos[0], pos[1], pos[2]);
  }

  let down = null;
  canvas.addEventListener('pointerdown', (e) => {
    try { canvas.setPointerCapture(e.pointerId); } catch { /* ללא לכידה */ }
    down = { x: e.clientX, y: e.clientY, lx: e.clientX, ly: e.clientY, moved: 0 };
    e.stopPropagation();
  });
  canvas.addEventListener('pointermove', (e) => {
    if (!down) { if (e.pointerType === 'mouse') highlight(dirAt(e.clientX, e.clientY)); return; }
    const dx = e.clientX - down.lx, dy = e.clientY - down.ly;
    down.moved += Math.abs(dx) + Math.abs(dy); down.lx = e.clientX; down.ly = e.clientY;
    if (down.moved > 4) { highlight(null); onOrbit(dx, dy); }
  });
  const up = (e) => {
    if (down && down.moved <= 4) { const d = dirAt(e.clientX, e.clientY); if (d) onPick(d); }
    down = null;
    if (e.pointerType !== 'mouse') highlight(null);
  };
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', () => { down = null; highlight(null); });
  canvas.addEventListener('pointerleave', () => { if (!down) highlight(null); });

  function size() {
    const w = canvas.clientWidth, h = canvas.clientHeight;
    if (w && h && (canvas.width !== Math.round(w * renderer.getPixelRatio()))) { renderer.setSize(w, h, false); camera.aspect = w / h; camera.updateProjectionMatrix(); }
  }
  /** לקרוא בכל פריים עם הזוויות של המצלמה הראשית. */
  function render(theta, phi) {
    size();
    const s = Math.sin(phi);
    camera.position.set(3.2 * s * Math.sin(theta), 3.2 * Math.cos(phi), 3.2 * s * Math.cos(theta));
    camera.up.set(0, 1, 0);
    camera.lookAt(0, 0, 0);
    renderer.render(scene, camera);
  }
  return { render, dirAt };
}

/** זוויות המצלמה (theta סביב האנכי, phi מהקוטב) שמביטות מהכיוון `dir`. */
export function anglesFor(dir, currentTheta = 0) {
  const [x, y, z] = dir;
  const len = Math.hypot(x, y, z) || 1;
  const horiz = Math.hypot(x, z);
  const theta = horiz < 1e-6 ? 0 : Math.atan2(x, z);
  const phi = Math.min(Math.PI - 0.06, Math.max(0.06, Math.acos(y / len)));
  return { theta: horiz < 1e-6 ? nearestQuarter(currentTheta) : theta, phi };
}
const nearestQuarter = (t) => Math.round(t / (Math.PI / 2)) * (Math.PI / 2);
