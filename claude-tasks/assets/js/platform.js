/**
 * שכבת הפלטפורמה בממשק: חיבורים, ספקים, פרויקטים, וניהול.
 *
 * מופרד מ-app.js כי אלה שני עולמות: שם זה זרימת המטלות היומיומית, וכאן
 * ההגדרות שנוגעים בהן פעם בכמה שבועות.
 */

import { $, $$, el, api, toast, state } from './core.js';

/* ── חיבורים ───────────────────────────────────────────────────── */

export async function loadConnections() {
  const { connections } = await api('my-connections');
  state.connections = connections;
  renderGithub();
}

function renderGithub() {
  const gh = state.connections?.github;
  const box = $('#ghState');
  if (!gh) return;

  box.textContent = gh.connected
    ? `מחובר כ־${gh.login}${gh.scopes.length ? ` · הרשאות: ${gh.scopes.join(', ')}` : ''}`
    : 'לא מחובר. בלי חיבור אי אפשר לקשר פרויקט לריפו.';

  $('#ghDisconnect').hidden = !gh.connected;
  $('#ghToken').placeholder = gh.connected ? 'טוקן חדש (ריק = ללא שינוי)' : 'ghp_… / github_pat_…';

  // היקף חסר הוא הסיבה השכיחה ל"למה זה לא עובד", ועדיף לומר מראש.
  if (gh.connected && gh.scopes.length && !gh.scopes.includes('repo')) {
    box.textContent += ' — חסר ההיקף repo, ולכן כתיבה לקוד תיכשל';
  }
}

$('#ghForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const token = $('#ghToken').value.trim();
  if (!token) return toast('אין מה לשמור — התיבה ריקה', 'error');
  try {
    const r = await api('connect-github', { token });
    $('#ghToken').value = '';
    await loadConnections();
    toast(`מחובר כ־${r.github.login}`, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

$('#ghDisconnect').addEventListener('click', async () => {
  try {
    await api('disconnect-github');
    await loadConnections();
    toast('החיבור לגיטהאב נותק', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── ספקים ─────────────────────────────────────────────────────── */

export async function loadProviders() {
  const r = await api('providers');
  state.providers = r.providers;
  state.defaultProvider = r.default;
  renderProviders();
  renderProviderPicker();
  return r;
}

function renderProviders() {
  $('#providerList').replaceChildren(...state.providers.map((p) => {
    const isDefault = state.defaultProvider === p.provider;

    const key   = el('input', { type: 'password', dir: 'ltr', autocomplete: 'off',
      placeholder: p.connected ? `מחובר · …${p.tail} (ריק = ללא שינוי)` : `${p.prefix}…` });
    const model = el('input', { type: 'text', dir: 'ltr', value: p.model || '',
      placeholder: p.default_model || 'שם המודל כפי שהספק קורא לו' });

    const save = async () => {
      try {
        await api('connect-provider', { provider: p.provider, key: key.value.trim(), model: model.value.trim() });
        key.value = '';
        await loadProviders();
        toast(`${p.label} נשמר`, 'ok');
      } catch (ex) { toast(ex.message, 'error'); }
    };

    const actions = [el('button', { type: 'button', class: 'btn btn--ghost', onclick: save }, ['שמירה'])];

    if (p.connected) {
      actions.push(el('button', {
        type: 'button', class: 'btn btn--ghost',
        onclick: async () => {
          try {
            const r = await api('test-provider', { provider: p.provider });
            toast(`${p.label}: ${r.message} (${r.model})`, 'ok');
          } catch (ex) { toast(ex.message, 'error'); }
        },
      }, ['בדיקה']));

      if (!isDefault) actions.push(el('button', {
        type: 'button', class: 'btn btn--ghost',
        onclick: async () => {
          await api('set-default-provider', { provider: p.provider }).catch((ex) => toast(ex.message, 'error'));
          await loadProviders();
        },
      }, ['הפוך לברירת מחדל']));

      actions.push(el('button', {
        type: 'button', class: 'btn btn--ghost',
        onclick: async () => {
          try {
            await api('disconnect-provider', { provider: p.provider });
            await loadProviders();
            toast(`${p.label} נותק`, 'ok');
          } catch (ex) { toast(ex.message, 'error'); }
        },
      }, ['ניתוק']));
    }

    return el('div', { class: `provider${p.connected ? ' is-on' : ''}` }, [
      el('div', { class: 'provider__head' }, [
        el('b', { text: `${isDefault ? '★ ' : ''}${p.label}` }),
        el('span', { class: 'hint', text: p.connected ? 'מחובר' : p.hint }),
      ]),
      el('label', { class: 'field' }, [el('span', { text: 'מפתח' }), key]),
      el('label', { class: 'field' }, [el('span', { text: 'מודל' }), model]),
      el('div', { class: 'row' }, actions),
    ]);
  }));
}

/** בורר "מי יבצע" בשורת הקליטה. מציג רק ספקים שבאמת מחוברים. */
function renderProviderPicker() {
  const connected = state.providers.filter((p) => p.connected);
  const sel = $('#capProvider');
  sel.replaceChildren(
    el('option', { value: '', text: connected.length ? '⚙ ברירת מחדל' : '⚙ ספק' }),
    ...connected.map((p) => el('option', { value: p.provider, text: p.label.split(' — ')[1] || p.label })),
  );
  // בורר עם אפשרות אחת אינו בורר.
  sel.hidden = connected.length < 2;
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

$('#projForm').addEventListener('submit', async (e) => {
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

$('#repoForm').addEventListener('submit', async (e) => {
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

async function openProject(id) {
  const { project, members, engine } = await api('project', { id });
  state.openProject = project;
  renderEngine(project, engine);
  loadSkills().catch(() => {});

  $('#pvTitle').textContent = project.name;
  $('#pvMeta').replaceChildren(
    el('span', { class: 'badge', text: project.repo_name ? `📦 ${project.repo_owner}/${project.repo_name}` : 'ללא ריפו' }),
    el('span', { class: 'badge', text: `🌿 ${project.default_branch}` }),
    el('span', { class: 'badge', text: `הרשאתך: ${{ read: 'צפייה', write: 'כתיבה', admin: 'ניהול' }[project.my_level]}` }),
  );

  renderMembers(members);
  $('#pvAddMember').hidden = project.my_level !== 'admin';
  if (project.my_level === 'admin') {
    const { people } = await api('people');
    $('#pvPerson').replaceChildren(...people.map((u) => el('option', { value: String(u.id), text: u.display_name })));
  }

  $('#pvFile').hidden = true;
  await browse('');
  $('#projectView').showModal();
}

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

$('#pvAddMember').addEventListener('submit', async (e) => {
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

$('#skNew').addEventListener('click', () => skillForm(true));
$('#skCancel').addEventListener('click', () => skillForm(false));

$('#pvSkillForm').addEventListener('submit', async (e) => {
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

$('#pvInstall').addEventListener('click', async (e) => {
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

$('#pvSaveCheck').addEventListener('click', async () => {
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
    return el('div', { class: 'diag' }, users.map((u) => row(
      `${u.display_name} (${u.username})`,
      [u.role === 'admin' ? 'מנהל' : 'משתמש',
       u.has_github ? `גיטהאב: ${u.github_login}` : 'ללא גיטהאב',
       `${u.projects} פרויקטים`].join(' · '),
    )));
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

$('#adminTabs').addEventListener('click', (e) => {
  const chip = e.target.closest('.chip');
  if (!chip) return;
  $$('.chip', $('#adminTabs')).forEach((c) => c.classList.toggle('is-active', c === chip));
  showAdminTab(chip.dataset.tab);
});

/* ── פתיחת החלוניות ────────────────────────────────────────────── */

$('#projectsBtn').addEventListener('click', async () => {
  $('#projects').showModal();
  await loadConnections().catch(() => {});
  await loadProjects().catch((ex) => toast(ex.message, 'error'));
  await loadRepoOptions();
});

$('#adminBtn').addEventListener('click', () => {
  $('#admin').showModal();
  showAdminTab('diag');
});
