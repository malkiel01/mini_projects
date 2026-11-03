/*
 * שלב 23 (docs/spec.md §19) — "➕ צ'אט חדש" מהלוח: הגיליון (שם, נקודת התחלה, 🚀), השורות הממתינות ברשימת הצ'אטים,
 * המשיק (טופס + מדריך של 8 צעדים) תחת 🔌 חיבורים. הרשומה שנשלחת ל-claude.ai נבנית בשרת בלבד.
 */
import { $, el, api, toast, state, on } from './core.js';

const MODES = [['empty', 'ריק'], ['template', 'מהתבנית'], ['followup', 'המשך מצ\'אט']];
let launchCtx = null;

/* ── הגיליון ➕ ──────────────────────────────────────────────── */

/** ‏{projectId, name?, mode?, replaces?} — מ"➕ צ'אט חדש" (ריק / תבנית), או מצ'אט ההמשך (followup, replaces). */
export async function openLaunchSheet({ projectId, name = '', mode = 'empty', replaces = null, replacesName = '' }) {
  launchCtx = { projectId, mode, replaces };
  let info = { launcher: null };
  try { info = await api('launches', { project_id: projectId }); } catch (ex) { toast(ex.message, 'error'); return; }
  const project = (state.projects || []).find((p) => p.id === projectId);
  $('#lnName').value = name || `${project?.name || 'פרויקט'} - צ'אט`;
  $('#lnModes').replaceChildren(...MODES.map(([v, t]) => el('button', {
    type: 'button', role: 'radio', 'aria-checked': String(v === mode), text: t, disabled: v === 'followup' && !replaces,
    title: v === 'followup' && !replaces ? 'נפתח מגיליון צ\'אט ההמשך של צ\'אט קיים' : '',
    onclick: () => { launchCtx.mode = v; paintModes(); },
  })));
  $('#lnReplaces').hidden = !replaces;
  $('#lnReplaces').textContent = replaces ? `🔁 מחליף את "${replacesName}" — הישן יסומן "הוחלף" כשהחדש יירשם.` : '';
  const l = info.launcher;
  $('#lnNoLauncher').hidden = !!l;
  $('#lnGo').hidden = !l;
  $('#lnLauncher').hidden = !l;
  if (l) {
    $('#lnLauncher').textContent = `🚀 ${l.label}${l.env_label ? ' · סביבה: ' + l.env_label : ''}${l.status === 'failing' ? ' · ⚠ ' + l.last_error : ''}`
      + (l.prompt_ok ? '' : ' · ⚠ הפרומפט השמור ישן — העתק מחדש (🔌 חיבורים)');
  }
  $('#lnErr').hidden = true;
  $('#launchSheet').showModal();
}

function paintModes() {
  $('#lnModes').querySelectorAll('button').forEach((b, i) => b.setAttribute('aria-checked', String(MODES[i][0] === launchCtx.mode)));
}

on('#lnForm', 'submit', async (e) => {
  e.preventDefault();
  const btn = $('#lnGo');
  btn.disabled = true; btn.textContent = '🚀 פותח…';
  // חלון שנפתח אחרי await נחסם בטלפון — פותחים אותו כבר בנגיעה, ומפנים אותו כשהכתובת חוזרת
  const w = window.open('', '_blank');
  try {
    const r = await api('launch-chat', { project_id: launchCtx.projectId, name: $('#lnName').value, mode: launchCtx.mode,
      ...(launchCtx.replaces ? { replaces_channel_id: launchCtx.replaces } : {}) });
    if (w) w.location.href = r.session_url; else window.open(r.session_url, '_blank', 'noopener');
    $('#launchSheet').close();
    toast(`🚀 "${r.name}" נפתח ב-Claude — הוא יירשם כאן לבד תוך דקה-שתיים`, 'ok');
    document.dispatchEvent(new CustomEvent('ct:reload-channels'));
  } catch (ex) {
    if (w) w.close();
    $('#lnErr').hidden = false;
    $('#lnErr').textContent = ex.message;
  }
  btn.disabled = false; btn.textContent = '🚀 פתיחה';
});
on('#lnSetup', 'click', () => { $('#launchSheet').close(); openLauncherGuide(); });

/* ── השורות הממתינות ברשימת הצ'אטים ─────────────────────────── */

export async function renderLaunchRows(projectId) {
  const box = $('#launchRows');
  if (!box) return;
  let r;
  try { r = await api('launches', { project_id: projectId }); } catch { box.replaceChildren(); return; }
  box.replaceChildren(...(r.launches || []).map((x) => el('li', { class: `card launch-row${x.status === 'expired' ? ' is-off' : ''}` }, [
    el('span', { class: 'ic', 'aria-hidden': 'true', text: '🚀' }),
    el('div', { class: 'body' }, [
      el('div', { class: 'l1' }, [el('span', { text: x.name })]),
      el('div', { class: 'l2', text: x.status === 'expired' ? 'לא נרשם תוך 30 דק\' — אפשר לפתוח שוב'
        : x.help ? 'עוד לא נרשם — פתח את השיחה ובדוק שהיא בסביבה עם CLAUDE_TASKS_TOKEN' : 'נפתח… נרשם כאן לבד תוך דקה-שתיים' }),
    ]),
    x.session_url ? el('a', { class: 'btn ghost sm', href: x.session_url, target: '_blank', rel: 'noopener', text: 'פתח' }) : null,
  ])));
  if ((r.launches || []).some((x) => x.status === 'pending')) setTimeout(() => {
    if (state.projectId === projectId) document.dispatchEvent(new CustomEvent('ct:reload-channels'));
  }, 15000);
}

/* ── המשיק: מדריך + טופס (🔌 חיבורים) ─────────────────────── */

export async function openLauncherGuide(existing = null) {
  let g;
  try { g = (await api('launchers', { project_id: state.projectId || 0 })).guide; } catch (ex) { toast(ex.message, 'error'); return; }
  const copy = (text, label) => el('button', { type: 'button', class: 'btn ghost sm', text: `📋 ${label}`, onclick: async (e) => {
    try { await navigator.clipboard.writeText(text); e.currentTarget.textContent = '✓ הועתק'; } catch { toast(text, ''); }
  } });
  const steps = [
    ['פתח את דף המשימות ב-claude.ai', el('a', { href: g.routines_url, target: '_blank', rel: 'noopener', class: 'btn ghost sm', text: 'claude.ai/code/routines ↗' }),
      'ושם: New scheduled task (משימה חדשה).'],
    ['שם המשימה', copy(g.task_name, g.task_name)],
    ['הפרומפט — הדבק את הטקסט הזה כמו שהוא', copy(g.prompt, 'העתקת הפרומפט'), el('pre', { class: 'nc-prompt', dir: 'ltr', text: g.prompt })],
    ['טריגר (Trigger): רק API — בלי תזמון', null],
    ['ריפו', el('b', { dir: 'ltr', text: g.repo?.url ? g.repo.url.replace('https://github.com/', '') + (g.repo.branch ? ' · ' + g.repo.branch : '') : 'הריפו של הפרויקט' })],
    ['סביבה', el('b', { dir: 'ltr', text: g.environment || 'הסביבה שבה שמור CLAUDE_TASKS_TOKEN' })],
    ['Create ← Generate token: העתק את הטוקן (מוצג פעם אחת) ואת ה-Fire URL', null],
    ['חזור לכאן, הדבק את שניהם למטה ← שמירה. הבדיקה האמיתית — ה-🚀 הראשון', null],
  ];
  $('#lgSteps').replaceChildren(...steps.map(([t, ...more], i) => el('li', {}, [el('b', { text: `${i + 1}. ${t}` }), ...more.filter(Boolean)])));
  $('#lgId').value = existing?.id || '';
  $('#lgUrl').value = '';
  $('#lgUrl').placeholder = existing ? `trig_… שמור: ${existing.trigger}` : 'https://api.anthropic.com/v1/claude_code/routines/trig_…/fire';
  $('#lgUrl').required = !existing;
  $('#lgToken').value = '';
  $('#lgToken').placeholder = existing ? `ריק = ללא שינוי · היום …${existing.tail}` : 'sk-ant-…';
  $('#lgLabel').value = existing?.label || '';
  $('#lgEnv').value = existing?.env_label || g.environment || '';
  $('#lgConfirm').checked = !!existing?.prompt_ok;
  $('#launcherGuide').showModal();
}

on('#lgForm', 'submit', async (e) => {
  e.preventDefault();
  const id = Number($('#lgId').value) || 0;
  const cur = (state.launchers || []).find((x) => x.id === id);
  try {
    const r = await api('launcher-save', { id, label: $('#lgLabel').value, env_label: $('#lgEnv').value, token: $('#lgToken').value.trim(),
      fire_url: $('#lgUrl').value.trim() || (cur ? `https://api.anthropic.com/v1/claude_code/routines/${cur.trigger}/fire` : ''),
      prompt_confirmed: $('#lgConfirm').checked ? 1 : 0 });
    state.launchers = r.launchers;
    $('#launcherGuide').close();
    toast('🚀 המשיק נשמר — עכשיו "➕ צ\'אט חדש" פותח צ\'אט בלחיצה', 'ok');
    document.dispatchEvent(new CustomEvent('ct:launchers-changed'));
  } catch (ex) { toast(ex.message, 'error'); }
});

/** הקבוצה "🚀 משיק צ'אטים" במסך החיבורים. */
export async function renderLaunchers(box) {
  let r;
  try { r = await api('launchers', {}); } catch { return; }
  state.launchers = r.launchers;
  const rows = r.launchers.map((l) => el('li', { class: `card conn-row${l.status === 'failing' ? ' is-bad' : ''}`, tabindex: '0', role: 'button',
    'aria-label': l.label, onclick: () => openLauncherGuide(l), onkeydown: (ev) => { if (ev.key === 'Enter') openLauncherGuide(l); } }, [
    el('span', { class: 'ic', 'aria-hidden': 'true', text: '🚀' }),
    el('div', { class: 'body' }, [
      el('div', { class: 'l1' }, [el('span', { text: l.label })]),
      el('div', { class: 'l2', text: [l.status === 'failing' ? `🔴 ${l.last_error}` : '🟢 מוכן', l.env_label, `…${l.tail}`,
        l.prompt_ok ? '' : '⚠ הפרומפט השמור ישן — העתק מחדש'].filter(Boolean).join(' · ') }),
    ]),
    el('span', { class: 'conn-ctl' }, [el('button', { type: 'button', class: 'icon-btn', 'aria-label': `הסרת ${l.label}`, title: 'הסרה',
      onclick: async (ev) => {
        ev.stopPropagation();
        if (!confirm(`להסיר את המשיק "${l.label}"? (המשימה ב-claude.ai נשארת — אפשר למחוק אותה שם)`)) return;
        try { await api('launcher-remove', { id: l.id }); renderLaunchers(box); } catch (ex) { toast(ex.message, 'error'); }
      } }, ['🔌'])]),
  ]));
  box.replaceChildren(...(rows.length ? [el('h3', { class: 'conn-group', text: '🚀 משיק צ\'אטים · פותח צ\'אט חדש מהלוח בלחיצה' }), el('ul', { class: 'list' }, rows)] : []));
}
