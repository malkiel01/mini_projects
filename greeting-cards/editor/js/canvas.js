// הקנבס: מצייר את המסמך ב-Konva, ומטפל בבחירה, גרירה, ידיות, הצמדה, זום ומחוות.
//
// הכלל (spec/03-editor.md, "מחוות וזום"): הצביטה מגדילה רק את הקנבס. ‏touch-action:none רק על אזור
// הקנבס, ושום preventDefault ברמת המסמך. הזום הוא טרנספורמציה של קבוצת "world" בלבד — הערכים במסמך
// תמיד בפיקסלים של הדף, כך שהייצוא זהה בכל זום.

/* global Konva */
import { nearestWeight } from './fonts.js';
import { fillText } from './merge.js';
import { ICONS } from './icons.js';
import { ELEMENTS, scalePath } from './elements.js';

const SNAP_PX = 6;           // מרחק הצמדה, בפיקסלי מסך
const MIN_ZOOM = 0.05, MAX_ZOOM = 8;

Konva.hitOnDragEnabled = true;
Konva.capturePointerEventsEnabled = true;

export class Canvas {
    /**
     * @param {HTMLElement} el
     * @param {{onSelect:Function, onCommit:Function, onEditText:Function, onZoom:Function}} cb
     */
    constructor(el, cb) {
        this.el = el;
        this.cb = cb;
        this.doc = null;
        this.images = new Map();      // assetId → HTMLImageElement
        this.nodes = new Map();       // layerId → Konva.Group
        this.selected = [];
        this.view = { zoom: 1, x: 0, y: 0 };
        this.vars = null;             // שורת נמען בתצוגה מקדימה / בייצוא — {{משתנה}} מוחלף בערך

        this.stage = new Konva.Stage({ container: el, width: el.clientWidth || 300, height: el.clientHeight || 300 });
        this.main = new Konva.Layer();
        this.ui = new Konva.Layer();
        this.stage.add(this.main, this.ui);

        this.world = new Konva.Group();
        this.pageShadow = new Konva.Rect({ fill: '#fff', shadowColor: 'rgba(16,24,40,.25)', shadowBlur: 16, shadowOffsetY: 2, listening: false });
        this.page = new Konva.Group({ name: 'page' });
        this.pageBg = new Konva.Rect({ name: 'page-bg' });
        this.content = new Konva.Group();
        this.page.add(this.pageBg, this.content);
        this.world.add(this.pageShadow, this.page);
        this.main.add(this.world);

        this.guides = new Konva.Group({ listening: false });
        this.tr = new Konva.Transformer({
            rotateAnchorOffset: 28, anchorSize: 14, anchorCornerRadius: 7, borderStroke: '#2d4a8a', anchorStroke: '#2d4a8a',
            anchorFill: '#fff', padding: 2, rotationSnaps: [0, 45, 90, 135, 180, 225, 270, 315], rotationSnapTolerance: 6,
            ignoreStroke: true, flipEnabled: false,
            boundBoxFunc: (oldB, newB) => (Math.abs(newB.width) < 8 || Math.abs(newB.height) < 8 ? oldB : newB),
        });
        this.ui.add(this.guides, this.tr);

        this.bindSelection();
        this.bindGestures();
        this.bindTransform();

        new ResizeObserver(() => this.resize()).observe(el);
    }

    // ─── מסמך ───────────────────────────────────────────────────────────────

    setDoc(doc, { fit = false } = {}) {
        this.doc = doc;
        this.render();
        if (fit) this.fit();
    }

    setImage(assetId, img) {
        this.images.set(assetId, img);
    }

    render() {
        const doc = this.doc;
        if (!doc) return;
        const { width: W, height: H, background } = doc.page;
        this.pageShadow.size({ width: W, height: H });
        this.pageBg.setAttrs({ width: W, height: H, fill: background || '#fff', ...gradientAttrs(doc.page.gradient, W, H) });
        this.page.clip({ x: 0, y: 0, width: W, height: H });

        const ids = new Set(doc.layers.map(l => l.id));
        for (const [id, node] of this.nodes) {
            if (!ids.has(id)) { node.destroy(); this.nodes.delete(id); }
        }
        doc.layers.forEach((l, i) => {
            let g = this.nodes.get(l.id);
            if (!g || g.getAttr('ltype') !== l.type) {
                g?.destroy();
                g = new Konva.Group({ id: l.id, name: 'layer' });
                g.setAttr('ltype', l.type);
                this.bindNode(g);
                this.content.add(g);
                this.nodes.set(l.id, g);
            }
            this.paint(g, l);
            g.zIndex(i);
        });
        this.selected = this.selected.filter(id => ids.has(id));
        this.refreshTransformer();
        this.main.batchDraw();
    }

    /** בונה מחדש את תוכן השכבה. פשוט יותר מעדכון חלקי, ומהיר מספיק לעשרות שכבות. */
    paint(g, l) {
        g.setAttrs({
            x: l.x, y: l.y, rotation: l.rotation || 0, opacity: l.opacity ?? 1, visible: !l.hidden,
            draggable: !l.locked, scaleX: 1, scaleY: 1,
        });
        g.destroyChildren();
        g.clipFunc(null);
        const shadow = l.shadow ? { shadowColor: l.shadow.color, shadowBlur: l.shadow.blur, shadowOffsetX: l.shadow.x, shadowOffsetY: l.shadow.y } : {};

        if (l.type === 'text') {
            const s = l.style;
            const t = new Konva.Text({
                name: 'text', text: this.vars ? fillText(l.text, this.vars) : l.text, width: l.w, fontFamily: `"${s.font}"`,
                fontStyle: `${s.italic ? 'italic ' : ''}${nearestWeight(s.font, s.weight)}`, fontSize: s.size,
                fill: s.color, align: s.align === 'justify' ? 'justify' : s.align, direction: s.dir === 'ltr' ? 'ltr' : 'rtl',
                lineHeight: s.lineHeight || 1.2, wrap: 'word',
                stroke: s.stroke?.width ? s.stroke.color : undefined, strokeWidth: s.stroke?.width || 0, fillAfterStrokeEnabled: true,
                ...(s.shadow ? { shadowColor: s.shadow.color, shadowBlur: s.shadow.blur, shadowOffsetX: s.shadow.x, shadowOffsetY: s.shadow.y } : {}),
            });
            // הגובה של טקסט נגזר מהתוכן; הוא נשמר במסמך כדי שהייבוא והייצוא יראו אותו דבר.
            // בתצוגת נמען — לא: הגובה של התבנית הוא מה שנשמר.
            if (!this.vars) l.h = t.height();
            else g.setAttr('filledH', t.height());
            if (s.background) {
                const p = s.background.padding || 0;
                g.add(new Konva.Rect({ x: -p, y: -p, width: l.w + 2 * p, height: t.height() + 2 * p, fill: s.background.color, cornerRadius: s.background.radius || 0 }));
            }
            g.add(t);
        } else if (l.type === 'image') {
            const img = this.images.get(l.asset);
            const node = img
                ? new Konva.Image({ image: img, width: l.w, height: l.h, ...shadow,
                    // חיתוך: שברים (0–1) מהתמונה המקורית — נשארים נכונים גם אם התמונה הוחלפה בגודל אחר.
                    ...(l.crop ? { crop: { x: l.crop.x * img.naturalWidth, y: l.crop.y * img.naturalHeight, width: l.crop.w * img.naturalWidth, height: l.crop.h * img.naturalHeight } } : {}) })
                : new Konva.Rect({ width: l.w, height: l.h, fill: '#e3e6eb', stroke: '#9aa1b2', dash: [8, 6] });
            if (l.flipX) node.setAttrs({ scaleX: -1, x: l.w });
            if (l.flipY) node.setAttrs({ scaleY: -1, y: l.h });
            if (img && l.filters && (l.filters.brightness || l.filters.contrast || l.filters.saturation || l.filters.blur)) {
                const f = l.filters;
                node.filters([Konva.Filters.Brighten, Konva.Filters.Contrast, Konva.Filters.HSL, Konva.Filters.Blur]);
                node.cache({ pixelRatio: Math.min(1, 1600 / Math.max(l.w, l.h)) });
                node.setAttrs({ brightness: f.brightness || 0, contrast: f.contrast || 0, saturation: f.saturation || 0, blurRadius: f.blur || 0 });
            }
            if (l.mask === 'circle') {
                g.clipFunc(ctx => { ctx.ellipse(l.w / 2, l.h / 2, l.w / 2, l.h / 2, 0, 0, Math.PI * 2); });
            } else if (l.mask === 'rounded') {
                const r = Math.min(l.w, l.h) * 0.12;
                g.clipFunc(ctx => { ctx.roundRect(0, 0, l.w, l.h, r); });
            }
            g.add(node);
        } else if (l.type === 'icon') {
            const ic = ICONS[l.icon] || ICONS.star;
            const filled = l.filled && ic.filled;
            const k = Math.min(l.w, l.h) / 24;
            g.add(new Konva.Path({
                data: filled ? ic.filled : ic.d, x: (l.w - 24 * k) / 2, y: (l.h - 24 * k) / 2, scaleX: k, scaleY: k,
                stroke: filled ? undefined : l.color, strokeWidth: l.strokeWidth || 2, fill: filled ? l.color : undefined,
                lineCap: 'round', lineJoin: 'round', ...shadow,
            }));
            g.add(new Konva.Rect({ width: l.w, height: l.h, fill: 'transparent', name: 'hit' }));
        } else if (l.type === 'shape') {
            const st = { fill: l.fill, stroke: l.stroke?.width ? l.stroke.color : undefined, strokeWidth: l.stroke?.width || 0, ...shadow };
            let node;
            switch (l.shape) {
                case 'element': {
                    const el = ELEMENTS[l.element] || ELEMENTS['badge-scallop'];
                    node = new Konva.Path({ data: scalePath(el.d, l.w / el.vb[0], l.h / el.vb[1]), ...st, ...gradientAttrs(l.gradient, l.w, l.h), lineCap: 'round', lineJoin: 'round', fillRule: 'nonzero' });
                    break;
                }
                case 'ellipse': node = new Konva.Ellipse({ x: l.w / 2, y: l.h / 2, radiusX: l.w / 2, radiusY: l.h / 2, ...st, ...gradientAttrs(l.gradient, l.w, l.h, -l.w / 2, -l.h / 2) }); break;
                case 'star': node = new Konva.Star({ x: l.w / 2, y: l.h / 2, numPoints: l.points || 5, innerRadius: 0.4, outerRadius: 1, scaleX: l.w / 2, scaleY: l.h / 2, ...st, strokeScaleEnabled: false }); break;
                case 'polygon': node = new Konva.RegularPolygon({ x: l.w / 2, y: l.h / 2, sides: l.points || 6, radius: 1, scaleX: l.w / 2, scaleY: l.h / 2, ...st, strokeScaleEnabled: false }); break;
                case 'arrow': node = new Konva.Arrow({ points: [0, l.h / 2, l.w, l.h / 2], pointerLength: l.h, pointerWidth: l.h, strokeWidth: l.h / 3, stroke: l.fill, fill: l.fill }); break;
                default: node = new Konva.Rect({ width: l.w, height: l.h, cornerRadius: Math.min(l.radius || 0, l.w / 2, l.h / 2), ...st, ...gradientAttrs(l.gradient, l.w, l.h) });
            }
            g.add(node);
            // אזור לחיצה מלא, גם לצורה דקה או לחץ.
            g.add(new Konva.Rect({ width: l.w, height: l.h, fill: 'transparent', name: 'hit' }));
        }
        // היפוך של צורה או אייקון (בתמונה ההיפוך כבר על הצומת עצמו).
        if ((l.flipX || l.flipY) && (l.type === 'shape' || l.type === 'icon')) {
            const inner = new Konva.Group({ scaleX: l.flipX ? -1 : 1, x: l.flipX ? l.w : 0, scaleY: l.flipY ? -1 : 1, y: l.flipY ? l.h : 0 });
            g.getChildren().slice().forEach(k => k.moveTo(inner));
            g.add(inner);
        }
    }

    layerOf(node) {
        const g = node.findAncestor('.layer', true);
        return g ? this.doc.layers.find(l => l.id === g.id()) : null;
    }

    // ─── בחירה ──────────────────────────────────────────────────────────────

    select(ids, { silent = false } = {}) {
        this.selected = ids.filter(id => this.nodes.has(id));
        this.refreshTransformer();
        if (!silent) this.cb.onSelect?.(this.selected.slice());
    }

    refreshTransformer() {
        const nodes = this.selected.map(id => this.nodes.get(id)).filter(Boolean);
        const layers = this.selected.map(id => this.doc?.layers.find(l => l.id === id)).filter(Boolean);
        const anyLocked = layers.some(l => l.locked);
        const onlyText = layers.length === 1 && layers[0].type === 'text';
        this.tr.setAttrs({
            resizeEnabled: !anyLocked, rotateEnabled: !anyLocked,
            enabledAnchors: onlyText
                ? ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'middle-left', 'middle-right']
                : ['top-left', 'top-center', 'top-right', 'middle-left', 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right'],
            keepRatio: true,
            borderStrokeWidth: anyLocked ? 1 : 1.5,
            borderDash: anyLocked ? [4, 4] : undefined,
        });
        this.tr.nodes(nodes);
        this.ui.batchDraw();
    }

    bindSelection() {
        this.stage.on('click tap', e => {
            if (this.gesture?.moved) return;
            const l = this.layerOf(e.target);
            const multi = e.evt.shiftKey || e.evt.ctrlKey || e.evt.metaKey;
            // שכבה נעולה (כמו רקע מהצ'אט) לא נבחרת בהקשה על הקנבס — רק מרשימת השכבות.
            if (!l || l.locked) { if (!multi) this.select([]); return; }
            if (multi) {
                this.select(this.selected.includes(l.id) ? this.selected.filter(i => i !== l.id) : [...this.selected, l.id]);
            } else if (!(this.selected.length === 1 && this.selected[0] === l.id)) {
                this.select([l.id]);
            }
        });
        this.stage.on('dblclick dbltap', e => {
            const l = this.layerOf(e.target);
            if (l && l.type === 'text' && !l.locked) this.cb.onEditText?.(l);
        });
    }

    bindNode(g) {
        g.on('dragstart', () => {
            const id = g.id();
            if (!this.selected.includes(id)) this.select([id]);
        });
        g.on('dragmove', () => this.snap(g));
        g.on('dragend', () => {
            this.guides.destroyChildren();
            this.ui.batchDraw();
            for (const n of this.tr.nodes().length ? this.tr.nodes() : [g]) {
                const l = this.doc.layers.find(x => x.id === n.id());
                if (l) { l.x = n.x(); l.y = n.y(); }
            }
            this.cb.onCommit?.();
        });
    }

    bindTransform() {
        // טקסט: ידית צד משנה את רוחב התיבה (לא מותחת אותיות); ידית פינה מגדילה את הפונט.
        this.tr.on('transform', () => {
            const anchor = this.tr.getActiveAnchor();
            if (!['middle-left', 'middle-right'].includes(anchor)) return;
            for (const g of this.tr.nodes()) {
                const l = this.doc.layers.find(x => x.id === g.id());
                if (l?.type !== 'text') continue;
                l.w = Math.max(20, l.w * g.scaleX());
                l.x = g.x(); l.y = g.y();
                this.paint(g, l);
            }
        });
        this.tr.on('transformend', () => {
            for (const g of this.tr.nodes()) {
                const l = this.doc.layers.find(x => x.id === g.id());
                if (!l) continue;
                const sx = Math.abs(g.scaleX()), sy = Math.abs(g.scaleY());
                l.x = g.x(); l.y = g.y(); l.rotation = Math.round(g.rotation() * 100) / 100;
                if (l.type === 'text') {
                    if (Math.abs(sx - 1) > 1e-3 || Math.abs(sy - 1) > 1e-3) {
                        l.style.size = Math.max(1, Math.round(l.style.size * sy * 10) / 10);
                        l.w = Math.max(20, l.w * sx);
                    }
                } else {
                    l.w = Math.max(1, l.w * sx);
                    l.h = Math.max(1, l.h * sy);
                }
                this.paint(g, l);
            }
            this.refreshTransformer();
            this.main.batchDraw();
            this.cb.onCommit?.();
        });
    }

    // ─── הצמדה וקווי עזר ────────────────────────────────────────────────────

    snap(g) {
        if (this.noSnap) return;
        const W = this.doc.page.width, H = this.doc.page.height;
        const thr = SNAP_PX / this.view.zoom;
        const box = g.getClientRect({ relativeTo: this.page });
        const xs = [0, W / 2, W], ys = [0, H / 2, H];
        for (const [id, n] of this.nodes) {
            if (id === g.id() || this.selected.includes(id) || !n.visible()) continue;
            const r = n.getClientRect({ relativeTo: this.page });
            xs.push(r.x, r.x + r.width / 2, r.x + r.width);
            ys.push(r.y, r.y + r.height / 2, r.y + r.height);
        }
        const best = (vals, edges) => {
            let hit = null;
            for (const e of edges) for (const v of vals) {
                const d = v - e.v;
                if (Math.abs(d) < thr && (!hit || Math.abs(d) < Math.abs(hit.d))) hit = { d, at: v };
            }
            return hit;
        };
        const hx = best(xs, [{ v: box.x }, { v: box.x + box.width / 2 }, { v: box.x + box.width }]);
        const hy = best(ys, [{ v: box.y }, { v: box.y + box.height / 2 }, { v: box.y + box.height }]);
        if (hx) g.x(g.x() + hx.d);
        if (hy) g.y(g.y() + hy.d);

        this.guides.destroyChildren();
        const sw = 1 / this.view.zoom;
        if (hx) this.guides.add(new Konva.Line({ points: [hx.at, -H, hx.at, 2 * H], stroke: '#e0457b', strokeWidth: sw }));
        if (hy) this.guides.add(new Konva.Line({ points: [-W, hy.at, 2 * W, hy.at], stroke: '#e0457b', strokeWidth: sw }));
        this.ui.batchDraw();
    }

    // ─── זום ותזוזה ─────────────────────────────────────────────────────────

    resize() {
        const w = this.el.clientWidth, h = this.el.clientHeight;
        if (!w || !h) return;
        const first = this.stage.width() !== w || this.stage.height() !== h;
        this.stage.size({ width: w, height: h });
        if (first && this.fitted !== false) this.fit();
        this.main.batchDraw();
    }

    applyView() {
        const { zoom, x, y } = this.view;
        this.world.setAttrs({ x, y, scaleX: zoom, scaleY: zoom });
        this.guides.setAttrs({ x, y, scaleX: zoom, scaleY: zoom });
        this.tr.forceUpdate();
        this.main.batchDraw();
        this.ui.batchDraw();
        this.cb.onZoom?.(zoom);
    }

    /** "התאם למסך": כל הדף גלוי, עם שוליים. */
    fit() {
        if (!this.doc) return;
        const m = 24, W = this.doc.page.width, H = this.doc.page.height;
        const cw = this.stage.width(), ch = this.stage.height();
        const zoom = Math.max(MIN_ZOOM, Math.min((cw - 2 * m) / W, (ch - 2 * m) / H));
        this.view = { zoom, x: (cw - W * zoom) / 2, y: (ch - H * zoom) / 2 };
        this.fitted = true;
        this.applyView();
    }

    /** זום סביב נקודה במסך (ברירת מחדל — מרכז). */
    zoomTo(zoom, center) {
        zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom));
        const c = center || { x: this.stage.width() / 2, y: this.stage.height() / 2 };
        const v = this.view;
        const px = (c.x - v.x) / v.zoom, py = (c.y - v.y) / v.zoom;
        this.view = { zoom, x: c.x - px * zoom, y: c.y - py * zoom };
        this.fitted = false;
        this.applyView();
    }

    zoomBy(f, center) { this.zoomTo(this.view.zoom * f, center); }

    /** 100% = פיקסל של הדף על פיקסל CSS. */
    actualSize() { this.zoomTo(1); }

    panBy(dx, dy) {
        this.view.x += dx;
        this.view.y += dy;
        this.fitted = false;
        this.applyView();
    }

    bindGestures() {
        const el = this.el;
        const pts = new Map();
        let pinch = null, pan = null;

        // גלגלת: גלילה מזיזה את התצוגה; Ctrl/⌘ + גלגלת (וגם צביטה במשטח מגע) — זום סביב העכבר.
        el.addEventListener('wheel', e => {
            e.preventDefault();
            const r = el.getBoundingClientRect();
            const c = { x: e.clientX - r.left, y: e.clientY - r.top };
            if (e.ctrlKey || e.metaKey) this.zoomBy(Math.exp(-e.deltaY * 0.01), c);
            else this.panBy(-e.deltaX, -e.deltaY);
        }, { passive: false });

        const local = e => { const r = el.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };

        el.addEventListener('pointerdown', e => {
            pts.set(e.pointerId, local(e));
            if (pts.size === 2) {
                // אצבע שנייה: מפסיקים כל גרירה של שכבה, ועוברים לזום של הקנבס.
                this.stage.find('.layer').forEach(n => n.isDragging() && n.stopDrag());
                const [a, b] = [...pts.values()];
                pinch = { dist: Math.hypot(a.x - b.x, a.y - b.y), mid: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }, zoom: this.view.zoom };
                pan = null;
                this.gesture = { moved: true };
            } else if (pts.size === 1) {
                this.gesture = { moved: false };
                // הזזת התצוגה מתחילה רק על שטח ריק או על שכבה נעולה.
                const shape = this.stage.getIntersection(local(e));
                const l = shape ? this.layerOf(shape) : null;
                const onTr = shape && shape.getParent() === this.tr;
                if (!onTr && (!l || l.locked)) pan = { last: local(e), start: local(e) };
            }
        }, true);

        el.addEventListener('pointermove', e => {
            if (!pts.has(e.pointerId)) return;
            pts.set(e.pointerId, local(e));
            if (pinch && pts.size >= 2) {
                const [a, b] = [...pts.values()];
                const dist = Math.hypot(a.x - b.x, a.y - b.y);
                const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
                this.zoomTo(pinch.zoom * (dist / pinch.dist), pinch.mid);
                this.panBy(mid.x - pinch.mid.x, mid.y - pinch.mid.y);
                pinch.mid = mid;
                pinch.zoom = this.view.zoom;
                pinch.dist = dist;
            } else if (pan) {
                const p = local(e);
                if (Math.hypot(p.x - pan.start.x, p.y - pan.start.y) > 4) this.gesture.moved = true;
                this.panBy(p.x - pan.last.x, p.y - pan.last.y);
                pan.last = p;
            }
        }, true);

        const up = e => {
            pts.delete(e.pointerId);
            if (pts.size < 2) pinch = null;
            if (pts.size === 0) {
                pan = null;
                // ה-click/tap של Konva מגיע אחרי pointerup; ‏moved נשמר עד שהוא עובר.
                setTimeout(() => { if (pts.size === 0) this.gesture = null; }, 0);
            }
        };
        el.addEventListener('pointerup', up, true);
        el.addEventListener('pointercancel', up, true);
    }

    // ─── מיקום על המסך (לעריכת טקסט במקום) ───────────────────────────────────

    screenRect(id) {
        const g = this.nodes.get(id);
        if (!g) return null;
        const p = g.getAbsolutePosition();
        return { x: p.x, y: p.y, zoom: this.view.zoom, rotation: g.getAbsoluteRotation() };
    }

    hideNode(id, hidden) {
        const g = this.nodes.get(id);
        if (g) { g.visible(!hidden); this.tr.visible(!hidden); this.main.batchDraw(); this.ui.batchDraw(); }
    }

    // ─── ייצוא ──────────────────────────────────────────────────────────────

    /**
     * קנבס של הדף בלבד, בקנה מידה של הדף (1 = פיקסל אמיתי). נגזר מהמסמך ולא מהמסך — לכן אין הבדל
     * בין מה שרואים בכל זום לבין הקובץ. בלי ידיות ובלי קווי עזר (הם בשכבה אחרת).
     */
    pageCanvas(scale = 1) {
        const { width: W, height: H } = this.doc.page;
        const p = this.page.getAbsolutePosition();
        const z = this.view.zoom;
        return this.page.toCanvas({ x: p.x, y: p.y, width: W * z, height: H * z, pixelRatio: scale / z });
    }
}

/**
 * מדרג (page.gradient או gradient של צורה) — לתכונות של Konva. הזווית כמו ב-CSS: ‏0 — כלפי מעלה,
 * ‏90 — ימינה, ‏180 — כלפי מטה. ‏ox/oy — היכן ראשית הצורה ביחס לפינה (באליפסה — המרכז).
 */
export function gradientAttrs(g, w, h, ox = 0, oy = 0) {
    if (!g || !Array.isArray(g.colors) || g.colors.length < 2) return { fillPriority: 'color' };   // גם מחזיר רקע שהמדרג שלו הוסר
    const stops = g.colors.flatMap((c, i) => [i / (g.colors.length - 1), c]);
    const cx = w / 2 + ox, cy = h / 2 + oy;
    if (g.type === 'radial') {
        return { fillPriority: 'radial-gradient', fillRadialGradientStartPoint: { x: cx, y: cy }, fillRadialGradientEndPoint: { x: cx, y: cy },
            fillRadialGradientStartRadius: 0, fillRadialGradientEndRadius: Math.hypot(w, h) / 2, fillRadialGradientColorStops: stops };
    }
    const a = ((g.angle ?? 180) * Math.PI) / 180;
    const dx = Math.sin(a), dy = -Math.cos(a);
    const half = (Math.abs(w * dx) + Math.abs(h * dy)) / 2;
    return { fillPriority: 'linear-gradient', fillLinearGradientStartPoint: { x: cx - dx * half, y: cy - dy * half },
        fillLinearGradientEndPoint: { x: cx + dx * half, y: cy + dy * half }, fillLinearGradientColorStops: stops };
}
