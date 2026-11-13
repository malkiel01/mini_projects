/* רכיב מוכן: נגן שמע (kit 1.0.0) — משפר בלבד: מסתיר את <audio controls> ומחבר אליו את הכפתור, הפס והזמן. */
(() => {
  const t = s => { s = Math.max(0, Math.floor(s || 0)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
  const init = root => {
    if (root.kolAudio) return;
    root.kolAudio = true;
    const a = root.querySelector('.kol-audio__el'), b = root.querySelector('.kol-audio__play'), seek = root.querySelector('.kol-audio__seek'), time = root.querySelector('.kol-audio__time');
    if (!a || !b) return;
    root.classList.add('is-ready');
    b.addEventListener('click', () => { if (a.paused) a.play().catch(() => {}); else a.pause(); });
    a.addEventListener('play', () => root.classList.add('is-playing'));
    a.addEventListener('pause', () => root.classList.remove('is-playing'));
    a.addEventListener('ended', () => root.classList.remove('is-playing'));
    const draw = () => { if (seek && a.duration) seek.value = String(Math.round(a.currentTime / a.duration * 1000)); if (time) time.textContent = t(a.duration && !a.paused ? a.currentTime : (a.currentTime || a.duration)); };
    a.addEventListener('timeupdate', draw); a.addEventListener('loadedmetadata', draw);
    if (seek) seek.addEventListener('input', () => { if (a.duration) a.currentTime = seek.value / 1000 * a.duration; });
  };
  const all = () => document.querySelectorAll('.kol-audio').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
