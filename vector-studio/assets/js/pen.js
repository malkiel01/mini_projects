// העט ועריכת הנקודות.
//
// עט: הקשה — נקודה חדה; גרירה — נקודה חלקה (הידיות נמשכות אחרי האצבע); הקשה על הנקודה הראשונה — סוגרת.
//   Enter / Esc / "✓ סיום" — מסיים נתיב פתוח; "✕" — מבטל; Ctrl+Z או ↶ בזמן ציור — מוחק את הנקודה האחרונה.
// עריכת נקודות: גרירת נקודה או ידית; הקשה על המקטע — נקודה חדשה; לחיצה כפולה / Alt על נקודה — חלקה ↔ פינה;
//   Delete — מחיקת הנקודה שנבחרה. נתיב שהיה צורה חיה (מלבן…) הופך לנתיב רגיל בעריכה הראשונה.

import { parse, serialize } from './geometry/path.js';
import { apply, invert, multiply, translate, isIdentity, IDENTITY } from './geometry/matrix.js';
import { toNodes, fromNodes, splitAt, toggleSmooth, removeNode, moveHandle, moveNode, nearest } from './geometry/nodes.js';
import { layout, findPath, nextId } from './model.js';
import { keyOf } from './ui-core/keys.js';

const R_ANCHOR = 4, R_HANDLE = 3.5;

export function createPenTools(api) {
  const { S } = api;
  let pen = null;   // { id, subs, before, off, dragging }
  let edit = null;  // { id, subs, node: [si, ni] | null }
  let drag = null;  // { kind: 'anchor'|'handle'|'pen', ... }
  let hoverPt = null;
  let lastTap = null;
  let snap = null;  // המצב לפני ה-down האחרון — אם מתברר שזו צביטה (אצבע שנייה), חוזרים אליו

  // ---------- המרות קואורדינטות ----------

  const pathMat = (path, off) => multiply(translate(off[0], off[1]), path.transform && !isIdentity(path.transform) ? path.transform : IDENTITY);
  const toLocal = (path, off, wx, wy) => apply(invert(pathMat(path, off)), wx, wy);
  const toScreen = (path, off, p) => {
    const [wx, wy] = apply(pathMat(path, off), p[0], p[1]);
    return [wx * S.view.zoom + S.view.x, wy * S.view.zoom + S.view.y];
  };
  const tol = (e) => (e.pointerType === 'touch' ? 22 : 8);

  function write(path, subs) {
    path.d = serialize(fromNodes(subs));
    delete path.shape; // עריכת נקודות — לא עוד צורה חיה
  }

  // ---------- עט ----------

  function penDown(e, sx, sy, wx, wy) {
    if (!pen) {
      const surface = api.surfaceAt(wx, wy);
      const off = layout(S.doc).get(surface.id);
      const before = JSON.stringify(S.doc);
      const layer = api.targetLayer(surface);
      const path = { id: nextId(S.doc, 'p'), d: 'M0 0', fill: null, stroke: { ...api.DEFAULT_STYLE.stroke } };
      layer.paths.push(path);
      const p = [wx - off[0], wy - off[1]];
      pen = { id: path.id, before, off, subs: [{ closed: false, nodes: [{ p, i: null, o: null }] }] };
      write(path, pen.subs);
      S.sel = new Set([path.id]);
      drag = { kind: 'pen', idx: 0, s0: [sx, sy] };
      api.updateBar();
      return;
    }
    const path = findPath(S.doc, pen.id).path;
    const nodes = pen.subs[0].nodes;
    const first = toScreen(path, pen.off, nodes[0].p);
    if (nodes.length > 1 && Math.hypot(sx - first[0], sy - first[1]) <= tol(e)) {
      pen.subs[0].closed = true;
      write(path, pen.subs);
      drag = { kind: 'pen', idx: 0, s0: [sx, sy], closing: true };
      return;
    }
    nodes.push({ p: toLocal(path, pen.off, wx, wy), i: null, o: null });
    write(path, pen.subs);
    drag = { kind: 'pen', idx: nodes.length - 1, s0: [sx, sy] };
  }

  function penMove(sx, sy, wx, wy) {
    if (!drag || drag.kind !== 'pen') return;
    if (Math.hypot(sx - drag.s0[0], sy - drag.s0[1]) < 4) return;
    const path = findPath(S.doc, pen.id).path;
    const node = pen.subs[0].nodes[drag.idx];
    const h = toLocal(path, pen.off, wx, wy);
    node.o = h;
    node.i = [2 * node.p[0] - h[0], 2 * node.p[1] - h[1]];
    write(path, pen.subs);
  }

  function penUp() {
    const closing = drag?.closing;
    drag = null;
    if (closing) finish();
  }

  /** סיום הנתיב: פחות משתי נקודות — נמחק. נכנס להיסטוריה כפעולה אחת. */
  function finish() {
    if (!pen) return;
    const o = findPath(S.doc, pen.id);
    const nodes = pen.subs[0].nodes;
    if (o && nodes.length < 2) {
      o.layer.paths = o.layer.paths.filter(p => p.id !== pen.id);
      S.sel = new Set();
    }
    const before = pen.before;
    pen = null; drag = null; hoverPt = null;
    api.commitFrom(before);
    api.updateBar();
  }

  function cancel() {
    if (!pen) return;
    S.doc = JSON.parse(pen.before);
    pen = null; drag = null; hoverPt = null;
    S.sel = new Set();
    api.refresh();
    api.updateBar();
  }

  // ---------- עריכת נקודות ----------

  function startEdit(id) {
    const o = findPath(S.doc, id);
    // קבוצה — עורכים אחרי פירוק; טקסט — אין לו נקודות (עד שיהפוך לקווי מתאר)
    if (!o || o.path.type === 'group' || o.path.type === 'text' || o.path.type === 'image') { edit = null; api.updateBar(); return; }
    let subs;
    try { subs = toNodes(parse(o.path.d)); } catch { edit = null; return; }
    edit = { id, subs, node: null };
    S.sel = new Set([id]);
    api.updateBar();
  }

  function editHit(sx, sy, e) {
    const o = findPath(S.doc, edit.id);
    if (!o) return null;
    const off = layout(S.doc).get(o.surface.id);
    const t = tol(e);
    // ידיות של הנקודה שנבחרה ושכנותיה — קודם, כי הן מעל
    for (const [si, ni] of visibleHandles()) {
      const node = edit.subs[si].nodes[ni];
      for (const k of ['i', 'o']) {
        if (!node[k]) continue;
        const [hx, hy] = toScreen(o.path, off, node[k]);
        if (Math.hypot(sx - hx, sy - hy) <= t) return { kind: 'handle', si, ni, k };
      }
    }
    for (let si = 0; si < edit.subs.length; si++) {
      const nodes = edit.subs[si].nodes;
      for (let ni = 0; ni < nodes.length; ni++) {
        const [ax, ay] = toScreen(o.path, off, nodes[ni].p);
        if (Math.hypot(sx - ax, sy - ay) <= t) return { kind: 'anchor', si, ni };
      }
    }
    const [lx, ly] = toLocal(o.path, off, ...api.toWorld(sx, sy));
    const near = nearest(edit.subs, [lx, ly]);
    if (near && near.dist * S.view.zoom <= t) return { kind: 'segment', ...near };
    return null;
  }

  function visibleHandles() {
    if (!edit?.node) return [];
    const [si, ni] = edit.node;
    const sub = edit.subs[si];
    if (!sub) return [];
    const n = sub.nodes.length;
    const out = [[si, ni]];
    if (ni > 0 || sub.closed) out.push([si, (ni - 1 + n) % n]);
    if (ni < n - 1 || sub.closed) out.push([si, (ni + 1) % n]);
    return out;
  }

  function nodeDown(e, sx, sy, wx, wy) {
    const before = JSON.stringify(S.doc);
    if (edit) {
      const hit = editHit(sx, sy, e);
      if (hit) {
        const o = findPath(S.doc, edit.id);
        const off = layout(S.doc).get(o.surface.id);
        if (hit.kind === 'segment') {
          const ni = splitAt(edit.subs[hit.sub], hit.seg, hit.t);
          write(o.path, edit.subs);
          edit.node = [hit.sub, ni];
          api.commitFrom(before);
          drag = { kind: 'anchor', si: hit.sub, ni, before: JSON.stringify(S.doc), off, last: toLocal(o.path, off, wx, wy) };
          api.updateBar();
          return;
        }
        if (hit.kind === 'anchor') {
          // הקשה כפולה על אותה נקודה (גם במגע — ל-pointerdown אין detail אמין) או Alt
          const now = performance.now();
          const dbl = lastTap && lastTap.si === hit.si && lastTap.ni === hit.ni && now - lastTap.t < 350;
          lastTap = { si: hit.si, ni: hit.ni, t: now };
          if (e.altKey || dbl) {
            lastTap = null;
            toggleSmooth(edit.subs[hit.si], hit.ni);
            write(o.path, edit.subs);
            edit.node = [hit.si, hit.ni];
            api.commitFrom(before);
            api.updateBar();
            return;
          }
          edit.node = [hit.si, hit.ni];
          api.updateBar();
        }
        drag = { ...hit, before, off, last: toLocal(o.path, off, wx, wy), alt: e.altKey };
        api.draw();
        return;
      }
    }
    const other = api.hitTest(sx, sy, e.pointerType === 'touch' ? 12 : 5);
    if (other) { startEdit(other); api.refresh(); return; }
    // רקע
    edit = null;
    S.sel = new Set();
    api.refresh();
    api.updateBar();
    return 'background';
  }

  function nodeMove(sx, sy, wx, wy) {
    if (!drag || !edit) return;
    const o = findPath(S.doc, edit.id);
    const p = toLocal(o.path, drag.off, wx, wy);
    const node = edit.subs[drag.si].nodes[drag.ni];
    if (drag.kind === 'anchor') {
      moveNode(node, p[0] - drag.last[0], p[1] - drag.last[1]);
      drag.last = p;
    } else if (drag.kind === 'handle') {
      moveHandle(node, drag.k, p, !drag.alt);
    }
    write(o.path, edit.subs);
    api.draw();
  }

  function nodeUp() {
    if (drag?.before) api.commitFrom(drag.before);
    drag = null;
  }

  function deleteNode() {
    if (!edit?.node) return false;
    const before = JSON.stringify(S.doc);
    const o = findPath(S.doc, edit.id);
    const [si, ni] = edit.node;
    if (removeNode(edit.subs, si, ni)) write(o.path, edit.subs);
    else { o.layer.paths = o.layer.paths.filter(p => p.id !== edit.id); edit = null; S.sel = new Set(); }
    if (edit) edit.node = null;
    api.commitFrom(before);
    api.updateBar();
    return true;
  }

  function toggleSelected() {
    if (!edit?.node) return;
    const before = JSON.stringify(S.doc);
    const [si, ni] = edit.node;
    toggleSmooth(edit.subs[si], ni);
    write(findPath(S.doc, edit.id).path, edit.subs);
    api.commitFrom(before);
  }

  // ---------- שכבת-על ----------

  function overlay(ctx, colors) {
    const id = pen?.id || edit?.id;
    if (!id) return;
    const o = findPath(S.doc, id);
    if (!o) return;
    const off = pen ? pen.off : layout(S.doc).get(o.surface.id);
    const subs = pen ? pen.subs : edit.subs;
    ctx.lineWidth = 1;
    ctx.strokeStyle = colors.brand;

    // קו "גומי" מהנקודה האחרונה לעכבר
    if (pen && hoverPt && !drag) {
      const nodes = subs[0].nodes, last = nodes[nodes.length - 1];
      const a = toScreen(o.path, off, last.p);
      ctx.setLineDash([4, 3]);
      ctx.beginPath(); ctx.moveTo(...a); ctx.lineTo(...hoverPt); ctx.stroke();
      ctx.setLineDash([]);
    }

    const handles = pen ? subs.map((s, si) => [si, s.nodes.length - 1]).concat(drag?.kind === 'pen' ? [[0, drag.idx]] : []) : visibleHandles();
    for (const [si, ni] of handles) {
      const node = subs[si]?.nodes[ni];
      if (!node) continue;
      const a = toScreen(o.path, off, node.p);
      for (const k of ['i', 'o']) {
        if (!node[k]) continue;
        const h = toScreen(o.path, off, node[k]);
        ctx.beginPath(); ctx.moveTo(...a); ctx.lineTo(...h); ctx.stroke();
        ctx.beginPath(); ctx.arc(h[0], h[1], R_HANDLE, 0, Math.PI * 2);
        ctx.fillStyle = colors.brand; ctx.fill();
      }
    }
    subs.forEach((s, si) => s.nodes.forEach((node, ni) => {
      const [x, y] = toScreen(o.path, off, node.p);
      const sel = edit?.node && edit.node[0] === si && edit.node[1] === ni;
      ctx.fillStyle = sel ? colors.brand : '#fff';
      ctx.fillRect(x - R_ANCHOR, y - R_ANCHOR, R_ANCHOR * 2, R_ANCHOR * 2);
      ctx.strokeRect(x - R_ANCHOR + .5, y - R_ANCHOR + .5, R_ANCHOR * 2 - 1, R_ANCHOR * 2 - 1);
    }));
  }

  return {
    get drawing() { return !!pen; },
    get editing() { return edit; },
    down(e, sx, sy, wx, wy) {
      snap = { doc: JSON.stringify(S.doc), pen: pen && JSON.stringify(pen), edit: edit && JSON.stringify(edit), sel: [...S.sel] };
      if (S.tool === 'pen') { penDown(e, sx, sy, wx, wy); api.draw(); return null; }
      return nodeDown(e, sx, sy, wx, wy);
    },
    move(sx, sy, wx, wy) { if (S.tool === 'pen') penMove(sx, sy, wx, wy); else nodeMove(sx, sy, wx, wy); api.draw(); },
    up() { if (S.tool === 'pen') penUp(); else nodeUp(); api.draw(); },
    hover(sx, sy) { if (pen) { hoverPt = [sx, sy]; api.draw(); } },
    /** הכלי התחלף: העט מסיים, עריכה נפתחת על הנבחר. */
    activate(tool) {
      if (tool !== 'pen') finish();
      if (tool === 'node') { const [id] = S.sel; if (S.sel.size === 1) startEdit(id); }
      else edit = null;
      api.updateBar();
    },
    /** מצב חוזר מההיסטוריה — הנקודות נטענות מחדש מה-d. */
    reload() { if (edit) startEdit(edit.id); },
    /** אצבע שנייה הגיעה — מה שה-down הראשון עשה (נקודה בעט, הזזה בעריכה) מתבטל, והציור ממשיך משם. */
    abortDown() {
      drag = null;
      if (!snap) return;
      S.doc = JSON.parse(snap.doc);
      pen = snap.pen && JSON.parse(snap.pen);
      edit = snap.edit && JSON.parse(snap.edit);
      S.sel = new Set(snap.sel);
      snap = null;
      api.refresh();
      api.updateBar();
    },
    /** ↶ בזמן ציור: הנקודה האחרונה (ואם היא היחידה — הנתיב כולו). */
    undoPoint() {
      if (!pen) return;
      const nodes = pen.subs[0].nodes;
      if (nodes.length > 1) { nodes.pop(); write(findPath(S.doc, pen.id).path, pen.subs); api.draw(); }
      else cancel();
    },
    finish, cancel, deleteNode, toggleSelected,
    key(e) {
      const k = e.key;
      if (pen) {
        if (k === 'Enter') { finish(); return true; }
        if (k === 'Escape') { finish(); return true; }
        if ((e.ctrlKey || e.metaKey) && keyOf(e) === 'z') { this.undoPoint(); return true; }
      }
      if (edit && (k === 'Delete' || k === 'Backspace') && edit.node) { deleteNode(); return true; }
      return false;
    },
    overlay,
  };
}
