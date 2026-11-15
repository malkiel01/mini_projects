// קול מרוקו — 🤖 עבודה מצ'אט (lib/agent.php): יצירת כניסה וסקיל להעתקה. M6: הועבר מ-code.js (שנמחק עם </> קוד);
// נטען לפי צורך מחלון ✨ עוזר הבינה (ai-sheet.js).
'use strict';

const Agent = (() => {
  async function sheetView() {
    const body = h('div', { class: 'agent' }, h('p', { class: 'empty' }, 'טוען…'));
    sheet('🤖 עבודה מצ\'אט', body);
    let d;
    try { d = await api('agent_tokens'); } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    const label = h('input', { maxlength: 60, placeholder: 'למשל: Claude במחשב' });
    const out = h('div');
    const draw = tokens => body.replaceChildren(
      h('p', {}, 'סקיל שמעתיקים לצ\'אט (Claude). יש בו שם משתמש וסיסמה שעובדים רק כאן: הצ\'אט עורך את הטיוטה, המילים וקבצי הקוד — ',
        h('b', {}, 'בלי פרסום'), ', בלי גישה לאתר ובלי שום דבר אחר. הפרסום נשאר אצלכם.'),
      h('div', { class: 'toolbar' }, h('label', { class: 'f', style: 'flex:1' }, h('span', {}, 'שם לכניסה'), label),
        h('button', { type: 'button', class: 'btn primary', style: 'align-self:end', onclick: create }, '➕ יצירת סקיל')),
      out,
      h('h3', { class: 'sec-title' }, 'כניסות פעילות'),
      tokens.length ? h('ul', { class: 'list' }, tokens.map(t => h('li', { class: 'w-topic' },
        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, `${t.label} · ${t.id}`),
          h('small', {}, `${t.mine ? '' : t.name + ' · '}נוצרה ${when(t.created_at)} · ${t.last_used ? 'שימוש אחרון ' + when(t.last_used) : 'עוד לא בשימוש'}${t.expired ? ' · פגה' : ''}`)),
        h('button', { type: 'button', class: 'btn sm ghost danger', onclick: async () => {
          if (!confirm(`לבטל את "${t.label}"? הצ'אט שמשתמש בה יפסיק לעבוד מיד.`)) return;
          try { draw((await api('agent_token_revoke', { id: t.id })).tokens); toast('בוטלה'); } catch (e) { toast(e.message, true); }
        } }, '✕ ביטול')))) : h('p', { class: 'hint' }, 'אין כניסות פעילות.'),
      h('p', { class: 'hint' }, 'כל כניסה פגה אחרי 90 יום. כל מה שהצ\'אט עושה — ביומן, עם 🤖 ליד השם.'));
    async function create() {
      try {
        const r = await api('agent_token_create', { label: label.value });
        const ta = h('textarea', { class: 'code-area', dir: 'auto', rows: 10, readonly: true }, r.skill);
        out.replaceChildren(h('div', { class: 'summary' },
          h('p', {}, h('b', { style: 'color:var(--ok)' }, '✓ נוצרה. '), 'הסיסמה מוצגת ', h('b', {}, 'רק עכשיו'), ' — להעתיק את הסקיל ולשמור אותו.'),
          h('dl', { class: 'cells' }, h('div', {}, h('dt', {}, 'שם משתמש'), h('dd', { dir: 'ltr' }, r.user)), h('div', {}, h('dt', {}, 'סיסמה'), h('dd', { dir: 'ltr' }, r.password))),
          h('div', { class: 'row-btns wrap' },
            h('button', { type: 'button', class: 'btn primary', onclick: async () => {
              try { await navigator.clipboard.writeText(r.skill); toast('הסקיל הועתק — להדביק בצ\'אט'); } catch { ta.select(); toast('סמנו והעתיקו ידנית', true); }
            } }, '📋 העתקת הסקיל'),
            h('button', { type: 'button', class: 'btn ghost', onclick: () => {
              const a = h('a', { href: URL.createObjectURL(new Blob([r.skill], { type: 'text/markdown' })), download: 'SKILL.md' });
              a.click(); URL.revokeObjectURL(a.href);
            } }, '⬇️ SKILL.md')),
          ta));
        label.value = '';
        draw(r.tokens);                     // out — אותו אלמנט, עם הסקיל שבתוכו
      } catch (e) { toast(e.message, true); }
    }
    draw(d.tokens);
  }
  return { sheet: sheetView };
})();
