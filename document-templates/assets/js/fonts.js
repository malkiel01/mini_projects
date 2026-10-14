// גופנים שהמשתמש העלה: טעינה לתצוגה (FontFace) ולהטמעה ב-PDF — מאותו קובץ.
// ctx — מאיפה מותר לקבל את הקובץ: { tpl } בעורך, { doc } במסמך, { token } לממלא בקישור.
(function () {
  'use strict';

  const DEFAULT = "Alef, Arial, sans-serif";
  const bytes = new Map();    // id → Promise<ArrayBuffer>
  const faces = new Map();    // id → Promise<FontFace>

  const family = (id) => id ? `'dtf-${id}', ${DEFAULT}` : DEFAULT;

  function fetchBytes(id, ctx = {}) {
    if (!bytes.has(id)) {
      const url = ctx.token ? 'api.php?action=fill_font&id=' + id
        : 'api.php?action=font_file&id=' + id + (ctx.doc ? '&doc=' + ctx.doc : ctx.tpl ? '&tpl=' + ctx.tpl : '');
      bytes.set(id, fetch(url, { credentials: 'same-origin', headers: ctx.token ? { 'X-Fill-Token': ctx.token } : {} })
        .then(r => { if (!r.ok) throw new Error('הגופן לא נטען'); return r.arrayBuffer(); })
        .catch(e => { bytes.delete(id); throw e; }));
    }
    return bytes.get(id);
  }

  /** טוען לתצוגה. נכשל — נשארים עם גופן ברירת המחדל (לא שוברים את הדף). */
  function load(id, ctx) {
    if (!id) return Promise.resolve();
    if (!faces.has(id)) {
      faces.set(id, fetchBytes(id, ctx).then(async (buf) => {
        const face = new FontFace('dtf-' + id, buf.slice(0));
        await face.load();
        document.fonts.add(face);
        return face;
      }).catch(() => null));
    }
    return faces.get(id);
  }

  /** כל הגופנים של מבנה — לפני ציור השדות. */
  const loadAll = (schema, ctx) => Promise.all([...new Set((schema.fields || []).map(f => f.font).filter(Boolean))].map(id => load(id, ctx)));

  /** הוספה מבחוץ (אחרי העלאה): הקובץ כבר אצלנו. */
  function add(id, buf) {
    bytes.set(id, Promise.resolve(buf));
    faces.delete(id);
    return load(id);
  }

  window.DTFonts = { family, load, loadAll, bytes: fetchBytes, add, DEFAULT };
})();
