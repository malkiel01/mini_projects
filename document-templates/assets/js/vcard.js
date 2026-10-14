// קובץ אנשי קשר (vCard / ‎.vcf) — מה שהטלפון מייצא: שם, טלפון, מייל לכל כרטיס.
// אנדרואיד מייצא vCard 2.1 — עברית מקודדת ב-QUOTED-PRINTABLE ושורות ארוכות נשברות ב-"=";
// אייפון — vCard 3.0, עם שבירת שורה ברווח בתחילתה. שניהם נקראים כאן.
(function (root) {
  'use strict';

  /** =D7=99 → בייטים → UTF-8 */
  function qp(s, charset) {
    const bytes = [];
    for (let i = 0; i < s.length; i++) {
      if (s[i] === '=' && /^[0-9A-Fa-f]{2}$/.test(s.substr(i + 1, 2))) { bytes.push(parseInt(s.substr(i + 1, 2), 16)); i += 2; }
      else bytes.push(s.charCodeAt(i) & 0xff);
    }
    try { return new TextDecoder(charset || 'utf-8').decode(new Uint8Array(bytes)); }
    catch (e) { return new TextDecoder('utf-8').decode(new Uint8Array(bytes)); }
  }
  const unescape = (s) => s.replace(/\\n/gi, ' ').replace(/\\([,;\\])/g, '$1');

  /** טקסט של קובץ vcf → [{name, phone, email}] */
  function parse(text) {
    const raw = String(text).replace(/^﻿/, '').replace(/\r\n?/g, '\n').split('\n');
    const lines = [];
    for (const l of raw) {
      const prev = lines[lines.length - 1];
      if (prev !== undefined && /^[ \t]/.test(l)) lines[lines.length - 1] = prev + l.slice(1);                 // vCard 3: המשך ברווח
      else if (prev !== undefined && /QUOTED-PRINTABLE/i.test(prev.split(':')[0]) && prev.endsWith('=')) lines[lines.length - 1] = prev.slice(0, -1) + l;   // 2.1: "=" בסוף
      else lines.push(l);
    }
    const out = [];
    let cur = null;
    for (const l of lines) {
      const i = l.indexOf(':');
      if (i < 0) continue;
      const params = l.slice(0, i).split(';');
      const key = params[0].toUpperCase().replace(/^ITEM\d+\./, '');
      let val = l.slice(i + 1);
      if (key === 'BEGIN' && /VCARD/i.test(val)) { cur = { fn: '', n: '', phones: [], email: '' }; continue; }
      if (key === 'END' && cur) {
        const parts = cur.n.split(';').map(s => s.trim());   // משפחה;פרטי;אמצעי;תואר;סיומת
        const name = cur.fn.trim() || [parts[3], parts[1], parts[2], parts[0], parts[4]].filter(Boolean).join(' ');
        const cell = cur.phones.find(p => p.cell) || cur.phones[0];
        out.push({ name, phone: cell ? cell.v : '', email: cur.email });
        cur = null;
        continue;
      }
      if (!cur) continue;
      const P = params.slice(1).join(';').toUpperCase();
      if (/ENCODING=QUOTED-PRINTABLE|(^|;)QUOTED-PRINTABLE/.test(P)) val = qp(val, (P.match(/CHARSET=([\w-]+)/) || [])[1]);
      val = unescape(val).trim();
      if (key === 'FN') cur.fn = val;
      else if (key === 'N') cur.n = val;
      else if (key === 'TEL' && val) cur.phones.push({ v: val.replace(/^tel:/i, ''), cell: /CELL|MOBILE/.test(P) });
      else if (key === 'EMAIL' && val && !cur.email) cur.email = val.replace(/^mailto:/i, '');
    }
    return out.filter(c => c.name || c.phone || c.email);
  }

  const api = { parse };
  if (typeof module !== 'undefined') module.exports = api;
  else root.DTVcard = api;
})(typeof window !== 'undefined' ? window : globalThis);
