// ייצוא STL להדפסה בתלת מימד: כל חלק הוא תיבה — 12 משולשים. בינארי, קטן ומהיר.
//
// קנה מידה (1:10, 1:20…) ועובי מינימלי: לוח 18 מ"מ ב-1:20 הוא 0.9 מ"מ — דק
// מכדי להדפיס; חלק שמידתו קטנה מהמינימום מעובה סביב מרכזו. הדלתות סגורות
// (המודל כפי שנבנה), בלי פרזול (קטן מדי בקנה מידה). הגופים חופפים מעט
// במקומות שבהם לוחות נפגשים — כל פורס (slicer) מאחד גופים כאלה.

/**
 * @param parts   רשימת החלקים של המודל (או ההרכבה)
 * @param scale   מחלק: 10 ל-1:10
 * @param minT    עובי מינימלי במ"מ של ההדפסה (אחרי הקנה)
 * @returns ArrayBuffer של STL בינארי, ומה עובה
 */
export function toSTL(parts, { scale = 10, minT = 1.0 } = {}) {
  const boxes = [];
  let thickened = 0;
  for (const p of parts) {
    const b = p.box;
    const dims = [b.w / scale, b.h / scale, b.d / scale];
    const pos = [b.x / scale, b.y / scale, b.z / scale];
    for (let i = 0; i < 3; i++) if (dims[i] < minT) { pos[i] -= (minT - dims[i]) / 2; dims[i] = minT; thickened += 1; }
    boxes.push({ pos, dims });
  }
  const n = boxes.length * 12;
  const buf = new ArrayBuffer(84 + n * 50);
  const dv = new DataView(buf);
  const header = 'carpentry-3d STL 1:' + scale;
  for (let i = 0; i < 80; i++) dv.setUint8(i, i < header.length ? header.charCodeAt(i) : 0);
  dv.setUint32(80, n, true);
  let off = 84;
  const tri = (nrm, a, b, c) => {
    for (const v of [nrm, a, b, c]) for (let i = 0; i < 3; i++) { dv.setFloat32(off, v[i], true); off += 4; }
    dv.setUint16(off, 0, true); off += 2;
  };
  for (const { pos: [x, y, z], dims: [w, h, d] } of boxes) {
    const X = x + w, Y = y + h, Z = z + d;
    // שש פאות, כל אחת שני משולשים, נגד כיוון השעון כשמביטים מבחוץ
    tri([0, 0, -1], [x, y, z], [x, Y, z], [X, Y, z]); tri([0, 0, -1], [x, y, z], [X, Y, z], [X, y, z]);          // אחור
    tri([0, 0, 1], [x, y, Z], [X, y, Z], [X, Y, Z]); tri([0, 0, 1], [x, y, Z], [X, Y, Z], [x, Y, Z]);            // חזית
    tri([-1, 0, 0], [x, y, z], [x, y, Z], [x, Y, Z]); tri([-1, 0, 0], [x, y, z], [x, Y, Z], [x, Y, z]);          // שמאל
    tri([1, 0, 0], [X, y, z], [X, Y, z], [X, Y, Z]); tri([1, 0, 0], [X, y, z], [X, Y, Z], [X, y, Z]);            // ימין
    tri([0, -1, 0], [x, y, z], [X, y, z], [X, y, Z]); tri([0, -1, 0], [x, y, z], [X, y, Z], [x, y, Z]);          // תחתית
    tri([0, 1, 0], [x, Y, z], [x, Y, Z], [X, Y, Z]); tri([0, 1, 0], [x, Y, z], [X, Y, Z], [X, Y, z]);            // גג
  }
  return { buffer: buf, triangles: n, thickened };
}

/** מידות ההדפסה (מ"מ) של מודל בקנה מידה נתון. */
export function printSize(bounds, scale) {
  return { w: Math.round(bounds.w / scale * 10) / 10, h: Math.round(bounds.h / scale * 10) / 10, d: Math.round(bounds.d / scale * 10) / 10 };
}
