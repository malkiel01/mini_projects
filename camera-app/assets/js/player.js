/**
 * הנגן. אחד לכול: הקלטה, צילום, ושידור חי (HLS דרך hls.js).
 *
 * מה שיש בו ואין ב-<video controls>: מהירויות 0.25–16, פריים-פריים,
 * זום דיגיטלי עם גרירה וצביטה, צילום מסך מהפריים הנוכחי, סימניות על
 * פס הגלילה, וקיצורי מקלדת כמו בעורכי וידאו (J/K/L, פסיק/נקודה).
 */

import { esc, fmtClock } from './app.js';

const FRAME = 1 / 25;
const SPEEDS = [0.25, 0.5, 1, 1.5, 2, 4, 8, 16];

export function createPlayer(host, opts = {}) {
  const el = document.createElement('div');
  el.className = 'player';
  el.innerHTML = `
    <div class="player__stage" tabindex="0">
      <video playsinline preload="metadata" ${opts.muted === false ? '' : 'muted'}></video>
      <img alt="" hidden>
      <div class="player__overlay" hidden></div>
      <div class="player__live" hidden>● חי</div>
      <div class="player__zoom" hidden>×1.0</div>
    </div>
    <div class="player__bar">
      <button class="btn btn--icon" data-play type="button" title="נגן/עצור (רווח)">▶</button>
      <button class="btn btn--icon" data-fprev type="button" title="פריים אחורה (,)">⏮</button>
      <button class="btn btn--icon" data-fnext type="button" title="פריים קדימה (.)">⏭</button>
      <span class="player__time" data-time>0:00 / 0:00</span>
      <div class="scrub" data-scrub><div class="scrub__track"></div><div class="scrub__fill"></div><div class="scrub__knob" style="left:0"></div></div>
      <select class="speed" data-speed title="מהירות">${SPEEDS.map((s) => `<option value="${s}" ${s === 1 ? 'selected' : ''}>×${s}</option>`).join('')}</select>
      <button class="btn btn--icon" data-mute type="button" title="שמע (M)">🔇</button>
      <button class="btn btn--icon" data-shot type="button" title="צילום מסך (S)" hidden>📷</button>
      <button class="btn btn--icon" data-zoomreset type="button" title="איפוס זום (0)" hidden>⤢</button>
      <button class="btn btn--icon" data-fs type="button" title="מסך מלא (F)">⛶</button>
    </div>`;
  host.appendChild(el);

  const stage = el.querySelector('.player__stage');
  const video = el.querySelector('video');
  const img = el.querySelector('img');
  const overlay = el.querySelector('.player__overlay');
  const liveBadge = el.querySelector('.player__live');
  const zoomLabel = el.querySelector('.player__zoom');
  const bar = el.querySelector('.player__bar');
  const btnPlay = el.querySelector('[data-play]');
  const timeEl = el.querySelector('[data-time]');
  const scrub = el.querySelector('[data-scrub]');
  const fill = el.querySelector('.scrub__fill');
  const knob = el.querySelector('.scrub__knob');
  const speedSel = el.querySelector('[data-speed]');
  const btnMute = el.querySelector('[data-mute]');
  const btnShot = el.querySelector('[data-shot]');
  const btnZoomReset = el.querySelector('[data-zoomreset]');

  let kind = 'video';
  let hls = null;
  let bookmarks = [];
  let destroyed = false;

  /* ───── מקור ───── */

  function showOverlay(html) { overlay.innerHTML = html; overlay.hidden = !html; }

  function setSrc(src, k = 'video', extra = {}) {
    kind = k;
    if (hls) { hls.destroy(); hls = null; }
    video.pause();
    video.removeAttribute('src'); video.load();
    img.hidden = true; video.hidden = false;
    liveBadge.hidden = k !== 'live';
    bar.hidden = false;
    showOverlay('');
    resetZoom();
    btnShot.hidden = !opts.onScreenshot || k === 'image';
    if (extra.poster) video.poster = extra.poster; else video.removeAttribute('poster');

    if (k === 'image') {
      video.hidden = true; img.hidden = false; img.src = src; bar.hidden = true;
      return;
    }
    if (k === 'live') {
      speedSel.value = '1'; video.playbackRate = 1;
      if (window.Hls && window.Hls.isSupported()) {
        hls = new window.Hls({ liveSyncDurationCount: 3, liveMaxLatencyDurationCount: 8, enableWorker: true });
        hls.loadSource(src);
        hls.attachMedia(video);
        hls.on(window.Hls.Events.MANIFEST_PARSED, () => { video.play().catch(() => {}); });
        hls.on(window.Hls.Events.ERROR, (_e, data) => {
          if (!data.fatal) return;
          if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) hls.startLoad();
          else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) hls.recoverMediaError();
          else showOverlay('<b>השידור נקטע</b>מנסה שוב…');
        });
      } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = src; video.play().catch(() => {});
      } else {
        showOverlay('<b>הדפדפן אינו תומך בשידור חי</b>hls.js לא נטען');
      }
      return;
    }
    video.src = src;
    if (extra.autoplay) video.play().catch(() => {});
  }

  function setBookmarks(list) {
    bookmarks = list || [];
    scrub.querySelectorAll('.scrub__mark').forEach((m) => m.remove());
    drawMarks();
  }
  function drawMarks() {
    if (!video.duration || !isFinite(video.duration)) return;
    scrub.querySelectorAll('.scrub__mark').forEach((m) => m.remove());
    for (const b of bookmarks) {
      const m = document.createElement('div');
      m.className = 'scrub__mark';
      m.style.left = (100 * b.at_seconds / video.duration) + '%';
      m.title = b.note || fmtClock(b.at_seconds);
      scrub.insertBefore(m, knob);
    }
  }

  /* ───── בקרה ───── */

  function togglePlay() { if (kind === 'image') return; if (video.paused) video.play().catch(() => {}); else video.pause(); }
  function frameStep(n) { if (kind !== 'video') return; video.pause(); video.currentTime = Math.max(0, Math.min(video.duration || 0, video.currentTime + n * FRAME)); }
  function seekBy(s) { if (kind !== 'video') return; video.currentTime = Math.max(0, Math.min(video.duration || 0, video.currentTime + s)); }
  function setSpeed(s) { s = Math.max(0.25, Math.min(16, s)); video.playbackRate = s; speedSel.value = String(SPEEDS.reduce((a, b) => Math.abs(b - s) < Math.abs(a - s) ? b : a)); }
  function stepSpeed(dir) { const i = SPEEDS.indexOf(+speedSel.value); setSpeed(SPEEDS[Math.max(0, Math.min(SPEEDS.length - 1, i + dir))]); }

  btnPlay.onclick = togglePlay;
  el.querySelector('[data-fprev]').onclick = () => frameStep(-1);
  el.querySelector('[data-fnext]').onclick = () => frameStep(1);
  speedSel.onchange = () => { video.playbackRate = +speedSel.value; };
  btnMute.onclick = () => { video.muted = !video.muted; btnMute.textContent = video.muted ? '🔇' : '🔊'; };
  el.querySelector('[data-fs]').onclick = () => {
    if (document.fullscreenElement) document.exitFullscreen();
    else el.requestFullscreen?.();
  };
  btnShot.onclick = () => screenshot();
  btnZoomReset.onclick = resetZoom;

  video.addEventListener('play', () => { btnPlay.textContent = '⏸'; });
  video.addEventListener('pause', () => { btnPlay.textContent = '▶'; });
  video.addEventListener('ended', () => { btnPlay.textContent = '▶'; opts.onEnded?.(); });
  video.addEventListener('loadedmetadata', () => { drawMarks(); updateTime(); });
  video.addEventListener('timeupdate', updateTime);
  video.addEventListener('error', () => {
    if (kind === 'video') showOverlay('<b>לא ניתן לנגן</b>הקובץ חסר או שהדפדפן אינו תומך בקידוד (H.265?)');
  });
  stage.addEventListener('click', (e) => { if (e.target === video && !dragging) togglePlay(); });
  stage.addEventListener('dblclick', () => el.querySelector('[data-fs]').click());

  function updateTime() {
    if (kind === 'live') { timeEl.textContent = 'חי'; fill.style.width = '100%'; knob.style.left = '100%'; return; }
    const d = video.duration && isFinite(video.duration) ? video.duration : 0;
    timeEl.textContent = fmtClock(video.currentTime) + ' / ' + fmtClock(d);
    const p = d ? (100 * video.currentTime / d) : 0;
    fill.style.width = p + '%'; knob.style.left = p + '%';
    opts.onTime?.(video.currentTime);
  }

  // פס גלילה: לחיצה וגרירה.
  let scrubbing = false;
  const scrubTo = (e) => {
    if (kind !== 'video' || !video.duration) return;
    const r = scrub.getBoundingClientRect();
    const x = Math.max(0, Math.min(1, (e.clientX - r.left) / r.width));
    video.currentTime = x * video.duration;
  };
  scrub.addEventListener('pointerdown', (e) => { scrubbing = true; scrub.setPointerCapture(e.pointerId); scrubTo(e); });
  scrub.addEventListener('pointermove', (e) => { if (scrubbing) scrubTo(e); });
  scrub.addEventListener('pointerup', () => { scrubbing = false; });

  /* ───── זום דיגיטלי ───── */

  let scale = 1, tx = 0, ty = 0;
  let dragging = false, dragStart = null;
  const pointers = new Map();
  let pinchStart = null;

  function applyZoom() {
    const target = kind === 'image' ? img : video;
    if (scale <= 1.001) { scale = 1; tx = 0; ty = 0; }
    const w = stage.clientWidth, h = stage.clientHeight;
    tx = Math.min(0, Math.max(w - w * scale, tx));
    ty = Math.min(0, Math.max(h - h * scale, ty));
    target.style.transform = scale === 1 ? '' : `translate(${tx}px, ${ty}px) scale(${scale})`;
    zoomLabel.textContent = '×' + scale.toFixed(1);
    zoomLabel.hidden = scale === 1;
    btnZoomReset.hidden = scale === 1;
  }
  function zoomAt(factor, cx, cy) {
    const ns = Math.max(1, Math.min(8, scale * factor));
    // הנקודה מתחת לסמן נשארת במקום.
    tx = cx - (cx - tx) * (ns / scale);
    ty = cy - (cy - ty) * (ns / scale);
    scale = ns;
    applyZoom();
  }
  function resetZoom() { scale = 1; tx = 0; ty = 0; applyZoom(); }

  stage.addEventListener('wheel', (e) => {
    e.preventDefault();
    const r = stage.getBoundingClientRect();
    zoomAt(e.deltaY < 0 ? 1.2 : 1 / 1.2, e.clientX - r.left, e.clientY - r.top);
  }, { passive: false });

  stage.addEventListener('pointerdown', (e) => {
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 2) {
      const [a, b] = [...pointers.values()];
      pinchStart = { d: Math.hypot(a.x - b.x, a.y - b.y), scale, cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2 };
    } else if (scale > 1) {
      dragStart = { x: e.clientX, y: e.clientY, tx, ty };
      stage.setPointerCapture(e.pointerId);
    }
  });
  stage.addEventListener('pointermove', (e) => {
    if (!pointers.has(e.pointerId)) return;
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 2 && pinchStart) {
      const [a, b] = [...pointers.values()];
      const d = Math.hypot(a.x - b.x, a.y - b.y);
      const r = stage.getBoundingClientRect();
      const f = (pinchStart.scale * d / pinchStart.d) / scale;
      zoomAt(f, pinchStart.cx - r.left, pinchStart.cy - r.top);
    } else if (dragStart) {
      if (Math.hypot(e.clientX - dragStart.x, e.clientY - dragStart.y) > 4) dragging = true;
      tx = dragStart.tx + (e.clientX - dragStart.x);
      ty = dragStart.ty + (e.clientY - dragStart.y);
      applyZoom();
    }
  });
  const endPointer = (e) => {
    pointers.delete(e.pointerId);
    if (pointers.size < 2) pinchStart = null;
    dragStart = null;
    setTimeout(() => { dragging = false; }, 0);
  };
  stage.addEventListener('pointerup', endPointer);
  stage.addEventListener('pointercancel', endPointer);

  /* ───── צילום מסך ───── */

  function screenshot() {
    if (!opts.onScreenshot || kind === 'image') return;
    if (!video.videoWidth) return;
    const c = document.createElement('canvas');
    c.width = video.videoWidth; c.height = video.videoHeight;
    try {
      c.getContext('2d').drawImage(video, 0, 0);
      c.toBlob((blob) => { if (blob) opts.onScreenshot(blob, video.currentTime); }, 'image/jpeg', 0.92);
    } catch (err) {
      showOverlay('<b>לא ניתן לצלם</b>' + esc(err.message));
      setTimeout(() => showOverlay(''), 2500);
    }
  }

  /* ───── מקלדת ───── */

  function onKey(e) {
    if (destroyed) return;
    const t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    switch (e.key) {
      case ' ': case 'k': case 'K': e.preventDefault(); togglePlay(); break;
      case 'ArrowLeft': e.preventDefault(); seekBy(-5); break;
      case 'ArrowRight': e.preventDefault(); seekBy(5); break;
      case ',': frameStep(-1); break;
      case '.': frameStep(1); break;
      case 'j': case 'J': stepSpeed(-1); break;
      case 'l': case 'L': stepSpeed(1); break;
      case '+': case '=': zoomAt(1.25, stage.clientWidth / 2, stage.clientHeight / 2); break;
      case '-': zoomAt(1 / 1.25, stage.clientWidth / 2, stage.clientHeight / 2); break;
      case '0': resetZoom(); break;
      case 's': case 'S': screenshot(); break;
      case 'f': case 'F': el.querySelector('[data-fs]').click(); break;
      case 'm': case 'M': btnMute.click(); break;
      case 'b': case 'B': opts.onBookmark?.(video.currentTime); break;
      default: return;
    }
  }
  document.addEventListener('keydown', onKey);

  function destroy() {
    destroyed = true;
    document.removeEventListener('keydown', onKey);
    if (hls) { hls.destroy(); hls = null; }
    video.pause(); video.removeAttribute('src'); video.load();
    el.remove();
  }

  if (opts.src) setSrc(opts.src, opts.kind || 'video', { poster: opts.poster, autoplay: opts.autoplay });
  if (opts.bookmarks) setBookmarks(opts.bookmarks);
  if (opts.overlay) showOverlay(opts.overlay);

  return {
    el, video, setSrc, setBookmarks, destroy, showOverlay, screenshot,
    play: () => video.play().catch(() => {}), pause: () => video.pause(),
    seek: (s) => { video.currentTime = s; }, currentTime: () => video.currentTime,
    get kind() { return kind; },
  };
}
