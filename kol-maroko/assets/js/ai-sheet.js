// קול מרוקו — ✨ עוזר הבינה על קבצי הפרויקט (פרוסה X1 §2.1; lib/ai.php: ai_suggest_files / ai_apply_files).
// הבקשה + ההקשר (קבצים, טווח שורות, אלמנט) ← הצעה: diff לכל קובץ (רכיב ה-diff של 📁), ובמילים — "מה ישתנה" של N1.
// "החל" — השרת כותב דרך מנוע הקבצים (גרסה אחת לכל קובץ, "✨ <הבקשה>"); "בטל" — לא נשמר כלום.
// הכול מוצג כטקסט (h() / textContent) — אין בקובץ הזה innerHTML: התוכן שהבינה מחזירה אינו רץ כאן.
'use strict';

const AiSheet = (() => {
  /** ctx: { paths: [...], range?: {path, from, to}, element?: {path, element}, title?, onApplied? } */
  function open(ctx) {
    const paths = [...new Set([...(ctx.paths || []), ctx.range?.path, ctx.element?.path].filter(Boolean))];
    const prompt = h('textarea', { class: 'ai-prompt', rows: 3, 'data-ai-prompt': true, placeholder: 'מה לשנות? למשל: "תחליף את הכותרת הראשית ל…"', 'aria-label': 'הבקשה' });
    const out = h('div', { class: 'ai-out', 'aria-live': 'polite' });
    const go = h('button', { type: 'button', class: 'btn primary', 'data-ai-go': true }, '✨ הצע שינוי');
    const chips = h('div', { class: 'line wrap ai-ctx' }, h('span', { class: 'muted' }, 'ההקשר:'),
      paths.map(p => h('code', { class: 'chip', dir: 'ltr' }, p)),
      ctx.range ? h('span', { class: 'chip' }, `שורות ${ctx.range.from}–${ctx.range.to}`) : null,
      ctx.element ? h('span', { class: 'chip', dir: 'ltr' }, ctx.element.element) : null);
    sheet(ctx.title || '✨ עוזר הבינה', chips, prompt,
      h('div', { class: 'row-btns' }, go, h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'סגירה')), out);
    setTimeout(() => prompt.focus(), 50);
    prompt.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) go.click(); });
    go.onclick = async () => {
      if (prompt.value.trim().length < 3) { toast('כתבו מה לשנות', true); return; }
      go.disabled = true;
      out.replaceChildren(h('p', { class: 'hint' }, '✨ חושב… (עד דקה או שתיים)'));
      try {
        const r = await api('ai_suggest_files', { prompt: prompt.value, paths: ctx.paths || [], range: ctx.range || null, element: ctx.element || null });
        show(r, out, ctx);
      } catch (e) { out.replaceChildren(h('p', { class: 'error', role: 'alert', 'data-ai-error': true }, e.message)); }
      go.disabled = false;
    };
  }

  /** ההצעה: סיכום, מה לא הוחל ולמה, ולכל קובץ — סימון לכל עריכה ו-diff. */
  function show(r, out, ctx) {
    const picks = new Map();                            // i → תיבת סימון
    const files = r.files.map(f => {
      const edits = r.edits.filter(e => e.ok && e.path === f.path);
      const head = h('div', { class: 'line wrap ai-file-head' },
        h('b', { dir: 'ltr' }, f.path),
        f.kind === 'create' ? h('span', { class: 'chip' }, 'קובץ חדש') : null,
        f.kind === 'words' ? h('span', { class: 'chip' }, '📚 טבלת המילים') : null,
        f.system ? h('span', { class: 'chip warn', 'data-ai-system': true }, 'קובץ מערכת — יסומן "שונה"') : null);
      const marks = f.kind === 'words' ? null : h('div', { class: 'line wrap ai-marks' }, edits.map(e => {
        const cb = h('input', { type: 'checkbox', checked: true, 'data-ai-edit': e.i });
        picks.set(e.i, cb);
        return h('label', { class: 'chip' }, cb, ` עריכה ${e.i + 1}`);
      }));
      if (f.kind === 'words') edits.forEach(e => picks.set(e.i, { checked: true }));    // המילים — הכול או כלום (התצוגה של N1 אחת)
      const words = f.kind === 'words' && f.plan ? h('div', { class: 'ai-words' },
        h('p', { 'data-ai-plan': f.plan.counts.changed }, f.plan.summary),
        f.plan.changed?.length ? h('ul', { class: 'hint' }, f.plan.changed.slice(0, 30).map(c => h('li', {}, c.word + ': ' + Object.keys(c.fields).join(', ')))) : null) : null;
      return h('section', { class: 'ai-file', 'data-ai-file': f.path }, head, marks, words, Project.diffView(f.old, f.new));
    });
    const rej = r.rejected.length ? h('div', { class: 'ai-rej', 'data-ai-rejected': r.rejected.length },
      h('b', {}, `לא הוחלו (${r.rejected.length}):`),
      h('ul', {}, r.rejected.map(x => h('li', {}, h('code', { dir: 'ltr' }, x.path || '?'), ' — ', x.why)))) : null;
    const apply = h('button', { type: 'button', class: 'btn primary', 'data-ai-apply': true, disabled: !r.files.length }, 'החל');
    const cancel = h('button', { type: 'button', class: 'btn ghost', 'data-ai-cancel': true, onclick: () => out.replaceChildren(h('p', { class: 'hint' }, 'בוטל — לא נשמר כלום.')) }, 'בטל');
    out.replaceChildren(...[
      r.summary ? h('p', { class: 'ai-sum', 'data-ai-summary': true }, r.summary) : null,
      rej,
      r.files.length ? files : h('p', { class: 'empty' }, 'אין שינוי להחיל.'),
      h('div', { class: 'row-btns' }, apply, cancel),
      r.usage ? h('p', { class: 'hint' }, `${r.usage.tokens.toLocaleString('he-IL')} טוקנים` + (r.usage.quota != null ? ` · החודש ${r.usage.used.toLocaleString('he-IL')} מתוך ${r.usage.quota.toLocaleString('he-IL')}` : '')) : null,
    ].flat().filter(Boolean));          // replaceChildren אינו משטח מערכים, ו-null נכתב כ-"null"
    apply.onclick = async () => {
      const only = [...picks].filter(([, cb]) => cb.checked).map(([i]) => i);
      if (!only.length) { toast('לא סומנה אף עריכה', true); return; }
      apply.disabled = true;
      try {
        const a = await api('ai_apply_files', { request_id: r.request_id, only });
        out.replaceChildren(h('p', { class: 'ok', role: 'status', 'data-ai-done': a.applied.length },
          `✓ הוחל ב-${a.applied.length} קבצים — גרסה בהיסטוריה של כל קובץ (↩️ בגרסאות, או Ctrl+Z בעורך).`));
        if (a.rejected.length) out.append(h('p', { class: 'hint' }, `${a.rejected.length} עריכות לא הוחלו.`));
        toast('✨ הוחל');
        await ctx.onApplied?.(a);
      } catch (e) {
        apply.disabled = false;
        out.prepend(h('p', { class: 'error', role: 'alert', 'data-ai-error': true }, e.message));
      }
    };
  }

  return { open };
})();
