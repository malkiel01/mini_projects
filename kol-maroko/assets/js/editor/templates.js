/**
 * קול מרוקו — העורך החזותי, פרוסה V13: תבניות של מקטע / עמוד (01-gap 8.1, 8.2).
 *
 * "שמור כתבנית" ← templates/<שם>/ בפרויקט: template.html, style.css (הכללים שחלים רק עליו — אותה הצעה של רכיב, העתקה
 * כברירת מחדל), template.json (שם, סוג, תאריך) ו-thumb.png (צילום מהקנבס). קבצים רגילים ב-📁, ואינם נכנסים לחבילה
 * (lib/build.php — build_is_config). הוספה מתבנית: ה-HTML **מועתק** (לא מקושר — לקישור יש רכיב), וה-CSS מתווסף לסוף קובץ
 * העיצוב של העמוד בבלוק "/* תבנית: <שם> *\/"; מחלקה שכבר קיימת עם תוכן אחר ← שם חדש (-2), ונאמר. ייצוא / ייבוא — ZIP.
 * כל טקסט מהעמוד או מהתבנית — כטקסט בלבד; אין innerHTML. ייבוא — רק templates/<שם>/ ו-media/, דרך pf_zip_import (התוכנית קודם).
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc;
  const NAME = /^[a-z0-9][a-z0-9-]{0,47}$/;

  // ── חלק טהור (נבדק ב-tests/editor/templates.mjs) ──
  const slug = s => String(s || '').normalize('NFKD').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'template';
  function checkName(s) { s = String(s || '').trim(); if (!NAME.test(s)) throw new Error('שם התבנית — אותיות לטיניות קטנות, ספרות ומקף (עד 48), למשל hero-strip'); return s; }
  /** טקסט להערת CSS: בלי "*\/" ובלי ירידת שורה. */
  const commentSafe = s => String(s || '').replace(/\*\//g, '* /').replace(/[\r\n]+/g, ' ').slice(0, 80);
  /** המחלקות שבבוררים של גיליון. */
  function cssClasses(text) {
    const out = new Set(), m = K().parseCss(text);
    for (const r of K().cssAll(m)) if (r.type === 'rule') for (const x of r.sel.matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)) out.add(x[1]);
    return out;
  }
  const normRule = t => String(t).replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ').replace(/\s*([{};:,>])\s*/g, '$1').replace(/;}/g, '}').trim();
  /** הכללים (טקסט מנורמל, כולל @media) שבורר שלהם מזכיר את המחלקה. */
  function rulesOf(text, cls) {
    const re = new RegExp('\\.' + cls.replace(/[^\w-]/g, '\\$&') + '(?![\\w-])'), m = K().parseCss(text), out = [];
    for (const r of K().cssAll(m)) if (r.type === 'rule' && re.test(r.sel)) out.push((r.parent && r.parent.type === 'at' ? '@' + r.parent.name + ' ' + r.parent.sel.trim() + '|' : '') + normRule(text.slice(r.from, r.to)));
    return out;
  }
  /** החלטה על כל מחלקה של התבנית: { rename: Map<ישן, חדש>, same: Set } — "same": כל הכללים שלה כבר בעמוד, זהים; "rename":
   *  המחלקה בשימוש בעמוד (בקנבס או ב-CSS) עם תוכן אחר. used — כל המחלקות שבעמוד (DOM + CSS). */
  function plan(tplCss, tplHtml, pageCss, used) {
    const rename = new Map(), same = new Set(), mine = new Set([...cssClasses(tplCss)]);
    for (const x of String(tplHtml).matchAll(/\bclass\s*=\s*"([^"]*)"/gi)) for (const c of x[1].split(/\s+/).filter(Boolean)) if (!mine.has(c)) { /* מחלקה בלי כלל בתבנית — כמו שהיא */ }
    const taken = new Set([...used, ...mine]);
    for (const c of mine) {
      if (!used.has(c)) continue;
      const a = rulesOf(tplCss, c), b = new Set(pageCss.flatMap(t => rulesOf(t, c)));
      if (a.length && a.every(r => b.has(r))) { same.add(c); continue; }
      let i = 2, nn = c + '-' + i; while (taken.has(nn)) nn = c + '-' + ++i;
      taken.add(nn); rename.set(c, nn);
    }
    return { rename, same };
  }
  /** שינוי שמות מחלקות: ב-HTML (בתכונות class) וב-CSS (בבוררים בלבד). */
  function renameHtml(html, rename) {
    if (!rename.size) return html;
    return String(html).replace(/(\bclass\s*=\s*)(["'])([^"']*)\2/gi, (all, a, q, v) => a + q + v.split(/(\s+)/).map(t => rename.get(t) || t).join('') + q);
  }
  function renameCss(text, rename) {
    if (!rename.size) return text;
    const m = K().parseCss(text), ps = [];
    for (const r of K().cssAll(m)) if (r.type === 'rule' && r.selFrom >= 0) {
      const sel = r.sel.replace(/\.(-?[_a-zA-Z][\w-]*)/g, (all, c) => (rename.has(c) ? '.' + rename.get(c) : all));
      if (sel !== r.sel) ps.push({ from: r.selFrom, to: r.selTo, text: sel });
    }
    return K().applyPatches(text, ps);
  }
  /** כללים זהים שכבר בעמוד — לא מוסיפים שוב (רק כשכל המחלקות שלהם "same", או שאין להם מחלקה בכלל והם זהים). */
  function dropSame(text, pageCss) {
    const have = new Set(pageCss.flatMap(t => { const m = K().parseCss(t); return K().cssAll(m).filter(r => r.type === 'rule').map(r => (r.parent && r.parent.type === 'at' ? '@' + r.parent.name + ' ' + r.parent.sel.trim() + '|' : '') + normRule(t.slice(r.from, r.to))); }));
    const m = K().parseCss(text), ps = [];
    for (const r of K().cssAll(m)) if (r.type === 'rule' && have.has((r.parent && r.parent.type === 'at' ? '@' + r.parent.name + ' ' + r.parent.sel.trim() + '|' : '') + normRule(text.slice(r.from, r.to)))) ps.push(...K().css.removeNode(m, r).patches);
    ps.sort((a, b) => b.from - a.from);
    let out = text; for (const p of ps) out = out.slice(0, p.from) + p.text + out.slice(p.to);
    return out.replace(/@media[^{]*\{\s*\}/g, '').replace(/\n{3,}/g, '\n\n').trim();
  }
  /** ההפניות של התבנית לקבצים בפרויקט (src / href / srcset / poster / url()) — נתיבים יחסיים בלבד. */
  function refs(html, css) {
    const out = new Set(), add = r => { r = String(r || '').trim().split(/[?#]/)[0]; if (!r || /^([a-z][a-z0-9+.-]*:|\/\/|#|\/)/i.test(r) || r.includes('..')) return; try { out.add(decodeURIComponent(r).replace(/^\.\//, '')); } catch { out.add(r.replace(/^\.\//, '')); } };
    for (const x of String(html).matchAll(/\b(?:src|href|poster)\s*=\s*(["'])([^"']*)\1/gi)) add(x[2]);
    for (const x of String(html).matchAll(/\bsrcset\s*=\s*(["'])([^"']*)\1/gi)) for (const part of x[2].split(',')) add(part.trim().split(/\s+/)[0]);
    for (const x of (String(html) + '\n' + String(css)).matchAll(/url\(\s*(['"]?)([^'")]+)\1\s*\)/gi)) add(x[2]);
    return [...out];
  }

  // ── ZIP (STORE בכתיבה; STORE / DEFLATE בקריאה) ──
  let CRC = null;
  function crc32(b) { if (!CRC) { CRC = new Uint32Array(256); for (let i = 0; i < 256; i++) { let c = i; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; CRC[i] = c >>> 0; } } let c = 0xFFFFFFFF; for (let i = 0; i < b.length; i++) c = CRC[(c ^ b[i]) & 0xFF] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; }
  /** files: [[נתיב, Uint8Array]] ← Uint8Array של ZIP. שמות ב-UTF-8 (דגל 11). */
  function zipBuild(files) {
    const enc = new TextEncoder(), parts = [], cen = []; let off = 0;
    const u16 = v => [v & 255, (v >>> 8) & 255], u32 = v => [v & 255, (v >>> 8) & 255, (v >>> 16) & 255, (v >>> 24) & 255];
    for (const [name, data] of files) {
      const nb = enc.encode(name), crc = crc32(data);
      const head = [...u32(0x04034b50), ...u16(20), ...u16(0x0800), ...u16(0), ...u16(0), ...u16(0x21), ...u32(crc), ...u32(data.length), ...u32(data.length), ...u16(nb.length), ...u16(0)];
      parts.push(Uint8Array.from(head), nb, data);
      cen.push([...u32(0x02014b50), ...u16(20), ...u16(20), ...u16(0x0800), ...u16(0), ...u16(0), ...u16(0x21), ...u32(crc), ...u32(data.length), ...u32(data.length), ...u16(nb.length), ...u16(0), ...u16(0), ...u16(0), ...u16(0), ...u32(0), ...u32(off)], nb);
      off += head.length + nb.length + data.length;
    }
    let csize = 0; const cparts = cen.map(x => (x instanceof Uint8Array ? x : Uint8Array.from(x))); for (const x of cparts) csize += x.length;
    const end = Uint8Array.from([...u32(0x06054b50), ...u16(0), ...u16(0), ...u16(files.length), ...u16(files.length), ...u32(csize), ...u32(off), ...u16(0)]);
    const all = [...parts, ...cparts, end], total = all.reduce((s, x) => s + x.length, 0), out = new Uint8Array(total); let p = 0;
    for (const x of all) { out.set(x, p); p += x.length; }
    return out;
  }
  /** קריאה (לתצוגה המקדימה): [{ name, method, size, data? }] — data רק ל-STORE (DEFLATE — inflate בדפדפן). */
  function zipList(buf) {
    const b = buf instanceof Uint8Array ? buf : new Uint8Array(buf), dv = new DataView(b.buffer, b.byteOffset, b.byteLength), dec = new TextDecoder('utf-8', { fatal: true });
    let e = -1; for (let i = b.length - 22; i >= Math.max(0, b.length - 22 - 65535); i--) if (dv.getUint32(i, true) === 0x06054b50) { e = i; break; }
    if (e < 0) throw new Error('הקובץ אינו ZIP תקין');
    const count = dv.getUint16(e + 10, true); let p = dv.getUint32(e + 16, true); const out = [];
    for (let i = 0; i < count; i++) {
      if (dv.getUint32(p, true) !== 0x02014b50) throw new Error('ה-ZIP פגום');
      const method = dv.getUint16(p + 10, true), csz = dv.getUint32(p + 20, true), size = dv.getUint32(p + 24, true), nl = dv.getUint16(p + 28, true), xl = dv.getUint16(p + 30, true), cl = dv.getUint16(p + 32, true), lo = dv.getUint32(p + 42, true);
      let name; try { name = dec.decode(b.subarray(p + 46, p + 46 + nl)); } catch { throw new Error('שם קובץ ב-ZIP שאינו UTF-8'); }
      const ln = dv.getUint16(lo + 26, true), lx = dv.getUint16(lo + 28, true), at = lo + 30 + ln + lx;
      out.push({ name, method, size, csize: csz, raw: b.subarray(at, at + csz) });
      p += 46 + nl + xl + cl;
    }
    return out;
  }
  /** בדיקת התוכן: תבנית אחת (templates/<שם>/…, עם template.json ו-template.html) ו-media/ בלבד. */
  function checkZip(list) {
    const all = list.map(x => x.name).filter(n => !/^__MACOSX\//.test(n) && !/(^|\/)\.DS_Store$/.test(n));
    const names = all.filter(n => !/\/$/.test(n));
    // T2 (V13 ⚪): גם תיקיות (שם שמסתיים ב-/) נבדקות — נתיב, וגבול התבנית
    const bad = all.map(n => n.replace(/\/$/, '')).find(n => n === '' || n.includes('\\') || n.startsWith('/') || /^[a-z]:/i.test(n) || n.split('/').some(s => s === '..' || s === '.' || s === '') || /[\u0000-\u001f]/.test(n));
    if (bad != null) throw new Error('נתיב לא חוקי ב-ZIP: ' + (bad || '/'));
    const dirOut = all.filter(n => /\/$/.test(n)).find(n => !/^(templates|media)\/$/.test(n) && !/^templates\/[^/]+\//.test(n) && !/^media\/[^/]/.test(n));
    if (dirOut) throw new Error('ב-ZIP של תבנית מותר רק templates/<שם>/ ו-media/ — נמצאה תיקייה: ' + dirOut);
    const tpls = new Set(all.filter(n => /\/$/.test(n)).map(n => (/^templates\/([^/]+)\//.exec(n) || [])[1]).filter(Boolean)); for (const n of names) { const m = /^templates\/([^/]+)\//.exec(n); if (m) tpls.add(m[1]); else if (!/^media\/[^/].*/.test(n)) throw new Error('ב-ZIP של תבנית מותר רק templates/<שם>/ ו-media/ — נמצא: ' + n); }
    if (tpls.size !== 1) throw new Error(tpls.size ? 'ב-ZIP יש יותר מתבנית אחת: ' + [...tpls].join(', ') : 'ב-ZIP אין תיקייה templates/<שם>/');
    const name = [...tpls][0]; checkName(name);
    for (const f of ['template.html', 'template.json']) if (!names.includes('templates/' + name + '/' + f)) throw new Error('חסר templates/' + name + '/' + f);
    return { name, names };
  }

  class Templates {
    /** opt: { canvas, K, X, build, comp(), styler, writeOps, joinTx, txCount, pushFx, note, sheet, undo, canWrite, file(), doc(), pagePath, docOf, sheets(), api, pvUrl, refPath, rel, host, shoot } */
    constructor(opt) { Object.assign(this, opt); this.list = []; this.ver = 0; }
    c() { return this.canvas; }
    /** הרשימה מהפרויקט: templates/<שם>/template.json. */
    async load() {
      const files = await Project.docs.files(), out = [];
      for (const f of files.filter(x => x.kind !== 'dir' && /^templates\/[^/]+\/template\.json$/.test(x.path))) {
        const name = f.path.split('/')[1]; let meta = {};
        try { const d = await Project.docs.open(f.id); meta = JSON.parse(d.text()); } catch (e) { meta = { error: 'template.json אינו נקרא — ' + e.message }; }
        out.push({ name, meta, thumb: files.some(x => x.path === 'templates/' + name + '/thumb.png') });
      }
      this.list = out.sort((a, b) => String(b.meta.created || '').localeCompare(String(a.meta.created || '')));
      this.draw();
      return this.list;
    }
    // ── הלשונית "📄 תבניות" ──
    draw() {
      const host = this.host; if (!host) return;
      const imp = h('input', { type: 'file', accept: '.zip,application/zip', hidden: true, 'data-tpl-import-file': true });
      imp.addEventListener('change', () => { const f = imp.files[0]; imp.value = ''; if (f) this.importZip(f); });
      const grid = h('div', { class: 'ed-lib-grid tp-grid', role: 'list', 'aria-label': 'תבניות' });
      for (const t of this.list) {
        const lab = t.meta.label || t.name, page = t.meta.kind === 'page', bad = t.meta.error;
        const it = { id: 'tpl-' + t.name, icon: page ? '📄' : '▤', label: lab, html: '<div></div>', run: tg => this.insert(t.name, tg) };
        const b = this.libBtn(it, !!bad || page);
        b.classList.add('tp-item'); b.dataset.tpl = t.name;
        b.title = bad ? '⚠️ ' + bad : page ? lab + ' — תבנית עמוד: "עמוד חדש מתבנית" יגיע עם לוח העמודים (V15)' : lab + ' · templates/' + t.name + '/ — גרירה לקנבס או לחיצה (אחרי הנבחר). ה-HTML מועתק (לא מקושר).';
        if (t.thumb && this.pvUrl) { const im = h('img', { class: 'tp-thumb', alt: '', loading: 'lazy' }); im.src = this.pvUrl('templates/' + t.name + '/thumb.png') + '?v=' + this.ver; b.prepend(im); }
        grid.append(h('div', { class: 'tp-row', role: 'listitem' }, b, h('button', { type: 'button', class: 'icon-btn', 'data-tpl-export': t.name, 'aria-label': 'ייצוא ' + lab + ' ל-ZIP', title: '🗜 ייצוא ל-ZIP (התבנית + המדיה שלה)', onclick: () => this.exportZip(t.name) }, '🗜')));
      }
      host.replaceChildren(h('p', { class: 'hint' }, 'תבניות של מקטע או עמוד (templates/). גוררים אל הקנבס, או לוחצים — נכנס אחרי הנבחר. ה-HTML מועתק, וה-CSS נוסף לסוף קובץ העיצוב של העמוד.'),
        this.list.length ? grid : h('p', { class: 'hint', 'data-tpl-empty': true }, 'עוד אין תבניות. לחיצה ימנית על רובריקה ← "📄 שמור כתבנית".'),
        h('div', { class: 'ed-ask-bar tp-bar' }, h('button', { type: 'button', class: 'btn sm', 'data-tpl-save-page': true, disabled: !this.canWrite() || null, onclick: () => this.save(null) }, '📄 שמור את העמוד כתבנית'),
          h('button', { type: 'button', class: 'btn sm', 'data-tpl-import': true, disabled: !this.canWrite() || null, onclick: () => imp.click() }, '📦 ייבוא תבנית (ZIP)'), imp,
          h('button', { type: 'button', class: 'btn sm', 'data-tpl-project-zip': true, title: 'כל הפרויקט — ה-ZIP של 📁 פרויקט', onclick: () => { location.href = 'api.php?action=pf_zip'; } }, '🗜 הפרויקט כולו (ZIP)')));
    }
    libBtn(it, off) {
      const bd = this.build, b = h('button', { type: 'button', class: 'ed-lib-item', 'data-lib': it.id, 'aria-disabled': off ? 'true' : null }, h('span', { 'aria-hidden': 'true' }, it.icon), ' ', h('span', { class: 'tp-lab' }, it.label));
      let p0 = null;
      b.addEventListener('pointerdown', e => { if (e.button > 0 || off) return; p0 = { x: e.clientX, y: e.clientY, id: e.pointerId }; try { b.setPointerCapture(e.pointerId); } catch { /* */ } });
      b.addEventListener('pointermove', e => { if (p0 && !bd.drag.active && Math.hypot(e.clientX - p0.x, e.clientY - p0.y) > 4) { if (!this.canWrite()) { p0 = null; return; } try { b.releasePointerCapture(p0.id); } catch { /* */ } p0 = null; bd.drag.start({ lib: it }, e.clientX, e.clientY); } });
      b.addEventListener('pointerup', () => { p0 = null; });
      b.addEventListener('click', () => { if (bd.swallow) return; if (off) return this.note(b.title, true); it.run(); });
      return b;
    }

    // ── שמירה ──
    free(base) { let s = base, i = 2; while (Project.docs.byPath('templates/' + s + '/template.json')) s = base + '-' + i++; return s; }
    /** n — רובריקה (מקטע); null — העמוד כולו. */
    save(n) {
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      const c = this.c(), page = !n || n.tag === 'body' || n.tag === 'html'; if (page) n = null;
      const guess = slug(n ? (K().classesOf(n)[0] || K().attrOf(n, 'id')?.value || n.tag) : (this.file()?.path || 'page').replace(/\.html?$/, '').split('/').pop() + '-page');
      const lab = h('input', { type: 'text', class: 'ct-in', value: '', 'data-ts': 'label', 'aria-label': 'שם לתצוגה', placeholder: page ? 'למשל: עמוד נחיתה' : 'למשל: פס עליון' });
      const nm = h('input', { type: 'text', class: 'ct-in', dir: 'ltr', value: this.free(guess), 'data-ts': 'name', 'aria-label': 'שם (תיקייה)' });
      lab.addEventListener('input', () => { const s2 = slug(lab.value); if (!nm.dataset.touched) nm.value = this.free(s2 === 'template' || s2.length < 3 ? guess : s2); });   // שם בעברית ← השם מההקשר
      nm.addEventListener('input', () => { nm.dataset.touched = '1'; });
      const rules = page ? [] : (this.comp() ? this.comp().ownRules(n) : []);
      rules.forEach(r => { r.act = 'copy'; });
      const rlist = page ? h('p', { class: 'hint' }, 'תבנית עמוד: template.html = הקובץ כולו; style.css = קובצי ה-CSS שהעמוד מקשר (חוץ מרכיבים).')
        : h('div', { class: 'cm-rules', 'data-ts-rules': true }, rules.length ? rules.map((r, i) => h('div', { class: 'cm-rule', 'data-ts-rule': i }, h('code', { dir: 'ltr' }, r.sel + (r.media ? ' · ' + r.media : '')), h('span', { class: 'hint' }, ' · ' + r.path + ':' + r.line),
          h('select', { class: 'ct-in', 'data-ts-rule-act': i, 'aria-label': 'מה לעשות בכלל ' + r.sel, onchange: e => { r.act = e.target.value; } }, [['copy', 'העתק לתבנית'], ['skip', 'לא לכלול']].map(([v, t]) => h('option', { value: v }, t))))) : [h('p', { class: 'hint' }, 'אין כללים שחלים רק על האלמנט — style.css יהיה ריק.')]);
      const err = h('p', { class: 'ed-warn', hidden: true, 'data-ts-err': true }), go = h('button', { type: 'button', class: 'btn primary', 'data-ts-go': true }, '📄 צור את התבנית');
      const dlg = this.sheet('📄 שמור כתבנית · ' + (page ? 'העמוד כולו' : c.label(n)), h('p', { class: 'hint' }, 'נוצרת תיקייה templates/<שם>/ עם template.html, style.css, template.json ו-thumb.png. העמוד עצמו אינו משתנה.'),
        h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'שם לתצוגה'), lab), h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'שם (תיקייה)'), nm), h('p', { class: 'hint' }, 'סוג: ' + (page ? 'עמוד' : 'מקטע')),
        h('h4', { class: 'dp-h' }, 'CSS (style.css)'), rlist, err, h('div', { class: 'ed-ask-bar' }, go));
      setTimeout(() => lab.focus(), 0);
      go.addEventListener('click', async () => {
        go.disabled = true;
        try { await this.doSave(n, { name: nm.value.trim(), label: lab.value.trim() || nm.value.trim(), kind: page ? 'page' : 'section' }, rules); dlg.close(); }
        catch (e) { err.hidden = false; err.textContent = '⚠️ ' + e.message; go.disabled = false; }
      });
      return dlg;
    }
    async doSave(n, meta, rules) {
      const name = checkName(meta.name), base = 'templates/' + name + '/', c = this.c(), m = c.m;
      if (Project.docs.byPath(base + 'template.json')) throw new Error('כבר יש תבנית בשם ' + name);
      let html, css;
      if (!n) { html = m.src; css = [...new Set([...this.sheets()].filter(s => !/^components\//.test(s.path)).map(s => '/* ' + commentSafe(s.path) + ' */\n' + s.doc.text().replace(/^﻿/, '').trim()))].join('\n\n') + '\n'; }
      else {
        html = K().outer(m, n);
        const lead = m.src.slice(K().leadOf(m, n), n.from).replace(/^[\s\S]*[\r\n]/, '');
        if (/^[ \t]+$/.test(lead)) html = html.replace(new RegExp('(\\r?\\n)' + lead, 'g'), '$1');
        const take = rules.filter(r => r.act !== 'skip');
        css = take.map(r => (r.media ? r.media + ' {\n  ' + r.text.replace(/\n/g, '\n  ') + '\n}' : r.text)).join('\n\n') + (take.length ? '\n' : '');
      }
      const json = JSON.stringify({ name, label: meta.label.slice(0, 80), kind: meta.kind, created: new Date().toISOString(), from: this.file()?.path || '' }, null, 2) + '\n';
      const ids = []; let thumbErr = '';
      try {
        for (const [f, content] of [['template.html', html.replace(/\s*$/, '\n')], ['style.css', css], ['template.json', json]]) { const d = await this.api('pf_create', { path: base + f, content }); ids.push(d.file.id); }
        try { const shot = await this.shoot(n || (m.root && (m.root.kids.find(k => k.tag === 'body') || m.root))); if (shot && shot.blob) { const up = await Media.upload(new File([shot.blob], name + '-thumb.png', { type: 'image/png' })); const d = await this.api('pf_link', { path: base + 'thumb.png', file_id: up.id }); if (d && d.file) ids.push(d.file.id); } } catch (e) { thumbErr = e.message || 'הצילום נכשל'; }
      } catch (e) { await Promise.all(ids.map(id => this.api('pf_delete', { id }).catch(() => null))); throw e; }
      await Project.docs.reload(); this.ver++;
      this.pushFx('שמור כתבנית · ' + name, { undo: () => Promise.all(ids.map(id => this.api('pf_delete', { id }).catch(() => null))).then(() => Project.docs.reload()).then(() => this.load()), redo: () => this.api('pf_restore', { ids }).then(() => Project.docs.reload()).then(() => this.load()).catch(e => this.note('השחזור של קובצי התבנית נכשל: ' + e.message, true)) });
      await this.load();
      this.note('נשמרה התבנית ' + meta.label + ' (templates/' + name + '/) — בלשונית "📄 תבניות"' + (thumbErr ? ' · בלי צילום (' + thumbErr + ')' : ''), !!thumbErr, { label: 'בטל', run: this.undo });
      return name;
    }

    // ── הוספה מתבנית ──
    async read(name) {
      const base = 'templates/' + name + '/', get = async p => { const f = Project.docs.byPath(base + p); if (!f) return null; const d = await Project.docs.open(f.id); return d && !d.binary ? d.text() : null; };
      const html = await get('template.html'), css = (await get('style.css')) || '';
      let meta = {}; try { meta = JSON.parse((await get('template.json')) || '{}'); } catch { meta = {}; }
      if (html == null) throw new Error('חסר ' + base + 'template.html');
      return { html, css, meta };
    }
    /** t — יעד הגרירה ({ par, ref }); בלי — אחרי הנבחר. */
    async insert(name, t) {
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      let tp; try { tp = await this.read(name); } catch (e) { return this.note(e.message, true); }
      if (tp.meta.kind === 'page') return this.note('תבנית עמוד — "עמוד חדש מתבנית" יגיע עם לוח העמודים (V15)', true);
      const c = this.c(), lab = tp.meta.label || name, cssPath = this.pagePath(), doc = cssPath && this.docOf(cssPath);
      if (tp.css.trim() && !doc) return this.note('לעמוד אין קובץ CSS מקושר — הכללים של התבנית לא ייכתבו. מקשרים קובץ CSS (🌐 כללי) ומנסים שוב', true);
      const pageCss = [...this.sheets()].map(s => s.doc.text());
      const used = new Set(); for (const e of c.doc.querySelectorAll('[class]')) for (const x of e.classList) used.add(x); for (const t2 of pageCss) for (const x of cssClasses(t2)) used.add(x);
      const p = plan(tp.css, tp.html, pageCss, used);
      let html = renameHtml(tp.html, p.rename), css = dropSame(renameCss(tp.css, p.rename), pageCss);
      // תבנית שיובאה מ-ZIP יכולה לשאת קוד: סקריפטים, on…, javascript: — שואלים (כמו הדבקה מבחוץ), לא מכניסים בשקט
      const cl = this.build.clean(html);
      if (cl.removed.length) {
        const go = await this.build.ask('קוד בתוך התבנית', ['בתבנית ' + lab + ' יש קוד שירוץ בעמוד: ' + cl.removed.join(', ')], [['clean', 'הכנס בלי הקוד'], ['keep', 'הכנס כמו שהוא'], ['cancel', 'בטל']]);
        if (!go || go === 'cancel') return;
        if (go === 'clean') html = cl.html;
      }
      const at = t || (this.comp() ? this.comp().here() : null); if (!at || !at.par) return this.note('אין לאן להוסיף', true);
      if (this.comp()) html = this.comp().indentIn(at.par, at.ref, html.replace(/\s+$/, ''));
      const n0 = this.txCount(), it = { id: 'tpl-' + name, label: 'תבנית ' + lab, html };
      const ok = this.build.add(it, at.par, at.ref);
      if (ok === false || this.txCount() === n0) return;
      const sel = c.selected, P = sel ? K().pathOf(sel) : null;
      if (css.trim()) {
        const w = this.writeOps([() => { const was = doc.text(), sep = was.replace(/\s+$/, '').length ? '\n\n' : ''; const block = '/* תבנית: ' + commentSafe(lab) + ' */\n' + css.trim() + '\n'; const end = was.replace(/\s+$/, '').length; return [{ doc, patches: [{ from: end, to: was.length, text: sep + block }] }]; }], 'CSS של תבנית · ' + lab);
        if (!w) return;
      }
      const k = this.txCount() - n0, label = 'הוספה מתבנית · ' + lab + (p.rename.size ? ' · ' + [...p.rename].map(([a, b]) => a + ' ← ' + b).join(', ') : '');
      if (k > 1) this.joinTx(k, label); else this.state().tx[this.state().tx.length - 1].label = label;
      if (P) { const cur = K().byPath(c.m, P); if (cur) { c.select(cur); c.reveal(cur); } }
      this.note('נוספה התבנית ' + lab + (p.rename.size ? ' — שמות מחלקות שכבר בעמוד עם תוכן אחר שונו: ' + [...p.rename].map(([a, b]) => '.' + a + ' ← .' + b).join(', ') : '') + (css.trim() ? ' · ה-CSS נוסף לסוף ' + cssPath : ''), false, { label: 'בטל', run: this.undo });
      return { rename: p.rename };
    }

    // ── ייצוא / ייבוא ──
    async bytesOf(path) {
      const f = Project.docs.byPath(path); if (!f) return null;
      if (f.kind === 'file') { try { const d = await Project.docs.open(f.id); if (d && !d.binary) return new TextEncoder().encode(d.text()); } catch { /* בינארי — מהתצוגה */ } }
      if (!this.pvUrl) return null;
      const r = await fetch(this.pvUrl(path), { credentials: 'same-origin' }); if (!r.ok) return null;
      return new Uint8Array(await r.arrayBuffer());
    }
    /** ZIP: templates/<שם>/ + קובצי המדיה שהתבנית מפנה אליהם (media/…). מחזיר את הקבצים (לבדיקה) ומוריד. */
    async exportZip(name, opt = {}) {
      try {
        const base = 'templates/' + name + '/', files = [], miss = [];
        for (const f of (await Project.docs.files()).filter(x => x.kind !== 'dir' && x.path.startsWith(base)).sort((a, b) => a.path.localeCompare(b.path))) { const b = await this.bytesOf(f.path); if (b) files.push([f.path, b]); else miss.push(f.path); }
        const tp = await this.read(name), from = String(tp.meta.from || '').includes('/') ? String(tp.meta.from).replace(/[^/]+$/, '') : '';
        for (const r of refs(tp.html, tp.css)) {
          const p = (from + r).replace(/^\.\//, ''); if (!/^media\//.test(p) || files.some(x => x[0] === p)) continue;
          const b = await this.bytesOf(p); if (b) files.push([p, b]); else miss.push(p);
        }
        const zip = zipBuild(files);
        if (!opt.noDownload) { const a = h('a', { download: 'template-' + name + '.zip', hidden: true }); a.href = URL.createObjectURL(new Blob([zip], { type: 'application/zip' })); document.body.append(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 2000); }
        this.note('🗜 התבנית ' + name + ' יוצאה ל-ZIP: ' + files.length + ' קבצים' + (miss.length ? ' · חסרים: ' + miss.join(', ') : ''), !!miss.length);
        return { zip, files: files.map(x => x[0]), miss };
      } catch (e) { this.note(e.message, true); return null; }
    }
    /** ייבוא: בדיקה מקומית (רק תבנית אחת ו-media/) ← התוכנית של השרת (מה יתווסף, מה יוחלף) ← אישור. */
    async importZip(file) {
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      let info; try { info = checkZip(zipList(new Uint8Array(await file.arrayBuffer()))); } catch (e) { this.sheet('📦 ייבוא תבנית', h('p', { class: 'error', 'data-tpl-import-err': true }, e.message)); return null; }
      const scope = '&scope=' + encodeURIComponent('templates/' + info.name + '/,media/');   // T2 (V13 🟡A): השרת אוכף את הגבול, לא רק checkZip
      const send = async q => { let res, data; try { res = await fetch('api.php?action=pf_zip_import' + scope + q, { method: 'POST', headers: { 'Content-Type': 'application/zip', 'X-Kol': '1', 'X-CSRF': state.csrf }, body: file }); data = await res.json(); } catch { throw new Error(res ? 'תשובה לא תקינה מהשרת' : 'אין חיבור לשרת'); } if (!data.ok) throw new Error(data.error || 'שגיאה'); return data; };
      let plan0; try { plan0 = (await send('')).plan; } catch (e) { this.sheet('📦 ייבוא תבנית', h('p', { class: 'error', 'data-tpl-import-err': true }, e.message)); return null; }
      const li = (title, items, key) => (items.length ? h('details', { open: true, 'data-tpl-imp': key }, h('summary', {}, title + ' · ' + items.length), h('ul', {}, items.map(p => h('li', { dir: 'ltr' }, p)))) : null);
      const can = !plan0.errors.length && plan0.counts.add + plan0.counts.replace > 0;
      const go = h('button', { type: 'button', class: 'btn primary', 'data-tpl-import-go': true, disabled: !can || null }, 'ייבוא · ' + plan0.counts.add + ' חדשים, ' + plan0.counts.replace + ' מוחלפים');
      const dlg = this.sheet('📦 ייבוא תבנית · ' + info.name, h('p', { class: 'hint' }, 'עדיין לא נכתב דבר. הקבצים שב-ZIP (' + info.names.length + '):'),
        plan0.errors.length ? h('div', { class: 'ed-warn', role: 'alert' }, plan0.errors.join(' · ')) : null,
        li('➕ יתווספו', plan0.add, 'add'), li('♻️ התנגשות — קובץ באותו שם קיים ושונה, יוחלף (נקודת שחזור נשמרת לפני)', plan0.replace, 'replace'), li('＝ זהים — לא ישתנו', plan0.same, 'same'),
        h('div', { class: 'ed-ask-bar' }, go));
      return new Promise(done => {
        go.addEventListener('click', async () => {
          go.disabled = true;
          try { const r = await send('&apply=1&token=' + encodeURIComponent(plan0.token)); dlg.close(); await Project.docs.reload(); this.ver++; await this.load(); this.note('📦 יובאה התבנית ' + info.name + ': ' + r.done.add + ' נוספו · ' + r.done.replace + ' הוחלפו'); done(r); }
          catch (e) { go.disabled = false; this.note(e.message, true); done(null); }
        });
      });
    }
  }

  g.KolTemplates = { Templates, slug, checkName, commentSafe, cssClasses, rulesOf, plan, renameHtml, renameCss, dropSame, refs, crc32, zipBuild, zipList, checkZip };
})(typeof window !== 'undefined' ? window : globalThis);
