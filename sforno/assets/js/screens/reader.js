/*
 * הקורא (אפיון 04 §1) — #/r/<edition>[/<sid>]. הטקסט: SFRender על ה-source_html של הגרסה שפורסמה (read-revision).
 *
 *   - שני מצבים, מתג אחד: גלילה רציפה, או מקטע יחיד עם "הבא" / "הקודם". כל שינוי (מצב, גופן, ניקוד, מנהג) — לוכדים את
 *     המקום (sid + היסט יחסי) לפני, ומחזירים אחרי. המקום נשמר במכשיר (pos:<edition>).
 *   - הקשה קצרה = סימון מקום (סרגל פעולות); לחיצה ארוכה / גרירה = בחירת טקסט של הדפדפן; קישור — רק על ‹button.sf-link›.
 *   - הסתרת ניקוד / טעמים — בתצוגה בלבד. ההעתקה (גם של בחירה) מעתיקה את המקור, עם שורת מקור.
 *   - סידור: מנהגים קבועים מול שינוי זמני לתפילה הזו; תנאי שלא נבחר — סימון "▸ למי שאומר", נפתח לצפייה בלבד.
 *   - קטע מוסתר — ‹hidden› (גם מחוץ לעץ הנגישות).
 * בלי הכנסת HTML כמחרוזת: הכול ב-SF.h ו-SFRender.
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const C = window.SFReaderCore;

    const THEMES = { auto: 'אוטומטי', light: 'בהיר', sepia: 'ספיה', dark: 'כהה', contrast: 'ניגודיות' };
    const FONTS = { text: 'דוד', serif: 'סריף', sans: 'בלי סריף' };
    const WIDTHS = { s: 'צר', m: 'בינוני', l: 'רחב' };
    const COLORS = { yellow: 'צהוב', green: 'ירוק', blue: 'כחול', pink: 'ורוד' };
    const DEFAULTS = { font: 'text', size: 22, weight: 400, theme: 'auto', ink: '', bg: '', width: 'm', lh: 1.8, niqqud: true, taamim: true,
        mode: 'scroll', instructions: true, wake: false };
    const BLOCKS = 'p[data-sid], li[data-sid], h2[data-sid], h3[data-sid], h4[data-sid]';

    SF.readerSettings = async () => Object.assign({}, DEFAULTS, await SF.store.get('settings', {}));

    let catalog = null;
    SF.catalog = async fresh => {
        if (!catalog || fresh) catalog = await SF.api('read-catalog');
        return catalog;
    };

    /** גיליון (phone-first-design §5) בלי "שמירה" — השינויים חיים. */
    SF.sheet = function sheet(title, body, opts = {}) {
        const dlg = h('dialog', { 'aria-label': title, class: opts.cls || null },
            h('form', { method: 'dialog', onsubmit: e => e.preventDefault() },
                h('header', { class: 'sheet-head' },
                    h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', onclick: () => dlg.close() }, '✕'),
                    h('h2', null, title), opts.action || h('span')),
                h('div', { class: 'sheet-body' }, body)));
        dlg.addEventListener('close', () => { dlg.remove(); if (opts.onClose) opts.onClose(); });
        document.body.append(dlg);
        dlg.showModal();
        return dlg;
    };

    /** pdf.js — מקומי, נטען רק כשצריך. */
    let pdfjs = null;
    SF.loadPdfjs = () => pdfjs || (pdfjs = new Promise((resolve, reject) => {
        const s = h('script', { src: 'assets/vendor/pdfjs/pdf.min.js' });
        s.onload = () => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdfjs/pdf.worker.min.js'; resolve(window.pdfjsLib); };
        s.onerror = () => { pdfjs = null; reject(new Error('לא ניתן לטעון את רכיב ה-PDF')); };
        document.head.append(s);
    }));

    /** הגדרות התצוגה → משתנים על ‹body› (כדי שגם הרקע מסביב יתחלף). */
    function applySettings(s) {
        const b = document.body;
        b.classList.add('reading');
        b.dataset.rdTheme = s.theme;
        const st = b.style;
        st.setProperty('--rd-size', s.size + 'px');
        st.setProperty('--rd-weight', String(s.weight));
        st.setProperty('--rd-lh', String(s.lh));
        st.setProperty('--rd-width', { s: '30em', m: '40em', l: '100%' }[s.width] || '40em');
        st.setProperty('--rd-font', { text: "var(--font-text, 'David Libre', serif)", serif: "'Frank Ruehl CLM', 'Times New Roman', serif", sans: 'system-ui, Arial, sans-serif' }[s.font]);
        if (s.ink) st.setProperty('--rd-ink-x', s.ink); else st.removeProperty('--rd-ink-x');
        if (s.bg) st.setProperty('--rd-bg-x', s.bg); else st.removeProperty('--rd-bg-x');
    }
    function clearSettings() {
        const b = document.body;
        b.classList.remove('reading', 'rd-wide');
        delete b.dataset.rdTheme;
        for (const k of ['--rd-size', '--rd-weight', '--rd-lh', '--rd-width', '--rd-font', '--rd-ink-x', '--rd-bg-x']) b.style.removeProperty(k);
    }
    SF.applyReaderSettings = applySettings;

    /** כל צומתי הטקסט של רכיב, בסדר המסמך. */
    function textNodes(el) {
        const out = [];
        const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
        while (w.nextNode()) out.push(w.currentNode);
        return out;
    }
    const skipNode = n => !!(n.parentElement && (n.parentElement.closest('button') || n.parentElement.closest('[hidden]')));

    /** הדגשת התאמות החיפוש (גם בכתיב חלופי — המילים מגיעות מהשרת אחרי המילון). */
    function markWords(el, words) {
        if (!words || !words.length) return null;
        const re = /[א-ת֑-ֽֿ-ׂׄ-ׇ'a-zA-Z0-9]+/g;
        let first = null;
        for (const n of textNodes(el)) {
            if (skipNode(n)) continue;
            const hits = [];
            let m;
            while ((m = re.exec(n.nodeValue))) {
                const tok = SFRender.normalize(m[0]);
                if (words.some(w => C.wordHit(w, tok))) hits.push([m.index, m[0].length]);
            }
            for (let i = hits.length - 1; i >= 0; i--) {
                const [at, len] = hits[i];
                const rest = n.splitText(at);
                rest.splitText(len);
                const mk = h('mark', { class: 'sf-match' }, rest.nodeValue);
                rest.parentNode.replaceChild(mk, rest);
                first = mk;
            }
        }
        return first;
    }

    async function screen(main, { edition, sid }) {
        const back = document.getElementById('back');
        const s = await SF.readerSettings();
        const [data, cat] = await Promise.all([SF.api('read-revision', { params: { edition } }), SF.catalog().catch(() => ({ customs: [] }))]);
        const kind = data.title.kind;
        back.dataset.to = kind === 'siddur' ? '#/siddur' : kind === 'tehillim' ? '#/tehillim' : '#/';
        document.title = data.title.name + ' · ספורנו';
        const groups = kind === 'siddur' ? cat.customs || [] : [];
        const condNames = {};
        for (const g of groups) for (const c of g.customs) condNames[c.rule] = c.name;
        let cst = C.conds.enter(groups, await SF.store.get('customs', {}));
        const saved = await SF.store.get('pos:' + edition, null);
        let marks = await SF.store.get('marks', []);
        const tehillim = kind === 'tehillim' ? await SF.store.get('tehillim', { done: {}, group: [], names: [] }) : null;
        const chapters = kind === 'tehillim' ? C.chapters(data.toc) : [];
        let showPages = !data.html;
        let wake = null;
        let here = null;          // ה-sid שסומן בהקשה
        let doc = null, orig = null;
        let units = [], unit = 0;

        applySettings(s);
        SF.onLeave(() => {
            savePos();
            cst = C.conds.leave(cst);   // יציאה מהתפילה: השינוי הזמני מתבטל
            clearSettings();
            document.removeEventListener('copy', onCopy);
            document.removeEventListener('visibilitychange', onVis);
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('pagehide', savePos);
            if (wake) wake.release().catch(() => {});
            SF.pendingMark = null;
        });

        // ── מבנה המסך ─────────────────────────────────────────
        const text = h('article', { class: 'rd-text', 'aria-label': data.title.name });
        const tools = h('aside', { class: 'rd-tools', id: 'rd-tools', 'aria-label': 'כלים' });
        const toolsBtn = h('button', { class: 'icon-btn rd-tools-btn', type: 'button', 'aria-label': 'כלים', 'aria-expanded': 'false', 'aria-controls': 'rd-tools',
            onclick: () => { const open = tools.classList.toggle('open'); toolsBtn.setAttribute('aria-expanded', String(open)); } }, '⋯');
        const modeBtn = h('button', { class: 'icon-btn', type: 'button', onclick: () => setMode(s.mode === 'scroll' ? 'section' : 'scroll') });
        const temp = h('div', { class: 'rd-temp', role: 'status', hidden: true });
        const names = h('div', { class: 'rd-names', hidden: true });
        const nav = h('nav', { class: 'rd-nav', 'aria-label': 'מעבר בין מקטעים', hidden: true });
        const act = h('div', { class: 'rd-act', role: 'toolbar', 'aria-label': 'פעולות על המקום שסומן', hidden: true });
        const pagesBox = h('div', { class: 'rd-pages', hidden: true });
        const bar = h('div', { class: 'rd-bar', role: 'toolbar', 'aria-label': 'הקורא' },
            h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'תוכן העניינים', onclick: tocSheet, disabled: !data.html }, '☰'),
            modeBtn,
            h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הגדרות תצוגה', onclick: settingsSheet }, 'Aa'),
            groups.length ? h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'מנהגים', onclick: customsSheet }, '🔀') : null,
            h('a', { class: 'icon-btn', href: '#/search/' + edition, 'aria-label': 'חיפוש בספר' }, '🔍'),
            h('span', { class: 'rd-where one', 'aria-live': 'polite' }),
            toolsBtn);
        const body = h('div', { class: 'rd-body' }, h('div', { class: 'rd-main' }, names, temp, text, pagesBox), tools);
        main.replaceChildren(h('div', { class: 'rd' }, bar, body, nav, act));
        document.body.classList.toggle('rd-wide', true);
        const where = bar.querySelector('.rd-where');

        // ── הטקסט ─────────────────────────────────────────────
        const strip = () => (!s.niqqud || !s.taamim ? { niqqud: !s.niqqud, taamim: !s.taamim } : null);
        const rset = st => ({ conds: C.conds.map(cst, groups), condDefault: false, condCollapsed: true, condNames,
            notes: { instruction: s.instructions, commentary: false }, filesBase: 'api.php?action=read-file&path=', strip: st });

        function draw() {
            if (!data.html) { text.replaceChildren(h('p', { class: 'hint' }, 'למהדורה הזו יש עמודים בלבד.')); return; }
            doc = SFRender.render(data.html, rset(strip()));
            orig = null;
            SFRender.bind(doc, {
                onLink: to => { const [ep, sd] = to.split(':'); SF.go('#/r/' + ep + '/' + sd); },
                onJump: sd => go(sd, true),
            });
            text.replaceChildren(doc);
            paintMarks();
            units = unitList();
            if (s.mode === 'section') showUnit(Math.min(unit, units.length - 1));
            tempBanner();
        }
        /** אותו טקסט בלי הסתרת ניקוד — המקור להעתקה (לא מוצג). */
        const origDoc = () => orig || (orig = SFRender.render(data.html, rset(null)));

        // ── מקטע יחיד ─────────────────────────────────────────
        const isSec = el => el.localName === 'section' && el.classList.contains('sf-sec');
        const ownContent = el => [...el.childNodes].some(c => (c.nodeType === 3 && c.nodeValue.trim()) || (c.nodeType === 1 && !isSec(c) && !c.classList.contains('sf-sec-title')));
        function unitList() {
            const secs = [...doc.querySelectorAll('section.sf-sec')];
            const out = ownContent(doc) ? [doc] : [];
            for (const x of secs) if (ownContent(x) || !x.querySelector('section.sf-sec')) out.push(x);
            return out.length ? out : [doc];
        }
        function unitOf(el) {
            for (let x = el; x; x = x.parentElement) { const i = units.indexOf(x); if (i >= 0) return i; if (x === doc) break; }
            return 0;
        }
        function showAll() {
            for (const x of doc.querySelectorAll('[data-uh]')) { x.hidden = false; delete x.dataset.uh; }
        }
        function hide(x, on) {
            if (on && !x.hidden) { x.hidden = true; x.dataset.uh = '1'; } else if (!on && x.dataset.uh) { x.hidden = false; delete x.dataset.uh; }
        }
        function showUnit(i) {
            showAll();
            unit = Math.max(0, Math.min(units.length - 1, i));
            const u = units[unit];
            const path = [];
            for (let x = u; x; x = x.parentElement) { path.push(x); if (x === doc) break; }
            for (const p of path) {
                for (const c of p.children) {
                    if (isSec(c)) hide(c, !path.includes(c));
                    else if (!c.classList.contains('sf-sec-title')) hide(c, p !== u);
                }
            }
            navBar();
        }
        const unitTitle = u => (u === doc ? data.title.name : (u.querySelector(':scope > .sf-sec-title') || {}).textContent || '');
        function navBar() {
            const on = s.mode === 'section' && !!data.html && !showPages;
            nav.hidden = on ? !act.hidden : true;
            modeBtn.textContent = s.mode === 'section' ? '📜' : '📄';
            modeBtn.setAttribute('aria-label', s.mode === 'section' ? 'מעבר לגלילה רציפה' : 'מעבר למקטע יחיד');
            if (!on) return;
            nav.replaceChildren(
                h('button', { class: 'btn ghost', type: 'button', disabled: unit === 0, onclick: () => step(-1) }, '› הקודם'),
                h('span', { class: 'one' }, (unit + 1) + ' / ' + units.length + ' · ' + unitTitle(units[unit])),
                h('button', { class: 'btn ghost', type: 'button', disabled: unit >= units.length - 1, onclick: () => step(1) }, 'הבא ‹'));
        }
        function step(d) {
            showUnit(unit + d);
            window.scrollTo({ top: 0 });
            savePos();
        }

        // ── המקום ─────────────────────────────────────────────
        const line = () => bar.getBoundingClientRect().bottom + 6;
        function capture() {
            if (!doc) return null;
            const rects = [];
            for (const x of doc.querySelectorAll('[data-sid]')) {
                const r = x.getBoundingClientRect();
                rects.push({ sid: x.dataset.sid, top: r.top, bottom: r.bottom });
            }
            return C.pickTop(rects, line());
        }
        function restore(p) {
            if (!p || !doc) return;
            const el = doc.querySelector('[data-sid="' + p.sid + '"]');
            if (!el) return;
            if (s.mode === 'section') showUnit(unitOf(el));
            const r = el.getBoundingClientRect();
            window.scrollTo({ top: window.scrollY + C.scrollDelta({ top: r.top, bottom: r.bottom }, p.off, line()) });
        }
        /** שינוי שמזיז את הטקסט: לוכדים לפני, מחזירים אחרי שהדפדפן סידר. */
        function keep(fn) {
            const p = capture();
            fn();
            restore(p);
            requestAnimationFrame(() => restore(p));
        }
        function savePos() {
            const p = capture();
            if (p) SF.store.set('pos:' + edition, { sid: p.sid, off: p.off, mode: s.mode, rev: data.revision && data.revision.pid });
            if (p) whereLabel(p.sid);
        }
        function whereLabel(sd) {
            const el = doc && doc.querySelector('[data-sid="' + sd + '"]');
            const sec = el && el.closest('section.sf-sec');
            where.textContent = sec ? unitTitle(sec) : '';
        }
        let scrollT = 0;
        function onScroll() { clearTimeout(scrollT); scrollT = setTimeout(savePos, 400); }
        /** קפיצה למקום (תוכן עניינים, קישור, שיתוף, תוצאת חיפוש). */
        function go(sd, flash) {
            const el = doc && doc.querySelector('[data-sid="' + sd + '"]');
            if (!el) { SF.toast('המקום לא נמצא בגרסה הזו', true); return false; }
            const cb = el.closest('.sf-cond-body');
            if (cb && cb.hidden) { const b = cb.previousElementSibling; if (b) b.click(); }
            restore({ sid: sd, off: 0 });
            if (flash && !el.matches('section')) { el.classList.add('sf-hit'); setTimeout(() => el.classList.remove('sf-hit'), 1600); }
            savePos();
            return true;
        }
        function setMode(m) {
            keep(() => {
                s.mode = m;
                if (m === 'scroll') showAll();
                SF.store.set('settings', s);
                navBar();
            });
        }

        // ── סימון מקום, פעולות ───────────────────────────────
        let downAt = 0;
        text.addEventListener('pointerdown', () => { downAt = Date.now(); });
        text.addEventListener('click', ev => {
            if (ev.target.closest('button, a, .sf-link, mark')) return;
            const sel = window.getSelection();
            if (sel && !sel.isCollapsed) return;              // בחירת טקסט — לא סימון
            if (Date.now() - downAt > 500) return;           // לחיצה ארוכה
            const el = ev.target.closest(BLOCKS) || ev.target.closest('[data-sid]');
            if (!el || !doc.contains(el)) return;
            mark(el.dataset.sid === here ? null : el.dataset.sid);
        });
        function mark(sd) {
            for (const x of doc.querySelectorAll('.rd-here')) x.classList.remove('rd-here');
            here = sd;
            if (!sd) { act.hidden = true; navBar(); return; }
            doc.querySelector('[data-sid="' + sd + '"]').classList.add('rd-here');
            actions();
        }
        const myMarks = sd => marks.filter(m => m.edition === edition && m.sid === sd);
        async function saveMarks(fn) { marks = await SF.store.update('marks', [], fn); paintMarks(); actions(); SF.put(tools, toolList()); }
        function paintMarks() {
            if (!doc) return;
            for (const x of doc.querySelectorAll('[data-hl], [data-bm], [data-note]')) { delete x.dataset.hl; delete x.dataset.bm; delete x.dataset.note; }
            for (const m of marks) {
                if (m.edition !== edition) continue;
                const el = doc.querySelector('[data-sid="' + m.sid + '"]');
                if (!el) continue;
                if (m.type === 'highlight') el.dataset.hl = m.color;
                if (m.type === 'bookmark') el.dataset.bm = '1';
                if (m.type === 'note') el.dataset.note = '1';
            }
        }
        function excerpt(sd) {
            const el = doc.querySelector('[data-sid="' + sd + '"]');
            return el ? blockText(el).replace(/\s+/g, ' ').slice(0, 70) : '';
        }
        function sectionOf(sd) {
            const el = doc.querySelector('[data-sid="' + sd + '"]');
            const sec = el && el.closest('section.sf-sec');
            return sec ? unitTitle(sec) : '';
        }
        function actions() {
            const bm = myMarks(here).find(m => m.type === 'bookmark');
            const hl = myMarks(here).find(m => m.type === 'highlight');
            const note = myMarks(here).find(m => m.type === 'note');
            const now = () => new Date().toISOString();
            const add = (type, extra) => saveMarks(list => list.concat([Object.assign({ id: SF.store.id(), edition, sid: here, type, excerpt: excerpt(here), created_at: now(), updated_at: now() }, extra)]));
            const drop = m => saveMarks(list => list.filter(x => x.id !== m.id));
            const btn = (ic, label, fn, pressed) => h('button', { class: 'icon-btn', type: 'button', 'aria-label': label, title: label, 'aria-pressed': pressed === undefined ? null : String(!!pressed), onclick: fn }, ic);
            const chap = kind === 'tehillim' ? chapterOf(here) : null;
            SF.put(act,
                btn('🔖', bm ? 'הסרת סימניה' : 'סימניה', () => (bm ? drop(bm) : add('bookmark')), !!bm),
                h('span', { class: 'rd-colors', role: 'group', 'aria-label': 'הדגשה' },
                    Object.entries(COLORS).map(([c, n]) => h('button', { class: 'rd-color', type: 'button', dataset: { c }, 'aria-label': 'הדגשה ב' + n, 'aria-pressed': String(!!hl && hl.color === c),
                        onclick: () => (hl && hl.color === c ? drop(hl) : saveMarks(list => list.filter(x => !(hl && x.id === hl.id)).concat([{ id: SF.store.id(), edition, sid: here, type: 'highlight', color: c, excerpt: excerpt(here), created_at: now(), updated_at: now() }]))) }))),
                btn('📝', note ? 'עריכת ההערה' : 'הערה אישית', () => noteSheet(note), !!note),
                btn('📋', 'העתקה עם מקור', copyHere),
                btn('🔗', 'שיתוף קישור לקטע', shareHere),
                kind === 'siddur' ? btn('⭐', 'למועדפים', favHere) : null,
                chap ? btn('✓', tehillim.done[chap.sid] ? 'פרק ' + chap.n + ' — סומן שסיימתי' : 'סיימתי את פרק ' + chap.n, () => done(chap), !!tehillim.done[chap.sid]) : null,
                btn('✕', 'ביטול הסימון', () => mark(null)));
            act.hidden = false;
            nav.hidden = true;
        }
        function noteSheet(note) {
            const ta = h('textarea', { rows: 5, maxlength: 2000, 'aria-label': 'ההערה' }, note ? note.note : '');
            const save = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
                const v = ta.value.trim();
                const now = new Date().toISOString();
                await saveMarks(list => {
                    const rest = list.filter(x => !(note && x.id === note.id));
                    return v ? rest.concat([Object.assign({}, note || { id: SF.store.id(), edition, sid: here, type: 'note', created_at: now }, { note: v, excerpt: excerpt(here), updated_at: now })]) : rest;
                });
                dlg.close();
            } }, 'שמירה');
            const dlg = SF.sheet('הערה אישית', h('div', { class: 'fields' }, h('p', { class: 'hint wide' }, SF.sync && SF.sync.active() ? 'נשמרת בחשבון שלך, מוצפנת, ומסתנכרנת בין המכשירים.' : 'נשמרת רק במכשיר הזה.'), h('label', { class: 'f wide' }, ta)), { action: save });
            ta.focus();
        }

        // ── העתקה עם מקור ────────────────────────────────────
        const source = sd => C.sourceLine(data.title.name, data.edition.label, sectionOf(sd));
        /** הטקסט של בלוק מהמקור (עם ניקוד), רק מה שגלוי ובתוך הטווח (אם יש). */
        function blockText(el, range) {
            const o = origDoc().querySelector('[data-sid="' + el.dataset.sid + '"]');
            const dn = textNodes(el), on = o ? textNodes(o) : [];
            const st = strip();
            let out = '';
            dn.forEach((n, i) => {
                if (skipNode(n) || (range && !range.intersectsNode(n))) return;
                let a = 0, z = n.nodeValue.length;
                if (range && n === range.startContainer) a = range.startOffset;
                if (range && n === range.endContainer) z = range.endOffset;
                const src = on[i] ? on[i].nodeValue : n.nodeValue;
                if (st && on[i]) { const m = SFRender.stripMap(src, st).map; out += src.slice(m[a], m[z]); } else out += src.slice(a, z);
            });
            return out;
        }
        function selectedBlocks(range) {
            const all = [...doc.querySelectorAll(BLOCKS)].filter(b => !b.closest('[hidden]') && range.intersectsNode(b));
            return all.filter(b => !all.some(o => o !== b && b.contains(o)));
        }
        function onCopy(e) {
            const sel = window.getSelection();
            if (!doc || !sel || sel.isCollapsed || !sel.rangeCount || !text.contains(sel.anchorNode)) return;
            const range = sel.getRangeAt(0);
            const blocks = selectedBlocks(range);
            const parts = blocks.length ? blocks.map(b => blockText(b, range)) : [sel.toString()];
            const first = blocks[0] && blocks[0].dataset.sid;
            e.clipboardData.setData('text/plain', C.copyText(parts, source(first)));
            e.preventDefault();
        }
        async function copyHere() {
            const el = doc.querySelector('[data-sid="' + here + '"]');
            const t = C.copyText([blockText(el)], source(here));
            try { await navigator.clipboard.writeText(t); SF.toast('הועתק, עם המקור'); } catch (e) { SF.toast('ההעתקה נחסמה בדפדפן', true); }
        }
        async function shareHere() {
            const url = C.shareLink(location.origin + location.pathname, edition, here);
            try {
                if (navigator.share) await navigator.share({ url });
                else { await navigator.clipboard.writeText(url); SF.toast('הקישור הועתק'); }
            } catch (e) { /* ביטול השיתוף — לא שגיאה */ }
        }
        async function favHere() {
            const name = sectionOf(here) || excerpt(here).slice(0, 40);
            await SF.store.update('favorites', [], list => list.some(f => f.edition === edition && f.sid === here) ? list
                : list.concat([{ id: SF.store.id(), edition, sid: here, name, created_at: new Date().toISOString() }]));
            SF.toast('נוסף למועדפים');
        }

        // ── תהילים ───────────────────────────────────────────
        function chapterOf(sd) {
            const el = doc && doc.querySelector('[data-sid="' + sd + '"]');
            if (!el) return null;
            for (const c of chapters) { const sec = doc.querySelector('[data-sid="' + c.sid + '"]'); if (sec && sec.contains(el)) return c; }
            return null;
        }
        async function done(chap) {
            const st = await SF.store.update('tehillim', { done: {}, group: [], names: [] }, t => {
                const d = Object.assign({}, t.done);
                if (d[chap.sid]) delete d[chap.sid]; else d[chap.sid] = new Date().toISOString();
                return Object.assign({}, t, { done: d });
            });
            tehillim.done = st.done;
            SF.toast(st.done[chap.sid] ? 'פרק ' + chap.n + ' — סומן שסיימת' : 'הסימון בוטל');
            if (here) actions();
            SF.put(tools, toolList());
        }
        function namesBanner() {
            const list = tehillim ? tehillim.names : [];
            names.hidden = !list.length;
            if (list.length) names.replaceChildren(h('span', { 'aria-hidden': 'true' }, '🕯️ '), list.map(n => (n.note ? n.note + ' ' : '') + n.name).join(' · '));
        }

        // ── מנהגים: קבוע מול זמני ───────────────────────────
        function tempBanner() {
            const on = groups.length && C.conds.isTemp(cst);
            temp.hidden = !on;
            if (!on) return;
            temp.replaceChildren(h('span', null, '🔀 שינוי זמני לתפילה הזו'),
                h('button', { class: 'btn sm', type: 'button', onclick: async () => { cst = C.conds.save(cst); await SF.store.set('customs', cst.perm); SF.toast('נשמר כקבוע'); tempBanner(); } }, 'לשמור כקבוע'),
                h('button', { class: 'btn sm ghost', type: 'button', onclick: () => { keep(() => { cst = C.conds.leave(cst); draw(); }); } }, 'ביטול'));
        }
        function customsSheet() {
            const cur = C.conds.current(cst);
            const body = h('div', null, h('p', { class: 'hint' }, 'שינוי כאן — לתפילה הזו בלבד, ומתבטל ביציאה. "לשמור כקבוע" — לכל הפעמים.'),
                groups.map(g => h('fieldset', { class: 'group' }, h('legend', { class: 'sec-title' }, g.customs.map(c => c.name).join(' / ')),
                    h('div', { class: 'seg' }, g.customs.map(c => h('label', null,
                        h('input', { type: 'radio', name: 'g-' + g.grp, value: c.rule, checked: cur[g.grp] === c.rule, onchange: () => { keep(() => { cst = C.conds.set(cst, g.grp, c.rule); draw(); }); } }),
                        h('span', null, c.name)))))));
            SF.sheet('מנהגים', body);
        }

        // ── גיליונות: תוכן עניינים, הגדרות ───────────────────
        function tocSheet() {
            const parent = {};
            for (const t of data.toc) parent[t.sid] = t.parent_sid;
            const depth = sd => { let d = 0; for (let p = parent[sd]; p; p = parent[p]) d++; return d; };
            const dlg = SF.sheet('תוכן העניינים', h('ul', { class: 'list rd-toc' }, data.toc.map(t => h('li', null,
                h('button', { type: 'button', class: 'use-row', style: '--d:' + depth(t.sid), onclick: () => { dlg.close(); go(t.sid, true); } },
                    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, t.kind === 'anchor' ? '📍' : '§'),
                    h('span', { class: 'use-tx' }, h('b', { class: 'one' }, t.title || '—')))))));
        }
        function settingsSheet() {
            const set = (k, v, redraw) => {
                keep(() => {
                    s[k] = v;
                    applySettings(s);
                    if (redraw) draw();
                });
                SF.store.set('settings', s);
            };
            const seg = (k, opts, redraw, cls) => h('div', { class: 'seg' + (cls ? ' ' + cls : ''), role: 'radiogroup' }, Object.entries(opts).map(([v, label]) => h('label', null,
                h('input', { type: 'radio', name: 'rd-' + k, value: v, checked: String(s[k]) === v, onchange: () => set(k, isNaN(+v) || v === '' ? v : +v, redraw) }),
                h('span', null, label))));
            const sw = (k, label, redraw) => h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!s[k], onchange: e => set(k, e.target.checked, redraw) }), h('span', null, label));
            const size = h('input', { type: 'range', min: 14, max: 44, step: 1, value: s.size, 'aria-label': 'גודל הגופן', oninput: e => { set('size', +e.target.value); sizeOut.textContent = e.target.value; } });
            const sizeOut = h('output', null, String(s.size));
            const lh = h('input', { type: 'range', min: 1.3, max: 2.6, step: 0.1, value: s.lh, 'aria-label': 'מרווח שורות', oninput: e => set('lh', +e.target.value) });
            const color = (k, label) => h('span', { class: 'rd-pick' }, h('input', { type: 'color', value: s[k] || (k === 'ink' ? '#16192b' : '#ffffff'), 'aria-label': label, onchange: e => set(k, e.target.value) }),
                h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'ביטול ' + label, onclick: () => set(k, '') }, '↺'));
            SF.sheet('תצוגה', h('div', { class: 'fields' },
                h('div', { class: 'f wide' }, h('span', null, 'גודל ', sizeOut), h('div', { class: 'rd-range' }, h('span', { 'aria-hidden': 'true' }, 'א'), size, h('span', { 'aria-hidden': 'true', style: 'font-size:1.4em' }, 'א'))),
                h('div', { class: 'f wide' }, h('span', null, 'גופן'), seg('font', FONTS)),
                h('div', { class: 'f wide' }, h('span', null, 'עובי'), seg('weight', { 400: 'רגיל', 700: 'מודגש' })),
                h('div', { class: 'f wide' }, h('span', null, 'רקע'), seg('theme', THEMES, false, 'grid')),
                h('div', { class: 'f' }, h('span', null, 'צבע טקסט'), color('ink', 'צבע טקסט')),
                h('div', { class: 'f' }, h('span', null, 'צבע רקע'), color('bg', 'צבע רקע')),
                h('div', { class: 'f wide' }, h('span', null, 'רוחב שורה'), seg('width', WIDTHS)),
                h('div', { class: 'f wide' }, h('span', null, 'מרווח שורות'), lh),
                h('div', { class: 'f wide' }, sw('niqqud', 'ניקוד', true), sw('taamim', 'טעמים', true), sw('instructions', 'הוראות פתוחות', true)),
                h('p', { class: 'hint wide' }, 'הסתרת ניקוד וטעמים — בתצוגה בלבד. העתקה מעתיקה את המקור המלא.')), { cls: 'rd-sheet' });
        }

        // ── כלים (סרגל צד במחשב; נפתח ונסגר בטלפון) ──────────
        function toolList() {
            const sw = (label, on, fn) => h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: on, onchange: e => fn(e.target.checked) }), h('span', null, label));
            const mine = marks.filter(m => m.edition === edition);
            const icon = { bookmark: '🔖', highlight: '🖍️', note: '📝' };
            return [
                h('h3', { class: 'sec-title' }, 'כלים'),
                sw('הוראות פתוחות', s.instructions, v => { keep(() => { s.instructions = v; draw(); }); SF.store.set('settings', s); }),
                sw('מסך דולק', s.wake, async v => { s.wake = v; SF.store.set('settings', s); if (v) wake = await C.wakeLock(navigator); else if (wake) { wake.release().catch(() => {}); wake = null; } }),
                data.pages && data.html ? sw('עמודים מקוריים', showPages, v => { showPages = v; pagesMode(); }) : null,
                h('h3', { class: 'sec-title' }, 'סימניות, הדגשות והערות'),
                mine.length ? h('ul', { class: 'list' }, mine.map(m => h('li', null, h('button', { type: 'button', class: 'use-row', onclick: () => { tools.classList.remove('open'); go(m.sid, true); } },
                    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, icon[m.type] || '•'),
                    h('span', { class: 'use-tx' }, h('b', { class: 'one' }, m.type === 'note' ? m.note : m.excerpt || '—'), h('small', { class: 'one' }, m.type === 'note' ? m.excerpt : sectionOf(m.sid))))))) :
                    h('p', { class: 'hint' }, 'הקשה קצרה על שורה מסמנת אותה, ואז אפשר להוסיף סימניה, הדגשה או הערה.'),
            ];
        }
        function onVis() { if (document.visibilityState === 'visible' && s.wake) C.wakeLock(navigator).then(w => { wake = w; }); }

        // ── עמודים (PDF סרוק / תמונה) ────────────────────────
        function pagesMode() {
            pagesBox.hidden = !showPages;
            text.hidden = showPages;
            navBar();
            if (showPages && !pagesBox.firstChild) pagesBox.append(pagesViewer(data.pages));
        }
        function pagesViewer(pg) {
            if (pg.kind === 'image') return h('figure', { class: 'imp-page' }, h('img', { src: 'api.php?action=read-file&path=' + pg.src, alt: 'העמוד המקורי' }));
            const canvas = h('canvas', { 'aria-label': 'עמוד' });
            const label = h('span', { class: 'one' }, '…');
            let pdf = null, n = 1, zoom = 1;
            const draw1 = async () => {
                const page = await pdf.getPage(n);
                const base = page.getViewport({ scale: 1 });
                const scale = Math.min(3, (pagesBox.clientWidth || 360) / base.width) * zoom * (window.devicePixelRatio || 1);
                const vp = page.getViewport({ scale });
                canvas.width = Math.round(vp.width); canvas.height = Math.round(vp.height);
                canvas.style.width = Math.round(vp.width / (window.devicePixelRatio || 1)) + 'px';
                canvas.setAttribute('aria-label', 'עמוד ' + n + ' מתוך ' + pdf.numPages);
                label.textContent = 'עמוד ' + n + ' מתוך ' + pdf.numPages;
                await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
            };
            const b = (ic, lab, fn) => h('button', { class: 'icon-btn', type: 'button', 'aria-label': lab, onclick: () => { if (pdf) { fn(); draw1(); } } }, ic);
            const box = h('div', null,
                h('div', { class: 'rd-pg-bar' }, b('›', 'העמוד הקודם', () => { n = Math.max(1, n - 1); }), label, b('‹', 'העמוד הבא', () => { n = Math.min(pdf.numPages, n + 1); }),
                    b('−', 'הקטנה', () => { zoom = Math.max(0.5, zoom - 0.25); }), b('+', 'הגדלה', () => { zoom = Math.min(4, zoom + 0.25); })),
                h('div', { class: 'rd-pg' }, canvas));
            SF.loadPdfjs().then(lib => lib.getDocument({ url: 'api.php?action=read-pages&edition=' + edition, withCredentials: true, isEvalSupported: false }).promise)
                .then(p => { pdf = p; return draw1(); }).catch(e => box.replaceChildren(h('p', { class: 'error' }, e.message)));
            return box;
        }

        // ── התחלה ─────────────────────────────────────────────
        draw();
        namesBanner();
        SF.put(tools, toolList());
        pagesMode();
        document.addEventListener('copy', onCopy);
        document.addEventListener('visibilitychange', onVis);
        if (s.wake) C.wakeLock(navigator).then(w => { wake = w; });
        await new Promise(r => requestAnimationFrame(r));
        const pm = SF.pendingMark && SF.pendingMark.edition === edition ? SF.pendingMark : null;
        SF.pendingMark = null;
        if (pm && doc) {
            const el = doc.querySelector('[data-sid="' + pm.sid + '"]');
            if (el) markWords(el, pm.words);
        }
        if (sid && doc) {
            go(sid, true);
            history.replaceState(null, '', '#/r/' + edition);   // מכאן — המקום השמור (רענון חוזר למקום הקריאה, לא לנקודת הכניסה)
        } else if (saved && doc) {
            if (saved.mode && saved.mode !== s.mode) { s.mode = saved.mode; if (s.mode === 'scroll') showAll(); }
            restore(saved);
            requestAnimationFrame(() => restore(saved));
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { if (!moved) restore(saved); });
        }
        navBar();
        if (doc) whereLabel((capture() || {}).sid);
        let moved = false;
        ['wheel', 'touchstart', 'keydown'].forEach(t => window.addEventListener(t, () => { moved = true; }, { once: true, passive: true }));
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('pagehide', savePos);
        SF.reader = { capture, restore, setMode, go, units: () => units.length, unit: () => unit, conds: () => cst, settings: s, step };   // לבדיקות בדפדפן
    }

    SF.route('#/r/:edition', screen, { title: 'קורא', parent: '#/' });
    SF.route('#/r/:edition/:sid', screen, { title: 'קורא', parent: '#/' });
})(window.SF);
