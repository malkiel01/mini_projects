/**
 * הדבקה מ-Word עם העיצוב. Word מדביק HTML עם הערות מותנות, <o:p>, סגנונות mso, ורשימות שהן בעצם
 * פסקאות עם תבליט "מזויף" (mso-list). כאן מנקים ובונים מחדש ul/ol מקוננים — והשאר (מודגש, צבע, גודל,
 * גופן, יישור, ריווח) נקרא ע"י ההרחבות עצמן מה-style.
 */

export function cleanPastedHtml(html) {
    if (!/class="?Mso|urn:schemas-microsoft-com|mso-/i.test(html)) return html;
    html = html
        .replace(/<!--\[if[\s\S]*?<!\[endif\]-->/gi, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<\/?o:p[^>]*>/gi, '')
        .replace(/<\/?(v|w|m):[^>]*>/gi, '');
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('style, meta, link, xml, script').forEach(el => el.remove());
    // תמונות מ-Word מפנות לקבצים מקומיים (file://) שהדפדפן לא יכול לקרוא
    doc.querySelectorAll('img').forEach(img => { if (!/^(https?:|data:)/.test(img.getAttribute('src') || '')) img.remove(); });

    // כותרות ושם מסמך לפי המחלקה
    doc.querySelectorAll('p.MsoTitle').forEach(p => p.classList.add('wp-title'));
    for (let i = 1; i <= 3; i++) doc.querySelectorAll(`p.MsoHeading${i}`).forEach(p => replaceTag(p, 'h' + i));

    // רשימות: פסקאות רצופות עם mso-list:lN levelK
    for (const p of [...doc.body.querySelectorAll('p')]) {
        if (!p.isConnected || !listInfo(p)) continue;
        const run = [];
        for (let cur = p; cur && listInfo(cur); cur = nextElement(cur)) run.push({ el: cur, ...listInfo(cur) });
        buildLists(run);
    }

    // ניקוי סגנונות: משאירים רק מה שהעורך מבין
    const keep = /^(color|background(-color)?|font-size|font-family|font-weight|font-style|text-decoration|text-align|line-height|margin-top|margin-bottom|margin-right|margin-left|direction)$/i;
    doc.body.querySelectorAll('[style]').forEach(el => {
        const out = [];
        for (const d of el.getAttribute('style').split(';')) {
            const [k, ...v] = d.split(':');
            const key = (k || '').trim();
            let val = v.join(':').trim();
            if (!keep.test(key) || !val || /^mso-/i.test(key)) continue;
            if (/font-family/i.test(key)) val = val.split(',')[0].replace(/["']/g, '').trim();
            out.push(`${key}:${val}`);
        }
        if (out.length) el.setAttribute('style', out.join(';')); else el.removeAttribute('style');
    });
    doc.body.querySelectorAll('[class]').forEach(el => { if (!el.classList.contains('wp-title')) el.removeAttribute('class'); });
    doc.body.querySelectorAll('span:not([style])').forEach(s => s.replaceWith(...s.childNodes));
    return doc.body.innerHTML;
}

function nextElement(el) {
    let n = el.nextSibling;
    while (n && (n.nodeType === 3 && !n.textContent.trim())) n = n.nextSibling;
    return n && n.nodeType === 1 ? n : null;
}

function replaceTag(el, tag) {
    const n = el.ownerDocument.createElement(tag);
    for (const a of el.attributes) n.setAttribute(a.name, a.value);
    n.append(...el.childNodes);
    el.replaceWith(n);
    return n;
}

function listInfo(p) {
    if (!p || p.tagName !== 'P') return null;
    const st = p.getAttribute('style') || '';
    const m = /mso-list:\s*l(\d+)\s+level(\d+)/i.exec(st);
    if (!m) return null;
    const marker = p.querySelector('span[style*="mso-list:Ignore"], span[style*="mso-list: Ignore"]');
    const text = (marker?.textContent || '').replace(/ /g, ' ').trim();
    let type = 'bullet';
    if (/^[0-9]+[.)]?$/.test(text)) type = 'decimal';
    else if (/^[א-ת]{1,3}[.)]?$/.test(text)) type = 'hebrew';
    else if (/^[ivxlcdm]+[.)]?$/i.test(text)) type = 'roman';
    else if (/^[a-z][.)]$/i.test(text)) type = 'decimal';
    return { list: m[1], level: +m[2], type, marker };
}

/** מחליף רצף פסקאות-רשימה ב-ul/ol מקוננים לפי הרמה. */
function buildLists(run) {
    const doc = run[0].el.ownerDocument;
    const root = doc.createElement('div');
    run[0].el.before(root);
    const stack = [];   // [{level, list}]
    for (const item of run) {
        item.marker?.remove();
        while (stack.length && stack[stack.length - 1].level > item.level) stack.pop();
        let top = stack[stack.length - 1];
        if (!top || top.level < item.level) {
            const list = doc.createElement(item.type === 'bullet' ? 'ul' : 'ol');
            if (item.type !== 'bullet') list.setAttribute('data-list', item.type);
            if (top) (top.list.lastElementChild || top.list).append(list);
            else root.append(list);
            stack.push(top = { level: item.level, list });
        }
        const li = doc.createElement('li');
        const p = doc.createElement('p');
        p.append(...item.el.childNodes);
        li.append(p);
        top.list.append(li);
        item.el.remove();
    }
    root.replaceWith(...root.childNodes);
}
