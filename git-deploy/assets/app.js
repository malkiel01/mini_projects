(function () {
'use strict';

const repoListEl = document.getElementById('repo-list');
const csrf = repoListEl ? repoListEl.dataset.csrf : '';
const cacheInfoEl = document.getElementById('cache-info');

// ==== Helpers ====
function api(action, opts = {}) {
    const { method = 'POST', body = {}, query = {} } = opts;
    const qs = new URLSearchParams({ action, ...query }).toString();
    const url = 'api.php?' + qs;

    let fetchOpts = { method, headers: { 'X-CSRF-Token': csrf } };
    if (method === 'POST') {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf', csrf);
        for (const k in body) fd.append(k, body[k]);
        fetchOpts.body = fd;
    }
    return fetch(url, fetchOpts).then(r => r.json());
}

function setLoading(btn, on) {
    if (!btn) return;
    btn.classList.toggle('is-loading', on);
    btn.disabled = on;
}

function showOutput(el, text, isOk) {
    el.hidden = false;
    el.textContent = text || '(no output)';
    el.classList.toggle('is-error', isOk === false);
    el.classList.toggle('is-success', isOk === true);
}

function fmtAge(s) {
    if (s < 60) return s + ' שניות';
    if (s < 3600) return Math.floor(s/60) + ' דקות';
    if (s < 86400) return Math.floor(s/3600) + ' שעות';
    return Math.floor(s/86400) + ' ימים';
}

// ==== Refresh repos ====
async function refreshRepos(silent) {
    const btn = document.getElementById('btn-refresh');
    if (!silent) setLoading(btn, true);
    try {
        const r = await api('refresh_repos');
        if (!r.ok) {
            if (cacheInfoEl) cacheInfoEl.textContent = 'שגיאה ברענון: ' + (r.detail || r.error);
            return;
        }
        if (cacheInfoEl) cacheInfoEl.textContent = 'נשלפו ' + r.count + ' ריפוז זה עתה.';
        // Re-render the list
        location.reload();
    } catch (e) {
        if (cacheInfoEl) cacheInfoEl.textContent = 'שגיאת רשת ברענון.';
    } finally {
        if (!silent) setLoading(btn, false);
    }
}

// Auto-refresh on load (background, no reload if cache fresh)
(function autoRefresh() {
    if (!repoListEl) return;
    // First-time visit (no repos cached) -> reload after fetch
    const isEmpty = !!repoListEl.querySelector('.empty');
    if (isEmpty) {
        refreshRepos(false);
    } else {
        // background fetch only - update cache silently
        api('refresh_repos').then(r => {
            if (r.ok && cacheInfoEl) cacheInfoEl.textContent = 'נשלפו ' + r.count + ' ריפוז זה עתה.';
        }).catch(() => {});
    }
})();

document.getElementById('btn-refresh')?.addEventListener('click', () => refreshRepos(false));

// ==== Deploy a single existing path ====
repoListEl?.addEventListener('click', async (e) => {
    const deployBtn = e.target.closest('.btn-deploy');
    const removeBtn = e.target.closest('.btn-remove-path');
    const addBtn = e.target.closest('.btn-add-path');

    if (deployBtn) {
        const row = deployBtn.closest('.path-row');
        const card = deployBtn.closest('.repo-card');
        const repo = card.dataset.repo;
        const path = row.dataset.path;
        const branch = row.dataset.branch;
        const out = row.querySelector('.deploy-output');
        setLoading(deployBtn, true);
        showOutput(out, 'מבצע pull...\n', null);
        try {
            const r = await api('deploy', { body: { repo, branch, path } });
            showOutput(out, r.output || JSON.stringify(r, null, 2), !!r.ok);
            if (r.ok) {
                // soft refresh path metadata
                const meta = row.querySelector('.path-meta');
                if (meta && r.entry) {
                    meta.innerHTML = 'branch: <code>' + branch + '</code> · commit: <code>' + (r.hash || '?') + '</code> · נמשך: ' + (r.entry.last_pulled || '');
                }
            }
        } catch (err) {
            showOutput(out, 'שגיאת רשת: ' + err.message, false);
        } finally {
            setLoading(deployBtn, false);
        }
        return;
    }

    if (removeBtn) {
        const row = removeBtn.closest('.path-row');
        const card = removeBtn.closest('.repo-card');
        const repo = card.dataset.repo;
        const path = row.dataset.path;
        if (!confirm('להסיר את הניתוב ' + path + ' מהזיכרון? (הקבצים בשרת לא יימחקו)')) return;
        setLoading(removeBtn, true);
        try {
            const r = await api('remove_path', { body: { repo, path } });
            if (r.ok) {
                row.remove();
                const list = card.querySelector('.paths');
                if (list && !list.querySelector('.path-row')) {
                    list.innerHTML = '<div class="path-empty muted small">אין עדיין ניתובים שמורים. לחץ "הוסף ניתוב" כדי לפרוס לתיקייה בשרת.</div>';
                }
            } else {
                alert('הסרה נכשלה');
            }
        } finally {
            setLoading(removeBtn, false);
        }
        return;
    }

    if (addBtn) {
        const card = addBtn.closest('.repo-card');
        openAddPathModal(card.dataset.repo, card.dataset.defaultBranch);
        return;
    }
});

// ==== Add path modal ====
const modal = document.getElementById('modal-add-path');
const addRepoEl = document.getElementById('add-path-repo');
const addBranchEl = document.getElementById('add-path-branch');
const addTargetEl = document.getElementById('add-path-target');
const addOutEl = document.getElementById('add-path-output');
const addConfirm = document.getElementById('add-path-confirm');
const addCancel = document.getElementById('add-path-cancel');

let modalRepo = null;

async function openAddPathModal(repo, defaultBranch) {
    modalRepo = repo;
    addRepoEl.textContent = repo;
    addTargetEl.value = '';
    addOutEl.hidden = true; addOutEl.textContent = '';
    addBranchEl.innerHTML = '<option>טוען...</option>';
    modal.hidden = false;

    try {
        const r = await api('list_branches', { method: 'GET', query: { repo } });
        if (r.ok) {
            const branches = r.branches || [];
            addBranchEl.innerHTML = branches.map(b => {
                const sel = b === defaultBranch ? ' selected' : '';
                return '<option' + sel + '>' + b + '</option>';
            }).join('');
        } else {
            addBranchEl.innerHTML = '<option>(שגיאה)</option>';
        }
    } catch {
        addBranchEl.innerHTML = '<option>(שגיאת רשת)</option>';
    }
}

addCancel?.addEventListener('click', () => { modal.hidden = true; });
modal?.addEventListener('click', (e) => { if (e.target === modal) modal.hidden = true; });

addConfirm?.addEventListener('click', async () => {
    const branch = addBranchEl.value;
    const path = addTargetEl.value.trim();
    if (!path) { alert('יש להזין נתיב'); return; }
    setLoading(addConfirm, true);
    showOutput(addOutEl, 'מבצע clone/pull לתיקייה ' + path + '...\n', null);
    try {
        const r = await api('add_path', { body: { repo: modalRepo, branch, path } });
        showOutput(addOutEl, r.output || JSON.stringify(r, null, 2), !!r.ok);
        if (r.ok) {
            setTimeout(() => { modal.hidden = true; location.reload(); }, 1200);
        }
    } catch (err) {
        showOutput(addOutEl, 'שגיאת רשת: ' + err.message, false);
    } finally {
        setLoading(addConfirm, false);
    }
});

})();
