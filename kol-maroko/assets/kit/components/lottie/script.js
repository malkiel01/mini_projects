/* רכיב מוכן: Lottie (kit 1.0.1) — משפר בלבד: טוען את הנגן שבאותה תיקייה (lottie_light.min.js — קובץ בפרויקט, לא CDN) רק כשיש
   אנימציה בעמוד, ומנגן את data-kol-src (ריק — sample.json שבתיקייה). מנגן רק כשנראה במסך; prefers-reduced-motion — הפריים הראשון. */
(() => {
  const me = document.currentScript && document.currentScript.src, base = me ? me.replace(/[?#].*$/, '').replace(/[^/]*$/, '') : '';
  let loading = null;
  const player = () => loading || (loading = new Promise((ok, bad) => { if (window.lottie) return ok(window.lottie); const s = document.createElement('script'); s.src = base + 'lottie_light.min.js'; s.onload = () => ok(window.lottie); s.onerror = bad; document.head.append(s); }));
  const init = async root => {
    if (root.kolLottie) return;
    root.kolLottie = true;
    const stage = root.querySelector('.kol-lottie__stage'); if (!stage || !base) return;
    // רק מהאתר עצמו: data-kol-src מבחוץ (https://… של אתר אחר) — לא נטען (בלי בקשה החוצה); נשארת התמונה
    let src = base + 'sample.json';
    const own = root.getAttribute('data-kol-src');
    if (own) { let u = null; try { u = new URL(own, location.href); } catch { /* */ } if (!u || u.origin !== new URL(base).origin) return; src = u.href; }   // המקור — של הסקריפט עצמו (בתצוגה החיה location.origin הוא "null")
    let L; try { L = await player(); } catch { return; }
    if (!L) return;
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const anim = L.loadAnimation({ container: stage, renderer: 'svg', loop: root.hasAttribute('data-kol-loop'), autoplay: false, path: src });
    anim.addEventListener('DOMLoaded', () => {
      root.classList.add('is-ready');
      if (reduce) { anim.goToAndStop(0, true); return; }
      if (!('IntersectionObserver' in window)) { anim.play(); return; }
      new IntersectionObserver(es => { for (const e of es) { if (e.isIntersecting) anim.play(); else anim.pause(); } }).observe(root);
    });
  };
  const all = () => document.querySelectorAll('.kol-lottie').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
