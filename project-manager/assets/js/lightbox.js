// תצוגה במסך מלא של תמונה או סרטון — בדף הפרויקט (מהשטח) ובפורטל.
// צריך: $, $$, esc, ו-<dialog id="lightbox"> (index.html, portal/index.html).
'use strict';

/** תצוגה במסך מלא — תמונה או סרטון, עם הקודם / הבא. גם בדף הפרויקט וגם בפורטל. */
const Lightbox = (() => {
    let items = [], i = 0, bound = false, urlOf = null;

    function bind() {
        if (bound) return;
        bound = true;
        const dlg = $('#lightbox');
        $('.lb-prev', dlg).addEventListener('click', () => go(-1));
        $('.lb-next', dlg).addEventListener('click', () => go(1));
        // בעברית ובערבית "הקודם" מימין; בשפות משמאל לימין — משמאל
        const rtl = () => document.documentElement.dir !== 'ltr';
        dlg.addEventListener('keydown', e => {
            if (e.key === 'ArrowRight') go(rtl() ? -1 : 1);
            if (e.key === 'ArrowLeft') go(rtl() ? 1 : -1);
        });
        // החלקה בטלפון
        let x0 = null;
        dlg.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
        dlg.addEventListener('touchend', e => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 50) go((dx > 0) === rtl() ? 1 : -1); x0 = null; });
        dlg.addEventListener('close', () => { $('#lb-body').innerHTML = ''; });
    }

    /** list: [{id, name, video}], url: id => כתובת הקובץ */
    function open(list, index, url) {
        bind();
        items = list; i = index; urlOf = url;
        draw();
        $('#lightbox').showModal();
    }

    function go(d) { if (items.length < 2) return; i = (i + d + items.length) % items.length; draw(); }

    function draw() {
        const m = items[i];
        $('#lb-body').innerHTML = m.video
            ? `<video src="${urlOf(m.id)}" controls autoplay playsinline></video>`
            : `<img src="${urlOf(m.id)}" alt="${esc(m.name)}">`;
        $('#lb-cap').textContent = items.length > 1 ? `${i + 1} / ${items.length}` : '';
        $$('#lightbox .lb-prev, #lightbox .lb-next').forEach(b => { b.hidden = items.length < 2; });
    }

    return { open };
})();
