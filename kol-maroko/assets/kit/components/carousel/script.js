/* רכיב מוכן: קרוסלה (kit 1.0.0) — משפר בלבד. בלי הקובץ: גלילה אופקית עם scroll-snap (החלקה במגע עובדת).
   כאן: חיצים, מקלדת.
   עובד עם כל מספר שקופיות, ב-RTL וב-LTR. */
(() => {
  const init = root => {
    if (root.kolSl) return;
    root.kolSl = true;
    const track = root.querySelector('.kol-carousel__track'); if (!track) return;
    const slides = () => [...track.children];
    const rtl = () => getComputedStyle(track).direction === 'rtl';
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const cur = () => { const t = track.getBoundingClientRect(), list = slides(); let best = 0, d = Infinity; list.forEach((s, i) => { const r = s.getBoundingClientRect(), x = Math.abs(rtl() ? r.right - t.right : r.left - t.left); if (x < d) { d = x; best = i; } }); return best; };
    const go = i => { const list = slides(); if (!list.length) return; i = (i + list.length) % list.length; const t = track.getBoundingClientRect(), r = list[i].getBoundingClientRect(); track.scrollBy({ left: rtl() ? r.right - t.right : r.left - t.left, behavior: reduce ? 'auto' : 'smooth' }); };
    const step = k => { const list = slides(), per = Math.max(1, Math.round(track.clientWidth / (list[0] ? list[0].getBoundingClientRect().width : 1))); go(cur() + k * per); };
    root.querySelector('.kol-carousel__prev')?.addEventListener('click', () => step(-1));
    root.querySelector('.kol-carousel__next')?.addEventListener('click', () => step(1));
    root.addEventListener('keydown', e => { if (e.target.closest('input, textarea, select')) return; if (e.key === 'ArrowLeft') { e.preventDefault(); step(rtl() ? 1 : -1); } if (e.key === 'ArrowRight') { e.preventDefault(); step(rtl() ? -1 : 1); } });
    const mark = () => { const i = cur(); slides().forEach((s, k) => s.classList.toggle('is-active', k === i)); };
    mark(); let tm = 0; track.addEventListener('scroll', () => { clearTimeout(tm); tm = setTimeout(mark, 80); }, { passive: true });
    root.classList.add('is-ready');
  };
  const all = () => document.querySelectorAll('.kol-carousel').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
