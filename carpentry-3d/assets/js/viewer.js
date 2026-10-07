// התצוגה התלת־ממדית: three.js, בקרת מצלמה שנכתבה כאן (בלי OrbitControls),
// ובניית הגופים מרשימת החלקים. הקובץ לא מחשב אף מידה — הוא מצייר מה
// שהמודל נתן. בקרת המצלמה מותאמת מ-sukkah-sketch: גרירה מסובבת, שתי
// אצבעות מזיזות ומזמנות, גלגלת מזמנת אל הנקודה שמתחת לסמן.
//
// `THREE` גלובלי — נטען בדף (CDN ואז עותק מקומי) לפני שהמודול הזה רץ.

import { material } from './model/materials.js';

const T = () => window.THREE;

export function createViewer(canvas, { onPick } = {}) {
  const THREE = T();
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(38, 1, 5, 40000);
  const ray = new THREE.Raycaster();

  scene.add(new THREE.HemisphereLight(0xffffff, 0x6b6560, 0.9));
  const sun = new THREE.DirectionalLight(0xffffff, 0.6);
  sun.position.set(-1500, 3000, 2500);
  scene.add(sun);

  // הרצפה: רשת עדינה שנותנת קנה מידה ותחושת כובד.
  const grid = new THREE.GridHelper(6000, 60, 0x57534e, 0x3f3b37);
  grid.position.y = -0.5;
  scene.add(grid);

  const group = new THREE.Group();
  scene.add(group);
  let pickables = [];
  let selected = null;

  // מצלמה: כדורית סביב יעד. theta סביב הציר האנכי, phi מהקוטב, radius מרחק.
  const ctl = { target: new THREE.Vector3(600, 1000, 175), theta: 0.55, phi: 1.15, radius: 4200 };
  function applyCam() {
    ctl.phi = Math.max(0.05, Math.min(Math.PI - 0.05, ctl.phi));
    ctl.radius = Math.max(200, Math.min(30000, ctl.radius));
    const s = Math.sin(ctl.phi);
    camera.position.set(
      ctl.target.x + ctl.radius * s * Math.sin(ctl.theta),
      ctl.target.y + ctl.radius * Math.cos(ctl.phi),
      ctl.target.z + ctl.radius * s * Math.cos(ctl.theta),
    );
    camera.up.set(0, 1, 0);
    camera.lookAt(ctl.target);
  }
  function panBy(dx, dy) {
    const k = 2 * ctl.radius * Math.tan(camera.fov * Math.PI / 360) / canvas.clientHeight;
    camera.updateMatrixWorld();
    const r = new THREE.Vector3().setFromMatrixColumn(camera.matrixWorld, 0);
    const u = new THREE.Vector3().setFromMatrixColumn(camera.matrixWorld, 1);
    ctl.target.addScaledVector(r, -dx * k).addScaledVector(u, dy * k);
  }
  function screenToNdc(cx, cy) {
    const r = canvas.getBoundingClientRect();
    return new THREE.Vector2((cx - r.left) / r.width * 2 - 1, -(cy - r.top) / r.height * 2 + 1);
  }
  function worldAt(cx, cy) {
    ray.setFromCamera(screenToNdc(cx, cy), camera);
    const h = ray.intersectObjects(pickables, false)[0];
    if (h) return h.point;
    const o = new THREE.Vector3();
    return ray.ray.intersectPlane(new THREE.Plane(new THREE.Vector3(0, 1, 0), -ctl.target.y), o) ? o : null;
  }
  // זום אל הנקודה שמתחת לאצבעות, לא אל מרכז המודל.
  function zoomBy(f, cx, cy) {
    if (cx !== undefined) { const pt = worldAt(cx, cy); if (pt) ctl.target.lerp(pt, 1 - f); }
    ctl.radius *= f;
  }

  const ptrs = {};
  let pinch = 0, moved = 0;
  canvas.addEventListener('pointerdown', (e) => {
    canvas.setPointerCapture(e.pointerId);
    ptrs[e.pointerId] = [e.clientX, e.clientY];
    moved = 0; pinch = 0;
  });
  canvas.addEventListener('pointermove', (e) => {
    const p = ptrs[e.pointerId]; if (!p) return;
    const dx = e.clientX - p[0], dy = e.clientY - p[1];
    moved += Math.abs(dx) + Math.abs(dy);
    const ids = Object.keys(ptrs);
    if (ids.length === 1) {
      if (e.shiftKey || e.buttons === 2) panBy(dx, dy);
      else { ctl.theta -= dx * 0.006; ctl.phi -= dy * 0.006; }
    } else if (ids.length === 2) {
      p[0] = e.clientX; p[1] = e.clientY;
      const A = ptrs[ids[0]], B = ptrs[ids[1]];
      const d = Math.hypot(A[0] - B[0], A[1] - B[1]);
      if (pinch) zoomBy(pinch / d, (A[0] + B[0]) / 2, (A[1] + B[1]) / 2);
      pinch = d;
      panBy(dx / 2, dy / 2);
    }
    p[0] = e.clientX; p[1] = e.clientY;
  });
  function up(e) {
    // הקשה בלי גרירה = בחירת חלק.
    if (ptrs[e.pointerId] && moved < 6 && Object.keys(ptrs).length === 1) pick(e.clientX, e.clientY);
    delete ptrs[e.pointerId]; pinch = 0;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', (e) => { delete ptrs[e.pointerId]; pinch = 0; });
  canvas.addEventListener('wheel', (e) => { e.preventDefault(); zoomBy(e.deltaY > 0 ? 1.12 : 1 / 1.12, e.clientX, e.clientY); }, { passive: false });
  canvas.addEventListener('contextmenu', (e) => e.preventDefault());

  function pick(cx, cy) {
    ray.setFromCamera(screenToNdc(cx, cy), camera);
    const h = ray.intersectObjects(pickables, false)[0];
    select(h ? h.object : null);
  }
  function select(mesh) {
    if (selected) { selected.material.emissive.setHex(0x000000); }
    selected = mesh;
    if (selected) selected.material.emissive.setHex(0x8a5a1a);
    if (onPick) onPick(selected ? selected.userData.part : null);
  }

  // חומר three.js לכל מזהה חומר במודל, נוצר פעם אחת ומשותף לכל החלקים שלו.
  // כל חלק מקבל עותק (clone) כדי שהדגשת הבחירה לא תצבע את כל החלקים מאותו חומר.
  const matCache = new Map();
  function threeMaterial(id) {
    if (!matCache.has(id)) {
      const m = material(id);
      const opts = { color: m.color ?? 0xcccccc, roughness: m.kind === 'glass' ? 0.1 : 0.75, metalness: 0 };
      if (m.kind === 'glass') { opts.transparent = true; opts.opacity = m.opacity ?? 0.4; }
      matCache.set(id, new THREE.MeshStandardMaterial(opts));
    }
    return matCache.get(id).clone();
  }

  // קווי מתאר דקים לכל לוח: בלעדיהם שני לוחות באותו חומר נבלעים זה בזה.
  const edgeMat = new THREE.LineBasicMaterial({ color: 0x1c1917, transparent: true, opacity: 0.35 });

  function setModel(model) {
    while (group.children.length) {
      const c = group.children.pop();
      c.traverse((o) => { if (o.geometry) o.geometry.dispose(); });
    }
    pickables = [];
    selected = null;
    for (const p of model.parts) {
      const geo = new THREE.BoxGeometry(p.box.w, p.box.h, p.box.d);
      const mesh = new THREE.Mesh(geo, threeMaterial(p.material));
      mesh.position.set(p.box.x + p.box.w / 2, p.box.y + p.box.h / 2, p.box.z + p.box.d / 2);
      mesh.userData.part = p;
      group.add(mesh);
      pickables.push(mesh);
      const lines = new THREE.LineSegments(new THREE.EdgesGeometry(geo), edgeMat);
      lines.position.copy(mesh.position);
      group.add(lines);
    }
    // פרזול: צירים וידיות כגופים קטנים, כדי שיראו איפה הם יושבים.
    for (const h of model.hardware) {
      if (!h.pos) continue;
      let mesh;
      if (h.kind === 'hinge') {
        mesh = new THREE.Mesh(new THREE.CylinderGeometry(17, 17, 12, 20), new THREE.MeshStandardMaterial({ color: 0x9ca3af, metalness: 0.6, roughness: 0.4 }));
        mesh.rotation.x = Math.PI / 2;
        mesh.position.set(h.pos[0], h.pos[1], h.pos[2] - 6);
      } else if (h.kind === 'handle') {
        const bar = h.material === 'hw:handle-knob';
        mesh = new THREE.Mesh(
          bar ? new THREE.SphereGeometry(12, 16, 12) : new THREE.BoxGeometry(12, 140, 12),
          new THREE.MeshStandardMaterial({ color: 0x374151, metalness: 0.7, roughness: 0.3 }),
        );
        mesh.position.set(h.pos[0], h.pos[1], h.pos[2] + (bar ? 12 : 20));
      }
      if (mesh) group.add(mesh);
    }
    if (onPick) onPick(null);
  }

  /** ממקם את המצלמה כך שכל הגוף נראה, ממבט איזומטרי מהחזית. */
  function frame(bounds) {
    ctl.target.set(bounds.w / 2, bounds.h / 2, bounds.d / 2);
    const size = Math.max(bounds.w, bounds.h, bounds.d * 2);
    ctl.radius = size / Math.tan(camera.fov * Math.PI / 360) * 0.62 + bounds.d;
    ctl.theta = 0.55; ctl.phi = 1.2;
  }
  function view(name) {
    if (name === 'front') { ctl.theta = 0; ctl.phi = Math.PI / 2; }
    if (name === 'side') { ctl.theta = Math.PI / 2; ctl.phi = Math.PI / 2; }
    if (name === 'top') { ctl.theta = 0; ctl.phi = 0.06; }
    if (name === 'iso') { ctl.theta = 0.55; ctl.phi = 1.2; }
  }

  function fit() {
    const w = canvas.clientWidth, h = canvas.clientHeight;
    if (!w || !h) return;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }
  window.addEventListener('resize', fit);
  new ResizeObserver(fit).observe(canvas);
  fit();
  (function loop() { requestAnimationFrame(loop); applyCam(); renderer.render(scene, camera); })();

  return { setModel, frame, view, fit, select };
}
