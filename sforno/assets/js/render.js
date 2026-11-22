/*
 * ספורנו — מנוע התצוגה (אפיון 02 §6, האפיון §6). **אותו מודול** בתצוגה המקדימה בניהול ובקורא הציבורי (04).
 *
 *   SFRender.render(sourceHtml, settings, env) → רכיב ‹div class="sf-doc"›
 *
 * הקלט הוא source_html של גרסה — כבר מנוקה בשרת (lib/markup.php). גם כך, כאן נבנה DOM חדש **רק** מרשימת היתרים
 * משלו: כל רכיב נוצר ב-createElement, כל טקסט ב-createTextNode, ותכונות — רק אלה שהמנוע עצמו קובע. אין הכנסת HTML כמחרוזת.
 * התאמות אישיות (settings) משנות את התצוגה בלבד, לא את המקור.
 *
 * settings:
 *   conds  — 'all' (תצוגה מקדימה: הכול מוצג ומסומן) או {rule: true|false}; כלל שאינו במפה → condDefault
 *   condDefault — true / false (ברירת מחדל: false — תנאי שלא הוכרע לא מוצג)
 *   notes  — {instruction: true|false, commentary: true|false} — פתוחה מראש? (ברירת מחדל: הוראה פתוחה, פירוש סגור)
 *   marks  — true: סימוני עורך גלויים (נקודות ציון, תנאים) — לתצוגה המקדימה
 *   filesBase — קידומת לנתיב התמונות (files/…)
 * env: {document, parse(html) → צומת שילדיו הם התוכן}. ברירת מחדל: הדפדפן (DOMParser — מסמך שאינו מריץ דבר).
 *
 * המנוע משתמש רק ב: createElement, createTextNode, setAttribute, getAttribute, appendChild, childNodes, nodeType,
 * localName, nodeValue — כדי שבדיקת ה-snapshot (tests/render.test.mjs) תרוץ גם בלי דפדפן.
 */
'use strict';

(function (root, factory) {
    const m = factory();
    if (typeof module === 'object' && module.exports) module.exports = m;
    else root.SFRender = m;
})(typeof self !== 'undefined' ? self : this, function () {
    const STYLES = ['large', 'small', 'bold', 'red', 'gray', 'center'];
    const HTML = ['p', 'h2', 'h3', 'h4', 'br', 'b', 'strong', 'i', 'em', 'small', 'span', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'td', 'th'];
    const BLOCK = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'table', 'sf-section'];
    const SID = /^[a-z0-9]{8}$/;
    const TO = /^([a-z0-9]{16}:)?[a-z0-9]{8}$/;
    const RULE = /^[a-z][a-z0-9_-]{0,39}$/;
    const LANG = /^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8}){0,3}$/;
    const IMG = /^files\/[A-Za-z0-9_-][A-Za-z0-9._/-]{0,200}\.(png|jpe?g|gif|webp)$/i;
    const NOTE_LABEL = { instruction: 'הוראה', commentary: 'פירוש' };

    function defaultEnv() {
        return {
            document: document,
            parse: html => new DOMParser().parseFromString('<!doctype html><html><body>' + html, 'text/html').body,
        };
    }

    const attr = (n, k) => (n.getAttribute ? n.getAttribute(k) : null);
    const kids = n => Array.prototype.slice.call(n.childNodes || []);
    const hasBlock = n => kids(n).some(c => c.nodeType === 1 && (BLOCK.includes(c.localName) || hasBlock(c)));

    function condShown(rule, s) {
        if (s.conds === 'all') return true;
        const map = s.conds || {};
        if (rule && Object.prototype.hasOwnProperty.call(map, rule)) return !!map[rule];
        return !!s.condDefault;
    }

    function render(source, settings, env) {
        const s = settings || {};
        const e = env || defaultEnv();
        const doc = e.document;
        const el = (tag, attrs) => {
            const x = doc.createElement(tag);
            for (const k of Object.keys(attrs || {})) if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) x.setAttribute(k, attrs[k] === true ? '' : String(attrs[k]));
            return x;
        };
        const common = (n, x) => {
            const dir = attr(n, 'dir');
            if (dir === 'rtl' || dir === 'ltr' || dir === 'auto') x.setAttribute('dir', dir);
            const lang = attr(n, 'lang');
            if (lang && LANG.test(lang)) x.setAttribute('lang', lang);
            const sid = attr(n, 'sid');
            if (sid && SID.test(sid)) x.setAttribute('data-sid', sid);
            return x;
        };

        function walk(from, to, depth) {
            for (const n of kids(from)) {
                if (n.nodeType === 3) { to.appendChild(doc.createTextNode(n.nodeValue)); continue; }
                if (n.nodeType !== 1) continue;
                const tag = n.localName;
                let x = null;
                let into = null;
                switch (tag) {
                    case 'sf-section': {
                        x = common(n, el('section', { class: 'sf-sec' }));
                        const title = attr(n, 'title');
                        if (title) {
                            const hx = el('h' + Math.min(2 + depth, 4), { class: 'sf-sec-title' });
                            hx.appendChild(doc.createTextNode(title));
                            x.appendChild(hx);
                        }
                        walk(n, x, depth + 1);
                        to.appendChild(x);
                        continue;
                    }
                    case 'sf-anchor': {
                        x = common(n, el('span', { class: 'sf-anchor', 'data-title': attr(n, 'title') || null }));
                        break;
                    }
                    case 'sf-style': {
                        const v = attr(n, 'v');
                        if (!STYLES.includes(v)) { walk(n, to, depth); continue; }
                        x = el(hasBlock(n) ? 'div' : 'span', { class: 'sf-st sf-st-' + v });
                        break;
                    }
                    case 'sf-cond': {
                        const rule = attr(n, 'rule');
                        if (!condShown(rule, s)) continue;
                        x = el(hasBlock(n) ? 'div' : 'span', { class: 'sf-cond', 'data-rule': s.marks && rule && RULE.test(rule) ? rule : null });
                        break;
                    }
                    case 'sf-note': {
                        const kind = attr(n, 'kind') === 'commentary' ? 'commentary' : 'instruction';
                        const notes = s.notes || {};
                        const open = Object.prototype.hasOwnProperty.call(notes, kind) ? !!notes[kind] : kind === 'instruction';
                        const block = hasBlock(n);
                        x = el(block ? 'div' : 'span', { class: 'sf-note sf-note-' + kind });
                        const btn = el('button', { type: 'button', class: 'sf-note-btn', 'aria-expanded': String(open) });
                        btn.appendChild(doc.createTextNode(NOTE_LABEL[kind]));
                        x.appendChild(btn);
                        into = el(block ? 'div' : 'span', { class: 'sf-note-body', hidden: !open });
                        x.appendChild(into);
                        break;
                    }
                    case 'sf-link': {
                        const t = attr(n, 'to');
                        if (!t || !TO.test(t)) { walk(n, to, depth); continue; }
                        x = el('button', { type: 'button', class: 'sf-link', 'data-to': t });
                        break;
                    }
                    case 'sf-audio': {
                        x = common(n, el('span', { class: 'sf-audio', hidden: true }));   // עוגן בלבד בשלב א'
                        to.appendChild(x);
                        continue;
                    }
                    case 'img': {
                        const src = attr(n, 'src');
                        if (!src || !IMG.test(src) || src.indexOf('..') >= 0 || src.indexOf('//') >= 0) continue;
                        x = el('img', { src: (s.filesBase || '') + src, alt: attr(n, 'alt') || '', loading: 'lazy' });
                        to.appendChild(x);
                        continue;
                    }
                    default: {
                        if (!HTML.includes(tag)) { walk(n, to, depth); continue; }   // לא ברשימה — רק הטקסט
                        x = common(n, el(tag));
                        if (tag === 'td' || tag === 'th') {
                            for (const k of ['colspan', 'rowspan']) { const v = attr(n, k); if (v && /^[1-9][0-9]?$/.test(v)) x.setAttribute(k, v); }
                        }
                    }
                }
                if (tag !== 'br') walk(n, into || x, depth);
                to.appendChild(x);
            }
        }

        const out = el('div', { class: 'sf-doc' + (s.marks ? ' sf-marks' : ''), lang: 'he', dir: 'rtl' });
        walk(e.parse(String(source || '')), out, 0);
        return out;
    }

    /** האינטראקציה: הערה נפתחת בלחיצה, קישור פנימי קופץ. מאזין אחד על השורש — ה-DOM עצמו נשאר נקי. */
    function bind(rootEl, opts) {
        const o = opts || {};
        rootEl.addEventListener('click', ev => {
            const btn = ev.target.closest('.sf-note-btn');
            if (btn && rootEl.contains(btn)) {
                const body = btn.nextElementSibling;
                const open = btn.getAttribute('aria-expanded') !== 'true';
                btn.setAttribute('aria-expanded', String(open));
                if (body) body.hidden = !open;
                return;
            }
            const link = ev.target.closest('.sf-link');
            if (link && rootEl.contains(link)) {
                const to = link.getAttribute('data-to') || '';
                if (!TO.test(to)) return;
                if (to.indexOf(':') >= 0) { if (o.onLink) o.onLink(to); return; }
                const target = rootEl.querySelector('[data-sid="' + to + '"]');
                if (target) {
                    target.scrollIntoView({ block: 'start', behavior: 'smooth' });
                    target.classList.add('sf-hit');
                    setTimeout(() => target.classList.remove('sf-hit'), 1600);
                }
            }
        });
        return rootEl;
    }

    /** כמו sf_norm בשרת (lib/markup.php): בלי ניקוד וטעמים, סופיות מאוחדות, רווחים מאוחדים. לחיפוש (04). */
    function normalize(str) {
        const FIN = { 'ך': 'כ', 'ם': 'מ', 'ן': 'נ', 'ף': 'פ', 'ץ': 'צ' };
        const TOF = { 'כ': 'ך', 'מ': 'ם', 'נ': 'ן', 'פ': 'ף', 'צ': 'ץ' };
        return String(str || '')
            .replace(/[\u05BE\u05C0\u05C3\u05C6]/g, ' ')
            .replace(/[\u0591-\u05C7\u200B-\u200F\u202A-\u202E\u2066-\u2069\uFEFF]/g, '')
            .replace(/[\u05DA\u05DD\u05DF\u05E3\u05E5]/g, c => FIN[c])
            .replace(/[\u05DB\u05DE\u05E0\u05E4\u05E6](?![\u05D0-\u05EA])/g, c => TOF[c])
            .replace(/[\s\u00A0\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]+/g, ' ')
            .trim()
            .toLowerCase();
    }

    return { render, bind, normalize, STYLES };
});
