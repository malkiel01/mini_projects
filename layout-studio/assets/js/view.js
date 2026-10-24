// תצוגת העמודים בקנבס.
//
// הגלילה — של הדפדפן (תיבה עם "מרווח" בגודל המסמך כפול הזום): כך יש תנופה בטלפון, פסי גלילה, ומקלדת.
// הקנבס יושב מעל התיבה בגודל החלון בלבד, ומצייר רק את הפריסות שנראות — כך גם ספר של 600 עמודים מהיר.
// הבמה ב-direction: ltr — חשבון הגלילה פשוט; העמודים עצמם ממוקמים לפי צד הכריכה (pages.js).

import { layoutDoc, contentBox } from './pages.js';

const MIN_Z = 0.1, MAX_Z = 8;

export class PageView {
  constructor(host, { onChange } = {}) {
    this.host = host;
    this.onChange = onChange || (() => {});
    host.classList.add('stage');
    host.innerHTML = '<div class="scroller" tabindex="0" aria-label="העמודים"><div class="spacer"></div></div><canvas aria-hidden="true"></canvas>';
    this.scroller = host.querySelector('.scroller');
    this.spacer = host.querySelector('.spacer');
    this.canvas = host.querySelector('canvas');
    this.ctx = this.canvas.getContext('2d');
    this.zoom = 1;
    this.model = null;
    this.lay = null;
    this.raf = 0;

    this.scroller.addEventListener('scroll', () => this.draw(), { passive: true });
    new ResizeObserver(() => { this.resize(); }).observe(host);
    this.scroller.addEventListener('wheel', e => this.onWheel(e), { passive: false });
    this.bindPinch();
    matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => this.draw());
  }

  setModel(model, { keep = true } = {}) {
    const center = keep && this.lay ? this.docPointAt(this.viewW / 2, this.viewH / 2) : null;
    this.model = model;
    this.lay = layoutDoc(model);
    this.applySize();
    if (center) this.scrollDocTo(center.x, center.y, this.viewW / 2, this.viewH / 2);
    this.draw();
  }

  get viewW() { return this.scroller.clientWidth; }
  get viewH() { return this.scroller.clientHeight; }

  // היסט אופקי כשהמסמך צר מהחלון — ממרכזים
  get offX() { return Math.max(0, (this.viewW - this.lay.width * this.zoom) / 2); }

  applySize() {
    if (!this.lay) return;
    this.spacer.style.width = Math.ceil(this.lay.width * this.zoom) + 'px';
    this.spacer.style.height = Math.ceil(this.lay.height * this.zoom) + 'px';
  }

  resize() {
    const dpr = window.devicePixelRatio || 1;
    const w = this.viewW, h = this.viewH;
    this.canvas.style.width = w + 'px';
    this.canvas.style.height = h + 'px';
    this.canvas.width = Math.round(w * dpr);
    this.canvas.height = Math.round(h * dpr);
    if (this.fitPending && this.lay) { this.fitPending = false; this.fit(); return; }
    this.draw();
  }

  /** נקודה במסמך (pt) מתחת לנקודה בחלון (px). */
  docPointAt(vx, vy) {
    return { x: (this.scroller.scrollLeft + vx - this.offX) / this.zoom, y: (this.scroller.scrollTop + vy) / this.zoom };
  }

  /** גלילה כך שנקודה במסמך תהיה בנקודה בחלון. */
  scrollDocTo(dx, dy, vx, vy) {
    this.scroller.scrollLeft = dx * this.zoom + this.offX - vx;
    this.scroller.scrollTop = dy * this.zoom - vy;
  }

  setZoom(z, vx = this.viewW / 2, vy = this.viewH / 2) {
    z = Math.min(MAX_Z, Math.max(MIN_Z, z));
    if (!this.lay || Math.abs(z - this.zoom) < 1e-4) return;
    const p = this.docPointAt(vx, vy);
    this.zoom = z;
    this.applySize();
    this.scrollDocTo(p.x, p.y, vx, vy);
    this.draw();
  }

  /** זום שמכניס פריסה שלמה לרוחב (ולגובה, אם אפשר). */
  fit() {
    if (!this.lay) return;
    if (!this.viewW) { this.fitPending = true; return; }
    const cur = this.currentSpread();
    const zw = this.viewW / this.lay.width;
    const zh = this.viewH / (this.lay.pageH + 2 * 36);
    this.zoom = Math.min(MAX_Z, Math.max(MIN_Z, Math.min(zw, zh)));
    this.applySize();
    this.goSpread(cur);
  }

  /** הפריסה שהכי הרבה ממנה נראה בחלון. */
  currentSpread() {
    if (!this.lay) return 0;
    const top = this.docPointAt(0, 0).y, bottom = this.docPointAt(0, this.viewH).y, h = this.lay.pageH;
    let best = 0, bestVis = -1;
    this.lay.spreads.forEach((s, i) => {
      const vis = Math.min(bottom, s.y + h) - Math.max(top, s.y);
      if (vis > bestVis + 0.5) { best = i; bestVis = vis; }
    });
    return best;
  }

  goSpread(i) {
    const s = this.lay.spreads;
    i = Math.max(0, Math.min(s.length - 1, i));
    const top = s[i].y - 18;
    // פריסה שנכנסת כולה — במרכז; אחרת — מראשה
    const ph = this.lay.pageH * this.zoom;
    const vy = ph < this.viewH ? (this.viewH - ph) / 2 - 18 * this.zoom : 0;
    this.scroller.scrollTop = top * this.zoom - Math.max(0, vy);
    this.draw();
  }

  onWheel(e) {
    if (!e.ctrlKey && !e.metaKey) return;   // גלילה רגילה — של הדפדפן
    e.preventDefault();
    const r = this.scroller.getBoundingClientRect();
    this.setZoom(this.zoom * Math.exp(-e.deltaY * 0.0025), e.clientX - r.left, e.clientY - r.top);
  }

  // צביטה: שתי אצבעות מגדילות רק את המסמך (touch-action על התיבה חוסם את הזום של הדף)
  bindPinch() {
    let start = null;
    const mid = t => { const r = this.scroller.getBoundingClientRect();
      return { x: (t[0].clientX + t[1].clientX) / 2 - r.left, y: (t[0].clientY + t[1].clientY) / 2 - r.top }; };
    const dist = t => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
    this.scroller.addEventListener('touchstart', e => {
      if (e.touches.length === 2) {
        const m = mid(e.touches);
        start = { d: dist(e.touches), z: this.zoom, p: this.docPointAt(m.x, m.y) };
      }
    }, { passive: true });
    this.scroller.addEventListener('touchmove', e => {
      if (!start || e.touches.length !== 2) return;
      e.preventDefault();
      const m = mid(e.touches);
      this.zoom = Math.min(MAX_Z, Math.max(MIN_Z, start.z * dist(e.touches) / start.d));
      this.applySize();
      this.scrollDocTo(start.p.x, start.p.y, m.x, m.y);
      this.draw();
    }, { passive: false });
    const end = e => { if (e.touches.length < 2) start = null; };
    this.scroller.addEventListener('touchend', end);
    this.scroller.addEventListener('touchcancel', end);
    // Safari במחשב — מחוות צביטה של משטח המגע
    this.scroller.addEventListener('gesturestart', e => e.preventDefault());
  }

  draw() {
    if (this.raf) return;
    this.raf = requestAnimationFrame(() => { this.raf = 0; this.paint(); this.onChange(); });
  }

  paint() {
    const { ctx, canvas, lay, model } = this;
    if (!lay) return;
    const dpr = canvas.width / Math.max(1, this.viewW);
    const css = getComputedStyle(this.host);
    const col = n => css.getPropertyValue(n).trim();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.fillStyle = col('--paste');
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const z = this.zoom, sx = this.scroller.scrollLeft, sy = this.scroller.scrollTop, ox = this.offX;
    ctx.setTransform(dpr * z, 0, 0, dpr * z, dpr * (ox - sx), dpr * -sy);
    const top = sy / z, bottom = (sy + this.viewH) / z;
    const { pageW: w, pageH: h } = lay;
    const px = 1 / z;   // פיקסל אחד במסך, ביחידות המסמך

    for (const s of lay.spreads) {
      if (s.y + h < top - 40 || s.y > bottom + 40) continue;
      // צל ודף
      for (const p of s.pages) {
        ctx.fillStyle = col('--page-shadow');
        ctx.fillRect(p.x + 2 * px, p.y + 2 * px, w, h);
        ctx.fillStyle = col('--page');
        ctx.fillRect(p.x, p.y, w, h);
      }
      // קווי שוליים
      ctx.lineWidth = px;
      ctx.strokeStyle = col('--guide');
      for (const p of s.pages) {
        const [x, y, bw, bh] = contentBox(model, p.num);
        ctx.strokeRect(p.x + x + px / 2, p.y + y + px / 2, bw - px, bh - px);
      }
      // שדרה — קו דק בין שני עמודים
      if (s.pages.length === 2) {
        const xs = Math.max(s.pages[0].x, s.pages[1].x);
        ctx.strokeStyle = col('--spine');
        ctx.beginPath(); ctx.moveTo(xs, s.y); ctx.lineTo(xs, s.y + h); ctx.stroke();
      }
      // מספר עמוד מתחת לדף
      ctx.fillStyle = col('--muted');
      ctx.font = `${12 * px}px system-ui, sans-serif`;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      for (const p of s.pages) ctx.fillText(String(p.num), p.x + w / 2, p.y + h + 6 * px);
    }
  }
}
