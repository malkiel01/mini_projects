/*
 * Dropbox בממשק (פרוסה 03): קבצי התיק (רשימה, תמונה ממוזערת, תצוגה, העלאה, פתיחת תיקייה) ומסך ההגדרות
 * (חיבור, תיקייה ראשית, סריקת התאמה, ומפתחות האפליקציה למפתח). כל הפעולות דרך api.php; שום אסימון בדפדפן.
 * נקודות החיבור ל-app.js: window.FD (request, toast, csrf, openSettings), האירועים fd:ready ו-fd:case, #dbxFiles בתיק,
 * ו-window.FDDropbox.settings(<section>) — הלשונית "Dropbox" בהגדרות.
 */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const FD = () => window.FD;
  let status = null, caseId = '', browsePath = '', host = null;

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }
  function auto(n) { n.dir = 'auto'; return n; }   // שמות קבצים ונתיבים: "צילום קבר.jpg" נשאר בסדר הנכון
  function btn(text, cls, onClick) { const b = el('button', 'btn ' + (cls || 'ghost sm'), text); b.type = 'button'; b.addEventListener('click', onClick); return b; }
  function when(iso) {
    if (!iso) return '';
    const d = new Date(iso); if (isNaN(d)) return '';
    return d.toLocaleDateString('he-IL', {day: 'numeric', month: 'numeric'}) + ' ' + d.toLocaleTimeString('he-IL', {hour: '2-digit', minute: '2-digit'});
  }
  function size(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' מגה' : Math.max(1, Math.round(n / 1024)) + ' ק"ב'; }
  function api(action, method, data, params) { return FD().request(action, method || 'GET', data === undefined ? null : data, params || {}); }
  async function busy(b, fn) { b.disabled = true; try { await fn(); } catch (e) { FD().toast(e.message, true); } finally { b.disabled = false; } }

  /* ---------- קבצי התיק ---------- */

  async function renderCase() {
    const box = $('dbxFiles'); if (!box) return;
    box.replaceChildren();
    if (!caseId) { box.append(el('p', 'hint', 'הקבצים יופיעו אחרי שמירת התיק.')); return; }
    const want = caseId;
    let data;
    try { data = await api('case_files', 'GET', null, {case_id: caseId}); }
    catch (e) { box.append(el('p', 'hint', e.message)); return; }
    if (want !== caseId) return;
    box.replaceChildren();
    const head = el('div', 'dbx-head');
    if (!data.connected) head.append(el('span', 'chip warn', data.status === 'reauth' ? 'אחסון הקבצים — צריך חיבור מחדש' : 'אחסון הקבצים מנותק'));
    else if (!data.has_root) head.append(el('span', 'chip warn', 'לא נבחרה תיקייה ראשית'));
    if (data.folder) {
      const a = el('a', 'btn ghost sm', '↗ פתיחת התיקייה'); a.href = data.folder.dropbox_url; a.target = '_blank'; a.rel = 'noopener noreferrer';
      head.append(a);
    } else if (data.connected && data.has_root && data.can_upload) {
      head.append(btn('📁 פתיחת תיקייה', 'ghost sm', async ev => busy(ev.currentTarget, async () => {
        await api('dropbox_folder_create', 'POST', {case_id: caseId}); FD().toast('התיקייה נפתחה'); renderCase();
      })));
    }
    if (data.connected && data.has_root && data.can_upload) {
      const input = el('input'); input.type = 'file'; input.hidden = true; input.multiple = true;
      input.accept = 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.rtf';
      input.addEventListener('change', () => upload([...input.files], up));
      const up = btn('⬆️ העלאה', 'primary sm', () => input.click());
      head.append(up, input);
    }
    box.append(head);
    // L2א/12b: התיקייה קושרה לבד (ת"ז / שם ותאריך) — אפשר לבטל בלחיצה; התיקייה חוזרת לשאלה ב"שאלות מהבינה"
    if (data.link && data.link.auto) {
        const why = el('div', 'warn-box link-auto');
        why.append(el('span', '', '🔗 התיקייה קושרה לתיק לבד — ' + data.link.why + '.'));
        if (FD().can('case.edit')) why.append(btn('ביטול הקישור', 'ghost sm', ev => busy(ev.currentTarget, async () => {
          if (!confirm('לבטל את הקישור? הקבצים מהתיקייה יוצאו מהתיק, ובדיקות שסומנו מהם ושעוד לא אומתו יחזרו ל"טרם נבדק". התיקייה תחזור לשאלה.')) return;
          await api('link_undo', 'POST', {case_id: caseId});
          FD().toast('הקישור בוטל — השאלה מחכה ב"שאלות מהבינה"');
          document.dispatchEvent(new CustomEvent('fd:changed', {detail: {id: caseId}}));
          renderCase();
        })));
        box.append(why);
    }
    const list = el('ul', 'list');
    if (!data.files.length) list.append(el('li', 'empty', data.folder ? 'אין עדיין קבצים בתיקייה.' : 'אין עדיין תיקייה לתיק הזה.'));
    data.files.forEach(f => list.append(fileRow(f)));
    box.append(list);
    document.dispatchEvent(new CustomEvent('fd:files', {detail: {id: caseId, files: data.files}}));   // הצעת סוג לקובץ (ai.js)
  }
  function fileRow(f) {
    const li = el('li'); li.dataset.fileId = f.id;
    const b = el('button', 'use-row'); b.type = 'button';
    const ic = el('span', 'use-ic dbx-thumb');
    if (f.thumb) { const img = el('img'); img.alt = ''; img.loading = 'lazy'; img.src = 'api.php?' + new URLSearchParams({action: 'file_view', id: f.id}); ic.append(img); }
    else ic.textContent = f.preview === 'pdf' ? '📄' : '📎';
    const tx = el('span', 'use-tx');
    tx.append(auto(el('span', 'one', f.name)),
      el('small', '', [when(f.server_modified), size(f.size), f.added_via === 'dropbox' ? 'מתיקיית הקבצים' : (f.added_by || '')].filter(Boolean).join(' · ')));
    b.append(ic, tx);
    b.addEventListener('click', () => preview(f));
    li.append(b);
    return li;
  }
  async function upload(files, b) {
    if (!files.length) return;
    b.disabled = true;
    try {
      for (const file of files) {
        if (file.size > 50 * 1024 * 1024) { FD().toast(file.name + ': גדול מ-50 מגה', true); continue; }
        FD().toast('מעלה ' + file.name + '…');
        const fd = new FormData(); fd.append('case_id', caseId); fd.append('file', file, file.name);
        fd.append('upload_id', 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
        const res = await fetch('api.php?action=dropbox_upload', {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Token': FD().csrf()}, body: fd});
        const j = await res.json().catch(() => ({error: 'תגובת שרת לא תקינה'}));
        if (!res.ok) throw new Error(file.name + ': ' + (j.error || 'ההעלאה נכשלה'));
      }
      FD().toast('הקובץ הועלה');
    } catch (e) { FD().toast(e.message, true); }
    finally { b.disabled = false; renderCase(); }
  }
  /**
   * U1: הצופה בתוך האפליקציה — תמונה, PDF ו-Word (השרת ממיר Word ל-PDF, file_view). PDF מצויר כעמודים (pdf.js),
   * כי מציג ה-PDF של הדפדפן לא עובד בתוך מסגרת בטלפון. בלי קישור החוצה. אם pdf.js לא נטען — מסגרת, כגיבוי.
   */
  const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  let pdfjs = null;
  function loadPdfJs() {
    if (pdfjs) return pdfjs;
    pdfjs = new Promise((ok, no) => {
      if (window.pdfjsLib) { ok(window.pdfjsLib); return; }
      const s = document.createElement('script'); s.src = PDFJS + 'pdf.min.js'; s.crossOrigin = 'anonymous';
      s.onload = () => { if (!window.pdfjsLib) { no(new Error('pdf')); return; } window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js'; ok(window.pdfjsLib); };
      s.onerror = () => { pdfjs = null; no(new Error('pdf')); };
      document.head.append(s);
    });
    return pdfjs;
  }
  async function renderPdf(box, src, name) {
    const wait = el('p', 'hint', 'טוען את המסמך…'); box.append(wait);
    let doc;
    try {
      const res = await fetch(src, {credentials: 'same-origin'});
      if (!res.ok) { const j = await res.json().catch(() => ({})); throw Object.assign(new Error(j.error || 'המסמך לא נטען'), {server: true}); }
      const data = new Uint8Array(await res.arrayBuffer());
      const lib = await loadPdfJs();
      doc = await lib.getDocument({data}).promise;
    } catch (e) {
      wait.remove();
      if (e.server) { box.append(el('p', 'error', e.message)); return; }
      const fr = el('iframe', 'dbx-pdf'); fr.title = name; fr.src = src; box.append(fr); return;
    }
    wait.remove();
    const pages = el('div', 'pdf-pages'); box.append(pages);
    const width = Math.max(280, box.clientWidth || 340);
    let next = 1;
    const more = btn('עוד עמודים', 'ghost sm pdf-more', () => draw(5));
    const draw = async n => {
      more.remove();
      for (const end = Math.min(doc.numPages, next + n - 1); next <= end; next++) {
        const page = await doc.getPage(next);
        const v1 = page.getViewport({scale: 1}), scale = width / v1.width, dpr = Math.min(2, window.devicePixelRatio || 1);
        const vp = page.getViewport({scale: scale * dpr});
        const c = el('canvas'); c.width = vp.width; c.height = vp.height; c.setAttribute('aria-label', 'עמוד ' + next + ' מתוך ' + doc.numPages);
        pages.append(c);
        await page.render({canvasContext: c.getContext('2d'), viewport: vp}).promise;
      }
      if (next <= doc.numPages) pages.append(more);
    };
    await draw(3);
  }
  /** מצייר קובץ לתוך box. kind: image / pdf / none (מ-dbxFileRow), או לפי הסיומת. */
  function renderFile(box, name, src, kind) {
    kind = kind || (/\.(jpe?g|png|gif|webp|heic|heif|bmp|tiff?)$/i.test(name) ? 'image' : /\.(pdf|docx?|pptx?|odt|odp|rtf)$/i.test(name) ? 'pdf' : 'none');
    if (kind === 'image') { const img = el('img', 'dbx-preview'); img.alt = name; img.src = src; box.append(img); }
    else if (kind === 'pdf') renderPdf(box, src, name);
    else box.append(el('p', 'hint', 'אין תצוגה לסוג הקובץ הזה. אפשר לצפות בתמונות ובמסמכים.'));
  }
  function preview(f) {
    const box = $('preview'); box.replaceChildren();
    $('previewTitle').textContent = f.name; $('previewTitle').dir = 'auto';
    $('previewDialog').showModal();
    renderFile(box, f.name, 'api.php?' + new URLSearchParams({action: 'file_view', id: f.id}), f.preview);
  }

  /* ---------- הגדרות Dropbox ---------- */

  async function renderSettings() {
    const box = host; if (!box) return;
    box.replaceChildren(el('p', 'hint', 'טוען…'));
    try { status = await api('dropbox_status'); } catch (e) { box.replaceChildren(el('p', 'error', e.message)); return; }
    box.replaceChildren();
    const d = status.dropbox;
    const sec = el('section', 'group');
    sec.append(el('h3', '', 'החיבור'));
    const line = el('p');
    const label = {connected: '✅ מחובר', reauth: '⚠️ צריך חיבור מחדש', disconnected: 'לא מחובר'}[d.status] || d.status;
    line.append(el('b', '', label), d.account_name ? el('span', 'muted', ' · ' + d.account_name) : '');
    sec.append(line);
    if (d.root_path) { const r = el('p', 'hint', 'תיקייה ראשית: '); r.append(auto(el('bdi', '', d.root_path))); sec.append(r); }
    const meta = [d.last_sync_at ? 'סנכרון אחרון: ' + when(d.last_sync_at) : '', d.last_webhook_at ? 'עדכון אחרון מהאחסון: ' + when(d.last_webhook_at) : '']
      .filter(Boolean).join(' · ');
    if (meta) sec.append(el('p', 'hint', meta));
    if (d.last_error) sec.append(el('p', 'error', 'שגיאה אחרונה: ' + d.last_error));
    const row = el('div', 'row-btns wrap');
    if (status.can_admin) {
      if (!status.app_configured) sec.append(el('p', 'hint', 'החיבור לאחסון הקבצים עוד לא הוגדר — לפנות למפתח.'));
      else if (d.status !== 'connected') row.append(btn(d.status === 'reauth' ? 'חיבור מחדש' : 'חיבור לאחסון הקבצים', 'primary', ev => busy(ev.currentTarget, async () => {
        const r = await api('dropbox_connect', 'POST', {}); location.href = r.url;
      })));
      else {
        row.append(btn('סנכרון עכשיו', 'ghost', ev => busy(ev.currentTarget, async () => { await api('dropbox_sync', 'POST', {}); renderSettings(); })));
        row.append(btn('ניתוק', 'ghost danger', ev => busy(ev.currentTarget, async () => {
          if (!confirm('לנתק את אחסון הקבצים מהארגון? הקבצים עצמם לא יימחקו, ורשימות הקבצים בתיקים יישארו.')) return;
          await api('dropbox_disconnect', 'POST', {}); renderSettings();
        })));
      }
    }
    sec.append(row);
    box.append(sec);
    if (status.can_admin && d.status === 'connected') { box.append(rootSection(d)); if (d.root_path) box.append(scanSection()); }
    if (status.can_admin) box.append(templateSection(status));
    devSection(box);
  }
  function rootSection(d) {
    const sec = el('section', 'group');
    sec.append(el('h3', '', 'תיקייה ראשית'), el('p', 'hint', 'כל פעולה של האפליקציה מוגבלת לתיקייה הזו ולמה שבתוכה.'));
    const list = el('ul', 'list'); const crumbs = el('div', 'dbx-crumbs');
    const load = async path => {
      browsePath = path; list.replaceChildren(el('li', 'empty', 'טוען…'));
      try {
        const r = await api('dropbox_browse', 'GET', null, {path});
        crumbs.replaceChildren();
        const parts = path.split('/').filter(Boolean);
        crumbs.append(btn('🏠 ראשי', 'ghost sm', () => load('')));
        parts.forEach((p, i) => crumbs.append(btn(p, 'ghost sm', () => load('/' + parts.slice(0, i + 1).join('/')))));
        list.replaceChildren();
        if (!r.folders.length) list.append(el('li', 'empty', 'אין תת-תיקיות.'));
        r.folders.forEach(f => {
          const li = el('li'); const b = el('button', 'use-row'); b.type = 'button';
          b.append(el('span', 'use-ic', '📁'), auto(el('span', 'use-tx one', f.name)));
          b.addEventListener('click', () => load(f.path)); li.append(b); list.append(li);
        });
      } catch (e) { list.replaceChildren(el('li', 'empty', e.message)); }
    };
    const choose = btn('בחירת התיקייה הזו', 'primary sm', ev => busy(ev.currentTarget, async () => {
      if (!browsePath) { FD().toast('בחרו תיקייה (לא את שורש החשבון)', true); return; }
      if (d.root_path && !confirm('להחליף את התיקייה הראשית ל-' + browsePath + '?')) return;
      await api('dropbox_set_root', 'POST', {path: browsePath}); FD().toast('התיקייה הראשית נשמרה'); renderSettings();
    }));
    const open = btn(d.root_path ? 'החלפת תיקייה' : 'בחירת תיקייה', 'ghost sm', () => { open.hidden = true; picker.hidden = false; load(''); });
    const picker = el('div', 'dbx-picker'); picker.hidden = true; picker.append(crumbs, list, choose);
    sec.append(open, picker);
    return sec;
  }
  /** L3: תבנית התיקייה שהאפליקציה פותחת לתיק חדש, עם דוגמה חיה. */
  // במסך — שמות המשתנים בעברית (U9); לשרת — כמו שהוא שומר ({name} ...)
  const TPL = [['{name}', '{שם}'], ['{date}', '{תאריך}'], ['{dm}', '{יום.חודש}'], ['{month}', '{חודש}'], ['{year}', '{שנה}']];
  const tplHe = t => TPL.reduce((x, [en, he]) => x.split(en).join(he), String(t || ''));
  const tplEn = t => TPL.reduce((x, [en, he]) => x.split(he).join(en), String(t || ''));
  function templateSection(st) {
    const sec = el('section', 'group');
    sec.append(el('h3', '', 'תיקייה לתיק חדש'),
      el('p', 'hint', 'כך ייקרא הנתיב של התיקייה שנפתחת לתיק חדש, בתוך התיקייה הראשית. משתנים: {שם} של הנפטר · {תאריך} הלוויה · {יום.חודש} · {חודש} (שנה-חודש) · {שנה}. / — תיקייה בתוך תיקייה.'));
    const f = el('label', 'f'); f.append(el('span', '', 'התבנית'));
    const input = el('input'); input.dir = 'auto'; input.maxLength = 120; input.value = tplHe(st.folder_template); input.setAttribute('aria-label', 'תבנית התיקייה'); input.autocomplete = 'off'; f.append(input);
    const ex = el('p', 'hint'); const exB = auto(el('bdi', '', st.folder_template_example)); ex.append('לדוגמה: ', exB);
    const row = el('div', 'row-btns');
    row.append(btn('שמירה', 'primary sm', ev => busy(ev.currentTarget, async () => {
      const r = await api('dropbox_template_set', 'POST', {template: tplEn(input.value.trim())});
      input.value = tplHe(r.folder_template); exB.textContent = r.folder_template_example; FD().toast('התבנית נשמרה');
    })), btn('ברירת המחדל', 'ghost sm', () => { input.value = tplHe(st.folder_template_default); }));
    sec.append(f, ex, row);
    return sec;
  }
  function scanSection() {
    const sec = el('section', 'group');
    const out = el('div');
    sec.append(el('h3', '', 'חיבור תיקיות קיימות לתיקים'),
      el('p', 'hint', 'הסריקה מציעה תיק לכל תיקייה לפי תאריך ושם. שום חיבור לא נעשה בלי אישור.'),
      btn('סריקת התאמה', 'ghost sm', ev => busy(ev.currentTarget, async () => renderScan(out, await api('dropbox_scan')))), out);
    return sec;
  }
  function renderScan(out, r) {
    out.replaceChildren();
    const link = async (b, case_id, folder_id) => busy(b, async () => {
      await api('dropbox_link', 'POST', {case_id, folder_id}); FD().toast('שויך'); renderScan(out, await api('dropbox_scan'));
    });
    if (r.proposals.length) {
      out.append(el('h4', 'sec-title', 'הצעות (' + r.proposals.length + ')'));
      const list = el('ul', 'list');
      r.proposals.forEach(p => {
        const li = el('li', 'card');
        const body = el('div', 'body'); body.append(el('div', 'l1', p.case_name + (p.case_date ? ' · ' + p.case_date : '')), auto(el('div', 'l2', p.path)));
        li.append(body, btn('אישור', 'primary sm', ev => link(ev.currentTarget, p.case_id, p.folder_id)));
        list.append(li);
      });
      out.append(list);
    }
    if (r.unmatched.length) {
      out.append(el('h4', 'sec-title', 'לא משויכות (' + r.unmatched.length + ')'));
      const list = el('ul', 'list');
      r.unmatched.forEach(u => {
        const li = el('li', 'card');
        const body = el('div', 'body'); body.append(auto(el('div', 'l2', u.path)));
        const sel = el('select'); sel.setAttribute('aria-label', 'תיק לתיקייה ' + u.path);
        sel.append(new Option('בחירת תיק…', ''));
        r.cases.forEach(c => sel.append(new Option(c.name + (c.date ? ' · ' + c.date : ''), c.id)));
        body.append(sel);
        li.append(body, btn('שיוך', 'ghost sm', ev => { if (!sel.value) { FD().toast('בחרו תיק', true); return; } link(ev.currentTarget, sel.value, u.folder_id); }));
        list.append(li);
      });
      out.append(list);
    }
    if (!r.proposals.length && !r.unmatched.length) out.append(el('p', 'empty', 'כל התיקיות משויכות.'));
  }
  /** למפתח בלבד: מפתחות האפליקציה (נכתבים ולא נקראים בחזרה), הכתובות ל-App Console, ומצב כל הארגונים. */
  async function devSection(box) {
    let h;
    try { h = await api('dropbox_health'); } catch (e) { return; }
    const sec = el('section', 'group');
    sec.append(el('h3', '', '🛠️ מפתח — אפליקציית Dropbox'));
    sec.append(el('p', 'hint', 'App key: ' + (h.app_key_set ? '✅ מוגדר' : '❌ חסר') + ' · App secret: ' + (h.app_secret_set ? '✅ מוגדר' : '❌ חסר')));
    const uri = (label, v) => { const f = el('label', 'f'); f.append(el('span', '', label)); const i = el('input'); i.readOnly = true; i.dir = 'ltr'; i.value = v; f.append(i); return f; };
    sec.append(uri('Redirect URI', h.redirect_uri), uri('Webhook URI', h.webhook_uri));
    const key = el('input'); key.dir = 'ltr'; key.autocomplete = 'off'; key.placeholder = 'App key'; key.setAttribute('aria-label', 'App key');
    const secret = el('input'); secret.type = 'password'; secret.dir = 'ltr'; secret.autocomplete = 'off'; secret.placeholder = 'App secret'; secret.setAttribute('aria-label', 'App secret');
    sec.append(key, secret, btn('שמירה', 'primary sm', ev => busy(ev.currentTarget, async () => {
      await api('dropbox_app_set', 'POST', {app_key: key.value.trim(), app_secret: secret.value.trim()});
      key.value = ''; secret.value = ''; FD().toast('נשמר מוצפן'); renderSettings();
    })));
    if (h.orgs.length) {
      const list = el('ul', 'list');
      h.orgs.forEach(o => {
        const li = el('li', 'card'); const body = el('div', 'body');
        body.append(el('div', 'l1', 'ארגון ' + o.org_id + ' · ' + o.status),
          el('div', 'l2', ['סנכרון: ' + (when(o.last_sync_at) || '—'), 'webhook: ' + (when(o.last_webhook_at) || '—'), o.last_error ? 'שגיאה: ' + o.last_error : ''].filter(Boolean).join(' · ')));
        li.append(body); list.append(li);
      });
      sec.append(list);
    }
    box.append(sec);
  }

  /* ---------- חיבור ל-index.html ---------- */
  document.addEventListener('fd:case', e => { caseId = (e.detail && e.detail.id) || ''; renderCase(); });
  document.addEventListener('fd:ready', () => {
    api('dropbox_status').catch(() => {});      // סנכרון בפתיחת הרשימה, כשהאחרון ישן מ-2 דקות
    if (location.hash === '#dropbox') { history.replaceState(null, '', location.pathname + location.search); FD().openSettings('dropbox'); }
  });
  window.FDDropbox = {settings(section) { host = section; renderSettings(); }, preview, renderFile};
})();
