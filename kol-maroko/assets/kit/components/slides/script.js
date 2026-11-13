/* רכיב מוכן: מצגת (kit 1.0.0) — משפר בלבד. בלי הקובץ: גלילה אופקית עם scroll-snap (החלקה במגע עובדת).
   כאן: חיצים, נקודות (הראשונה בתבנית משוכפלת לכל שקופית; הטקסט שלה — המספר), מקלדת, הפעלה אוטומטית (data-kol-autoplay שניות; נעצרת בריחוף / בפוקוס / ב-prefers-reduced-motion).
   עובד עם כל מספר שקופיות, ב-RTL וב-LTR. */
(() => {
  const init = root => {
    if (root.kolSl) return;
    root.kolSl = true;
    const track = root.querySelector('.kol-slides__track'); if (!track) return;
    const slides = () => [...track.children];
    const rtl = () => getComputedStyle(track).direction === 'rtl';
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const cur = () => { const t = track.getBoundingClientRect(), list = slides(); let best = 0, d = Infinity; list.forEach((s, i) => { const r = s.getBoundingClientRect(), x = Math.abs(rtl() ? r.right - t.right : r.left - t.left); if (x < d) { d = x; best = i; } }); return best; };
    const go = i => { const list = slides(); if (!list.length) return; i = (i + list.length) % list.length; const t = track.getBoundingClientRect(), r = list[i].getBoundingClientRect(); track.scrollBy({ left: rtl() ? r.right - t.right : r.left - t.left, behavior: reduce ? 'auto' : 'smooth' }); };
    const step = k => { const list = slides(), per = Math.max(1, Math.round(track.clientWidth / (list[0] ? list[0].getBoundingClientRect().width : 1))); go(cur() + k * per); };
    root.querySelector('.kol-slides__prev')?.addEventListener('click', () => step(-1));
    root.querySelector('.kol-slides__next')?.addEventListener('click', () => step(1));
    root.addEventListener('keydown', e => { if (e.target.closest('input, textarea, select')) return; if (e.key === 'ArrowLeft') { e.preventDefault(); step(rtl() ? 1 : -1); } if (e.key === 'ArrowRight') { e.preventDefault(); step(rtl() ? -1 : 1); } });
    const dots = root.querySelector('.kol-slides__dots'), proto = dots && dots.querySelector('.kol-slides__dot');
    const drawDots = () => { if (!dots || !proto) return; const n = slides().length; while (dots.children.length > n) dots.lastElementChild.remove(); while (dots.children.length < n) dots.append(proto.cloneNode(true)); [...dots.children].forEach((d, i) => { const t = d.querySelector('.kol-slides__vh'); if (t) t.textContent = String(i + 1); d.onclick = () => go(i); }); };
    const mark = () => { const i = cur(); slides().forEach((s, k) => s.classList.toggle('is-active', k === i)); if (dots) [...dots.children].forEach((d, k) => d.setAttribute('aria-current', String(k === i))); };
    drawDots(); mark();
    let tm = 0; track.addEventListener('scroll', () => { clearTimeout(tm); tm = setTimeout(mark, 80); }, { passive: true });
    const sec = parseFloat(root.getAttribute('data-kol-autoplay') || '0') || 0;
    if (sec > 0 && !reduce) {
      let hold = false, timer = 0;
      const tick = () => { if (!hold && !document.hidden) go(cur() + 1); };
      timer = setInterval(tick, sec * 1000);
      root.addEventListener('pointerenter', () => { hold = true; }); root.addEventListener('pointerleave', () => { hold = false; });
      root.addEventListener('focusin', () => { hold = true; }); root.addEventListener('focusout', () => { hold = false; });
      void timer;
    }
    root.classList.add('is-ready');
  };
  const all = () => document.querySelectorAll('.kol-slides').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
