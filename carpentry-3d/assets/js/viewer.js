// התצוגה התלת־ממדית: three.js, בקרת מצלמה שנכתבה כאן (בלי OrbitControls),
// ובניית הגופים מרשימת החלקים. הקובץ לא מחשב אף מידה — הוא מצייר מה
// שהמודל נתן. בקרת המצלמה מותאמת מ-sukkah-sketch: גרירה מסובבת, שתי
// אצבעות מזיזות ומזמנות, גלגלת מזמנת אל הנקודה שמתחת לסמן.
//
// `THREE` גלובלי — נטען בדף (CDN ואז עותק מקומי) לפני שהמודול הזה רץ.

import { material, libraryVersion } from './model/materials.js';
import { cutSize } from './model/blocks.js';
import { textureForPart, clearTextures } from './textures.js';
import { buildAccessory, faceOf, rotationForNormal, localXOf } from './model/accessories.js';
import { accessoryGroup } from './accessory-mesh.js';

const T = () => window.THREE;

export function createViewer(canvas, { onPick } = {}) {
  const THREE = T();
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(38, 1, 5, 40000);
  const ray = new THREE.Raycaster();

  // צבעי הרשת וקווי המתאר באים מערכת הנושא ב-CSS, לא מכאן.
  const css = getComputedStyle(document.documentElement);
  const cssColor = (name, fallback) => new THREE.Color(css.getPropertyValue(name).trim() || fallback);

  scene.add(new THREE.HemisphereLight(0xffffff, 0xcdbfa9, 0.95));
  const sun = new THREE.DirectionalLight(0xffffff, 0.55);
  sun.position.set(-1500, 3000, 2500);
  scene.add(sun);

  // הרצפה: רשת עדינה שנותנת קנה מידה ותחושת כובד.
  const grid = new THREE.GridHelper(6000, 60, cssColor('--grid-major', '#cdbfa9'), cssColor('--grid-minor', '#e2d8c8'));
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
  let pinch = 0, moved = 0, twist = null;
  canvas.addEventListener('pointerdown', (e) => {
    canvas.setPointerCapture(e.pointerId);
    ptrs[e.pointerId] = [e.clientX, e.clientY];
    moved = 0; pinch = 0; twist = null;
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
      // סיבוב שתי אצבעות: השינוי בזווית שביניהן מסובב את המודל סביב הציר האנכי.
      const ang = Math.atan2(B[1] - A[1], B[0] - A[0]);
      if (twist !== null) { const da = Math.atan2(Math.sin(ang - twist), Math.cos(ang - twist)); ctl.theta += da; }
      twist = ang;
    }
    p[0] = e.clientX; p[1] = e.clientY;
  });
  function up(e) {
    // הקשה בלי גרירה = בחירת חלק.
    if (ptrs[e.pointerId] && moved < 6 && Object.keys(ptrs).length === 1) pick(e.clientX, e.clientY);
    delete ptrs[e.pointerId]; pinch = 0; twist = null;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', (e) => { delete ptrs[e.pointerId]; pinch = 0; twist = null; });
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

  // חומר three.js לכל חלק: טקסטורה (פרוצדורלית או תמונה) בקנה מידה פיזי,
  // מסובבת לכיוון הסיבים של החלק. המטמון מתנקה כשספריית החומרים משתנה.
  let libVersion = libraryVersion();
  function threeMaterial(p) {
    const m = material(p.material);
    const opts = { roughness: m.kind === 'glass' ? 0.08 : m.finish === 'paint' ? 0.55 : 0.7, metalness: 0 };
    if (m.kind === 'glass') {
      opts.color = m.color ?? 0xbfe0ea; opts.transparent = true; opts.opacity = m.opacity ?? 0.4;
    } else {
      opts.map = textureForPart(m, p, cutSize(p));
    }
    return new THREE.MeshStandardMaterial(opts);
  }

  // קווי מתאר דקים לכל לוח: בלעדיהם שני לוחות באותו חומר נבלעים זה בזה.
  const edgeMat = new THREE.LineBasicMaterial({ color: cssColor('--edge-line', '#4a3a2c'), transparent: true, opacity: 0.3 });

  // ---- רכיבי תנועה ושקיפות ----
  // כל חלק יושב בצומת (Group) משלו במרכזו; חלק עם `motion` מוזז/מסובב דרך
  // הצומת. ידיות וצירים ששייכים לדלת או למגירה (hardware.for) נכנסים לאותו
  // צומת ונעים איתה. המצבים (פתוח, שקוף) נשמרים לפי מזהה — ושורדים בנייה
  // מחדש של המודל כשהטופס משתנה.
  const partNodes = new Map();      // part id → { node, mesh, lines, part }
  const openGroups = new Set();     // קבוצות תנועה פתוחות (יעד)
  const amounts = new Map();        // קבוצה → כמה פתוחה כרגע (0..1), לאנימציה
  const ghostIds = new Set();       // חלקים שקופים
  let lastT = performance.now();

  function applyMotion(entry, a) {
    const { node, part: p } = entry;
    const m = p.motion;
    const cx = p.box.x + p.box.w / 2, cy = p.box.y + p.box.h / 2, cz = p.box.z + p.box.d / 2;
    if (m.kind === 'slide') {
      node.position.set(cx + m.vec[0] * a, cy + m.vec[1] * a, cz + m.vec[2] * a);
    } else if (m.kind === 'hinge') {
      const th = (m.angle * Math.PI / 180) * a;
      const rx = cx - m.pivot[0], rz = cz - m.pivot[2];
      node.position.set(m.pivot[0] + rx * Math.cos(th) + rz * Math.sin(th), cy, m.pivot[2] - rx * Math.sin(th) + rz * Math.cos(th));
      node.rotation.y = th;
    }
  }
  function animate() {
    const now = performance.now(), dt = Math.min(0.1, (now - lastT) / 1000); lastT = now;
    for (const entry of partNodes.values()) {
      const m = entry.part.motion; if (!m) continue;
      const target = openGroups.has(m.group) ? 1 : 0;
      const cur = amounts.get(m.group) ?? 0;
      if (cur === target) continue;
      const next = cur < target ? Math.min(target, cur + dt * 2.5) : Math.max(target, cur - dt * 2.5);
      amounts.set(m.group, next);
    }
    for (const entry of partNodes.values()) if (entry.part.motion) applyMotion(entry, amounts.get(entry.part.motion.group) ?? 0);
  }
  function setGhost(entry, on) {
    const mat = entry.mesh.material;
    const glass = material(entry.part.material).kind === 'glass';
    mat.transparent = on || glass;
    mat.opacity = on ? 0.18 : (glass ? (material(entry.part.material).opacity ?? 0.4) : 1);
    mat.depthWrite = !on;
    mat.needsUpdate = true;
    entry.lines.material = on ? ghostEdgeMat : edgeMat;
  }
  const ghostEdgeMat = new THREE.LineBasicMaterial({ color: cssColor('--edge-line', '#4a3a2c'), transparent: true, opacity: 0.12 });

  /** פתיחה/סגירה של קבוצת תנועה (דלת, מגירה). מחזיר את המצב החדש. */
  function toggleOpen(groupId) {
    if (openGroups.has(groupId)) openGroups.delete(groupId); else openGroups.add(groupId);
    return openGroups.has(groupId);
  }
  const isOpen = (groupId) => openGroups.has(groupId);
  function toggleGhost(partId) {
    if (ghostIds.has(partId)) ghostIds.delete(partId); else ghostIds.add(partId);
    const e = partNodes.get(partId); if (e) setGhost(e, ghostIds.has(partId));
    return ghostIds.has(partId);
  }
  const isGhost = (partId) => ghostIds.has(partId);
  /** כל רכיבי התנועה בבת אחת. */
  function openAll(on) {
    for (const e of partNodes.values()) if (e.part.motion) { if (on) openGroups.add(e.part.motion.group); else openGroups.delete(e.part.motion.group); }
  }
  const anyOpen = () => openGroups.size > 0;
  /** כל החזיתות (מה שזז) שקופות — כדי לראות מה יש מאחור. */
  function ghostFronts(on) {
    for (const e of partNodes.values()) if (e.part.motion) { if (on) ghostIds.add(e.part.id); else ghostIds.delete(e.part.id); setGhost(e, on); }
  }
  const anyGhost = () => ghostIds.size > 0;

  function setModel(model) {
    if (libraryVersion() !== libVersion) { clearTextures(); libVersion = libraryVersion(); }
    while (group.children.length) {
      const c = group.children.pop();
      c.traverse((o) => { if (o.geometry) o.geometry.dispose(); if (o.material && o.material.map) o.material.map.dispose(); });
    }
    pickables = [];
    selected = null;
    partNodes.clear();
    const ids = new Set(model.parts.map((p) => p.id));
    for (const id of [...ghostIds]) if (!ids.has(id)) ghostIds.delete(id);
    for (const p of model.parts) {
      const geo = new THREE.BoxGeometry(p.box.w, p.box.h, p.box.d);
      const mesh = new THREE.Mesh(geo, threeMaterial(p));
      mesh.userData.part = p;
      const lines = new THREE.LineSegments(new THREE.EdgesGeometry(geo), edgeMat);
      const node = new THREE.Group();
      node.position.set(p.box.x + p.box.w / 2, p.box.y + p.box.h / 2, p.box.z + p.box.d / 2);
      node.add(mesh); node.add(lines);
      node.userData.part = p;
      group.add(node);
      pickables.push(mesh);
      const entry = { node, mesh, lines, part: p };
      partNodes.set(p.id, entry);
      if (ghostIds.has(p.id)) setGhost(entry, true);
      if (p.motion) applyMotion(entry, amounts.get(p.motion.group) ?? 0);
    }
    // פרזול: צירים וידיות כגופים קטנים. מה ששייך לדלת/מגירה נע איתה.
    for (const h of model.hardware) {
      if (!h.pos) continue;
      let mesh;
      const owner = h.for && partNodes.get(h.for);
      const acc = ['hinge', 'handle', 'wheel'].includes(h.kind) ? buildAccessory(material(h.material), { side: hingeSideOf(h, owner) }) : null;
      if (acc) {
        // אביזר אמיתי: קבוצת גופים במערכת מקומית, מסובבת אל הפאה שהוא יושב עליה.
        // ידית אנכית על דלת: המוט מסתובב 90° סביב הנורמל. מה שנע עם הדלת נכנס לצומת שלה.
        const normal = h.kind === 'wheel' ? '-y' : ownerFace(h, owner);
        const place = (g) => {
          g.position.set(h.pos[0], h.pos[1], h.pos[2]);
          const r = rotationForNormal(normal);
          g.rotation.set(r[0], r[1], r[2]);
          if (h.kind === 'handle' && !h.horizontal && acc.type.kind === 'handle') g.rotateZ(Math.PI / 2);
          if (h.kind === 'handle' && acc.type.id === 'edge-profile' && owner) {
            // פרופיל קצה: יושב על הקצה העליון של הדלת/המגירה, לא באמצעה
            const b = owner.part.box;
            g.position.set(b.x + b.w / 2, b.y + b.h - acc.params.height / 2, h.pos[2]);
            g.rotation.set(r[0], r[1], r[2]);
          }
          return g;
        };
        if (h.kind === 'hinge' && owner && owner.part.motion) {
          const moving = place(accessoryGroup(THREE, acc, (pr) => pr.moving));
          const p = owner.part;
          moving.position.sub(new THREE.Vector3(p.box.x + p.box.w / 2, p.box.y + p.box.h / 2, p.box.z + p.box.d / 2));
          owner.node.add(moving);
          group.add(place(accessoryGroup(THREE, acc, (pr) => !pr.moving)));
          continue;
        }
        mesh = place(accessoryGroup(THREE, acc));
      } else if (h.kind === 'hinge') {
        mesh = new THREE.Mesh(new THREE.CylinderGeometry(17, 17, 12, 20), new THREE.MeshStandardMaterial({ color: 0x9ca3af, metalness: 0.6, roughness: 0.4 }));
        mesh.rotation.x = Math.PI / 2;
        mesh.position.set(h.pos[0], h.pos[1], h.pos[2] - 6);
      } else if (h.kind === 'handle') {
        mesh = new THREE.Mesh(h.horizontal ? new THREE.BoxGeometry(140, 12, 12) : new THREE.BoxGeometry(12, 140, 12), new THREE.MeshStandardMaterial({ color: 0x374151, metalness: 0.7, roughness: 0.3 }));
        mesh.position.set(h.pos[0], h.pos[1], h.pos[2] + 20);
      } else if (h.kind === 'led') {
        // פס לד: תיבה דקה עם חומר זוהר (emissive), אנכית לאורך הדופן או אופקית מתחת למדף
        const mat = new THREE.MeshStandardMaterial({ color: 0xffffff, emissive: 0xfff4d6, emissiveIntensity: 1.6, roughness: 0.4 });
        mesh = new THREE.Mesh(h.horizontal ? new THREE.BoxGeometry(h.len, 4, 8) : new THREE.BoxGeometry(6, h.len, 8), mat);
        mesh.position.set(h.pos[0] + (h.horizontal ? h.len / 2 : 3), h.pos[1] + (h.horizontal ? 0 : h.len / 2), h.pos[2]);
      } else if (h.kind === 'rod') {
        // מוט תלייה: גליל לרוחב העמודה
        mesh = new THREE.Mesh(new THREE.CylinderGeometry(12, 12, h.len, 16), new THREE.MeshStandardMaterial({ color: 0xc0c4c8, metalness: 0.8, roughness: 0.25 }));
        mesh.rotation.z = Math.PI / 2;
        mesh.position.set(h.pos[0] + h.len / 2, h.pos[1], h.pos[2]);
      }
      if (!mesh) continue;
      if (owner && owner.part.motion && h.kind !== 'hinge') {
        // ידית על דלת/מגירה: לתוך הצומת של החלק, בקואורדינטות יחסיות למרכזו
        const p = owner.part;
        mesh.position.sub(new THREE.Vector3(p.box.x + p.box.w / 2, p.box.y + p.box.h / 2, p.box.z + p.box.d / 2));
        owner.node.add(mesh);
      } else group.add(mesh);
    }
    if (onPick) onPick(null);
  }

  /** הפאה של הדלת/המגירה שהאביזר יושב עליה; ציר יושב על הפאה האחורית — הנורמל שלו הוא ההפך. */
  function ownerFace(h, owner) {
    if (!owner) return '+z';
    const f = faceOf(owner.part.box, h.pos);
    if (h.kind !== 'hinge') return f;
    return { '+z': '-z', '-z': '+z', '+x': '-x', '-x': '+x', '+y': '-y', '-y': '+y' }[f];
  }
  /** מאיזה צד של הדלת הציר (במערכת המקומית של האביזר): לפי מיקומו ביחס למרכז הדלת. */
  function hingeSideOf(h, owner) {
    if (h.kind !== 'hinge' || !owner) return 'left';
    const b = owner.part.box;
    const n = { '+z': '-z', '-z': '+z', '+x': '-x', '-x': '+x', '+y': '-y', '-y': '+y' }[faceOf(b, h.pos)];
    const lx = localXOf(n);
    const dx = h.pos[0] - (b.x + b.w / 2), dz = h.pos[2] - (b.z + b.d / 2);
    return dx * lx[0] + dz * lx[2] < 0 ? 'left' : 'right';
  }

  /** ממקם את המצלמה כך שכל הגוף נראה, ממבט איזומטרי מהחזית. */
  function frame(bounds) {
    ctl.target.set(bounds.w / 2, bounds.h / 2, bounds.d / 2);
    // המרחק לפי המידה הגדולה, ועוד קצת עבור העומק — כך גם מיטה ארוכה וגם ספרייה גבוהה נכנסות למסך.
    const size = Math.max(bounds.w, bounds.h, bounds.d) * 1.05;
    ctl.radius = size / Math.tan(camera.fov * Math.PI / 360) * 0.62 + bounds.d * 0.5;
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
  (function loop() { requestAnimationFrame(loop); animate(); applyCam(); renderer.render(scene, camera); })();

  return { setModel, frame, view, fit, select, toggleOpen, isOpen, toggleGhost, isGhost, openAll, anyOpen, ghostFronts, anyGhost, debug: () => ({ open: openGroups.size, motionParts: [...partNodes.values()].filter((e) => e.part.motion).length, amounts: [...amounts.entries()].slice(0, 4), theta: ctl.theta, phi: ctl.phi, radius: ctl.radius, target: ctl.target.toArray(), w: canvas.clientWidth, h: canvas.clientHeight, aspect: camera.aspect, fov: camera.fov }) };
}
