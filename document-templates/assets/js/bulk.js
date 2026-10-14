// שלב 12: הנפקה מאקסל, וייצוא התשובות לאקסל.
// גיליון אחד בשלבים: קובץ → מיפוי עמודות → תוצאה. הקובץ נשאר בדפדפן ונשלח
// שוב עם המיפוי — אין מצב בשרת בין התצוגה המקדימה להנפקה.
(function () {
  'use strict';

  /** הורדה של קובץ מהשרת (xlsx), עם השם שהשרת נתן. */
  async function download(action, query) {
    const res = await fetch('api.php?action=' + action + '&' + new URLSearchParams(query), { credentials: 'same-origin' });
    if (!res.ok) {
      let msg = 'ההורדה נכשלה';
      try { msg = (await res.json()).error || msg; } catch (e) { /* לא JSON */ }
      throw new Error(msg);
    }
    const cd = res.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename\*=UTF-8''([^;]+)/);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(await res.blob());
    a.download = m ? decodeURIComponent(m[1]) : 'export.xlsx';
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 30000);
  }

  /** ייצוא התשובות של תבנית — לפי הסינון (query כולל template_id). */
  function exportFile(query) {
    return download('doc_export', query);
  }

  /** גיליון ההנפקה מאקסל לתבנית t. */
  function open(t) {
    const { api, el, notice, sheet, tile } = window.DT;
    const body = el('div', { class: 'bulk' });
    const go = el('button', { type: 'button', class: 'primary small', hidden: true }, 'הנפקה');
    const dlg = sheet('הנפקה מאקסל — ' + t.name, body, { action: go });
    // replaceChildren מדפיס null — מסננים
    const put = (...nodes) => body.replaceChildren(...nodes.filter(n => n != null));
    let file = null;
    let prev = null;
    const selects = [];

    function step1(err) {
      go.hidden = true;
      const input = el('input', { type: 'file', name: 'file', class: 'visually-hidden', accept: '.xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
      input.addEventListener('change', () => { if (input.files[0]) preview(input.files[0]); });
      put(
        el('p', { class: 'hint' }, 'קובץ Excel או CSV: בשורה הראשונה שמות העמודות, וכל שורה אחריה — מסמך. אחר כך בוחרים איזו עמודה נכנסת לאיזה שדה.'),
        err ? notice(err, 'bad') : null,
        el('div', { class: 'tiles' },
          el('label', { class: 'tile' }, input, el('span', { class: 'tile-ic', 'aria-hidden': 'true' }, '📥'),
            el('span', {}, 'בחירת קובץ', el('small', {}, 'xlsx או csv, עד 500 שורות'))),
          tile('⬇️', 'קובץ דוגמה', async () => {
            try { await download('bulk_sample', { template_id: t.id }); }
            catch (e) { step1(e.message); }
          }, { sub: 'עם העמודות של התבנית' })));
    }

    async function preview(f) {
      file = f;
      body.replaceChildren(el('p', { class: 'empty' }, 'קורא את ' + f.name + '…'));
      const fd = new FormData();
      fd.append('template_id', t.id);
      fd.append('file', f);
      try { prev = await api('bulk_preview', fd); }
      catch (e) { step1(e.message); return; }
      step2();
    }

    function step2() {
      selects.length = 0;
      const groups = {};
      for (const x of prev.targets) (groups[x.group] ??= []).push(x);
      const count = el('b');
      const update = () => {
        const used = selects.map(s => s.value).filter(Boolean);
        // כל יעד — לעמודה אחת: מה שכבר נבחר במקום אחר — מסומן
        for (const s of selects) for (const o of s.querySelectorAll('option[value]')) {
          if (o.value) o.disabled = o.value !== s.value && used.includes(o.value);
        }
        count.textContent = String(used.length);
        go.disabled = !used.length;
      };
      const rows = prev.headers.map((h, i) => {
        let sample = prev.sample.map(r => r[i]).filter(Boolean)[0] || '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(sample)) sample = sample.split('-').reverse().join('/');
        const sel = el('select', { 'aria-label': 'לאן נכנסת העמודה ' + h, 'data-col': i },
          el('option', { value: '' }, '— לא לייבא —'),
          Object.entries(groups).map(([g, list]) => el('optgroup', { label: g },
            list.map(x => el('option', { value: x.value, selected: prev.mapping[i] === x.value }, x.label)))));
        sel.addEventListener('change', update);
        selects.push(sel);
        return el('li', { class: 'map-row' },
          el('span', { class: 'use-tx' }, el('b', {}, h || 'עמודה ' + (i + 1)), el('small', {}, sample ? 'למשל: ' + sample : 'ריקה בדוגמה')),
          sel);
      });
      const tag = el('input', { value: prev.tag, maxlength: 40, 'aria-label': 'תגית לכל המסמכים' });
      const send = el('input', { type: 'checkbox' });
      go.hidden = false;
      go.textContent = `הנפקת ${prev.rows} מסמכים`;
      go.onclick = () => issue(tag.value, send.checked);
      put(
        el('div', { class: 'summary' }, el('div', { class: 'line' },
          el('span', {}, '📎 ' + file.name), el('span', { class: 'muted' }, '·'),
          el('b', {}, String(prev.rows)), el('span', { class: 'muted' }, 'שורות ·'), count, el('span', { class: 'muted' }, 'עמודות לייבוא'))),
        prev.rows > prev.max ? notice(`יש בקובץ ${prev.rows} שורות — עד ${prev.max} בכל פעם. פצלו את הקובץ.`, 'bad') : null,
        el('p', { class: 'hint' }, 'לכל עמודה — לאן היא נכנסת. מה שזוהה לפי השם כבר נבחר.'),
        el('ul', { class: 'list' }, rows),
        el('label', { class: 'f' }, el('span', {}, 'תגית לכל המסמכים (כדי למצוא אותם אחר כך)'), tag),
        prev.roles ? el('label', { class: 'switch' }, send, el('span', {}, 'לשלוח במייל קישור למשתתפים שיש להם מייל')) : null,
        el('button', { type: 'button', class: 'ghost', onclick: () => step1() }, 'קובץ אחר'));
      update();
    }

    async function issue(tag, send) {
      const mapping = Object.fromEntries(selects.filter(s => s.value).map(s => [s.dataset.col, s.value]));
      const fd = new FormData();
      fd.append('template_id', t.id);
      fd.append('file', file);
      fd.append('mapping', JSON.stringify(mapping));
      fd.append('tag', tag);
      fd.append('send', send ? '1' : '0');
      go.disabled = true;
      go.textContent = 'מנפיק…';
      body.prepend(el('p', { class: 'notice' }, `מנפיק ${prev.rows} מסמכים — זה יכול לקחת רגע.`));
      let r;
      try { r = await api('bulk_issue', fd); }
      catch (e) {
        body.prepend(notice(e.message, 'bad'));
        go.disabled = false;
        go.textContent = `הנפקת ${prev.rows} מסמכים`;
        return;
      }
      step3(r);
    }

    function step3(r) {
      go.hidden = true;
      const issues = [...r.failed.map(x => ({ ...x, kind: 'bad' })), ...r.warnings.map(x => ({ ...x, kind: 'warn' }))]
        .sort((a, b) => a.row - b.row);
      put(
        el('div', { class: 'summary' },
          el('div', { class: 'line' }, el('b', {}, '✅ ' + r.created.length), el('span', {}, r.created.length === 1 ? 'מסמך נוצר' : 'מסמכים נוצרו'),
            r.failed.length ? el('span', { class: 'badge bad' }, r.failed.length === 1 ? 'שורה אחת לא נוצרה' : r.failed.length + ' שורות לא נוצרו') : null,
            r.warnings.length ? el('span', { class: 'badge warn' }, r.warnings.length === 1 ? 'הערה אחת' : r.warnings.length + ' הערות') : null),
          el('div', { class: 'line muted small' }, '🏷️ ' + r.tag)),
        issues.length ? el('p', { class: 'hint' }, 'מה לא עבר — לפי מספר השורה בקובץ:') : null,
        issues.length ? el('ul', { class: 'list' }, issues.map(x => el('li', {},
          el('span', { class: 'use-row' }, el('span', { class: 'use-ic', 'aria-hidden': 'true' }, x.kind === 'bad' ? '⛔' : '⚠️'),
            el('span', { class: 'use-tx' }, el('b', {}, 'שורה ' + x.row), el('small', { class: 'wrap' }, x.error)))))) : null,
        el('div', { class: 'tiles' },
          r.created.length ? window.DT.tile('🗂️', 'למסמכים שנוצרו', () => { dlg.close(); location.hash = 'docs?tag=' + encodeURIComponent(r.tag); }) : null,
          window.DT.tile('📥', 'קובץ נוסף', () => step1())));
    }

    step1();
  }

  window.DTBulk = { open, exportFile, download };
})();
