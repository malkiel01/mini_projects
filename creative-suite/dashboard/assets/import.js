// ייבוא חד-פעמי ללוח "מטלות לקלוד" — רץ בדפדפן של בעל המערכת, עם עוגיית הלוח שלו. מדלג על מה שכבר קיים.
(() => {
  const BOOT = JSON.parse(document.getElementById('boot').textContent);
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function api(action, body = {}) {
    const r = await fetch(`${BOOT.api}?action=${encodeURIComponent(action)}`, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    let j = null;
    try { j = await r.json(); } catch { /* למטה */ }
    if (!j) { const e = new Error(`הלוח לא ענה כמו שצריך (HTTP ${r.status})`); e.status = r.status; throw e; }
    if (!j.success) { const e = new Error(j.error || 'שגיאה מהלוח'); e.status = r.status; throw e; }
    return j;
  }

  const total = BOOT.plan.reduce((s, p) => s + p.topics.reduce((a, t) => a + t.tasks.length, 0), 0);
  let done = 0; const stats = { created: 0, skipped: 0, failed: 0 };

  function progress(text) {
    const pct = total ? Math.round(100 * done / total) : 100;
    $('run-text').textContent = text;
    $('run-pct').textContent = pct + '%';
    $('run-bar').setAttribute('aria-valuenow', pct);
    $('run-bar').firstElementChild.style.inlineSize = pct + '%';
  }
  function log(mark, html) {
    const ul = $('run-log'); ul.hidden = false;
    ul.insertAdjacentHTML('beforeend', `<li><span class="mk" aria-hidden="true">${mark}</span><span>${html}</span></li>`);
  }

  async function run() {
    const go = $('go'); go.disabled = true;
    $('run').hidden = false; $('run-log').innerHTML = ''; $('run-log').hidden = true;
    done = 0; stats.created = stats.skipped = stats.failed = 0;
    try {
      await api('me');
    } catch (e) {
      progress(e.status === 401 || e.status === 403 ? 'צריך להתחבר ללוח באותו דפדפן' : e.message);
      log('⚠', `<a href="${esc(BOOT.board)}">פתיחת הלוח</a>, להתחבר, ולחזור לכאן.`);
      go.disabled = false; return;
    }
    const repo = `${BOOT.repo.owner}/${BOOT.repo.name}`;
    const projects = new Map((await api('projects')).projects.map(p => [p.name, +p.id]));
    const topics = new Map((await api('topics')).topics.map(t => [t.name, +t.id]));

    for (const p of BOOT.plan) {
      let pid = projects.get(p.project);
      try {
        if (!pid) {
          pid = (await api('create-project', { name: p.project, repo_owner: BOOT.repo.owner, repo_name: BOOT.repo.name,
            default_branch: BOOT.repo.branch, description: p.description })).id;
          projects.set(p.project, pid);
          log('📁', `נוצר פרויקט: <b>${esc(p.name)}</b>`);
        }
      } catch (e) {
        log('⚠', `${esc(p.name)}: ${esc(e.message)} — הפרויקט דולג`);
        const n = p.topics.reduce((a, t) => a + t.tasks.length, 0); done += n; stats.failed += n; continue;
      }
      for (const t of p.topics) {
        progress(`${p.name} · ${t.name.replace(p.name + ' · ', '')}`);
        let tid = topics.get(t.name);
        try {
          if (!tid) {
            tid = (await api('create-topic', { name: t.name, repo, description: `חבילת היצירה · ${p.name}\n${p.doc}` })).id;
            topics.set(t.name, tid);
          }
        } catch (e) {
          log('⚠', `${esc(t.name)}: ${esc(e.message)}`); done += t.tasks.length; stats.failed += t.tasks.length; continue;
        }
        const have = new Set((await api('tasks', { topic_id: tid })).tasks.map(x => x.title));
        for (const [i, k] of t.tasks.entries()) {
          if (have.has(k.title)) { stats.skipped++; done++; continue; }
          try {
            const { id } = await api('create-task', { title: k.title, body: k.body, kind: k.kind, project_id: pid, topic_id: tid,
              auto_topic: false, repo, branch: BOOT.repo.branch, seq: i + 1 });
            if (k.note) await api('add-note', { id, body: k.note, kind: k.note_kind });
            if (k.status !== 'open') await api('update-task', { id, status: k.status });
            have.add(k.title); stats.created++;
          } catch (e) {
            stats.failed++; log('⚠', `${esc(k.title)}: ${esc(e.message)}`);
          }
          done++; progress(`${p.name} · ${t.name.replace(p.name + ' · ', '')}`);
        }
      }
    }
    progress(`הסתיים: ${stats.created} נוצרו · ${stats.skipped} כבר היו · ${stats.failed} נכשלו`);
    log(stats.failed ? '⚠' : '✅', stats.failed
      ? 'חלק נכשלו — אפשר ללחוץ שוב; מה שכבר נוצר ידולג.'
      : 'הכול בלוח. <a href="center.php">למרכז</a>');
    go.disabled = false; go.textContent = 'הרצה חוזרת';
  }

  $('go').addEventListener('click', run);
})();
