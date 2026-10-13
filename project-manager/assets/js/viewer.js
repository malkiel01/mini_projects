// צפייה בקבצים בתוך האפליקציה — PDF (pdf.js, בקנבס), תמונה וסרטון. בלי לצאת ללשונית של הדפדפן.
// Viewer.mount — צפייה בתוך חלון (חלון הקובץ); Viewer.open — מסך מלא. כל קישור לקובץ באפליקציה
// (api.php?action=file&id=…, לא הורדה) נפתח כאן — מהצעות, חוזה, כרטסת, אסמכתאות.
// צריך: $, $$, esc, t, api (core.js).
'use strict';

const Viewer = (() => {
    const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}${extra}`;
    const isPdf = f => f.mime === 'application/pdf' || /\.pdf$/i.test(f.name || '');

    // ---- pdf.js — נטען רק כשצריך ----
    let pdfLib = null;
    function pdfjs() {
        return pdfLib ||= new Promise((ok, bad) => {
            const sc = document.createElement('script');
            sc.src = 'assets/vendor/pdfjs/pdf.min.js?v=3.11.174';
            sc.onload = () => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdfjs/pdf.worker.min.js?v=3.11.174'; ok(window.pdfjsLib); };
            sc.onerror = () => { pdfLib = null; bad(new Error('pdf.js')); };
            document.head.appendChild(sc);
        });
    }
    async function pdfDoc(src) {
        const lib = await pdfjs();
        return lib.getDocument(src instanceof ArrayBuffer ? { data: src } : { url: src, withCredentials: true }).promise;
    }
    /** עמוד לקנבס ברוחב width (פיקסלים של CSS) — לתמונה ממוזערת בהעלאה. */
    async function drawPage(page, width) {
        const base = page.getViewport({ scale: 1 }), dpr = Math.min(2, window.devicePixelRatio || 1);
        const vp = page.getViewport({ scale: (width / base.width) * dpr });
        const c = document.createElement('canvas');
        c.width = Math.round(vp.width); c.height = Math.round(vp.height);
        await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
        return c;
    }
    /** קנבס → תמונה ממוזערת (320px, JPEG) לרשימת הקבצים. */
    function shrink(c) {
        const k = Math.min(1, 320 / Math.max(c.width, c.height)), o = document.createElement('canvas');
        o.width = Math.round(c.width * k); o.height = Math.round(c.height * k);
        const g = o.getContext('2d');
        g.fillStyle = '#fff'; g.fillRect(0, 0, o.width, o.height);
        g.drawImage(c, 0, 0, o.width, o.height);
        return o.toDataURL('image/jpeg', 0.75);
    }

    /** המקור: PDF (עמודים) או תמונה (עמוד אחד) — גודל, וציור בקנה מידה לקנבס. */
    async function source(f) {
        if (isPdf(f)) {
            const doc = await pdfDoc(fileUrl(f.id));
            return {
                n: doc.numPages, fit: 'width',
                async page(i) { const p = await doc.getPage(i), v = p.getViewport({ scale: 1 }); return { w: v.width, h: v.height, p }; },
                render(pg, scale, ctx) { return pg.p.render({ canvasContext: ctx, viewport: pg.p.getViewport({ scale }) }); },
            };
        }
        const img = new Image();
        img.src = fileUrl(f.id);
        await img.decode();
        return {
            n: 1, fit: 'contain',
            async page() { return { w: img.naturalWidth, h: img.naturalHeight }; },
            render(pg, scale, ctx) { ctx.drawImage(img, 0, 0, pg.w * scale, pg.h * scale); return { promise: Promise.resolve(), cancel() {} }; },
        };
    }

    /**
     * צפייה בקנבס בתוך box: עמוד בכל פעם (הקודם / הבא, "עמוד X מתוך N"), הגדלה / הקטנה (כפתורים, צביטה,
     * לחיצה כפולה, Ctrl+גלגלת), התאמה, ומסך מלא. בהגדלה גוללים בעמוד; בלי הגדלה — החלקה מחליפה עמוד.
     * opts.full — כבר במסך מלא (בלי כפתור); opts.onThumb(canvas) — אחרי ציור העמוד הראשון.
     * מחזיר פונקציה שעוצרת (סגירת החלון).
     */
    function mount(box, f, opts = {}) {
        let alive = true;
        box.innerHTML = `<div class="pdfv${opts.full ? ' in-full' : ''}">
            <div class="pdfv-bar" role="toolbar" aria-label="${esc(t('צפייה במסמך'))}">
                <button type="button" class="icon-btn" data-pv="prev" aria-label="${esc(t('העמוד הקודם'))}"><span aria-hidden="true">‹</span></button>
                <span class="pv-page" aria-live="polite"></span>
                <button type="button" class="icon-btn" data-pv="next" aria-label="${esc(t('העמוד הבא'))}"><span aria-hidden="true">›</span></button>
                <span class="pv-sp"></span>
                <button type="button" class="icon-btn" data-pv="out" aria-label="${esc(t('הקטנה'))}">−</button>
                <button type="button" class="pv-zoom" data-pv="fit" title="${esc(t('התאמה לרוחב'))}">100%</button>
                <button type="button" class="icon-btn" data-pv="in" aria-label="${esc(t('הגדלה'))}">+</button>
                ${opts.full ? '' : `<button type="button" class="icon-btn" data-pv="full" aria-label="${esc(t('מסך מלא'))}">⛶</button>`}
            </div>
            <div class="pdfv-stage" tabindex="0" aria-label="${esc(f.name || t('עמודי המסמך'))}">
                ${f.has_thumb ? `<img class="pdf-ph" src="${fileUrl(f.id, '&thumb=1')}" alt="">` : ''}<span class="pdf-wait">${esc(t('טוען את המסמך…'))}</span>
                <canvas hidden></canvas>
            </div></div>`;
        const root = $('.pdfv', box), stage = $('.pdfv-stage', root), cv = $('canvas', stage), bar = $('.pdfv-bar', root);
        const st = { i: 1, zoom: 1, task: null, src: null };

        async function draw(keepCenter = false) {
            const pg = await st.src.page(st.i);
            if (!alive) return;
            for (let k = 0; k < 20 && stage.clientWidth < 50; k++) await new Promise(r => requestAnimationFrame(r));
            // PDF — לרוחב; תמונה — כולה בתוך המסך (בחלון — עד 60% מהגובה)
            const W = Math.max(120, stage.clientWidth - 16), H = Math.max(120, opts.full ? stage.clientHeight - 16 : window.innerHeight * 0.6);
            const fit = st.src.fit === 'contain' ? Math.min(W / pg.w, H / pg.h) : W / pg.w;
            const css = fit * st.zoom;
            // צפיפות המסך — בלי קנבס ענק (זיכרון בטלפון): עד ~16 מיליון פיקסלים
            let dpr = Math.min(2, window.devicePixelRatio || 1);
            const px = pg.w * css * pg.h * css;
            if (px * dpr * dpr > 16e6) dpr = Math.max(0.5, Math.sqrt(16e6 / px));
            // הבמה ב-LTR, כדי שחשבון הגלילה יהיה פשוט גם בעברית; בלי מרכז קודם — מרכז העמוד
            const cx = keepCenter && stage.scrollWidth > stage.clientWidth ? (stage.scrollLeft + stage.clientWidth / 2) / stage.scrollWidth : 0.5;
            const cy = keepCenter ? (stage.scrollTop + stage.clientHeight / 2) / Math.max(1, stage.scrollHeight) : 0;
            st.task?.cancel();
            const off = document.createElement('canvas');
            off.width = Math.max(1, Math.round(pg.w * css * dpr)); off.height = Math.max(1, Math.round(pg.h * css * dpr));
            const ctx = off.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, off.width, off.height);
            st.task = st.src.render(pg, css * dpr, ctx);
            try { await st.task.promise; } catch { return; } // בוטל — ציור חדש בדרך
            if (!alive) return;
            cv.width = off.width; cv.height = off.height;
            cv.getContext('2d').drawImage(off, 0, 0);
            cv.style.inlineSize = Math.round(pg.w * css) + 'px';
            cv.style.blockSize = Math.round(pg.h * css) + 'px';
            cv.style.transform = '';
            cv.hidden = false;
            $$('.pdf-wait, .pdf-ph', stage).forEach(x => x.remove());
            stage.scrollLeft = cx * stage.scrollWidth - stage.clientWidth / 2;
            stage.scrollTop = keepCenter ? cy * stage.scrollHeight - stage.clientHeight / 2 : 0;
            $('.pv-page', bar).textContent = st.src.n > 1 ? t('עמוד {i} מתוך {n}', { i: st.i, n: st.src.n }) : '';
            $('[data-pv=fit]', bar).textContent = Math.round(st.zoom * 100) + '%';
            for (const k of ['prev', 'next']) $(`[data-pv=${k}]`, bar).hidden = st.src.n < 2;
            $('[data-pv=prev]', bar).disabled = st.i <= 1;
            $('[data-pv=next]', bar).disabled = st.i >= st.src.n;
            if (st.i === 1 && opts.onThumb) { const cb = opts.onThumb; opts.onThumb = null; cb(cv); }
        }
        const go = d => { const i = Math.min(st.src.n, Math.max(1, st.i + d)); if (i !== st.i) { st.i = i; draw(); } };
        const zoomTo = z => { z = Math.min(6, Math.max(0.5, z)); if (Math.abs(z - st.zoom) > 0.001) { st.zoom = z; draw(true); } };

        bar.addEventListener('click', e => {
            const b = e.target.closest('[data-pv]');
            if (!b || !st.src) return;
            const k = b.dataset.pv;
            if (k === 'prev') go(-1);
            else if (k === 'next') go(1);
            else if (k === 'in') zoomTo(st.zoom * 1.25);
            else if (k === 'out') zoomTo(st.zoom / 1.25);
            else if (k === 'fit') zoomTo(1);
            else if (k === 'full') open(f);
        });
        stage.addEventListener('keydown', e => {
            if (!st.src) return;
            const rtl = document.documentElement.dir !== 'ltr';
            if (e.key === 'ArrowLeft' && st.zoom <= 1.01) go(rtl ? 1 : -1);
            else if (e.key === 'ArrowRight' && st.zoom <= 1.01) go(rtl ? -1 : 1);
            else if (e.key === 'PageDown') go(1);
            else if (e.key === 'PageUp') go(-1);
            else if (e.key === '+' || e.key === '=') zoomTo(st.zoom * 1.25);
            else if (e.key === '-') zoomTo(st.zoom / 1.25);
            else return;
            e.preventDefault();
        });
        // מגע: צביטה — הגדלה (תצוגה מיידית ב-CSS, ציור חד בסוף); החלקה בלי הגדלה — עמוד; לחיצה כפולה — זום
        let pinch = null, swipe = null, lastTap = 0;
        const dist = tt => Math.hypot(tt[0].clientX - tt[1].clientX, tt[0].clientY - tt[1].clientY);
        stage.addEventListener('touchstart', e => {
            if (e.touches.length === 2) { pinch = { d: dist(e.touches), z: st.zoom, r: 1 }; swipe = null; }
            else if (e.touches.length === 1) swipe = { x: e.touches[0].clientX, y: e.touches[0].clientY, t: Date.now() };
        }, { passive: true });
        stage.addEventListener('touchmove', e => {
            if (!pinch || e.touches.length !== 2) return;
            e.preventDefault();
            pinch.r = Math.min(6 / pinch.z, Math.max(0.5 / pinch.z, dist(e.touches) / pinch.d));
            cv.style.transformOrigin = 'center top';
            cv.style.transform = `scale(${pinch.r})`;
        }, { passive: false });
        stage.addEventListener('touchend', e => {
            if (!st.src) return;
            if (pinch && e.touches.length < 2) { const z = pinch.z * pinch.r; pinch = null; zoomTo(z); if (Math.abs(z - st.zoom) <= 0.001) cv.style.transform = ''; return; }
            if (!swipe || e.touches.length) return;
            const tch = e.changedTouches[0], dx = tch.clientX - swipe.x, dy = tch.clientY - swipe.y, quick = Date.now() - swipe.t < 300;
            swipe = null;
            if (quick && Math.abs(dx) < 10 && Math.abs(dy) < 10) {
                if (Date.now() - lastTap < 300) { zoomTo(st.zoom > 1.01 ? 1 : 2.5); lastTap = 0; } else lastTap = Date.now();
                return;
            }
            if (st.zoom <= 1.01 && Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                const rtl = document.documentElement.dir !== 'ltr';
                go((dx < 0) === rtl ? -1 : 1);
            }
        });
        stage.addEventListener('wheel', e => { if (!e.ctrlKey || !st.src) return; e.preventDefault(); zoomTo(st.zoom * (e.deltaY < 0 ? 1.1 : 1 / 1.1)); }, { passive: false });

        source(f).then(src => { if (!alive) return; st.src = src; return draw(); }).catch(() => {
            if (!alive) return;
            box.innerHTML = `<p class="hint fv-fail">${esc(t('אי אפשר להציג את הקובץ כאן'))}</p>
                <a class="btn ghost" href="${fileUrl(f.id, '&download=1')}">⬇️ ${esc(t('הורדה'))}</a>`;
        });
        return () => { alive = false; st.task?.cancel(); };
    }

    // ---- מסך מלא, בתוך האפליקציה ----
    let dlg = null, stop = null;
    function dialog() {
        if (dlg) return dlg;
        dlg = document.createElement('dialog');
        dlg.id = 'viewer-dlg';
        dlg.setAttribute('aria-labelledby', 'viewer-title');
        dlg.innerHTML = `<header class="viewer-head">
                <button type="button" class="icon-btn" data-vclose aria-label="${esc(t('סגירה'))}">✕</button>
                <h2 id="viewer-title" class="one"></h2>
                <a class="icon-btn" id="viewer-dl" aria-label="${esc(t('הורדה'))}" title="${esc(t('הורדה'))}">⬇️</a>
            </header><div class="viewer-body" id="viewer-body"></div>`;
        document.body.appendChild(dlg);
        $('[data-vclose]', dlg).addEventListener('click', () => dlg.close());
        dlg.addEventListener('close', () => { stop?.(); stop = null; $('#viewer-body').innerHTML = ''; });
        return dlg;
    }

    /** מסך מלא: f — {id, name, mime, has_thumb} או מזהה בלבד (הפרטים נטענים). */
    async function open(f) {
        if (typeof f === 'number' || !f.mime) {
            try { ({ file: f } = await api('file', { params: { id: Number(f.id ?? f), meta: 1 } })); } catch (e) { toast(e.message, true); return; }
        }
        const d = dialog(), body = $('#viewer-body', d);
        stop?.(); stop = null;
        $('#viewer-title', d).textContent = f.name;
        $('#viewer-dl', d).href = fileUrl(f.id, '&download=1');
        if (!d.open) d.showModal();
        if (isPdf(f) || f.mime.startsWith('image/')) stop = mount(body, f, { full: true });
        else if (f.mime.startsWith('video/')) body.innerHTML = `<video src="${fileUrl(f.id)}" controls autoplay playsinline></video>`;
        else body.innerHTML = `<div class="viewer-none"><span class="ficon big" aria-hidden="true">📄</span>
            <p>${esc(t('אין תצוגה לסוג הקובץ הזה'))}</p><a class="btn primary" href="${fileUrl(f.id, '&download=1')}">⬇️ ${esc(t('הורדה'))}</a></div>`;
    }

    // כל קישור לקובץ באפליקציה (לא הורדה) — נפתח כאן, ולא בלשונית של הדפדפן
    document.addEventListener('click', e => {
        const a = e.target.closest?.('a[href*="action=file&id="]');
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey || /[?&](download|thumb)=1/.test(a.getAttribute('href'))) return;
        const id = Number(new URL(a.href, location.href).searchParams.get('id'));
        if (!id) return;
        e.preventDefault();
        open(id);
    }, true);

    return { mount, open, pdfDoc, drawPage, shrink, isPdf };
})();
