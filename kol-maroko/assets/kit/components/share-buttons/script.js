/* רכיב מוכן: כפתורי שיתוף (kit 1.0.0) — משפר בלבד: משלים בכל קישור את כתובת העמוד (או data-kol-url) ואת הכותרת שלו, ומעתיק
   קישור ללוח. בלי הקובץ — הקישורים נפתחים ריקים (הגולש מדביק בעצמו). שום סקריפט של רשת חברתית. */
(() => {
  const init = root => {
    if (root.kolShare) return;
    root.kolShare = true;
    const url = root.getAttribute('data-kol-url') || location.href.replace(/#.*$/, ''), title = document.title, u = encodeURIComponent(url), t = encodeURIComponent(title);
    const make = { whatsapp: 'https://wa.me/?text=' + t + '%20' + u, facebook: 'https://www.facebook.com/sharer/sharer.php?u=' + u, x: 'https://twitter.com/intent/tweet?url=' + u + '&text=' + t, telegram: 'https://t.me/share/url?url=' + u + '&text=' + t, email: 'mailto:?subject=' + t + '&body=' + u };
    for (const a of root.querySelectorAll('a[data-kol-share]')) { const k = a.getAttribute('data-kol-share'); if (make[k]) a.href = make[k]; }
    const copy = root.querySelector('[data-kol-share="copy"]');
    if (copy && navigator.clipboard) copy.addEventListener('click', () => navigator.clipboard.writeText(url).then(() => { root.classList.add('is-copied'); setTimeout(() => root.classList.remove('is-copied'), 2000); }).catch(() => {}));
    root.classList.add('is-ready');
  };
  const all = () => document.querySelectorAll('.kol-share').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
