/**
 * שכבת הפלטפורמה בממשק: חיבורים, ספקים, פרויקטים, וניהול.
 *
 * מופרד מ-app.js כי אלה שני עולמות: שם זה זרימת המטלות היומיומית, וכאן
 * ההגדרות שנוגעים בהן פעם בכמה שבועות.
 */

import { $, $$, el, api, toast, state, on } from './core.js';

/* ── חיבורים (docs/spec.md §10.1) ─────────────────────────────────
 *
 * רשימה אחת: "מחוברים (N)" — קוד (גיטהאב) ובינה מלאכותית (לפי סדר העדיפות).
 * כל שורה: סמל, סוג + שם, מצב 🟢/🔴, ובסוף 🔌 גלוי. בבינה — מספר ו-↑↓.
 * לחיצה על שורה פותחת גיליון; "+ חיבור" פותח אריחים.
 */

const KIND_ICON = { github: '🐙', anthropic: '✳️', openai: '⚪', google: '🔷' };
const KIND_NAME = { github: 'GitHub', anthropic: 'Anthropic', openai: 'OpenAI', google: 'Gemini' };

export async function loadConnections() {
  const { connections } = await api('my-connections');
  state.connections = connections;          // github (תאימות) + list
  renderConnections();
  renderProviderPicker();
}

function ago(ts) {
  const m = Math.round((Date.now() / 1000 - ts) / 60);
  return m < 1 ? 'עכשיו' : m < 60 ? `לפני ${m} דק'` : m < 1440 ? `לפני ${Math.round(m / 60)} שע'` : `לפני ${Math.round(m / 1440)} ימים`;
}

function connName(c) { return `${KIND_NAME[c.kind] || c.kind}${c.label ? ` · ${c.label}` : ''}`; }

function connSub(c) {
  if (c.status === 'failing') return `🔴 נכשל · ${c.last_error || 'שגיאה'}${c.last_ok_at ? ` · ${ago(c.last_ok_at)}` : ''}`;
  const bits = ['🟢 מחובר'];
  if (c.kind === 'github') bits.push(c.login || `…${c.tail}`);
  else bits.push(c.model, `…${c.tail}`);
  return bits.join(' · ');
}

function renderConnections() {
  const list = state.connections?.list || [];
  const code = list.filter((c) => c.kind === 'github');
  const ai = list.filter((c) => c.ai).sort((a, b) => a.priority - b.priority);
  $('#connCount').textContent = list.length ? `מחוברים (${list.length})` : 'חיבורים';
  const box = $('#connList');
  if (!list.length) {
    box.replaceChildren(el('p', { class: 'empty', text: 'אין חיבורים עדיין. ‎+ חיבור כדי להתחבר לגיטהאב או לספק בינה.' }));
    return;
  }
  const row = (c, i, n) => {
    const ctl = [];
    if (c.ai) {
      ctl.push(el('button', { type: 'button', class: 'icon-btn', 'aria-label': `העלאה של ${connName(c)}`, disabled: i === 0,
        onclick: (ev) => { ev.stopPropagation(); move(c, -1); } }, ['↑']));
      ctl.push(el('button', { type: 'button', class: 'icon-btn', 'aria-label': `הורדה של ${connName(c)}`, disabled: i === n - 1,
        onclick: (ev) => { ev.stopPropagation(); move(c, 1); } }, ['↓']));
    }
    ctl.push(el('button', { type: 'button', class: 'icon-btn', 'aria-label': `ניתוק ${connName(c)}`, title: 'ניתוק',
      onclick: (ev) => { ev.stopPropagation(); disconnect(c); } }, ['🔌']));
    return el('li', { class: `card conn-row${c.status === 'failing' ? ' is-bad' : ''}`, tabindex: '0', role: 'button',
      'aria-label': connName(c), onclick: () => openConn(c),
      onkeydown: (ev) => { if (ev.key === 'Enter') openConn(c); } }, [
      el('span', { class: 'ic', 'aria-hidden': 'true', text: c.ai ? String(i + 1) : KIND_ICON[c.kind] }),
      el('div', { class: 'body' }, [
        el('div', { class: 'l1' }, [el('span', { text: `${c.ai ? KIND_ICON[c.kind] + ' ' : ''}${connName(c)}` })]),
        el('div', { class: 'l2', text: connSub(c) }),
      ]),
      el('span', { class: 'conn-ctl' }, ctl),
    ]);
  };
  const group = (title, items) => items.length ? [el('h3', { class: 'conn-group', text: title }),
    el('ul', { class: 'list' }, items.map((c, i) => row(c, i, items.length)))] : [];
  box.replaceChildren(...group('קוד', code), ...group('בינה מלאכותית · לפי סדר — נכשל הראשון, עוברים לבא', ai));
}

async function move(c, dir) {
  try {
    const r = await api('connection-move', { id: c.id, dir });
    state.connections.list = r.connections;
    renderConnections(); renderProviderPicker();
  } catch (ex) { toast(ex.message, 'error'); }
}

async function disconnect(c) {
  const extra = c.kind === 'github' ? '\nפרויקטים שבחרו בחשבון הזה יחזרו לבחירה האוטומטית.' : '';
  if (!confirm(`לנתק את ${connName(c)}?${extra}`)) return;
  try {
    const r = await api('connection-remove', { id: c.id });
    const rel = (r.released_projects || []).map((p) => p.name);
    $('#connSheet').open && $('#connSheet').close();
    await loadConnections();
    toast(rel.length ? `נותק · חזרו לאוטומטי: ${rel.join(', ')}` : 'נותק', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
}

/* גיליון של חיבור: שם, מודל (בינה), מפתח חדש, בדיקה, ניתוק */
let connPicker = null;
function openConn(c) {
  state.openConn = c;
  $('#csTitle').textContent = connName(c);
  $('#csState').textContent = connSub(c);
  $('#csLabel').value = c.label || '';
  $('#csKeyWrap').hidden = !c.ai;
  $('#csKey').value = '';
  $('#csKey').placeholder = `מפתח חדש (ריק = ללא שינוי) · היום …${c.tail}`;
  if (c.ai) {
    connPicker = modelPicker({ provider: c.kind, current: c.model, getKey: () => $('#csKey').value.trim() });
    $('#csModelSlot').replaceChildren(connPicker.el);
    connPicker.load();
  } else { connPicker = null; $('#csModelSlot').replaceChildren(); }
  $('#connSheet').showModal();
}

on('#csForm', 'submit', async (e) => {
  e.preventDefault();
  const c = state.openConn;
  const patch = { id: c.id, label: $('#csLabel').value };
  if (c.ai) { patch.model = connPicker?.value || c.model; if ($('#csKey').value.trim()) patch.secret = $('#csKey').value.trim(); }
  try {
    await api('connection-update', patch);
    $('#connSheet').close();
    await loadConnections();
    toast('נשמר', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});
on('#csKey', 'change', () => { if ($('#csKey').value.trim()) connPicker?.load(); });
on('#csTest', 'click', async () => {
  try {
    const r = await api('connection-test', { id: state.openConn.id });
    state.connections.list = r.connections;
    renderConnections();
    $('#csState').textContent = connSub(r.connections.find((x) => x.id === state.openConn.id) || state.openConn);
    toast(r.message, 'ok');
  } catch (ex) { await loadConnections(); toast(ex.message, 'error'); }
});
on('#csRemove', 'click', () => disconnect(state.openConn));

/* "+ חיבור": אריחים 2×2, ואז טופס קצר */
let addKind = '';
let addPicker = null;
on('#connAddBtn', 'click', () => {
  addKind = '';
  $('#caTiles').hidden = false;
  $('#caForm').hidden = true;
  $('#caTitle').textContent = 'חיבור חדש';
  $('#connAdd').showModal();
});
on('#caTiles', 'click', (e) => {
  const t = e.target.closest('[data-kind]');
  if (!t) return;
  addKind = t.dataset.kind;
  const meta = (state.providers || []).find((p) => p.provider === addKind);
  $('#caTitle').textContent = `חיבור ${KIND_NAME[addKind]}`;
  $('#caTiles').hidden = true;
  $('#caForm').hidden = false;
  $('#caSecretLabel').textContent = addKind === 'github' ? 'Personal Access Token' : 'מפתח API';
  $('#caSecret').value = '';
  $('#caSecret').placeholder = addKind === 'github' ? 'ghp_… / github_pat_…' : `${meta?.prefix || ''}…`;
  $('#caHint').textContent = addKind === 'github'
    ? 'github.com ← Settings ← Developer settings ← Tokens (classic). היקפים: repo, ו-workflow להרצת מטלות.'
    : (meta?.hint || '');
  $('#caLabel').value = '';
  if (addKind !== 'github') {
    addPicker = modelPicker({ provider: addKind, getKey: () => $('#caSecret').value.trim() });
    $('#caModelSlot').replaceChildren(addPicker.el);
    addPicker.load();
  } else { addPicker = null; $('#caModelSlot').replaceChildren(); }
  $('#caSecret').focus();
});
on('#caSecret', 'change', () => { if ($('#caSecret').value.trim()) addPicker?.load(); });
on('#caForm', 'submit', async (e) => {
  e.preventDefault();
  try {
    await api('connection-add', { kind: addKind, secret: $('#caSecret').value.trim(), label: $('#caLabel').value,
      model: addPicker ? addPicker.value : '' });
    $('#connAdd').close();
    await loadConnections();
    toast(`${KIND_NAME[addKind]} מחובר`, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── בורר מודל ──────────────────────────────────────────────────
 *
 * רשימה אמיתית מהספק (provider-models), לפי המפתח שהוקלד או השמור. מודל
 * שמוגדר היום ואינו ברשימה — נשאר בה, מסומן. "אחר…" פותח הקלדה ידנית.
 */
export function modelPicker({ provider, current = '', getKey = () => '', scope = '' }) {
  const sel = el('select', { 'aria-label': 'מודל' });
  const custom = el('input', { type: 'text', dir: 'ltr', placeholder: 'מזהה המודל, כפי שהספק קורא לו', hidden: true });
  const note = el('small', { class: 'hint' });
  const idLine = el('code', { class: 'model-id', dir: 'ltr' });
  const wrap = el('label', { class: 'field' }, [el('span', { text: 'מודל' }), sel, custom, idLine, note]);
  const showId = () => { idLine.textContent = sel.value === '__custom' ? '' : sel.value; };
  let prov = provider;

  const fill = (models, source, error) => {
    // בבורר — השם הקריא; המזהה המדויק מוצג מתחת (בכיוון שמאל-ימין)
    const opts = models.map((m) => el('option', { value: m.id, text: m.label || m.id }));
    if (current && !models.some((m) => m.id === current)) {
      opts.unshift(el('option', { value: current, text: `${current} (מוגדר עכשיו)` }));
    }
    opts.push(el('option', { value: '__custom', text: 'אחר… (הקלדה)' }));
    sel.replaceChildren(...opts);
    sel.value = current || (models[0]?.id ?? '__custom');
    custom.hidden = sel.value !== '__custom';
    showId();
    note.textContent = error ? `לא נטענה רשימה מהספק (${error}) — רשימה בסיסית`
      : source === 'live' ? `${models.length} מודלים זמינים למפתח הזה` : 'רשימה בסיסית — חברו מפתח כדי לראות את כל המודלים';
  };
  sel.addEventListener('change', () => {
    showId();
    custom.hidden = sel.value !== '__custom';
    if (!custom.hidden) custom.focus();
  });

  const load = async () => {
    note.textContent = 'טוען מודלים…';
    try {
      const r = await api('provider-models', { provider: prov, key: getKey(), scope });
      fill(r.models, r.source, r.error);
    } catch (ex) { fill([], 'fallback', ex.message); }
  };

  return {
    el: wrap,
    load,
    setProvider(p) { prov = p; current = ''; return load(); },
    get value() { return sel.value === '__custom' ? custom.value.trim() : sel.value; },
  };
}

/* ── ספקים — הקטלוג (שמות, תחיליות, רמזים) לטפסים ולבורר "מי יבצע" ── */

export async function loadProviders() {
  const r = await api('providers');
  state.providers = r.providers;
  state.defaultProvider = r.default;
  renderProviderPicker();
  return r;
}

/** בורר "מי יבצע" במטלה חדשה: אוטומטי (לפי הסדר), או חיבור בינה מסוים. */
function renderProviderPicker() {
  const sel = $('#capProvider');
  if (!sel) return;
  const ai = (state.connections?.list || []).filter((c) => c.ai).sort((a, b) => a.priority - b.priority);
  sel.replaceChildren(
    el('option', { value: '', text: 'אוטומטי (לפי הסדר)' }),
    ...ai.map((c) => el('option', { value: String(c.id), text: `${KIND_NAME[c.kind]}${c.label ? ' · ' + c.label : ''} · ${c.model}` })),
  );
  // בורר עם אפשרות אחת אינו בורר.
  sel.closest('label')?.toggleAttribute('hidden', ai.length < 2);
}

/* ── פרויקטים ──────────────────────────────────────────────────── */

export async function loadProjects() {
  const { projects } = await api('projects');
  state.projects = projects;

  $('#projList').replaceChildren(...(projects.length ? projects.map((p) => el('button', {
    type: 'button', class: 'pcard', onclick: () => openProject(p.id),
  }, [
    el('b', { text: p.name }),
    el('span', { class: 'hint', text: p.repo_name ? `${p.repo_owner}/${p.repo_name}` : 'ללא ריפו' }),
    el('span', { class: 'badge', text: `${p.open_tasks} מטלות` }),
    el('span', { class: 'badge', text: `${p.member_count} חברים` }),
    el('span', { class: 'badge', text: { read: 'צפייה', write: 'כתיבה', admin: 'ניהול' }[p.my_level] || '' }),
  ])) : [el('p', { class: 'hint', text: 'אין עדיין פרויקטים.' })]));
}

async function loadRepoOptions() {
  const gh = state.connections?.github;
  $('#projGate').hidden = !!gh?.connected;
  if (!gh?.connected) {
    $('#pRepo').replaceChildren(el('option', { value: '', text: '— אין חיבור לגיטהאב —' }));
    return;
  }
  try {
    const { repos } = await api('repos');
    state.repos = repos;
    $('#pRepo').replaceChildren(
      el('option', { value: '', text: 'ללא ריפו' }),
      ...repos.map((r) => el('option', {
        value: r.full_name,
        text: `${r.full_name}${r.private ? ' 🔒' : ''}${r.can_push ? '' : ' (קריאה בלבד)'}`,
      })),
    );
    $('#pRepoHint').textContent = `${repos.length} ריפוזיטוריז בחשבון ${gh.login}.`;
  } catch (ex) {
    $('#pRepoHint').textContent = ex.message;
  }
}

on('#projForm', 'submit', async (e) => {
  e.preventDefault();
  const [owner, name] = ($('#pRepo').value || '/').split('/');
  const repo = state.repos?.find((r) => r.full_name === $('#pRepo').value);
  try {
    await api('create-project', {
      name: $('#pName').value,
      repo_owner: owner || '', repo_name: name || '',
      default_branch: repo?.default_branch || 'main',
    });
    $('#pName').value = '';
    await loadProjects();
    toast('הפרויקט נוצר', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

on('#repoForm', 'submit', async (e) => {
  e.preventDefault();
  const name = $('#rName').value.trim();
  if (!name) return;
  try {
    const r = await api('create-repo', { name, private: $('#rPrivate').checked });
    $('#rName').value = '';
    await loadRepoOptions();
    $('#pRepo').value = r.repo.full_name;
    if (!$('#pName').value) $('#pName').value = name;
    toast(`הריפו ${r.repo.full_name} נוצר ונבחר`, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── פרויקט יחיד ───────────────────────────────────────────────── */

export async function openProject(id) {
  const { project, members, engine } = await api('project', { id });
  state.openProject = project;
  renderEngine(project, engine);
  loadSkills().catch(() => {});

  $('#pvTitle').textContent = project.name;
  $('#pvMeta').replaceChildren(
    el('span', { class: 'badge', title: project.repo_inherited ? 'הריפו של פרויקט האב' : '',
      text: project.repo_name ? `📦 ${project.repo_owner}/${project.repo_name}${project.path ? ` › ${project.path}` : ''}` : 'ללא ריפו' }),
    el('span', { class: 'badge', text: `🌿 ${project.default_branch}` }),
    el('span', { class: 'badge', text: `הרשאתך: ${{ read: 'צפייה', write: 'כתיבה', admin: 'ניהול' }[project.my_level]}` }),
  );

  renderEdit(project);
  renderOwnerTools(project);
  renderMembers(members);
  $('#pvAddMember').hidden = project.my_level !== 'admin';
  if (project.my_level === 'admin') {
    const { people } = await api('people');
    $('#pvPerson').replaceChildren(...people.map((u) => el('option', { value: String(u.id), text: u.display_name })));
  }

  $('#pvFile').hidden = true;
  // תת-פרויקט — העיון מתחיל בתיקייה שלו, לא בשורש הריפו
  await browse(project.path || '');
  $('#projectView').showModal();
}

/* פרטי הפרויקט — שם, סמל, תיקייה. למנהל הפרויקט. */
function renderEdit(project) {
  const admin = project.my_level === 'admin';
  $('#pvEdit').hidden = !admin;
  if (!admin) return;
  $('#peName').value = project.name || '';
  $('#peIcon').value = project.icon || '';
  $('#pePath').value = project.path || '';
  $('#pePathWrap').hidden = !project.parent_id;
}

on('#pvEdit', 'submit', async (e) => {
  e.preventDefault();
  const p = state.openProject;
  const patch = { id: p.id, name: $('#peName').value.trim(), icon: $('#peIcon').value.trim() };
  if (p.parent_id) patch.path = $('#pePath').value.trim();
  try {
    await api('update-project', patch);
    $('#pvTitle').textContent = patch.name;
    Object.assign(p, patch);
    toast('נשמר', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

on('#peDelete', 'click', async () => {
  const p = state.openProject;
  if (!confirm(`למחוק את "${p.name}"? אפשר למחוק רק פרויקט ריק.`)) return;
  try {
    await api('delete-project', { id: p.id });
    toast('הפרויקט נמחק', 'ok');
    // אם עמדנו בתוכו — חוזרים למסך הראשי
    setTimeout(() => location.reload(), 600);
  } catch (ex) { toast(ex.message, 'error'); }
});

/*
 * בעל הלוח: "רק בעל הלוח" (owner_only), והעברה לתוך פרויקט אחר.
 */
function renderOwnerTools(project) {
  const owner = state.user?.role === 'admin';
  $('#pvOwner').hidden = !owner;
  if (!owner) return;
  $('#pvChatTokenValue').textContent = '••••••••';
  $('#pvChatTokenShow').hidden = false;
  const all = state.projects || [];
  const me = all.find((p) => p.id === project.id);
  $('#pvOwnerOnly').checked = Number(project.owner_only) === 1;
  // סומן באב — כאן אי אפשר לבטל
  const inherited = !!me?.owner_only_effective && Number(project.owner_only) !== 1;
  $('#pvOwnerOnly').disabled = inherited;
  $('#pvOwnerOnlyHint').hidden = !inherited;
  $('#pvOwnerOnlyHint').textContent = 'מסומן בפרויקט אב — חל גם כאן.';

  // יעדים: כל פרויקט שאינו הוא עצמו ואינו בתוך הענף שלו
  const below = new Set([project.id]);
  for (let grew = true; grew;) {
    grew = false;
    all.forEach((p) => { if (p.parent_id && below.has(p.parent_id) && !below.has(p.id)) { below.add(p.id); grew = true; } });
  }
  const path = (p) => {
    const names = [];
    for (let c = p, i = 0; c && i < 8; c = all.find((x) => x.id === c.parent_id), i++) names.unshift(c.name);
    return names.join(' › ');
  };
  const targets = all.filter((p) => !below.has(p.id)).map((p) => ({ id: p.id, label: path(p) }))
    .sort((a, b) => a.label.localeCompare(b.label, 'he'));
  $('#pvMoveTo').replaceChildren(
    el('option', { value: '', text: '— רמה עליונה —', selected: !project.parent_id }),
    ...targets.map((t) => el('option', { value: String(t.id), text: t.label, selected: t.id === project.parent_id })),
  );
}

on('#pvChatTokenShow', 'click', async () => {
  try {
    const r = await api('chat-token', { project_id: state.openProject.id });
    $('#pvChatTokenValue').textContent = `CLAUDE_TASKS_TOKEN=${r.token}\nCLAUDE_TASKS_URL=${r.url}\nCLAUDE_TASKS_PROJECT=${r.project_id}`;
    $('#pvChatTokenShow').hidden = true;
  } catch (ex) { toast(ex.message, 'error'); }
});

on('#pvOwnerOnly', 'change', async (e) => {
  try {
    await api('update-project', { id: state.openProject.id, owner_only: e.target.checked });
    toast(e.target.checked ? 'סומן: רק בעל הלוח — בכל הענף' : 'הסימון הוסר', 'ok');
  } catch (ex) { e.target.checked = !e.target.checked; toast(ex.message, 'error'); }
});

on('#pvMove', 'submit', async (e) => {
  e.preventDefault();
  const v = $('#pvMoveTo').value;
  try {
    await api('move-project', { id: state.openProject.id, parent_id: v ? Number(v) : null });
    $('#projectView').close();   // הסגירה מרעננת את העץ
    toast('הפרויקט הועבר', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

function renderMembers(members) {
  const canEdit = state.openProject?.my_level === 'admin';
  $('#pvMembers').replaceChildren(...members.map((m) => el('div', { class: 'member' }, [
    el('b', { text: m.display_name }),
    el('span', { class: 'hint', text: m.has_github ? `גיטהאב: ${m.github_login}` : 'ללא גיטהאב' }),
    canEdit
      ? el('select', {
          onchange: async (ev) => {
            try {
              await api('set-member', { project_id: state.openProject.id, user_id: m.user_id, level: ev.target.value });
              toast('ההרשאה עודכנה', 'ok');
            } catch (ex) { toast(ex.message, 'error'); }
          },
        }, ['read', 'write', 'admin'].map((lv) => el('option', {
          value: lv, selected: m.level === lv,
          text: { read: 'צפייה', write: 'כתיבה', admin: 'ניהול' }[lv],
        })))
      : el('span', { class: 'badge', text: { read: 'צפייה', write: 'כתיבה', admin: 'ניהול' }[m.level] }),
    canEdit ? el('button', {
      type: 'button', class: 'icon-btn', title: 'הסרה',
      onclick: async () => {
        try {
          await api('remove-member', { project_id: state.openProject.id, user_id: m.user_id });
          openProject(state.openProject.id);
        } catch (ex) { toast(ex.message, 'error'); }
      },
    }, ['✕']) : null,
  ])));
}

on('#pvAddMember', 'submit', async (e) => {
  e.preventDefault();
  try {
    await api('set-member', {
      project_id: state.openProject.id,
      user_id: Number($('#pvPerson').value),
      level: $('#pvLevel').value,
    });
    openProject(state.openProject.id);
    toast('החבר נוסף', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── סקילים ────────────────────────────────────────────────────── */

async function loadSkills() {
  const { skills } = await api('skills', { project_id: state.openProject.id });
  const admin = state.openProject.my_level === 'admin';

  $('#skNew').hidden = !admin;
  $('#pvSkills').replaceChildren(...(skills.length ? skills.map((s) => el('div', { class: 'member' }, [
    el('b', { text: s.name }),
    el('span', { class: 'hint', text: s.description || '—' }),
    s.always ? el('span', { class: 'badge', text: 'תמיד' }) : null,
    s.scope === 'global' ? el('span', { class: 'badge', text: 'גלובלי' }) : null,
    admin ? el('button', { type: 'button', class: 'icon-btn', title: 'עריכה',
      onclick: () => editSkill(s.id) }, ['✎']) : null,
    admin ? el('button', { type: 'button', class: 'icon-btn', title: 'מחיקה',
      onclick: () => removeSkill(s) }, ['✕']) : null,
  ])) : [el('p', { class: 'hint', text: 'אין עדיין סקילים בפרויקט.' })]));
}

function skillForm(open, skill = null) {
  $('#pvSkillForm').hidden = !open;
  $('#skNew').hidden = open || state.openProject?.my_level !== 'admin';
  $('#skId').value    = skill?.id || '';
  $('#skName').value  = skill?.name || '';
  $('#skDesc').value  = skill?.description || '';
  $('#skBody').value  = skill?.body || '';
  $('#skAlways').checked = !!skill?.always;
}

async function editSkill(id) {
  const { skills } = await api('skills', { project_id: state.openProject.id });
  const brief = skills.find((s) => s.id === id);
  if (!brief) return;
  // הרשימה אינה נושאת את הגוף — מושכים אותו רק כשעורכים.
  const { skill } = await api('skill', { project_id: state.openProject.id, name: brief.name });
  skillForm(true, skill);
}

async function removeSkill(s) {
  try {
    await api('delete-skill', { id: s.id });
    await loadSkills();
    toast(`הסקיל "${s.name}" נמחק`, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
}

on('#skNew', 'click', () => skillForm(true));
on('#skCancel', 'click', () => skillForm(false));

on('#pvSkillForm', 'submit', async (e) => {
  e.preventDefault();
  try {
    await api('save-skill', {
      id: $('#skId').value ? Number($('#skId').value) : null,
      project_id: state.openProject.id,
      name: $('#skName').value,
      description: $('#skDesc').value,
      body: $('#skBody').value,
      always: $('#skAlways').checked,
    });
    skillForm(false);
    await loadSkills();
    toast('הסקיל נשמר', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── מנוע ההרצה ────────────────────────────────────────────────── */

function renderEngine(project, engine) {
  const box = $('#pvEngineState');
  $('#pvCheck').value = engine.check_command || '';

  const admin = project.my_level === 'admin';
  $('#pvInstall').disabled = !admin || !project.repo_name;
  $('#pvSaveCheck').disabled = !admin;
  $('#pvInstall').textContent = engine.installed ? 'התקנה מחדש' : 'התקנת המנוע';

  if (!project.repo_name) { box.textContent = 'אין ריפו מקושר, ולכן אין מה להתקין.'; return; }

  const when = engine.installed
    ? `מותקן (${new Date(engine.installed_at * 1000).toLocaleDateString('he-IL')}).`
    : 'טרם הותקן.';

  // הריצה בגיטהאב מדווחת חזרה דרך הרשת. כתובת מקומית פירושה שהיא תעבוד
  // ותיעלם בלי שאיש יידע, ועדיף לומר זאת לפני ההתקנה ולא אחריה.
  box.textContent = engine.board_public
    ? when
    : `${when} שימו לב: כתובת הלוח (${engine.board_url}) אינה https ציבורית, ולכן ההרצה לא תוכל לדווח בחזרה.`;
}

on('#pvInstall', 'click', async (e) => {
  const btn = e.currentTarget;
  btn.disabled = true;
  const was = btn.textContent;
  btn.textContent = 'מתקין…';
  try {
    const r = await api('install-engine', { project_id: state.openProject.id });
    const summary = Object.entries(r.files).map(([f, what]) => `${f.split('/').pop()}: ${what}`).join(' · ');
    toast(`המנוע הותקן — ${summary}`, 'ok');
    openProject(state.openProject.id);
  } catch (ex) {
    toast(ex.message, 'error');
    btn.textContent = was;
    btn.disabled = false;
  }
});

on('#pvSaveCheck', 'click', async () => {
  try {
    await api('update-project', { id: state.openProject.id, check_command: $('#pvCheck').value });
    toast('פקודת הבדיקה נשמרה', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/** שולח מטלה להרצה. נקרא מחלונית המטלה ב-app.js. */
export async function runTask(id) {
  const r = await api('run-task', { id });
  return r;
}

/* ── עיון בקוד ─────────────────────────────────────────────────── */

async function browse(path) {
  const tree = $('#pvTree');
  const file = $('#pvFile');
  tree.replaceChildren(el('p', { class: 'hint', text: 'טוען…' }));
  file.hidden = true;

  try {
    const r = await api('repo-tree', { project_id: state.openProject.id, path });
    renderCrumbs(path);

    if (r.tree.kind === 'file') {
      tree.replaceChildren();
      // קובץ בינארי מגיע כג'יבריש; עדיף לומר זאת מאשר להציג רעש.
      file.textContent = /�/.test(r.tree.content)
        ? '(קובץ בינארי — לא ניתן להצגה כאן)'
        : r.tree.content;
      file.hidden = false;
      return;
    }

    tree.replaceChildren(...r.tree.items.map((it) => el('button', {
      type: 'button', class: 'tree__row',
      onclick: () => browse(it.path),
    }, [
      el('span', { text: it.type === 'dir' ? '📁' : '📄' }),
      el('span', { text: it.name }),
    ])));
    if (!r.tree.items.length) tree.replaceChildren(el('p', { class: 'hint', text: 'תיקייה ריקה.' }));
  } catch (ex) {
    tree.replaceChildren(el('p', { class: 'hint', text: ex.message }));
  }
}

function renderCrumbs(path) {
  const parts = path ? path.split('/') : [];
  const crumbs = [el('button', { type: 'button', class: 'crumb', onclick: () => browse('') }, ['שורש'])];
  parts.forEach((part, i) => {
    const upto = parts.slice(0, i + 1).join('/');
    crumbs.push(el('span', { text: '/' }));
    crumbs.push(el('button', { type: 'button', class: 'crumb', onclick: () => browse(upto) }, [part]));
  });
  $('#pvCrumbs').replaceChildren(...crumbs);
}

/* ── ניהול ─────────────────────────────────────────────────────── */

const ADMIN_TABS = {
  async diag() {
    const { diagnostics: d } = await api('admin-diagnostics');
    const yes = (v) => (v ? '✅' : '❌');
    return el('div', { class: 'diag' }, [
      row('PHP', d.php),
      row('pdo_sqlite', yes(d.pdo_sqlite)),
      row('cURL', yes(d.curl)),
      row('sodium (הצפנת סודות לגיטהאב)', yes(d.sodium)),
      row('mbstring', yes(d.mbstring)),
      row('data/ ניתנת לכתיבה', yes(d.data_writable)),
      row('מפתח הצפנה', `${yes(d.secret_key.exists)} הרשאות ${d.secret_key.perms}`),
      row('יציאה ל-api.github.com', `${yes(d.reach_github.ok)} ${d.reach_github.detail}`),
      row('יציאה ל-api.anthropic.com', `${yes(d.reach_anthropic.ok)} ${d.reach_anthropic.detail}`),
      row('גודל המסד', `${Math.round(d.db_size / 1024)} KB`),
      row('משתמשים / פרויקטים / מטלות',
          `${d.counts.users} / ${d.counts.projects} / ${d.counts.tasks}`),
    ]);
  },

  async users() {
    const { users } = await api('admin-users');
    const meId = state.user?.id;
    return el('div', { class: 'diag' }, users.map((u) => {
      const r = row(
        `${u.display_name} (${u.username})${u.id === meId ? ' — אתה' : ''}`,
        [u.role === 'admin' ? 'מנהל' : 'משתמש',
         u.platform_linked ? '🔗 כלים דיגיטליים' : '',
         u.has_github ? `גיטהאב: ${u.github_login}` : 'ללא גיטהאב',
         `${u.projects} פרויקטים`,
         `${u.tasks} מטלות`].filter(Boolean).join(' · '),
      );
      // חשבון נוסף (לא אני, לא מקושר לכלים הדיגיטליים) — אפשר למחוק; מה שבו עובר אליי
      if (u.id !== meId && !Number(u.platform_linked)) {
        r.append(el('button', {
          type: 'button', class: 'btn btn--ghost danger-text', text: '🗑 מחיקה',
          onclick: async () => {
            if (!confirm(`למחוק את "${u.display_name}"? מה שנוצר בו (${u.tasks} מטלות, ${u.projects} פרויקטים) יעבור אליך.`)) return;
            try {
              await api('remove-user', { id: u.id });
              toast('החשבון נמחק', 'ok');
              showAdminTab('users');
            } catch (ex) { toast(ex.message, 'error'); }
          },
        }));
      }
      return r;
    }));
  },

  async events() {
    const { events } = await api('admin-events', { limit: 80 });
    if (!events.length) return el('p', { class: 'hint', text: 'היומן ריק.' });
    return el('div', { class: 'diag' }, events.map((e) => el('div', {
      class: `drow${e.ok ? '' : ' drow--bad'}`,
    }, [
      el('span', { text: new Date(e.created_at * 1000).toLocaleString('he-IL') }),
      el('b', { text: e.action }),
      el('span', { text: e.actor }),
      el('span', { text: e.target }),
      el('span', { class: 'hint', text: e.detail }),
    ])));
  },
};

function row(label, value) {
  return el('div', { class: 'drow' }, [el('b', { text: label }), el('span', { text: String(value) })]);
}

async function showAdminTab(tab) {
  const body = $('#adminBody');
  body.replaceChildren(el('p', { class: 'hint', text: 'טוען…' }));
  try {
    body.replaceChildren(await ADMIN_TABS[tab]());
  } catch (ex) {
    body.replaceChildren(el('p', { class: 'hint', text: ex.message }));
  }
}

on('#adminTabs', 'click', (e) => {
  const chip = e.target.closest('.chip');
  if (!chip) return;
  $$('.chip', $('#adminTabs')).forEach((c) => c.classList.toggle('is-active', c === chip));
  showAdminTab(chip.dataset.tab);
});

/* ── פתיחת החלוניות ────────────────────────────────────────────── */

on('#projectsBtn', 'click', async () => {
  $('#projects').showModal();
  await loadConnections().catch(() => {});
  await loadProjects().catch((ex) => toast(ex.message, 'error'));
  await loadRepoOptions();
});

on('#adminBtn', 'click', () => {
  $('#admin').showModal();
  showAdminTab('diag');
});
