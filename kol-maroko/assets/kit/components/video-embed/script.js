/* רכיב מוכן: וידאו מוטמע (kit 1.0.0) — משפר בלבד: לחיצה ← iframe (youtube-nocookie / player.vimeo), בארגז חול.
   כתובת שאינה YouTube / Vimeo — הקישור נפתח כרגיל. שם המסגרת — הטקסט שבתבנית (.kol-embed__label). */
(() => {
  const src = href => {
    let u; try { u = new URL(href, location.href); } catch { return null; }
    const host = u.hostname.replace(/^www\.|^m\./, '');
    let id = null;
    if (host === 'youtube.com' || host === 'youtube-nocookie.com') id = u.searchParams.get('v') || (/^\/(?:embed|shorts)\/([\w-]{6,20})/.exec(u.pathname) || [])[1];
    else if (host === 'youtu.be') id = (/^\/([\w-]{6,20})/.exec(u.pathname) || [])[1];
    if (id && /^[\w-]{6,20}$/.test(id)) return 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
    if (host === 'vimeo.com' || host === 'player.vimeo.com') { const v = (/(\d{5,12})/.exec(u.pathname) || [])[1]; if (v) return 'https://player.vimeo.com/video/' + v + '?autoplay=1&dnt=1'; }
    return null;
  };
  const init = a => {
    if (a.kolEmbed) return;
    a.kolEmbed = true;
    a.addEventListener('click', e => {
      const s = src(a.getAttribute('href') || ''); if (!s) return;
      e.preventDefault();
      const f = document.createElement('iframe');
      f.className = 'kol-embed__frame';
      f.src = s;
      f.title = (a.querySelector('.kol-embed__label') || a).textContent.trim();
      f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      f.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');
      f.referrerPolicy = 'strict-origin-when-cross-origin';
      f.allowFullscreen = true;
      a.replaceWith(f);
      f.focus();
    });
  };
  const all = () => document.querySelectorAll('.kol-embed__link').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
