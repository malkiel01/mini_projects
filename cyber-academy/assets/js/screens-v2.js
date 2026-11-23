/* מסכי חוזה v2: stage → track → module → lesson. רק תצוגה — החישוב ב-lib/model-v2.php.
   אין הישגים מומצאים: בלי הערכות → "אין הערכות מתועדות", לא 0. מסלול לא מאושר → "מוצע · לא אושר". */
'use strict';
window.CA = window.CA || {};
(function () {
  const { esc, TASK, MODE, fmtDate, chip, taskChip, measures, dist, emptyLine, row } = CA.ui;

  const trackHref = (id) => '#/track/' + encodeURIComponent(id);
  const moduleHref = (id) => '#/module/' + encodeURIComponent(id);
  const byId = (list, id) => list.find((x) => x.id === id);
  const KIND = { theory: { icon: '📖', label: 'תאוריה' }, analysis: { icon: '🔎', label: 'ניתוח' }, lab: { icon: '🛠️', label: 'מעבדה' } };
  const ASSESS = { planned: { label: 'מתוכננת', cls: '' }, in_progress: { label: 'בתהליך', cls: 'learn' }, evaluated: { label: 'הוערכה', cls: 'ok' } };
  const EVKIND = { command_output: 'פלט פקודה', lab_report: 'דוח מעבדה', quiz: 'בוחן', oral_explanation: 'הסבר בעל פה', code: 'קוד', other: 'אחר' };
  const EVENT = { snapshot: '🔄', curriculum: '🗺️', task: '📋', assessment: '📝', decision: '⚖️' };

  const CURR = { proposed: 'מוצע · לא אושר', approved: 'מאושר' };
  const currTag = (status, full) => `<span class="chip ${status === 'approved' ? 'ok' : 'proposed'}" title="${esc(status === 'approved' ? 'סילבוס מאושר' : 'סילבוס מוצע · לא אושר')}">${esc(full ? (status === 'approved' ? 'סילבוס מאושר' : 'סילבוס מוצע · לא אושר') : CURR[status] || status)}</span>`;
  const noAssess = (what) => `<div class="nocontract">📭 <b>אין הערכות מתועדות</b>${what ? ' ' + esc(what) : ''} — אין כאן "נלמד" או "הוכח" עד שתירשם הערכה.</div>`;
  const measureOrNone = (m, what) => (m ? measures(m) : noAssess(what));
  const listOr = (items, empty) => `<ul class="list">${items.join('') || '<li>' + emptyLine(empty) + '</li>'}</ul>`;
  const lessonRow = (l) => row({
    icon: KIND[l.kind].icon,
    title: esc(l.title),
    sub: [KIND[l.kind].label, MODE[l.assessmentMode].icon + ' ' + MODE[l.assessmentMode].label, esc(l.learningObjectives[0] || '')].filter(Boolean).join(' · '),
    meta: `<span class="chip learn">${KIND[l.kind].label}</span>${l.authorizedLabRequired ? '<span class="chip warn">🔐 מעבדה מורשית בלבד</span>' : ''}`,
  });

  const recRow = (m) => (r) => row({
    href: r.ref.type === 'module' ? moduleHref(r.ref.id) : r.ref.type === 'task' ? '#/tasks/blocked' : null,
    icon: { reassess: '🔁', close_gap: '🩹', unblock: '⛔' }[r.kind] || '💡',
    title: esc(r.title), sub: esc(r.reason), end: '<span class="derived">נגזר</span>',
  });

  const screens = {
    home(m) {
      const c = m.counts;
      const tc = m.taskCounts;
      return { title: 'תמונת מצב', html: `
        <div class="curr-note">${currTag(m.curriculum.curriculumStatus, true)}<span>גרסת סילבוס ${esc(m.curriculum.version)}${m.curriculum.curriculumStatus === 'approved' && m.curriculum.approvedAt ? ' · אושר ' + fmtDate(m.curriculum.approvedAt) : ''}</span></div>
        ${m.measure ? `<p class="hint">שני מדדים נפרדים: <b>נלמד</b> ו<b>הוכח במעשה</b>. אין ביניהם ממוצע.</p>${measures(m.measure)}<div class="summary">${dist(m.measure)}</div>` : noAssess()}
        <div class="summary"><div class="line">היקף הסילבוס: <b>${c.stages}</b> שלבים <i class="sep">·</i> <b>${c.tracks}</b> מסלולים <i class="sep">·</i> <b>${c.modules}</b> מודולים <i class="sep">·</i> <b>${c.lessons}</b> שיעורים</div>
          <p class="hint" style="margin:0">זה גודל התוכנית — לא התקדמות.</p></div>
        ${m.tasks.length ? `<h2 class="sec-title">משימות</h2><div class="tiles">${Object.keys(TASK).map((k) => `<a class="tile" href="#/tasks/${k}"><span class="tile-ic" aria-hidden="true">${TASK[k].icon}</span><span><b>${tc[k]}</b> ${TASK[k].label}</span></a>`).join('')}</div>` : ''}
        ${m.recommendations.length ? `<h2 class="sec-title">מה הלאה <span class="derived">נגזר</span></h2>${listOr(m.recommendations.slice(0, 3).map(recRow(m)), '')}` : ''}
      ` };
    },

    syllabus(m) {
      return { title: 'תוכנית האקדמיה', html: `
        <p class="hint">שלבים ← מסלולים ← מודולים ← שיעורים. זו תוכנית הלימוד — לא התקדמות.${m.curriculum.curriculumStatus === 'approved' ? '' : ' מסלול "מוצע · לא אושר" הוא הצעה, לא תוכנית מחייבת.'}</p>
        <div class="curr-note">${currTag(m.curriculum.curriculumStatus, true)}<span>גרסה ${esc(m.curriculum.version)}</span></div>
        ${m.stages.map((st) => `<h2 class="sec-title">🗂️ ${esc(st.title)}</h2>${st.description ? `<p class="hint">${esc(st.description)}</p>` : ''}
          ${listOr(st.trackIds.map((id) => byId(m.tracks, id)).map((t) => row({
            href: trackHref(t.id), icon: `<span class="num">${t.order}</span>`, title: esc(t.title),
            sub: `${t.counts.modules} מודולים · ${t.counts.lessons} שיעורים` + (t.prerequisites.length ? ' · דורש: ' + t.prerequisites.map((p) => esc(p.title)).join(', ') : ''),
            meta: currTag(t.curriculumStatus) + (t.measure ? `<span class="chip learn" title="נלמד">📘 ${t.measure.learned}</span><span class="chip ok" title="הוכח במעשה">🧪 ${t.measure.proven}</span>` : ''),
          })), 'אין מסלולים בשלב הזה.')}`).join('') || emptyLine('אין שלבים בסילבוס.')}
      ` };
    },

    track(m, id) {
      const t = byId(m.tracks, id);
      if (!t) return { title: 'מסלול', back: '#/syllabus', html: emptyLine('המסלול לא נמצא.') };
      const stage = byId(m.stages, t.stageId);
      const mods = t.moduleIds.map((mid) => byId(m.modules, mid));
      return { title: t.title, back: '#/syllabus', html: `
        <div class="summary">
          <div class="line">${stage ? '🗂️ ' + esc(stage.title) + ' <i class="sep">·</i> ' : ''}מסלול <b>${t.order}</b> <span style="margin-inline-start:auto">${currTag(t.curriculumStatus, true)}</span></div>
          ${t.description ? `<div class="line muted">${esc(t.description)}</div>` : ''}
          <div class="line">${t.prerequisites.length ? 'דרישות קדם: ' + t.prerequisites.map((p) => `<a href="${trackHref(p.id)}">${esc(p.title)}</a>`).join(', ') : 'ללא דרישות קדם'}</div>
        </div>
        ${measureOrNone(t.measure, 'במסלול')}
        <h2 class="sec-title">מודולים (${mods.length})</h2>
        ${listOr(mods.map((md) => row({
          href: moduleHref(md.id), icon: `<span class="num">${md.order}</span>`, title: esc(md.title),
          sub: `${md.lessons.length} שיעורים · ${md.skills.length} מיומנויות · ${md.topics.length} נושאים`,
          meta: md.labRequired ? '<span class="chip warn">🛠️ דורש מעבדה</span>' : '',
        })), 'אין עדיין מודולים במסלול.')}
      ` };
    },

    module(m, id) {
      const md = byId(m.modules, id);
      if (!md) return { title: 'מודול', back: '#/syllabus', html: emptyLine('המודול לא נמצא.') };
      const t = byId(m.tracks, md.trackId);
      return { title: md.title, back: trackHref(md.trackId), html: `
        <div class="summary">
          <div class="line">${esc(t.title)} <i class="sep">·</i> מודול <b>${md.order}</b> <span style="margin-inline-start:auto">${currTag(t.curriculumStatus)}</span></div>
          ${md.labRequired ? '<div class="line"><span class="chip warn">🛠️ דורש מעבדה — בסביבה מורשית בלבד</span></div>' : ''}
          ${md.prerequisites.length ? `<div class="line">דורש: ${md.prerequisites.map((p) => `<a href="${moduleHref(p.id)}">${esc(p.title)}</a>`).join(', ')}</div>` : ''}
        </div>
        <h2 class="sec-title">מטרות למידה</h2>
        ${md.learningObjectives.length ? `<ul class="list">${md.learningObjectives.map((o) => `<li><div class="use-row"><span class="use-tx"><b>${esc(o)}</b></span></div></li>`).join('')}</ul>` : emptyLine('לא הוגדרו מטרות למידה.')}
        <h2 class="sec-title">שיעורים (${md.lessons.length})</h2>
        ${listOr(md.lessons.map(lessonRow), 'אין עדיין שיעורים במודול.')}
        <h2 class="sec-title">נושאים (${md.topics.length})</h2>
        ${listOr(md.topics.map((tp) => row({ icon: '🏷️', title: esc(tp.title), sub: tp.description ? esc(tp.description) : '' })), 'לא הוגדרו נושאים.')}
        <h2 class="sec-title">מיומנויות (${md.skills.length})</h2>
        ${listOr(md.skills.map((k) => row({ icon: '🎯', title: esc(k.title),
          sub: k.assessmentCriteria.length ? 'קריטריונים: ' + k.assessmentCriteria.map(esc).join(', ') : 'לא הוגדרו קריטריוני הערכה',
          meta: k.competencyStatus ? chip(k.competencyStatus) : '<span class="chip">אין הערכה מתועדת</span>' })), 'לא הוגדרו מיומנויות.')}
      ` };
    },

    progress(m) {
      return { title: 'התקדמות', html: `
        <p class="hint">בכל רמה — נלמד והוכח בנפרד, וכשאין הערכות: "אין הערכות מתועדות". בלי אחוזים.</p>
        <h2 class="sec-title">האקדמיה כולה</h2>
        ${measureOrNone(m.measure)}
        <h2 class="sec-title">לפי מסלול</h2>
        <ul class="list">${m.tracks.map((t) => `<li><a class="use-row" href="${trackHref(t.id)}" style="display:block">
          <span class="use-tx"><b>${esc(t.title)}</b><small>${t.measure ? t.measure.total + ' כשירויות' : 'אין הערכות מתועדות'}</small><span class="row-meta">${currTag(t.curriculumStatus)}</span></span>
          ${t.measure ? measures(t.measure, true) : ''}</a></li>`).join('') || '<li>' + emptyLine('אין מסלולים.') + '</li>'}</ul>
      ` };
    },

    tasks(m, which) {
      if (!m.tasks.length) return { title: 'משימות', html: `<div class="nocontract">📭 <b>אין משימות מתועדות</b>.</div>` };
      const sel = TASK[which] ? which : (m.tasks.find((t) => t.status === 'active') ? 'active' : 'planned');
      const list = m.tasks.filter((t) => t.status === sel);
      return { title: 'משימות', html: `
        <div class="seg-tabs" role="tablist">${Object.keys(TASK).map((k) => `<button type="button" role="tab" aria-selected="${k === sel}" data-href="#/tasks/${k}">${TASK[k].label} <small>${m.taskCounts[k] || ''}</small></button>`).join('')}</div>
        ${listOr(list.map((t) => row({ icon: TASK[t.status].icon, title: esc(t.title),
          sub: [MODE[t.mode].icon + ' ' + MODE[t.mode].label, t.waitingOn.length ? 'ממתינה ל: ' + t.waitingOn.map((d) => esc(d.title)).join(', ') : ''].filter(Boolean).join(' · '),
          meta: taskChip(t.status) + (t.authorizedLab ? '<span class="chip warn">🔐 מעבדה מורשית</span>' : '') })), 'אין משימות ' + TASK[sel].label + '.')}
      ` };
    },

    evidence(m) {
      return { title: 'מבחנים וראיות', html: `
        <h2 class="sec-title">הערכות (${m.assessments.length})</h2>
        ${m.assessments.length ? listOr(m.assessments.map((a) => row({ icon: '📝',
          title: a.skills.map((k) => esc(k.title)).join(', ') || 'הערכה',
          sub: [a.assessedAt ? 'הוערך ' + fmtDate(a.assessedAt) : 'טרם הוערך', a.rubric.length ? a.rubric.length + ' קריטריונים במחוון' : 'אין מחוון', a.evidenceRefs.length + ' ראיות'].join(' · '),
          meta: `<span class="chip ${ASSESS[a.status].cls}">${ASSESS[a.status].label}</span>${a.status === 'evaluated' ? (a.approvedBy ? '<span class="chip ok">אושר</span>' : '<span class="chip warn">לא אושר</span>') : ''}` })), '') : noAssess()}
        <h2 class="sec-title">ראיות (${m.evidence.length})</h2>
        ${listOr(m.evidence.map((e) => `<li><div class="use-row"><span class="use-ic" aria-hidden="true">🧾</span>
          <span class="use-tx"><b>${esc(EVKIND[e.kind] || e.kind)}</b><small>${fmtDate(e.createdAt)}${e.visibility ? ' · ' + (e.visibility === 'private' ? 'פרטי' : 'מוגבל') : ''}</small>
          <span class="refs"><code title="${esc(e.reference)}">${esc(e.reference)}</code></span></span></div></li>`), 'אין ראיות מתועדות.')}
      ` };
    },

    gaps(m) {
      return { title: 'פערים והמלצות', html: `
        <h2 class="sec-title">פערי ידע (${m.gaps.length})</h2>
        ${listOr(m.gaps.map((c) => row({ href: moduleHref(c.moduleId), icon: '🩹', title: esc(c.title), meta: chip(c.status) })),
          m.measure ? 'אין כשירות שמסומנת "זוהה פער" או "נדרשת הערכה מחדש".' : 'אין הערכות מתועדות — אין ממה לגזור פערים.')}
        <h2 class="sec-title">המלצות להמשך <span class="derived">נגזר</span></h2>
        <p class="hint">נגזרות אוטומטית מהפערים ומהמשימות החסומות — לא המלצה של מדריך.</p>
        ${listOr(m.recommendations.map(recRow(m)), 'אין המלצות.')}
      ` };
    },

    timeline(m) {
      const days = [];
      for (const e of m.timeline) {
        const day = fmtDate(e.at);
        if (!days.length || days[days.length - 1].day !== day) days.push({ day, items: [] });
        days[days.length - 1].items.push(e);
      }
      return { title: 'ציר זמן', html: days.map((d) => `<div class="day">${d.day}</div><ul class="list">${d.items.map((e) =>
        row({ icon: EVENT[e.kind] || '•', title: esc(e.title), sub: fmtDate(e.at, true) })).join('')}</ul>`).join('') || emptyLine('אין עדיין אירועים.') };
    },
  };

  CA.screensV2 = screens;
})();
