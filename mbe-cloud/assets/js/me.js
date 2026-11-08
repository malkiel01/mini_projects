// "החשבון שלי בענן" (me.php) — לכל משתמש מחובר, גם בלי כונן (spec-5 ש2). חיבורים לקריאה בלבד, נתוני אפליקציות, ייצוא ומחיקה.
import { $, h as el } from './ui-core/dom.js';
import { toast } from './ui-core/toast.js';
import { initDialogs, confirm, prompt } from './ui-core/dialog.js';

const LAYER_IC = { files: '📁', libs: '📚', appdata: '🗂️', ai: '🤖' };
const LAYER_TITLE = { files: 'קבצים', libs: 'נתוני מערכת', appdata: 'נתוני משתמש', ai: 'בינה מלאכותית' };
const FILES_LABEL = { 'files.app': 'רק מה שהאפליקציה יצרה או שבחרת', 'files.readonly': 'קריאה בלבד', files: 'כל הכונן' };

// me-api.php — השרת של הדף הזה, ברשימה סגורה (spec-5 4.1א). כתיבה — POST עם JSON
async function api(action, body) {
  const init = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
  const res = await fetch(`me-api.php?action=${action}`, { credentials: 'same-origin', ...init });
  const j = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(j.error || 'תקלה בענן'), { code: j.code });
  return j;
}
const fail = e => toast(e.message || 'תקלה', true);

function bytes(n) {
  const u = [['ג"ב', 2 ** 30], ['מ"ב', 2 ** 20], ['ק"ב', 2 ** 10]];
  for (const [name, s] of u) if (n >= s) return `${+(n / s).toFixed(n / s < 10 ? 1 : 0)} ${name}`;
  return `${n} בתים`;
}
const when = iso => new Date(iso).toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric' });

function render(s) {
  $('#who').textContent = `${s.account.name} · ${s.account.email}`;
  $('#me-cells').replaceChildren(el('div', {}, el('dt', {}, '🗂️ נתוני אפליקציות'), el('dd', {}, `${bytes(s.usage.used)} מתוך ${bytes(s.usage.quota)}`)));
  $('#drive-line').textContent = s.drive === true ? '📁 יש לך כונן בענן. הקבצים — בדף הענן.'
    : 'אין לך כונן בענן: אפליקציות שומרות כאן רק את ההגדרות והנתונים שלך (🗂️). כדי לקבל כונן — פנו למנהל.';
  $('#links').replaceChildren(...(s.connections.length ? s.connections.map(c => el('li', {}, el('div', { class: 'acc-row' },
    el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🧩'),
    el('div', { class: 'body' }, el('div', { class: 'l1' }, c.name), el('div', { class: 'l2' }, `מחובר מ-${when(c.created_at)}`),
      el('ul', { class: 'layer-list' }, ...Object.entries(c.layers || {}).map(([l, st]) => el('li', { class: st === 'on' ? '' : 'off' },
        el('span', { class: 'mark', 'aria-hidden': 'true' }, st === 'on' ? '✅' : st === 'locked' ? '🔒' : '—'),
        el('span', {}, `${LAYER_IC[l] || ''} ${LAYER_TITLE[l] || l}`
          + (st === 'on' && l === 'files' && c.files_scope ? ` · ${FILES_LABEL[c.files_scope]}` : '')
          + (st === 'off' ? ' · לא פעיל באפליקציה הזו' : st === 'locked' ? ' · לא זמין בחשבון שלך' : '')))))))))
    : [el('li', {}, el('p', { class: 'empty' }, 'אין אפליקציות מחוברות. אפליקציה שמנהל הענן הפעיל מתחברת לבד בכניסה הראשונה.'))]));
  $('#appdata').replaceChildren(...(s.appdata.length ? s.appdata.map(d => el('li', {}, el('div', { class: 'acc-row' },
    el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🗂️'),
    el('div', { class: 'body' }, el('div', { class: 'l1' }, d.name), el('div', { class: 'l2' }, `${bytes(d.used)} · ${d.docs} פריטים · עודכן ${when(d.updated_at)}`)),
    el('button', { class: 'btn ghost sm danger', type: 'button', onclick: () => wipe(d) }, '🗑️ מחק נתונים'))))
    : [el('li', {}, el('p', { class: 'empty' }, 'עוד אין נתונים של אפליקציות.'))]));
}

async function load() {
  const [me, links, apps] = await Promise.all([api('me'), api('connections'), api('appdata_apps')]);
  render({ account: me.account, drive: me.granted, usage: me.appdata, connections: links.items, appdata: apps.items });
}

$('#export').addEventListener('click', async () => {
  try {
    const data = await api('account_export', {});
    const a = el('a', { href: URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })),
      download: `mbe-cloud-${new Date().toISOString().slice(0, 10)}.json` });
    document.body.append(a); a.click(); a.remove();
  } catch (e) { fail(e); }
});

async function wipe(d) {
  if (!await confirm(`למחוק את כל הנתונים של ${d.name} בענן? אי אפשר לשחזר.`, { ok: 'מחיקה', danger: true })) return;
  try { await api('appdata_wipe', { client: d.client }); toast('נמחק'); load(); } catch (e) { fail(e); }
}

$('#delete-acc').addEventListener('click', async () => {
  const v = await prompt('כל מה שיש לך בענן יימחק: הנתונים של האפליקציות, החיבורים, והקבצים אם יש לך כונן. אי אפשר לשחזר. כדי לאשר, כתבו "מחיקה".',
    { title: 'מחיקת החשבון בענן', ok: 'מחיקה' });
  if (v === null) return;
  if (v.trim() !== 'מחיקה') { toast('לא נמחק — צריך לכתוב "מחיקה"', true); return; }
  try { await api('account_delete', { confirm: 'DELETE' }); toast('החשבון נמחק'); setTimeout(() => location.reload(), 800); } catch (e) { fail(e); }
});

initDialogs();
load().catch(fail);
