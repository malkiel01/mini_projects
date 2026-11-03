/*
 * פתיחת צ'אטים — docs/spec.md §5.9 (שלב 11).
 *
 * ⚙ ← 🆕 צ'אט חדש: אריחי תפקידים, ולכל תפקיד הפרומפט הגמור (מספר הפרויקט, השם,
 * קובצי ההקשר, ההיררכיה, למי מדווחים) — "📋 העתקה" ו"פתיחת Claude Code". בעל
 * המערכת לא ממלא שום דבר ביד. ✏️ — עורך התבנית, שעוברת בירושה שדה-שדה מהאבות.
 *
 * 🔁 צ'אט המשך: בקשה לצ'אט ← הוא כותב תקציר (link.sh handoff) ← פרומפט המשך עם
 * --replaces. בלי תקציר תוך 2 דקות — "בלי תקציר" מ-30 ההודעות האחרונות.
 *
 * claude.ai/code לא מקבל פרומפט בכתובת (לא נמצאה דרך מתועדת) — לכן תמיד: העתקה, ואז פתיחה.
 */
import { $, el, api, toast, state, on } from './core.js';

const CODE_URL = 'https://claude.ai/code';
const nc = { sheet: null, role: null };

/** העתקה ופתיחה — הפרומפט בלוח, והצ'אט החדש בלשונית חדשה. */
async function copyText(text) {
  try { await navigator.clipboard.writeText(text); return true; } catch {
    // דפדפן בלי הרשאה ללוח — בוחרים את הטקסט, והאדם מעתיק
    return false;
  }
}

function envLine(env) {
  return el('p', { class: 'nc-env' }, env
    ? ['בחרו בסביבה ', el('b', { dir: 'ltr', text: env }), ' כשפותחים את הצ\'אט — שם שמור האסימון.']
    : ['שם הסביבה עוד לא נשמר בלוח. ', el('small', { text: '✏️ ← "שם הסביבה" — כדי שהגיליון יגיד לאיזו סביבה להיכנס.' })]);
}

/** הפרומפט, ומתחתיו שני הכפתורים. ‏label — למסך הקורא. */
function promptBlock(prompt, label, { collapsed = false } = {}) {
  // שורה לכל שורה, כל אחת בכיוון שלה; שורת הפקודה — משמאל לימין, בשורה אחת שגוללת (אחרת היא מתפצלת ומתהפכת בעברית)
  const pre = el('pre', { class: 'nc-prompt', tabindex: '0', 'aria-label': label }, prompt.split('\n').map((line) =>
    /^\s*claude-tasks\//.test(line)
      ? el('code', { class: 'nc-cmd', dir: 'ltr', text: line.trim() })
      : el('span', { class: 'nc-line', dir: 'auto', text: line || '\u00a0' })));
  return el('div', { class: 'nc-prompt-wrap' }, [
    // צ'אט המשך: בעל הלוח לא צריך לקרוא את הזיכרון — רק להעתיק ולפתוח. מקופל, ונפתח בנגיעה
    collapsed ? el('details', { class: 'what' }, [el('summary', { text: 'הצגת הפרומפט (לא חובה)' }), pre]) : pre,
    el('div', { class: 'row-btns' }, [
      el('button', {
        type: 'button', class: 'btn primary', text: '📋 העתקה',
        onclick: async () => {
          if (await copyText(prompt)) toast('הפרומפט הועתק', 'ok');
          else { getSelection().selectAllChildren(pre); toast('סמנתי את הפרומפט — העתיקו ידנית', ''); }
        },
      }),
      el('button', {
        type: 'button', class: 'btn ghost', text: 'פתיחת Claude Code',
        onclick: async () => {
          const ok = await copyText(prompt);
          window.open(CODE_URL, '_blank', 'noopener');
          toast(ok ? 'הפרומפט הועתק — הדבקה בצ\'אט החדש' : 'העתיקו את הפרומפט והדביקו בצ\'אט החדש', ok ? 'ok' : '');
        },
      }),
    ]),
  ]);
}

/* ── 🆕 צ'אט חדש ──────────────────────────────────────────────── */

/** לשורה ב-⚙: "4 תפקידים · סביבה: …". נטען ברקע כשנפתח הפרויקט. */
export async function loadChatSheet(projectId) {
  nc.sheet = await api('chat-sheet', { project_id: projectId });
  return nc.sheet;
}

export function chatSheetLine() {
  const s = nc.sheet;
  if (!s) return '…';
  return `${s.roles.length === 1 ? 'תפקיד אחד' : `${s.roles.length} תפקידים`} · סביבה: ${s.environment || 'לא הוגדרה'}`;
}

function renderNewChat() {
  const s = nc.sheet;
  if (!s) return;
  const role = s.roles.find((r) => r.key === nc.role) || (s.roles.length === 1 ? s.roles[0] : null);
  $('#ncEdit').hidden = !s.can_edit;
  $('#ncBody').replaceChildren(...[
    envLine(s.environment),
    el('p', { class: 'hint', text: `#${s.project.id} · ${s.project.path} — בחרו תפקיד:` }),
    el('div', { class: 'tiles nc-roles', role: 'radiogroup', 'aria-label': 'תפקיד' }, s.roles.map((r) => el('button', {
      type: 'button', class: 'nc-role', role: 'radio', 'aria-checked': String(role?.key === r.key),
      onclick: () => { nc.role = r.key; renderNewChat(); },
    }, [el('span', { class: 'ic', 'aria-hidden': 'true', text: r.icon || '💬' }), el('b', { text: r.label }),
        r.reports_to ? el('small', { text: `מדווח ל: ${r.reports_to}` }) : null]))),
    role ? el('h3', { text: role.name }) : null,
    role ? promptBlock(role.prompt, `הפרומפט ל${role.label}`) : null,
    // §9 (תקלה 3): link.sh מדפיס את השגיאה בעברית — וכאן שלוש הסיבות השכיחות, למי שלא רואה את הצ'אט
    role ? el('details', { class: 'nc-help' }, [
      el('summary', { text: 'לא נרשם תוך 10 דק\'? מה לבדוק' }),
      el('ol', {}, [
        el('li', {}, [el('b', { text: 'סביבה לא נכונה. ' }), s.environment
          ? `הצ'אט נפתח בסביבה אחרת. פותחים צ'אט חדש ובוחרים בסביבה "${s.environment}".`
          : 'הצ\'אט נפתח בסביבה שאין בה את האסימון. פותחים צ\'אט חדש בסביבה שבה הוא שמור.']),
        el('li', {}, [el('b', { text: 'אסימון חסר. ' }),
          'בהגדרות הסביבה (תפריט הסביבה בכותרת הצ\'אט ← Edit) חסר CLAUDE_TASKS_TOKEN. מוסיפים אותו שם — לעולם לא בצ\'אט — ופותחים צ\'אט חדש.']),
        el('li', {}, [el('b', { text: 'אסימון של תת-פרויקט. ' }),
          `האסימון שבסביבה שייך לענף אחר. צריך את האסימון של "${s.root?.name || 'השורש'}" — הוא פותח את כל הענפים.`]),
      ]),
    ]) : null,
    // ⚡ גרסת Routine (§5.12.5): משימה מתוזמנת שנרשמת בתחילת כל הרצה — כך בקשות ההרשאה שלה מגיעות ללוח
    role?.routine_prompt ? el('details', { class: 'nc-routine' }, [
      el('summary', { text: '⚡ גרסת Routine — משימה מתוזמנת' }),
      el('p', { class: 'hint', text: 'כל הרצה נרשמת ללוח בהתחלה ומתנתקת בסוף. בלי הריפו אין את השער של הלוח — ההרצה עוצרת ומדווחת.' }),
      // §5.13.3: הוראות יצירה מלאות — כך העתקה אחת יוצרת Routine נכון
      el('ol', { class: 'nc-steps' }, [
        el('li', {}, ['משימה מתוזמנת חדשה ב-claude.ai ← Code.']),
        el('li', {}, ['ריפו: ', s.repo?.url ? el('code', { dir: 'ltr', text: s.repo.url }) : el('b', { text: 'mini_projects' }),
          s.repo?.branch ? [' · ענף ', el('code', { dir: 'ltr', text: s.repo.branch })] : null].flat().filter(Boolean)),
        el('li', {}, ['סביבה: ', s.environment ? el('b', { dir: 'ltr', text: s.environment }) : 'זו שבה שמור האסימון']),
        el('li', {}, ['הפרומפט — למטה. מחליפים את {מה לעשות בכל הרצה} ואת {התיקייה}.']),
      ]),
      promptBlock(role.routine_prompt, `פרומפט Routine ל${role.label}`),
    ]) : null,
    s.roles.length === 1 && s.can_edit ? el('p', { class: 'hint', text: 'יש רק תפקיד אחד (ברירת המחדל). ✏️ — להגדיר את התפקידים של הצוות פעם אחת, וכל תת-פרויקט יקבל אותם.' }) : null,
  ].filter(Boolean));
}

export async function openNewChat(projectId) {
  try { await loadChatSheet(projectId); } catch (ex) { toast(ex.message, 'error'); return; }
  nc.role = null;
  renderNewChat();
  $('#pvsNewChat').showModal();
}

/* ── ✏️ עורך התבנית ──────────────────────────────────────────── */

const FIELDS = [
  ['name_pattern', 'תבנית השם', 'input', 'למשל: היצירה v2 - {role}. ‏{role} — שם התפקיד, {project} — שם הפרויקט.'],
  ['context_files', 'קבצים לקרוא קודם', 'textarea', 'נתיב בכל שורה. CLAUDE.md נקרא תמיד.'],
  ['hierarchy', 'ההיררכיה', 'textarea', 'כמה שורות: מי מעל מי.'],
  ['notify_session', 'הודעת ההצטרפות אל', 'input', 'session_… — ריק: צ\'אט האפיון של "מטלות לקלוד".'],
  ['extra', 'הנחיות נוספות', 'textarea', 'מתווסף לסוף כל פרומפט.'],
];

const tpl = { own: {}, inherited: {}, sources: {}, roles: [], rolesOwn: false, dirty: new Set() };

function inheritNote(field) {
  const src = tpl.sources[field];
  const own = tpl.own[field] !== null && tpl.own[field] !== undefined;
  if (own) {
    return el('button', {
      type: 'button', class: 'linklike', text: '↺ חזרה לירושה',
      onclick: () => { tpl.own[field] = null; tpl.dirty.add(field); renderTemplate(); },
    });
  }
  return el('small', { class: 'inherit', text: src ? `· יורש מ: ${src.name}` : '· ברירת מחדל' });
}

function roleRow(r, i) {
  const set = (k) => (e) => { tpl.roles[i][k] = e.target.value; tpl.dirty.add('roles'); tpl.rolesOwn = true; };
  return el('fieldset', { class: 'tpl-role' }, [
    el('legend', { text: `תפקיד ${i + 1}` }),
    el('div', { class: 'tpl-role-top' }, [
      el('input', { type: 'text', class: 'tpl-icon', value: r.icon || '', maxlength: '8', 'aria-label': 'סמל', oninput: set('icon') }),
      el('input', { type: 'text', value: r.label || '', maxlength: '40', placeholder: 'שם (המתכלל)', 'aria-label': 'שם התפקיד', oninput: set('label') }),
      el('input', { type: 'text', dir: 'ltr', value: r.key || '', maxlength: '30', placeholder: 'key', 'aria-label': 'מפתח באנגלית', oninput: set('key') }),
      el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'הסרת התפקיד', text: '✕',
        onclick: () => { tpl.roles.splice(i, 1); tpl.dirty.add('roles'); tpl.rolesOwn = true; renderTemplate(); } }),
    ]),
    el('input', { type: 'text', value: r.reports_to || '', maxlength: '80', placeholder: 'מדווח ל (המתכלל)', 'aria-label': 'מדווח ל', oninput: set('reports_to') }),
    el('textarea', { rows: '2', maxlength: '2000', placeholder: 'מה התפקיד עושה — נכנס לפרומפט', 'aria-label': 'תיאור התפקיד', oninput: set('brief') }, [r.brief || '']),
  ]);
}

function renderTemplate() {
  const body = [];
  if (nc.sheet?.can_edit) {
    body.push(el('label', { class: 'field' }, [el('span', { text: 'שם הסביבה (לכל הלוח)' }),
      el('input', { type: 'text', id: 'tplEnv', dir: 'auto', value: nc.sheet.environment || '', placeholder: 'Default Cloud Environment', maxlength: '80' })]),
      el('p', { class: 'hint', text: 'הסביבה ב-Claude Code שבה שמור CLAUDE_TASKS_TOKEN (של השורש). הגיליון אומר לבחור בה.' }));
  }
  for (const [f, label, kind, hint] of FIELDS) {
    const own = tpl.own[f];
    const props = { id: `tpl_${f}`, placeholder: tpl.inherited[f] || '', dir: 'auto',
      oninput: (e) => { tpl.own[f] = e.target.value; tpl.dirty.add(f); } };
    const input = kind === 'textarea' ? el('textarea', { ...props, rows: '3' }, [own ?? ''])
      : el('input', { ...props, type: 'text', value: own ?? '' });
    body.push(el('label', { class: 'field' }, [el('span', {}, [label, ' ', inheritNote(f)]), input]),
      el('p', { class: 'hint', text: hint }));
  }
  body.push(el('h3', {}, ['תפקידים ', tpl.rolesOwn ? el('button', { type: 'button', class: 'linklike', text: '↺ חזרה לירושה',
    onclick: () => { tpl.rolesOwn = false; tpl.roles = structuredClone(tpl.inheritedRoles); tpl.dirty.add('roles'); renderTemplate(); } })
    : el('small', { class: 'inherit', text: tpl.sources.roles ? `· יורש מ: ${tpl.sources.roles.name}` : '· ברירת מחדל' })]),
    ...tpl.roles.map(roleRow),
    el('button', { type: 'button', class: 'btn ghost', text: '+ תפקיד',
      onclick: () => { tpl.roles.push({ key: '', label: '', icon: '💬', reports_to: '', brief: '' }); tpl.dirty.add('roles'); tpl.rolesOwn = true; renderTemplate(); } }));
  $('#tplBody').replaceChildren(...body);
}

function openTemplate() {
  const t = nc.sheet.template;
  const projectId = nc.sheet.project.id;
  tpl.sources = t.sources;
  tpl.own = Object.fromEntries(FIELDS.map(([f]) => [f, t.own?.[f] ?? null]));
  // מה שיוצג כ-placeholder: הערך האפקטיבי כשהשדה לא מקומי
  tpl.inherited = Object.fromEntries(FIELDS.map(([f]) => [f, t.own?.[f] ? '' : (t[f] || '')]));
  tpl.rolesOwn = !!t.own?.roles;
  tpl.roles = structuredClone(t.roles);
  tpl.inheritedRoles = structuredClone(t.roles);
  tpl.dirty = new Set();
  tpl.projectId = projectId;
  renderTemplate();
  $('#pvsTemplate').showModal();
}

on('#ncEdit', 'click', openTemplate);

on('#tplForm', 'submit', async (e) => {
  e.preventDefault();
  const fields = {};
  for (const f of tpl.dirty) {
    if (f === 'roles') fields.roles = tpl.rolesOwn ? tpl.roles.filter((r) => (r.label || r.key)) : null;
    else fields[f] = tpl.own[f] === null ? null : String(tpl.own[f]).trim() || null;
  }
  $('#tplSave').disabled = true;
  try {
    const env = $('#tplEnv');
    if (env && env.value.trim() !== (nc.sheet.environment || '')) await api('chat-environment', { name: env.value.trim() });
    if (Object.keys(fields).length) await api('template-save', { project_id: tpl.projectId, fields });
    await loadChatSheet(tpl.projectId);
    renderNewChat();
    document.dispatchEvent(new CustomEvent('ct:chat-sheet'));
    $('#pvsTemplate').close();
    toast('התבנית נשמרה — הפרומפטים עודכנו', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
  $('#tplSave').disabled = false;
});

/* ── 🔁 צ'אט המשך (§5.9.4, §15.13) ───────────────────────────── */
/*
 * שום המתנה לא תופסת את המסך: ✕ תמיד סוגר ולעולם לא מבטל (הבקשה נשארת בשרת, והשורה של הערוץ מראה
 * "🔁 מכין תקציר… m:ss"); יש זמן שעבר וזמן צפוי — או "לא ידוע" ביושר; צ'אט שלא מאזין לא "חושב" (בלי ספינר).
 */

const ho = { channel: null, timer: null, tick: null, gen: 0, status: null, base: 0 };
export const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.max(0, s) % 60).padStart(2, '0')}`;
const secs = (s) => (s < 90 ? `${s} שניות` : `${Math.round(s / 60)} דקות`);

function stopHandoff() {
  clearTimeout(ho.timer); clearInterval(ho.tick);
  ho.timer = null; ho.tick = null; ho.gen++;
}
const elapsed = () => (ho.status ? ho.status.waited + Math.floor((Date.now() - ho.base) / 1000) : 0);

async function pollHandoff() {
  clearTimeout(ho.timer);
  if (!ho.channel || !$('#handoffSheet').open) return;
  const gen = ho.gen;
  let st;
  try { st = await api('handoff-status', { channel_id: ho.channel.id }); } catch (ex) { if (gen === ho.gen) toast(ex.message, 'error'); return; }
  if (gen !== ho.gen || !$('#handoffSheet').open) return;   // נסגר / נפתח מחדש בינתיים
  ho.status = st; ho.base = Date.now();
  if (st.state === 'ready') { showHandoffPrompt(false); return; }
  renderHandoff();
  if (st.state === 'waiting') ho.timer = setTimeout(pollHandoff, 4000);
}

/** מתעדכן כל שנייה בלי לפנות לשרת: הזמן, הפס, ו"לוקח יותר מהרגיל". */
function tickHandoff() {
  const t = elapsed(), st = ho.status;
  const e = $('#hoElapsed'); if (e) e.textContent = mmss(t);
  const bar = $('#hoBar');
  if (bar && st?.expected_seconds) {
    const pct = Math.min(100, Math.round((t / st.expected_seconds) * 100));
    bar.value = pct; bar.setAttribute('aria-valuenow', String(pct));
    $('#hoExpect').textContent = t > st.expected_seconds ? `לוקח יותר מהרגיל (בדרך כלל כ-${secs(st.expected_seconds)})` : `בדרך כלל כ-${secs(st.expected_seconds)}`;
  }
  const fb = $('#hoFallback');
  if (fb && st?.listening) fb.className = `btn ${t >= 120 ? 'primary' : 'ghost'}`;
  const late = $('#hoLate'); if (late) late.hidden = t < 300;
}

function renderHandoff() {
  const st = ho.status, name = ho.channel.name;
  const fallback = el('button', { type: 'button', id: 'hoFallback', class: `btn ${!st.listening || elapsed() >= 120 ? 'primary' : 'ghost'}`,
    text: 'בלי תקציר — לפתוח עכשיו', onclick: () => showHandoffPrompt(true) });
  const background = el('button', { type: 'button', class: 'btn ghost', text: 'להמשיך לחכות ברקע',
    onclick: () => { $('#handoffSheet').close(); toast('ממשיכים לחכות ברקע — השורה של הערוץ תראה כשהתקציר מוכן', 'ok'); } });
  const cancel = el('button', { type: 'button', class: 'linklike', text: 'ביטול הבקשה', onclick: async () => {
    try { await api('handoff-cancel', { channel_id: ho.channel.id }); toast('הבקשה בוטלה', 'ok'); } catch (ex) { toast(ex.message, 'error'); }
    pollHandoff();
  } });
  const again = el('button', { type: 'button', class: 'btn primary', text: '🔁 בקשה חדשה', onclick: async () => {
    try { await api('handoff-request', { channel_id: ho.channel.id }); } catch (ex) { toast(ex.message, 'error'); }
    pollHandoff();
  } });
  // בעל הלוח 2026-11-03: צ'אט שלא מאזין לא יענה עד שעה — לא מחכים לו. סיכום חכם עכשיו (קריאה אחת, כ-10 שניות),
  // או לבקש מהצ'אט הישן ישירות (בתוך Claude Code — שניות), או בלי תקציר
  // "לסכם את הצ'אט למעבר": זיקוק של כל השיח לזיכרון עבודה (כללים, החלטות, מה באמצע, מה פתוח) — בלי מה שכבר לא רלוונטי
  const SMART = '📝 לסכם את הצ\'אט למעבר לצ\'אט חדש';
  const smart = el('button', { type: 'button', class: 'btn primary', text: SMART, onclick: async (e) => {
    const b = e.currentTarget; b.disabled = true; b.textContent = '📝 מסכם…';
    try { await api('handoff-distill', { channel_id: ho.channel.id }); await showHandoffPrompt(false); }
    catch (ex) { toast(`${ex.message} — אפשר "בלי תקציר"`, 'error'); b.disabled = false; b.textContent = SMART; }
  } });
  const askText = 'תכתוב עכשיו תקציר העברה במבנה הקבוע (מצב וערכים עיקריים · החלטות ולמה · מה פתוח · קבצים שנגעת בהם · sessions וענף · מה הצ\'אט הבא צריך לעשות קודם), ושלח אותו ללוח: claude-tasks/bridge/link.sh handoff <קובץ>';
  const direct = el('details', { class: 'what' }, [
    el('summary', { text: `⚡ הכי מדויק: לבקש מ"${name}" ישירות` }),
    el('p', { class: 'hint', text: 'פותחים את הצ\'אט הישן ב-Claude Code ומדביקים את ההודעה הזו. הוא כותב את התקציר תוך שניות, והגיליון כאן ממשיך לבד.' }),
    el('button', { type: 'button', class: 'btn ghost', text: '📋 העתקת ההודעה לצ\'אט הישן', onclick: async (e) => {
      try { await navigator.clipboard.writeText(askText); e.currentTarget.textContent = '✓ הועתק — הדבק בצ\'אט הישן'; }
      catch { toast(askText, ''); }
    } }),
  ]);
  let body;
  if (st.state === 'waiting' && st.listening) {
    smart.className = 'btn ghost';   // צ'אט שמאזין עונה לבד — הסיכום החכם הוא רק אפשרות נוספת
    body = [
      el('p', { class: 'nc-wait', role: 'status' }, [el('span', { class: 'spin', 'aria-hidden': 'true' }),
        ` ממתין לתקציר מ"${name}" · `, el('b', { id: 'hoElapsed', text: mmss(elapsed()) })]),
      st.expected_seconds
        ? el('div', { class: 'ho-expect' }, [el('progress', { id: 'hoBar', max: '100', value: '0', 'aria-label': 'התקדמות משוערת' }),
            el('small', { id: 'hoExpect', text: `בדרך כלל כ-${secs(st.expected_seconds)}` })])
        : el('p', { class: 'hint', text: 'לא ידוע כמה זמן — עוד אין מספיק העברות קודמות להערכה.' }),
      el('p', { class: 'hint', id: 'hoLate', hidden: true, text: 'אין תגובה כבר 5 דקות. אפשר סיכום חכם עכשיו, בלי תקציר, או להמשיך לחכות ברקע.' }),
      el('p', { class: 'hint', text: 'הצ\'אט כותב תקציר העברה: מצב, החלטות, מה פתוח, קבצים, ענף, ומה הצ\'אט הבא עושה קודם. הסגירה (✕) לא מבטלת — ממשיכים לחכות ברקע.' }),
      el('div', { class: 'row-btns' }, [background, fallback]),
      el('div', { class: 'row-btns' }, [smart]), cancel,
    ];
  } else if (st.state === 'waiting') {
    // לא מאזין — לא "חושב": בלי ספינר, ההסבר, ו"בלי תקציר" הוא הכפתור הראשי
    body = [
      el('p', { class: 'nc-wait', role: 'status' }, [`⚪ "${name}" לא מאזין — הוא יראה את הבקשה רק כשיבדוק את הלוח (עד כשעה). לא צריך לחכות לו. עברו `,
        el('b', { id: 'hoElapsed', text: mmss(elapsed()) })]),
      (fallback.className = 'btn ghost', el('div', { class: 'row-btns' }, [smart])),
      el('p', { class: 'hint', text: 'הלוח מזקק את כל השיחה לזיכרון לצ\'אט החדש: כללי העבודה, ההחלטות, מה באמצע, מה פתוח, ושאלות ותשובות שעוד תקפות — בלי כל מה שכבר לא רלוונטי (קריאה אחת, על חשבון המכסה). בלי תקציר — רק ההודעות האחרונות, בחינם.' }),
      direct,
      el('div', { class: 'row-btns' }, [fallback, background]), cancel,
    ];
  } else if (st.state === 'expired') {
    body = [el('p', { class: 'nc-wait', text: '⌛ הבקשה נסגרה — שעה בלי תשובה מהצ\'אט. אפשר לפתוח עכשיו בלי לחכות לו:' }),
      el('div', { class: 'row-btns' }, [smart]), el('div', { class: 'row-btns' }, [(fallback.className = 'btn ghost', fallback), (again.className = 'btn ghost', again)])];
  } else if (st.state === 'cancelled') {
    body = [el('p', { class: 'nc-wait', text: 'הבקשה בוטלה.' }), el('div', { class: 'row-btns' }, [again, fallback])];
  } else {
    body = [el('p', { class: 'hint', text: '…' })];
  }
  $('#handoffBody').replaceChildren(...body);
  clearInterval(ho.tick);
  if (st.state === 'waiting') { ho.tick = setInterval(tickHandoff, 1000); tickHandoff(); }
}

async function showHandoffPrompt(fallback) {
  stopHandoff();
  const gen = ho.gen;
  let r;
  try { r = await api('handoff-prompt', { channel_id: ho.channel.id, fallback }); } catch (ex) { toast(ex.message, 'error'); return pollHandoff(); }
  if (gen !== ho.gen || !$('#handoffSheet').open) return;
  if (!r.ready) { pollHandoff(); return; }
  $('#handoffBody').replaceChildren(
    envLine(r.environment),
    el('p', { class: 'hint', text: r.source === 'handoff'
      ? `התקציר הגיע. כשהצ'אט החדש יירשם, "${r.name}" יסומן "הוחלף" והמטלות שנשאלו בו יעברו אליו.`
      : 'בלי תקציר — הפרומפט כולל את ההודעות האחרונות בערוץ.' }),
    promptBlock(r.prompt, 'הפרומפט לצ\'אט ההמשך', { collapsed: true }),
  );
  document.dispatchEvent(new CustomEvent('ct:reload-channels'));
}

export async function openHandoff(channel) {
  stopHandoff();
  ho.channel = channel; ho.status = null;
  $('#handoffBody').replaceChildren(el('p', { class: 'hint', text: '…' }));
  $('#handoffSheet').showModal();
  try {
    // בקשה שממתינה או תקציר שמוכן — ממשיכים מהם; אחרת בקשה חדשה (השרת לא שולח שנייה כשיש אחת שממתינה)
    const cur = await api('handoff-status', { channel_id: channel.id });
    if (!['waiting', 'ready'].includes(cur.state)) await api('handoff-request', { channel_id: channel.id });
  } catch (ex) { toast(ex.message, 'error'); return; }
  pollHandoff();
}
// ✕ / Escape / "ברקע" — סוגרים בלבד. הבקשה ממשיכה בשרת, והשורה של הערוץ מתעדכנת
on('#handoffSheet', 'close', () => { stopHandoff(); document.dispatchEvent(new CustomEvent('ct:reload-channels')); });

/* ── רמת "הצ'אט מתארך" ────────────────────────────────────────── */

export const HEALTH = {
  warn:  { chip: '🟡 מתארך', bar: '🟡 הצ\'אט מתארך', hint: 'אפשר להמשיך, אבל כדאי להתכונן לצ\'אט המשך.' },
  alert: { chip: '🔴 ארוך', bar: '🔴 הצ\'אט ארוך — מומלץ צ\'אט המשך', hint: 'ההקשר כבר נדחס או קרוב לסוף. צ\'אט חדש עם תקציר יעבוד טוב יותר.' },
};

export function healthDetail(c) {
  const mb = (c.health_bytes / 1_000_000).toFixed(1);
  return `${mb}MB · ${c.health_turns} הודעות שלך · ${c.health_compactions} דחיסות`;
}

export function isBoardOwner() { return state.user?.role === 'admin'; }
