// מגופי האביזר (accessories.js) לגופי three.js. משותף לצופה הראשי ולתצוגה
// המקדימה במסך "אביזרים". `THREE` גלובלי.

const matCache = new Map();
function finishMaterial(THREE, f) {
  if (!matCache.has(f.id)) matCache.set(f.id, new THREE.MeshStandardMaterial({ color: f.color, metalness: f.metalness, roughness: f.roughness }));
  return matCache.get(f.id);
}

/**
 * קבוצה של גופים במערכת המקומית של האביזר (ראשית בנקודת ההרכבה, +z החוצה).
 * `filter(prim)` — למשל רק הגופים שנעים עם הדלת.
 */
export function accessoryGroup(THREE, acc, filter = () => true) {
  const g = new THREE.Group();
  for (const pr of acc.prims) {
    if (!filter(pr)) continue;
    let geo;
    if (pr.shape === 'cylinder') geo = new THREE.CylinderGeometry(pr.size[0], pr.size[0], pr.size[1], 24);
    else if (pr.shape === 'box') geo = new THREE.BoxGeometry(pr.size[0], pr.size[1], pr.size[2]);
    else if (pr.shape === 'sphere') geo = new THREE.SphereGeometry(pr.size[0], 20, 14);
    else if (pr.shape === 'torus') geo = pr.arc ? new THREE.TorusGeometry(pr.size[0], pr.size[1], 12, 24, pr.arc[1]) : new THREE.TorusGeometry(pr.size[0], pr.size[1], 12, 32);
    else continue;
    const mesh = new THREE.Mesh(geo, finishMaterial(THREE, pr.finish));
    mesh.position.set(pr.pos[0], pr.pos[1], pr.pos[2]);
    if (pr.rot) mesh.rotation.set(pr.rot[0], pr.rot[1], pr.rot[2]);
    if (pr.arc) mesh.rotation.z = (mesh.rotation.z || 0) + pr.arc[0];
    mesh.userData.accessory = true;
    g.add(mesh);
  }
  return g;
}

/** הגבולות המקומיים של האביזר (לתצוגה המקדימה). */
export function accessoryExtent(acc) {
  let max = 1;
  for (const pr of acc.prims) {
    const r = Math.max(...pr.size) + Math.max(Math.abs(pr.pos[0]), Math.abs(pr.pos[1]), Math.abs(pr.pos[2]));
    max = Math.max(max, r);
  }
  return max;
}
