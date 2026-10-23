// בודק ‎.mbe — גוררים קובץ, רואים manifest, עץ שכבות, ייצוגים ובעיות. הכול בדפדפן, דרך הליבה עצמה.
// הקובץ עלול להיות עוין: כל טקסט ממנו נכנס כ-textContent, ותמונות — רק דרך <img> (סקריפט ב-SVG לא רץ שם).

import { open, PREVIEW, VERSION } from '../../../src/js/mbe.js';

const $ = s => document.querySelector(s);
const TAB_KEY = 'mbe-inspector-tab';
const IMG = /\.(png|jpe?g|gif|webp|svg)$/i;
const LAYER_IC = { raster: '🖼️', vector: '✒️', frame: '🔤', group: '📁' };
const SURFACE_IC = { page: '📄', artboard: '🎨', canvas: '🖌️' };
let current = null;
let urls = [];

function h(tag, attrs = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? '' : v);
    }
    for (const k of kids.flat()) if (k !== null && k !== undefined && k !== false) el.append(k instanceof Node ? k : String(k));
    return el;
}

function toast(msg, err = false) {
    const el = $('.toast');
    el.textContent = msg;
    el.classList.toggle('err', err);
    el.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}

const size = n => h('bdi', { dir: 'ltr' }, n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : `${(n / 1048576).toFixed(1)} MB`);
const sep = () => h('i', { class: 'sep', 'aria-hidden': 'true' }, '·');
const code = t => h('span', { class: 'code' }, t);

function blobUrl(data, path) {
    const type = path.endsWith('.svg') ? 'image/svg+xml' : 'image/' + (path.match(IMG)?.[1] || 'png').replace('jpg', 'jpeg');
    const u = URL.createObjectURL(new Blob([data], { type }));
    urls.push(u);
    return u;
}

async function load(file) {
    if (!file) return;
    urls.forEach(u => URL.revokeObjectURL(u));
    urls = [];
    try {
        const bytes = new Uint8Array(await file.arrayBuffer());
        current = { name: file.name, size: bytes.length, f: await open(bytes) };
    } catch (e) {
        toast('לא הצלחתי לקרוא את הקובץ: ' + e.message, true);
        return;
    }
    $('#drop').hidden = true;
    $('#view').hidden = false;
    render();
}

function render() {
    const { f, name, size: bytes } = current;
    const errors = f.issues.filter(i => i.level === 'error').length;
    const warnings = f.issues.length - errors;
    const m = f.manifest || {};
    const preview = f.files.get(PREVIEW);
    $('#sub').replaceChildren(h('bdi', {}, name));
    $('#summary').replaceChildren(h('div', { class: 'head' },
        h('div', { class: 'thumb' }, preview ? h('img', { src: blobUrl(preview, PREVIEW), alt: 'תמונה ממוזערת' }) : h('span', { class: 'muted' }, '—')),
        h('div', { class: 'body' },
            h('div', { class: 'name one' }, h('bdi', {}, name)),
            h('div', { class: 'muted one' }, m.kind ? `kind: ${m.kind}` : 'kind לא ידוע', sep(), `גרסה ${m.version ?? '?'}`, sep(), size(bytes)),
            h('div', { class: 'muted one' }, m.modified ? `נשמר ב-${m.modified.app} · ${String(m.modified.at || '').replace('T', ' ').replace('Z', '')}` : ''),
            h('div', { class: 'chips tight' },
                errors ? h('span', { class: 'chip bad' }, `${errors} שגיאות`) : h('span', { class: 'chip ok' }, 'תקין'),
                warnings ? h('span', { class: 'chip warn' }, `${warnings} אזהרות`) : null,
                f.renditions().some(r => r.stale) ? h('span', { class: 'chip warn' }, 'יש ייצוגים מיושנים') : null))));
    $('#n-issues').textContent = f.issues.length || '';
    $('#n-layers').textContent = countLayers(f.document) || '';
    $('#n-renditions').textContent = f.renditions().length || '';
    $('#n-parts').textContent = f.files.size + 3;
    let tab = 'issues';
    try { tab = sessionStorage.getItem(TAB_KEY) || tab; } catch { /* בלי אחסון — לשונית ברירת מחדל */ }
    if (tab === 'issues' && !f.issues.length && f.document) tab = 'layers';
    show(tab);
}

function countLayers(doc) {
    let n = 0;
    const walk = ls => Array.isArray(ls) && ls.forEach(l => { n++; walk(l?.children); });
    (Array.isArray(doc?.surfaces) ? doc.surfaces : []).forEach(s => walk(s?.layers));
    return n;
}

function show(tab) {
    for (const b of document.querySelectorAll('#tabs button')) b.setAttribute('aria-selected', String(b.dataset.tab === tab));
    try { sessionStorage.setItem(TAB_KEY, tab); } catch { /* לא חשוב */ }
    const f = current.f;
    const panel = $('#panel');
    if (tab === 'issues') panel.replaceChildren(issuesView(f));
    if (tab === 'layers') panel.replaceChildren(layersView(f));
    if (tab === 'renditions') panel.replaceChildren(renditionsView(f));
    if (tab === 'parts') panel.replaceChildren(partsView(f));
    if (tab === 'manifest') panel.replaceChildren(
        h('p', { class: 'hint' }, 'manifest.json כמו שהוא בקובץ, כולל שדות שהליבה לא מכירה (הם נשמרים).'),
        h('pre', { class: 'json' }, f.manifest ? JSON.stringify(f.manifest, null, 2) : '— לא נקרא —'));
}

function issuesView(f) {
    if (!f.issues.length) return h('p', { class: 'empty' }, '✅ אין בעיות — הקובץ עובר את כל בדיקות הליבה.');
    return h('ul', { class: 'list' }, f.issues.map(i => h('li', { class: 'card' },
        h('span', { class: 'level', 'aria-label': i.level === 'error' ? 'שגיאה' : 'אזהרה' }, i.level === 'error' ? '⛔' : '⚠️'),
        h('div', { class: 'body' },
            h('div', { class: 'l2', style: 'white-space:normal;color:var(--ink)' }, i.message),
            h('div', { class: 'l2' }, code(i.code), i.path ? [sep(), code(i.path)] : null)))));
}

function layersView(f) {
    const doc = f.document;
    if (!doc) return h('p', { class: 'empty' }, 'document.json לא נקרא — ראו בלשונית "בעיות".');
    const surfaces = Array.isArray(doc.surfaces) ? doc.surfaces : [];
    if (!surfaces.length) return h('p', { class: 'empty' }, 'אין משטחים במסמך.');
    const flows = doc.flows && typeof doc.flows === 'object' ? doc.flows : {};
    const node = (ic, title, sub) => h('div', { class: 'node' },
        h('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
        h('span', { class: 'use-tx' }, h('b', {}, title), sub ? h('small', {}, sub) : null));
    const layer = l => {
        if (!l || typeof l !== 'object') return h('li', {}, node('❓', 'שכבה לא תקינה'));
        const bits = [l.type, l.id];
        if (l.blend && l.blend !== 'normal') bits.push(l.blend);
        if (typeof l.opacity === 'number' && l.opacity !== 1) bits.push(`${Math.round(l.opacity * 100)}%`);
        if (l.visible === false) bits.push('מוסתרת');
        if (l.locked) bits.push('🔒');
        if (l.flow) bits.push(`שטף ${l.flow}${flows[l.flow]?.engine ? ' (' + flows[l.flow].engine + ')' : ''}`);
        const kids = Array.isArray(l.children) && l.children.length ? h('ul', { class: 'tree' }, l.children.map(layer)) : null;
        return h('li', {}, node(LAYER_IC[l.type] || '◻️', l.name || l.id || '—', bits.filter(Boolean).join(' · ')), kids);
    };
    return h('ul', { class: 'list tree' }, surfaces.map(s => h('li', {},
        node(SURFACE_IC[s?.type] || '▭', `${s?.type || 'משטח'} ${s?.id || ''}`, Array.isArray(s?.size) ? s.size.join(' × ') + ' ' + (doc.units || '') : ''),
        Array.isArray(s?.layers) && s.layers.length ? h('ul', { class: 'tree' }, s.layers.map(layer)) : null)));
}

function download(path, data) {
    const a = h('a', { href: URL.createObjectURL(new Blob([data])), download: path.split('/').pop() });
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

function renditionsView(f) {
    const rs = f.renditions();
    if (!rs.length) return h('p', { class: 'empty' }, 'אין ייצוגים בקובץ.');
    return h('div', {},
        h('p', { class: 'hint' }, 'ייצוג "מיושן" נגזר מגרסה קודמת של document.json — האפליקציה תחדש אותו בשמירה הבאה.'),
        h('div', { class: 'list' }, rs.map(r => h('div', { class: 'rend' },
            h('div', { class: 'top-line' },
                h('div', { class: 'body' },
                    h('div', { class: 'one' }, h('b', {}, r.path.replace('renditions/', ''))),
                    h('div', { class: 'muted one' }, h('bdi', { dir: 'ltr' }, r.type || '?'), sep(), size(r.data.length), r.by ? [sep(), r.by] : null)),
                h('span', { class: r.stale ? 'chip warn' : 'chip ok' }, r.stale ? 'מיושן' : 'עדכני'),
                h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הורדה', title: 'הורדה', onclick: () => download(r.path, r.data) }, '⬇️')),
            r.gaps?.length ? h('ul', { class: 'gaps' }, r.gaps.map(g => h('li', {}, g))) : null,
            IMG.test(r.path) ? h('img', { class: 'rend-img', src: blobUrl(r.data, r.path), alt: r.path }) : null))));
}

function partsView(f) {
    const rows = [['mimetype', null], ['manifest.json', null], ['document.json', null], ...[...f.files].sort(([a], [b]) => a < b ? -1 : 1)];
    const ic = p => p.startsWith('media/') ? '🖼️' : p.startsWith('renditions/') ? '📤' : p.startsWith('apps/') ? '🧩' : ['mimetype', 'manifest.json', 'document.json'].includes(p) ? '📋' : '❔';
    return h('div', {},
        h('p', { class: 'hint' }, '❔ = חלק שהליבה לא מכירה — נשמר בית-לבית. 🧩 = מידע פרטי של אפליקציה.'),
        h('ul', { class: 'list' }, rows.map(([p, d]) => h('li', { class: 'card' },
            h('span', { class: 'level', 'aria-hidden': 'true' }, ic(p)),
            h('div', { class: 'body' }, h('div', { class: 'l2 code', style: 'color:var(--ink);text-align:right' }, p)),
            d ? h('span', { class: 'muted', style: 'font-size:.8rem' }, size(d.length)) : null))));
}

$('#tabs').addEventListener('click', e => { const b = e.target.closest('button[data-tab]'); if (b && current) show(b.dataset.tab); });
for (const id of ['#pick', '#pick2']) $(id).addEventListener('change', e => { load(e.target.files[0]); e.target.value = ''; });
let depth = 0;
addEventListener('dragenter', e => { e.preventDefault(); depth++; document.body.classList.add('dragging'); });
addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; document.body.classList.remove('dragging'); } });
addEventListener('dragover', e => e.preventDefault());
addEventListener('drop', e => { e.preventDefault(); depth = 0; document.body.classList.remove('dragging'); load(e.dataTransfer.files[0]); });
$('#sub').textContent = `ליבת הפורמט · גרסה ${VERSION}`;
