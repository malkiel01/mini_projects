/*
 * ייבוא (אפיון 03): #/admin/content/import/<edition> — העלאת קובץ ורשימת הייבואים של המהדורה;
 * #/admin/content/i/<pid> — ייבוא אחד: התקדמות → אזהרות → מיפוי (שם · הזזה · מיזוג) → תצוגה מקדימה → "לעורך" (טיוטה).
 *
 * PDF: הטקסט מחולץ כאן, בדפדפן (pdf.js — assets/vendor/pdfjs), ונשלח לשרת. העמודים המקוריים מוצגים מהמקור; התצוגה
 * הזורמת של PDF מוסתרת עד שהמנהל סימן "בדקתי". אין כאן פרסום — הפרסום רק בעורך (02).
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const KIND = { html: ['🌐', 'HTML'], docx: ['📄', 'Word'], epub: ['📘', 'EPUB'], pdf: ['📕', 'PDF עם טקסט'], pdf_scan: ['🖼️', 'PDF סרוק'], image: ['🖼️', 'תמונה'] };
    const STATUS = {
        uploaded: ['הועלה', ''], awaiting_text: ['מחכה לחילוץ טקסט', 'warn'], queued: ['בתור', 'warn'], processing: ['בעיבוד', 'warn'],
        ready: ['מוכן למיפוי', 'ok'], failed: ['נכשל', 'bad'], applied: ['נכנס לטיוטה', 'ok'],
    };
    const FILES = 'api.php?action=content-file&path=';
    const chip = s => h('span', { class: 'chip ' + (STATUS[s] || ['', ''])[1] }, (STATUS[s] || [s])[0]);
    const mb = b => b < 1048576 ? Math.max(1, Math.round(b / 1024)) + 'KB' : (b / 1048576).toFixed(b < 10485760 ? 1 : 0) + 'MB';

    // ── העלאה ───────────────────────────────────────────────
    async function upload(edition, file, owner) {
        const q = new URLSearchParams({ action: 'import-upload', edition, name: file.name });
        if (owner) q.set('owner', '1');
        let res, data = null;
        try {
            res = await fetch('api.php?' + q, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/octet-stream', ...SF.headers() }, body: file });
            data = await res.json();
        } catch (e) {
            throw Object.assign(new Error('ההעלאה נקטעה. בדקו את החיבור לרשת ונסו שוב'), { code: 'network' });
        }
        if (!res.ok || !data || !data.ok) throw Object.assign(new Error((data && data.error && data.error.message) || 'ההעלאה נכשלה'), { code: data && data.error && data.error.code });
        return data.import;
    }

    SF.route('#/admin/content/import/:edition', async (main, { edition }) => {
        const d = await SF.api('import-list', { params: { edition } });
        document.getElementById('back').dataset.to = '#/admin/content/e/' + edition;
        const L = d.limits;
        const input = h('input', { type: 'file', accept: L.exts.map(x => '.' + x).join(','), 'aria-label': 'קובץ לייבוא' });
        const ownerBox = h('label', { class: 'switch', hidden: true }, h('input', { type: 'checkbox' }), h('span', null, 'יש לנו הרשאה מבעל הזכויות לייבא את הספר הזה (EPUB — רק בהרשאת בעליו)'));
        const err = h('p', { class: 'error', role: 'alert', hidden: true });
        const go = h('button', { class: 'btn primary', type: 'button', disabled: true }, '📥 העלאה');
        input.addEventListener('change', () => {
            const f = input.files[0];
            err.hidden = true;
            ownerBox.hidden = !(f && /\.epub$/i.test(f.name));
            go.disabled = !f;
            if (f && f.size > L.max) { err.textContent = 'הקובץ גדול מדי (' + mb(f.size) + ') — עד ' + mb(L.max); err.hidden = false; go.disabled = true; }
        });
        go.addEventListener('click', async () => {
            const f = input.files[0];
            if (!f) return;
            go.disabled = true;
            go.textContent = 'מעלה…';
            err.hidden = true;
            try {
                const im = await upload(edition, f, ownerBox.querySelector('input').checked);
                SF.go('#/admin/content/i/' + im.pid);
            } catch (e) {
                err.textContent = e.message; err.hidden = false;
                go.disabled = false; go.textContent = '📥 העלאה';
            }
        });
        main.replaceChildren(
            h('p', { class: 'hint' }, d.title.name + ' · ' + d.edition.label),
            h('section', { class: 'summary' },
                h('div', { class: 'line' }, h('b', null, 'ייבוא קובץ לטיוטה')),
                h('div', { class: 'line muted' }, 'Word (docx) · EPUB · HTML · PDF · תמונה (png / jpeg / webp) — עד ' + mb(L.max) + ' לקובץ'),
                !L.zip ? h('p', { class: 'error' }, 'בשרת אין תמיכה ב-zip — Word ו-EPUB לא ייובאו') : null,
                !L.images ? h('p', { class: 'hint' }, 'בשרת אין עיבוד תמונות (GD) — תמונות מתוך EPUB לא ייובאו') : null,
                input, ownerBox, err,
                h('div', { class: 'toolbar' }, go),
                h('p', { class: 'hint' }, 'הייבוא יוצר טיוטה בלבד — לא מפרסם, ולא משנה "ציבורי". קודם בודקים ומתקנים, ורק אז בעורך מפרסמים.'),
                d.has_draft ? h('p', { class: 'hint' }, '⚠️ במהדורה יש טיוטה פתוחה. אפשר לייבא ולמפות, אבל כדי להכניס לטיוטה — קודם לפרסם או לבטל אותה.') : null),
            h('h3', { class: 'sec-title' }, 'ייבואים קודמים'),
            h('ul', { class: 'list' }, d.imports.length ? d.imports.map(im => h('li', null, h('a', { class: 'use-row', href: '#/admin/content/i/' + im.pid },
                h('span', { class: 'use-ic', 'aria-hidden': 'true' }, (KIND[im.kind] || ['📦'])[0]),
                h('span', { class: 'use-tx' }, h('b', { class: 'one' }, im.name || (KIND[im.kind] || [, im.kind])[1]),
                    h('small', null, [(KIND[im.kind] || [, im.kind])[1], mb(im.size), SF.when(im.created_at), im.created_by].filter(Boolean).join(' · '))),
                chip(im.status), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))) : h('li', { class: 'empty' }, 'עוד לא יובא כאן קובץ')));
    }, { title: 'ייבוא', parent: '#/admin/content' });

    // ── pdf.js (נטען רק כשצריך) ──────────────────────────────
    let pdfjs = null;
    function loadPdfjs() {
        if (pdfjs) return pdfjs;
        pdfjs = new Promise((resolve, reject) => {
            const s = h('script', { src: 'assets/vendor/pdfjs/pdf.min.js' });
            s.onload = () => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdfjs/pdf.worker.min.js'; resolve(window.pdfjsLib); };
            s.onerror = () => { pdfjs = null; reject(new Error('לא ניתן לטעון את רכיב ה-PDF')); };
            document.head.append(s);
        });
        return pdfjs;
    }
    const openPdf = async pid => (await loadPdfjs()).getDocument({ url: 'api.php?action=import-source&import=' + pid, withCredentials: true, isEvalSupported: false }).promise;

    /** הטקסט של כל עמוד: שורה לכל hasEOL / ירידת שורה, ושורה ריקה במרווח גדול (פסקה חדשה). */
    async function pdfText(pdf, onPage) {
        const pages = [];
        for (let i = 1; i <= pdf.numPages; i++) {
            const tc = await (await pdf.getPage(i)).getTextContent();
            let out = '', lastY = null, lastH = 0;
            for (const it of tc.items) {
                if (typeof it.str !== 'string') continue;
                const y = it.transform[5], hgt = it.height || lastH || 10;
                if (lastY !== null && Math.abs(y - lastY) > hgt * 1.8) out = out.replace(/\n?$/, '\n\n');
                else if (lastY !== null && Math.abs(y - lastY) > hgt * 0.5 && !out.endsWith('\n')) out += '\n';
                out += it.str;
                if (it.hasEOL) out += '\n';
                if (it.str) { lastY = y; lastH = hgt; }
            }
            pages.push(out.trim());
            onPage(i, pdf.numPages);
        }
        return pages;
    }

    /** העמודים המקוריים: canvas לכל עמוד, 6 בכל פעם. */
    function pdfPages(pid) {
        const box = h('div', { class: 'imp-pages' });
        const more = h('button', { class: 'btn ghost', type: 'button', hidden: true }, 'עוד עמודים');
        let pdf = null, next = 1;
        async function draw() {
            more.hidden = true;
            const end = Math.min(pdf.numPages, next + 5);
            for (; next <= end; next++) {
                const page = await pdf.getPage(next);
                const vp = page.getViewport({ scale: 1.4 });
                const c = h('canvas', { width: Math.round(vp.width), height: Math.round(vp.height), 'aria-label': 'עמוד ' + next });
                box.append(h('figure', { class: 'imp-page' }, c, h('figcaption', null, 'עמוד ' + next)));
                await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
            }
            more.hidden = next > pdf.numPages;
        }
        more.addEventListener('click', draw);
        openPdf(pid).then(p => { pdf = p; return draw(); }).catch(e => box.replaceChildren(h('p', { class: 'error' }, e.message)));
        return h('div', null, box, more);
    }

    // ── ייבוא אחד ───────────────────────────────────────────
    SF.route('#/admin/content/i/:pid', async (main, { pid }) => {
        let alive = true;
        SF.onLeave(() => { alive = false; });
        let im = (await SF.api('import-status', { params: { import: pid } })).import;
        document.getElementById('back').dataset.to = '#/admin/content/import/' + im.edition;
        const head = h('section', { class: 'summary' });
        const body = h('div');
        main.replaceChildren(head, body);

        function drawHead(extra) {
            const k = KIND[im.kind] || ['📦', im.kind];
            head.replaceChildren(
                h('div', { class: 'line' }, h('b', { class: 'one', style: 'flex:1' }, k[0] + ' ' + (im.name || k[1])), chip(im.status)),
                h('div', { class: 'line muted' }, [k[1], mb(im.size), im.pages ? im.pages + ' עמודים' : null, im.owner_permission ? 'בהרשאת בעליו' : null].filter(Boolean).join(' · ')),
                ...(extra ? [extra] : []));
        }
        const bar = (pct, label) => h('div', { class: 'imp-bar', role: 'progressbar', 'aria-valuenow': String(pct), 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-label': label },
            h('span', { style: 'width:' + pct + '%' }), h('small', null, label + ' · ' + pct + '%'));

        async function poll() {
            while (alive && ['queued', 'processing'].includes(im.status)) {
                await new Promise(r => setTimeout(r, 1200));
                if (!alive) return;
                try { im = (await SF.api('import-status', { params: { import: pid } })).import; } catch (e) { /* ננסה שוב */ }
                if (['queued', 'processing'].includes(im.status)) drawHead(bar(im.progress, 'מעבד'));
            }
        }
        async function process() {
            drawHead(bar(im.progress, 'מעבד'));
            body.replaceChildren(h('p', { class: 'hint' }, 'העיבוד רץ ברקע. אפשר לצאת מהמסך — הוא ימשיך, והתוצאה תחכה כאן.'));
            const running = SF.api('import-run', { body: { import: pid } }).then(r => { im = r.import; }).catch(() => {});
            await Promise.race([running, poll()]);
            await running;
            if (alive && ['queued', 'processing'].includes(im.status)) await poll();   // סבב של cron תפס את הנעילה
            if (alive) draw();
        }
        async function extract() {
            drawHead(bar(0, 'מחלץ טקסט מה-PDF'));
            body.replaceChildren(h('p', { class: 'hint' }, 'הטקסט מחולץ כאן, בדפדפן. ב-PDF גדול זה לוקח זמן — לא לסגור את המסך.'));
            try {
                const pdf = await openPdf(pid);
                const pages = await pdfText(pdf, (i, n) => { if (alive) drawHead(bar(Math.round(100 * i / n), 'מחלץ טקסט · עמוד ' + i + ' מתוך ' + n)); });
                if (!alive) return;
                im = (await SF.api('import-pdf-text', { body: { import: pid, pages } })).import;
                process();
            } catch (e) {
                drawHead();
                body.replaceChildren(h('p', { class: 'error', role: 'alert' }, e.message), h('button', { class: 'btn primary', type: 'button', onclick: extract }, 'נסו שוב'));
            }
        }

        function warnings() {
            if (!im.warnings.length) return null;
            return h('details', { class: 'imp-warn', open: im.warnings.length < 6 }, h('summary', null, '⚠️ ' + im.warnings.length + ' דברים לבדוק'),
                h('ul', { class: 'issue-list' }, im.warnings.map(w => h('li', { class: 'issue warn' }, h('span', { 'aria-hidden': 'true' }, '⚠️'), h('span', null, w.msg)))));
        }

        async function map(op, i, title) {
            try { im = (await SF.api('import-map', { body: { import: pid, op, i, title } })).import; draw(); } catch (e) { SF.toast(e.message, true); }
        }
        function tree() {
            const n = im.tree.length;
            return h('ol', { class: 'list imp-tree' }, n ? im.tree.map((it, i) => h('li', { class: 'imp-item' },
                h('div', { class: 'imp-tx' },
                    h('b', { class: 'one' }, it.type === 'section' ? '§ ' + (it.title || 'ללא כותרת') : '¶ טקסט מחוץ למקטע'),
                    h('small', null, [it.sub ? it.sub + ' מקטעי משנה' : null, it.chars.toLocaleString('he-IL') + ' תווים'].filter(Boolean).join(' · ')),
                    it.preview ? h('small', { class: 'one' }, it.preview) : null),
                h('div', { class: 'imp-acts' },
                    it.type === 'section' ? h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'שינוי כותרת', onclick: () => {
                        const t = prompt('כותרת המקטע', it.title || '');
                        if (t !== null && t.trim()) map('rename', i, t.trim());
                    } }, '✏️') : null,
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'למעלה', disabled: i === 0, onclick: () => map('up', i) }, '↑'),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'למטה', disabled: i === n - 1, onclick: () => map('down', i) }, '↓'),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'מיזוג לתוך המקטע שלפניו', disabled: i === 0 || im.tree[i - 1].type !== 'section',
                        onclick: () => { if (confirm('למזג את "' + (it.title || 'הטקסט') + '" לתוך המקטע שלפניו?')) map('merge', i); } }, '⤴')))) :
                h('li', { class: 'empty' }, 'לא זוהו מקטעים'));
        }

        async function preview(box) {
            const d = (await SF.api('import-status', { params: { import: pid, html: '1' } })).import;
            const doc = SFRender.render(d.html || '', { conds: 'all', marks: true, filesBase: FILES });
            SFRender.bind(doc);
            box.replaceChildren(h('div', { class: 'imp-flow' }, doc));
        }

        async function apply(btn) {
            btn.disabled = true;
            try {
                const r = await SF.api('import-apply', { body: { import: pid } });
                SF.toast('נוצרה טיוטה ' + r.draft.n + ' — לא פורסמה');
                SF.go('#/admin/content/e/' + r.edition);
            } catch (e) { SF.toast(e.message, true); btn.disabled = false; }
        }

        function draw() {
            drawHead();
            if (im.status === 'awaiting_text') return extract();
            if (['queued', 'processing'].includes(im.status)) return process();
            if (im.status === 'failed') {
                return body.replaceChildren(
                    h('div', { class: 'fail', role: 'alert' }, h('div', { class: 'ic', 'aria-hidden': 'true' }, '⚠️'),
                        h('p', null, im.error || 'העיבוד נכשל'),
                        h('button', { class: 'btn primary', type: 'button', onclick: async () => {
                            try { im = (await SF.api('import-retry', { body: { import: pid } })).import; draw(); } catch (e) { SF.toast(e.message, true); }
                        } }, 'נסו שוב')));
            }
            const pagesOnly = !im.has_text;
            const isPdf = im.kind === 'pdf' || im.kind === 'pdf_scan';
            const parts = [warnings()];
            if (pagesOnly) {
                parts.push(h('p', { class: 'hint' }, 'מקור של עמודים בלבד: אין בו טקסט, אין חיפוש ואין "טקסט מזוהה". העמודים נשמרים כמו שהם.'));
                parts.push(isPdf ? pdfPages(pid) : im.page_src ? h('figure', { class: 'imp-page' }, h('img', { src: FILES + im.page_src, alt: 'העמוד המקורי' })) : null);
                return body.replaceChildren(...parts.filter(Boolean));
            }
            const flowBox = h('div', { class: 'imp-flowbox' });
            const panes = { map: h('div', null, h('p', { class: 'hint' }, 'המקטעים שזוהו. אפשר לשנות כותרת, להזיז ולמזג — לפני שהתוכן נכנס לטיוטה.'), tree()), flow: flowBox };
            if (isPdf) panes.pages = h('div');
            const tabsDef = [['map', '🗂️ מקטעים'], ['flow', isPdf ? '📃 טקסט זורם' : '👁 תצוגה מקדימה']];
            if (isPdf) tabsDef.push(['pages', '📄 עמודים']);
            const tabs = h('div', { class: 'seg-tabs', role: 'tablist' }, tabsDef.map(([k, label]) => h('button', { type: 'button', role: 'tab', 'aria-selected': String(k === 'map'), dataset: { tab: k }, onclick: () => tab(k) }, label)));
            function tab(k) {
                for (const b of tabs.children) b.setAttribute('aria-selected', String(b.dataset.tab === k));
                for (const [name, el] of Object.entries(panes)) el.hidden = name !== k;
                if (k === 'flow') flow();
                if (k === 'pages' && !panes.pages.firstChild) panes.pages.append(pdfPages(pid));
            }
            function flow() {
                if (isPdf && !im.text_checked) {
                    flowBox.replaceChildren(h('p', { class: 'hint' }, 'הטקסט חולץ אוטומטית מה-PDF ולא מוגה. התצוגה הזורמת מוסתרת עד שמסמנים שהוא נבדק מול העמודים.'));
                } else {
                    flowBox.replaceChildren(h('p', { class: 'loading' }, 'טוען…'));
                    preview(flowBox).catch(e => flowBox.replaceChildren(h('p', { class: 'error' }, e.message)));
                }
            }
            const checked = isPdf ? h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: im.text_checked, onchange: async e => {
                try { im = (await SF.api('import-text-checked', { body: { import: pid, on: e.target.checked } })).import; flow(); } catch (x) { e.target.checked = !e.target.checked; SF.toast(x.message, true); }
            } }), h('span', null, 'בדקתי את הטקסט מול העמודים המקוריים')) : null;
            const applyBtn = h('button', { class: 'btn primary', type: 'button', disabled: im.status !== 'ready', onclick: e => apply(e.currentTarget) },
                im.status === 'applied' ? '✓ נכנס לטיוטה ' + (im.applied_n || '') : '✏️ לטיוטה ולעורך');
            parts.push(h('div', { class: 'toolbar' }, h('span', { class: 'hint', style: 'flex:1;margin:0' }, 'נכנס כטיוטה בלבד — הפרסום בעורך'), applyBtn), checked, tabs, panes.map, flowBox, panes.pages || null);
            flowBox.hidden = true;
            if (panes.pages) panes.pages.hidden = true;
            body.replaceChildren(...parts.filter(Boolean));
        }
        draw();
    }, { title: 'ייבוא', parent: '#/admin/content' });
})(window.SF);
