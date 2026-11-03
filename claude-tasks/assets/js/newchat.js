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
function promptBlock(prompt, label) {
  // שורה לכל שורה, כל אחת בכיוון שלה; שורת הפקודה — משמאל לימין, בשורה אחת שגוללת (אחרת היא מתפצלת ומתהפכת בעברית)
  const pre = el('pre', { class: 'nc-prompt', tabindex: '0', 'aria-label': label }, prompt.split('\n').map((line) =>
    /^\s*claude-tasks\//.test(line)
      ? el('code', { class: 'nc-cmd', dir: 'ltr', text: line.trim() })
      : el('span', { class: 'nc-line', dir: 'auto', text: line || '\u00a0' })));
  return el('div', { class: 'nc-prompt-wrap' }, [
    pre,
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
    // ⚡ גרסת Routine (§5.12.5): משימה מתוזמנת שנרשמת בתחילת כל הרצה — כך בקשות ההרשאה שלה מגיעות ללוח
    role?.routine_prompt ? el('details', { class: 'nc-routine' }, [
      el('summary', { text: '⚡ גרסת Routine — משימה מתוזמנת' }),
      el('p', { class: 'hint', text: 'ליצור את המשימה ב-claude.ai עם הריפו mini_projects (בלעדיו אין את ה-hooks של הלוח), ולהחליף את {מה לעשות בכל הרצה}. כל הרצה נרשמת ללוח בהתחלה ומתנתקת בסוף.' }),
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

/* ── 🔁 צ'אט המשך ─────────────────────────────────────────────── */

const ho = { channel: null, timer: null };

function stopHandoff() { if (ho.timer) clearTimeout(ho.timer); ho.timer = null; }

async function pollHandoff(fallback = false) {
  stopHandoff();
  if (!ho.channel || !$('#handoffSheet').open) return;
  let r;
  try { r = await api('handoff-prompt', { channel_id: ho.channel.id, fallback }); } catch (ex) { toast(ex.message, 'error'); return; }
  if (!r.ready) {
    // replaceChildren הופך null לטקסט "null" — מסננים
    $('#handoffBody').replaceChildren(...[
      el('p', { class: 'nc-wait' }, [el('span', { class: 'spin', 'aria-hidden': 'true' }), ` ממתין לתקציר מ"${ho.channel.name}"… (${r.waited} שנ׳)`]),
      el('p', { class: 'hint', text: 'הצ\'אט קיבל בקשה לכתוב תקציר העברה: מצב וערכים עיקריים, החלטות, מה פתוח, קבצים, sessions וענף, ומה הצ\'אט הבא עושה קודם.' }),
      r.can_fallback
        ? el('div', {}, [el('p', { class: 'hint', text: r.listening === false
            ? `"${ho.channel.name}" לא מאזין עכשיו — הבקשה לתקציר תגיע אליו רק כשיבדוק את הלוח (בבדיקה השעתית, או כשתכתבו לו). אפשר להמשיך כבר עכשיו בלי תקציר: הפרומפט ייבנה מ-30 ההודעות האחרונות בערוץ.`
            : 'עברו 2 דקות בלי תקציר — אולי הצ\'אט לא פעיל. אפשר להמשיך בלי תקציר: הפרומפט ייבנה מ-30 ההודעות האחרונות בערוץ.' }),
            el('button', { type: 'button', class: 'btn ghost', text: 'בלי תקציר — מההודעות האחרונות', onclick: () => pollHandoff(true) })])
        : null,
    ].filter(Boolean));
    ho.timer = setTimeout(() => pollHandoff(), 4000);
    return;
  }
  $('#handoffBody').replaceChildren(
    envLine(r.environment),
    el('p', { class: 'hint', text: r.source === 'handoff'
      ? `התקציר הגיע. כשהצ'אט החדש יירשם, "${r.name}" יסומן "הוחלף" והמטלות שנשאלו בו יעברו אליו.`
      : 'בלי תקציר — הפרומפט כולל את 30 ההודעות האחרונות בערוץ.' }),
    promptBlock(r.prompt, 'הפרומפט לצ\'אט ההמשך'),
  );
}

export async function openHandoff(channel) {
  ho.channel = channel;
  $('#handoffBody').replaceChildren(el('p', { class: 'hint', text: '…' }));
  $('#handoffSheet').showModal();
  try {
    // בקשה מהעשר דקות האחרונות שעוד מחכה לתקציר — ממשיכים לחכות לה, לא שולחים שוב
    const cur = await api('handoff-prompt', { channel_id: channel.id }).catch(() => null);
    const waiting = cur && cur.requested && !cur.ready && cur.waited < 600;
    if (!waiting) await api('handoff-request', { channel_id: channel.id });
  } catch (ex) { toast(ex.message, 'error'); $('#handoffSheet').close(); return; }
  pollHandoff();
}
on('#handoffSheet', 'close', stopHandoff);

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
