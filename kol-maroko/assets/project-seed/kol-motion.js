/*
 * kol-motion.js — קובץ מערכת של קול מרוקו (גרסה 1.0.0). נוצר בידי העורך החזותי כשנבחר אפקט שדורש JS.
 * מותר לערוך ולמחוק; עריכה מסומנת "שונה". אין בו מילה שהגולש רואה, ואין בו אחסון (לא עוגיות, לא localStorage).
 *
 * מה הוא עושה:
 *   1. מוסיף ל-<html> את המחלקה kol-motion-on. רק תחתיה אנימציית כניסה (.kol-in) מחכה ל-is-in — בלי הקובץ הזה, או כש-JS
 *      כבוי, האנימציה רצה פעם אחת בטעינה ומסתיימת במצב הסופי. אף פעם לא תוכן שנשאר שקוף.
 *   2. "כשנראה במסך": לכל .kol-in — IntersectionObserver מוסיף is-in כשהאלמנט נכנס למסך.
 *        data-kol-in="repeat" — בכל כניסה מחדש (היציאה מסירה את is-in, והאנימציה מתחילה שוב בכניסה הבאה).
 *        בלי data-kol-in — מיד (כמו בטעינה).
 *   3. אפקטי עכבר: data-kol-tilt="<מעלות>" — הטיה בתלת-ממד לפי מיקום העכבר על האלמנט (--kol-tilt-x / --kol-tilt-y);
 *      data-kol-follow="<פיקסלים>" — תזוזה קטנה אחרי העכבר (--kol-follow-x / --kol-follow-y). ה-CSS שמשתמש במשתנים —
 *      בכלל של האלמנט (transform). בטלפון (בלי עכבר) — לא פועל.
 *   prefers-reduced-motion: reduce — שום דבר מכל זה; is-in מתווסף לכולם מיד.
 */
(function () {
  'use strict';
  var root = document.documentElement, reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  root.classList.add('kol-motion-on');
  function all(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
  function show(el) { el.classList.add('is-in'); }
  function restart(el) { el.classList.remove('is-in'); el.style.animationName = 'none'; void el.offsetWidth; el.style.animationName = ''; }
  function start() {
    var els = all('.kol-in');
    if (reduce || !('IntersectionObserver' in window)) { els.forEach(show); return; }
    var io = new IntersectionObserver(function (list) {
      list.forEach(function (e) {
        var el = e.target, mode = el.getAttribute('data-kol-in');
        if (e.isIntersecting) { show(el); if (mode !== 'repeat') io.unobserve(el); }
        else if (mode === 'repeat' && el.classList.contains('is-in')) restart(el);
      });
    }, { threshold: 0.15 });
    els.forEach(function (el) { if (el.hasAttribute('data-kol-in')) io.observe(el); else show(el); });
    if (reduce || !window.matchMedia || !window.matchMedia('(hover: hover)').matches) return;
    all('[data-kol-tilt]').forEach(function (el) {
      var max = parseFloat(el.getAttribute('data-kol-tilt')) || 10;
      el.addEventListener('pointermove', function (ev) { var r = el.getBoundingClientRect(), x = (ev.clientX - r.left) / r.width - 0.5, y = (ev.clientY - r.top) / r.height - 0.5; el.style.setProperty('--kol-tilt-x', (-y * max).toFixed(2) + 'deg'); el.style.setProperty('--kol-tilt-y', (x * max).toFixed(2) + 'deg'); });
      el.addEventListener('pointerleave', function () { el.style.removeProperty('--kol-tilt-x'); el.style.removeProperty('--kol-tilt-y'); });
    });
    all('[data-kol-follow]').forEach(function (el) {
      var max = parseFloat(el.getAttribute('data-kol-follow')) || 12;
      el.addEventListener('pointermove', function (ev) { var r = el.getBoundingClientRect(); el.style.setProperty('--kol-follow-x', (((ev.clientX - r.left) / r.width - 0.5) * 2 * max).toFixed(1) + 'px'); el.style.setProperty('--kol-follow-y', (((ev.clientY - r.top) / r.height - 0.5) * 2 * max).toFixed(1) + 'px'); });
      el.addEventListener('pointerleave', function () { el.style.removeProperty('--kol-follow-x'); el.style.removeProperty('--kol-follow-y'); });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
