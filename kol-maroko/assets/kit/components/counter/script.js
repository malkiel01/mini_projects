/* רכיב מוכן: מונה (kit 1.0.0) — משפר בלבד: כשהמספר נכנס למסך הוא סופר מ-data-kol-from אל המספר הכתוב, ובסוף
   חוזר בדיוק לטקסט שבקובץ. prefers-reduced-motion — בלי ספירה. */
(() => {
  const init = el => {
    if (el.kolCount) return;
    el.kolCount = true;
    const final = el.textContent, m = /-?[\d,.]+/.exec(final); if (!m) return;
    const to = parseFloat(m[0].replace(/,/g, '')), dec = (m[0].split('.')[1] || '').length, sep = m[0].includes(',');
    const from = parseFloat(el.getAttribute('data-kol-from') || '0') || 0, dur = Math.max(0, parseInt(el.getAttribute('data-kol-duration') || '1600', 10) || 0);
    if (!isFinite(to) || matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) return;
    const fmt = v => { let s = v.toFixed(dec); if (sep) { const [a, b] = s.split('.'); s = a.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (b ? '.' + b : ''); } return final.slice(0, m.index) + s + final.slice(m.index + m[0].length); };
    el.textContent = fmt(from);
    const io = new IntersectionObserver(es => {
      if (!es.some(e => e.isIntersecting)) return;
      io.disconnect();
      const t0 = performance.now();
      const step = t => { const k = Math.min(1, (t - t0) / (dur || 1)), e = 1 - Math.pow(1 - k, 3); el.textContent = k < 1 ? fmt(from + (to - from) * e) : final; if (k < 1) requestAnimationFrame(step); };
      requestAnimationFrame(step);
    }, { threshold: 0.4 });
    io.observe(el);
  };
  const all = () => document.querySelectorAll('.kol-counter__num').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
