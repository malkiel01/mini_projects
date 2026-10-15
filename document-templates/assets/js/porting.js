// ייצוא וייבוא של תבנית עם כל ההגדרות — בקובץ עצמו (README: "ייצוא תבנית"):
//   PDF  — ה-PDF המקורי, וההגדרות כקובץ מצורף בתוכו (dt-template.json). נבנה כאן, בדפדפן (pdf-lib).
//   Word — ה-docx המקורי, וההגדרות בתוכו (השרת: tpl_export&as=docx, ובהעלאה — docx_package).
//   JSON — לכל תבנית (גם שנכתבה בכלי או תמונה): קובץ ‎.dt.json‎ קריא.
(function () {
  'use strict';

  const PDFLIB = 'https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js';
  const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  const ENTRY = 'dt-template.json';

  const load = (src) => new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) { const t = setInterval(() => { if (window.PDFLib || window.pdfjsLib) { clearInterval(t); resolve(); } }, 50); setTimeout(() => { clearInterval(t); resolve(); }, 5000); return; }
    const s = document.createElement('script');
    s.src = src; s.onload = resolve; s.onerror = () => reject(new Error('טעינת רכיב נכשלה — בדקו את החיבור'));
    document.head.append(s);
  });
  const pdfLib = async () => { if (!window.PDFLib) await load(PDFLIB); return window.PDFLib; };
  const pdfJs = async () => {
    if (!window.pdfjsLib) await load(PDFJS + 'pdf.min.js');
    if (!pdfjsLib.GlobalWorkerOptions.workerSrc) pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
    return window.pdfjsLib;
  };
  const unb64 = (s) => Uint8Array.from(atob(s), c => c.charCodeAt(0));
  const safe = (n) => String(n || 'תבנית').replace(/[\\/:*?"<>|]+/g, '_');
  function save(blob, name) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name;
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 30000);
  }
  async function getJson(id) {
    const res = await fetch('api.php?action=tpl_export&id=' + id, { credentials: 'same-origin' });
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'הייצוא נכשל');
    return res.json();
  }

  /** האפשרויות לתבנית: הקובץ עצמו (PDF / Word) עם ההגדרות, ותמיד גם JSON. */
  const kinds = (t) => [
    ...(['pdf_parsed', 'pdf_locked'].includes(t.source) ? [['pdf', '📄 PDF עם כל ההגדרות', 'ה-PDF המקורי, וההגדרות בתוכו — מעלים אותו כמו כל PDF']] : []),
    ...(t.source === 'docx' ? [['docx', '📝 Word עם כל ההגדרות', 'קובץ ה-Word, וההגדרות בתוכו — מעלים אותו כמו כל Word']] : []),
    ['json', '{ } קובץ הגדרות (JSON)', 'לכל סוג תבנית; קריא — אפשר לפתוח ולבדוק'],
  ];

  async function exportAs(t, kind) {
    if (kind === 'docx') {
      const res = await fetch('api.php?action=tpl_export&as=docx&id=' + t.id, { credentials: 'same-origin' });
      if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'הייצוא נכשל');
      save(await res.blob(), safe(t.name) + '.docx');
      return;
    }
    const p = await getJson(t.id);
    if (kind === 'json') { save(new Blob([JSON.stringify(p, null, 1)], { type: 'application/json' }), safe(t.name) + '.dt.json'); return; }
    // PDF: המקורי + ההגדרות כקובץ מצורף (בלי עותק נוסף של ה-PDF בתוכן)
    const { PDFDocument, PDFName } = await pdfLib();
    const pdf = await PDFDocument.load(unb64(p.asset.data), { ignoreEncryption: true });
    // הגדרות ישנות שכבר מצורפות לקובץ — מוחלפות, לא נערמות
    try { const names = pdf.catalog.lookup(PDFName.of('Names')); if (names) names.delete(PDFName.of('EmbeddedFiles')); } catch (e) { /* אין */ }
    const settings = { ...p, asset: { mime: p.asset.mime, embedded: true } };
    await pdf.attach(new TextEncoder().encode(JSON.stringify(settings)), ENTRY,
      { mimeType: 'application/json', description: 'הגדרות התבנית — תבניות מסמכים', creationDate: new Date(), modificationDate: new Date() });
    save(new Blob([await pdf.save({ updateFieldAppearances: false })], { type: 'application/pdf' }), safe(t.name) + '.pdf');
  }

  /** PDF עם הגדרות מוכנות (dt-template.json עם fields — כמו תבנית שהוכנה מראש): העורך מחיל אותן בפתיחה הראשונה. */
  async function hasFields(file) {
    try {
      const lib = await pdfJs();
      const doc = await lib.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
      for (const it of Object.values((await doc.getAttachments()) || {})) {
        if (it.filename !== ENTRY) continue;
        try { const p = JSON.parse(new TextDecoder().decode(it.content)); if (p && Array.isArray(p.fields) && p.fields.length) return true; }
        catch (e) { /* לא שלנו */ }
      }
    } catch (e) { /* לא PDF תקין */ }
    return false;
  }

  /** PDF שיוצא מהמערכת: ההגדרות שבתוכו, או null. */
  async function readPdf(file) {
    try {
      const lib = await pdfJs();
      const doc = await lib.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
      const att = await doc.getAttachments();
      for (const it of Object.values(att || {})) {
        if (it.filename !== ENTRY) continue;
        try { const p = JSON.parse(new TextDecoder().decode(it.content)); if (p && p.format === 'dt-template' && p.schema) return p; }
        catch (e) { /* לא ההגדרות שלנו */ }
      }
      return null;
    } catch (e) { return null; }
  }

  /** ייבוא PDF עם הגדרות: ה-PDF נשמר נקי (בלי הקובץ המצורף), וההגדרות בונות את התבנית. */
  async function importPdf(file, p, name) {
    const { PDFDocument, PDFName } = await pdfLib();
    const pdf = await PDFDocument.load(new Uint8Array(await file.arrayBuffer()), { ignoreEncryption: true });
    try { const names = pdf.catalog.lookup(PDFName.of('Names')); if (names) names.delete(PDFName.of('EmbeddedFiles')); } catch (e) { /* אין */ }
    const clean = await pdf.save({ updateFieldAppearances: false });
    // ההגדרות לחוד, וה-PDF כקובץ רגיל (לא base64 — שלא יעבור את מגבלת ההעלאה של השרת)
    const pkg = { ...p, asset: { mime: 'application/pdf', embedded: true } };
    const fd = new FormData();
    fd.append('file', new Blob([JSON.stringify(pkg)], { type: 'application/json' }), 'template.dt.json');
    fd.append('asset', new Blob([clean], { type: 'application/pdf' }), 'template.pdf');
    if (name) fd.append('name', name);
    return window.DT.api('tpl_import', fd);
  }

  /** חלון הייצוא: בוחרים פורמט. */
  function exportDialog(t) {
    const { el, sheet, notice } = window.DT;
    const out = el('div');
    let dlg;
    dlg = sheet('📦 ייצוא: ' + t.name, el('div', { class: 'field-sheet' },
      el('p', { class: 'hint' }, 'כל ההגדרות עוברות: השדות, הטבלאות, הנוסחאות, הסגנונות, המספור, רשימות הערכים והגופנים. ' +
        'מייבאים ב"+ תבנית חדשה" — גם אצל משתמש אחר. לא עוברים: אנשי קשר קבועים ומקורות נתונים חיצוניים.'),
      el('div', { class: 'tiles' }, kinds(t).map(([k, label, sub]) => window.DT.tile(label.split(' ')[0], label.split(' ').slice(1).join(' '), async () => {
        out.replaceChildren(notice('מכין…'));
        try { await exportAs(t, k); dlg.close(); window.DT.toast('הקובץ ירד. מעלים אותו ב"+ תבנית חדשה" — והתבנית נבנית עם כל ההגדרות.'); }
        catch (e) { out.replaceChildren(notice(e.message, 'bad')); }
      }, { sub }))), out));
  }

  window.DTPort = { exportDialog, exportAs, readPdf, importPdf, hasFields };
})();
