/* המסכים. כל מסך: (model, arg) → { title, back, html }. רק תצוגה — כל חישוב נעשה בשרת (lib/model.php). */
'use strict';
window.CA = window.CA || {};
(function () {
  const { esc, STATUS, TASK, MODE, fmtDate, chip, taskChip, measures, dist, noContract, emptyLine, row } = CA.ui;

  const trackHref = (id) => '#/track/' + encodeURIComponent(id);
  // שדה אופציונלי שחסר ב-snapshot = "לא דווח" — לא 0 ולא "אין"
  const assessedText = (c) => !c.assessedReported ? 'הערכה: לא דווח' : c.lastAssessedAt ? 'הוערך ' + fmtDate(c.lastAssessedAt) : 'טרם הוערך';
  const evidenceText = (c) => !c.evidenceReported ? 'ראיות: לא דווח' : c.evidenceRefs.length ? c.evidenceRefs.length + ' ראיות' : 'אין ראיות';
  const prereqText = (t) => !t.prerequisitesReported ? 'דרישות קדם: לא דווח'
    : t.prerequisites.length ? 'דורש: ' + t.prerequisites.map((p) => (p.met ? '' : '🧱 ') + esc(p.title)).join(', ') : 'ללא דרישות קדם';
  // מוצע מול מאושר — מגיע מהשרת. ב-v1 תמיד "proposed"; לעולם לא מסיקים "מאושר" כאן.
  const CURR = { proposed: 'סילבוס מוצע · לא אושר', approved: 'סילבוס מאושר' };
  const currTag = (t, short) => `<span class="chip ${t.curriculumStatus === 'approved' ? 'ok' : 'proposed'}" title="${esc(CURR[t.curriculumStatus] || t.curriculumStatus)}">${esc(short ? (t.curriculumStatus === 'approved' ? 'מאושר' : 'מוצע') : (CURR[t.curriculumStatus] || t.curriculumStatus))}</span>`;
  const currNote = (m) => m.curriculum.curriculumStatusSource === 'not_in_contract_v1'
    ? `<div class="curr-note">${currTag(m.curriculum)}<span>החוזה v1 לא מגדיר אישור סילבוס, ולכן כל מסלול מוצג כמוצע.</span></div>` : '';
  const trackTitle = (m, id) => (m.tracks.find((t) => t.id === id) || {}).title || id;
  const emptySnapshot = () => `<div class="summary"><div class="line"><b>אין עדיין נתונים באקדמיה</b></div>
      <p class="muted">ה-snapshot תקין אבל ריק: אין מסלולים, כשירויות או משימות. כשמאגר האקדמיה יתמלא, הם יופיעו כאן.</p></div>`;

  const recRow = (r) => {
    const icon = { reassess: '🔁', close_gap: '🩹', prerequisite: '🧱', unblock: '⛔', next_track: '➡️' }[r.kind] || '💡';
    const href = r.ref && r.ref.type === 'track' ? trackHref(r.ref.id)
      : r.ref && r.ref.trackId ? trackHref(r.ref.trackId) : r.ref && r.ref.type === 'task' ? '#/tasks/blocked' : null;
    return row({ href, icon, title: esc(r.title), sub: esc(r.reason), end: '<span class="derived">נגזר</span>' });
  };

  const compRow = (c, m, withTrack) => row({
    href: withTrack ? trackHref(c.trackId) : null,
    icon: c.evidenceRefs.length ? '🧾' : '🎯',
    title: esc(c.title),
    sub: [withTrack ? esc(trackTitle(m, c.trackId)) : '', assessedText(c), evidenceText(c)].filter(Boolean).join(' · '),
    meta: chip(c.status),
  });

  const taskRow = (t, m) => row({
    icon: TASK[t.status].icon,
    title: esc(t.title),
    sub: [MODE[t.mode].icon + ' ' + MODE[t.mode].label, t.trackIds.map((id) => esc(trackTitle(m, id))).join(', ')].filter(Boolean).join(' · '),
    meta: taskChip(t.status),
  });

  const screens = {
    home(m) {
      if (m.empty) return { title: 'תמונת מצב', html: emptySnapshot() };
      const started = m.tracks.filter((t) => t.started).length;
      const tc = m.taskCounts;
      return { title: 'תמונת מצב', html: `
        <p class="hint">שני מדדים נפרדים: <b>נלמד</b> (בלמידה או הוכח בתאוריה) ו<b>הוכח במעשה</b> (מבחן מעשי או שליטה). אין ביניהם ממוצע.</p>
        ${measures(m.measure)}
        <div class="summary">
          <div class="line"><b>${m.tracks.length}</b> מסלולים <i class="sep">·</i> <b>${started}</b> התחילו <i class="sep">·</i> <b>${m.measure.total}</b> כשירויות</div>
          ${dist(m.measure)}
        </div>
        <h2 class="sec-title">משימות</h2>
        <div class="tiles">${Object.keys(TASK).map((k) => `<a class="tile" href="#/tasks/${k}"><span class="tile-ic" aria-hidden="true">${TASK[k].icon}</span><span><b>${tc[k]}</b> ${TASK[k].label}</span></a>`).join('')}</div>
        <h2 class="sec-title">מה הלאה <span class="derived">נגזר</span></h2>
        <ul class="list">${m.recommendations.slice(0, 3).map(recRow).join('') || '<li>' + emptyLine('אין המלצות — אין פערים, חסימות או דרישות קדם פתוחות.') + '</li>'}</ul>
        ${m.recommendations.length > 3 ? `<p class="hint" style="margin-top:6px"><a href="#/gaps">כל ${m.recommendations.length} ההמלצות ‹</a></p>` : ''}
      ` };
    },

    syllabus(m) {
      if (m.empty || !m.tracks.length) return { title: 'סילבוס', html: emptySnapshot() };
      return { title: 'סילבוס', html: `
        <p class="hint">המסלולים לפי הסדר שבחוזה, עם דרישות הקדם. 🧱 = דרישת קדם שעוד לא הוכחה במעשה.</p>
        ${currNote(m)}
        <ul class="list">${m.tracks.map((t) => row({
          href: trackHref(t.id),
          icon: `<span class="num">${t.order}</span>`,
          title: esc(t.title),
          sub: t.measure.total + ' כשירויות · ' + prereqText(t),
          meta: `<span class="chip learn" title="נלמד">📘 ${t.measure.learned}</span><span class="chip ok" title="הוכח במעשה">🧪 ${t.measure.proven}</span>${currTag(t, true)}`,
        })).join('')}</ul>
        <h2 class="sec-title">פירוק עמוק יותר</h2>
        ${noContract('שלבים, מודולים, נושאים ומיומנויות', 'בחוזה יש רק מסלול ← כשירות. יגיע כשהחוזה יכלול אותם (v2).')}
      ` };
    },

    track(m, id) {
      const t = m.tracks.find((x) => x.id === id);
      if (!t) return { title: 'מסלול', back: '#/syllabus', html: emptyLine('המסלול לא נמצא ב-snapshot הנוכחי.') };
      const comps = m.competencies.filter((c) => c.trackId === id);
      const tasks = m.tasks.filter((k) => k.trackIds.includes(id));
      return { title: t.title, back: '#/syllabus', html: `
        ${measures(t.measure)}
        <div class="summary">
          <div class="line">מסלול <b>${t.order}</b> <i class="sep">·</i> ${t.started ? 'התחיל' : 'טרם התחיל'} <span style="margin-inline-start:auto">${currTag(t)}</span></div>
          ${!t.prerequisitesReported ? '<div class="line nr">דרישות קדם: לא דווח</div>' : !t.prerequisites.length ? '<div class="line">ללא דרישות קדם</div>' : ''}
          ${t.prerequisites.length ? `<div class="line">דרישות קדם: ${t.prerequisites.map((p) => `<a href="${trackHref(p.id)}">${esc(p.title)}</a> ${p.met ? '<span class="chip ok">הוכח</span>' : '<span class="chip warn">לא הוכח</span>'}`).join(' ')}</div>` : ''}
          ${dist(t.measure)}
        </div>
        <h2 class="sec-title">כשירויות (${comps.length})</h2>
        <ul class="list">${comps.map((c) => compRow(c, m, false)).join('') || '<li>' + emptyLine('אין עדיין כשירויות במסלול הזה.') + '</li>'}</ul>
        <h2 class="sec-title">שלבים · מודולים · נושאים · מיומנויות</h2>
        <div class="nocontract">🧩 <b>פירוק לשלבים/מודולים/נושאים/מיומנויות</b> — יגיע כשהחוזה יכלול אותם (v2). עד אז מוצגות רק הכשירויות שתחת המסלול, בלי היררכיה מומצאת.</div>
        <h2 class="sec-title">משימות במסלול (${tasks.length})</h2>
        <ul class="list">${tasks.map((k) => taskRow(k, m)).join('') || '<li>' + emptyLine('אין משימות שמקושרות לכשירויות במסלול.') + '</li>'}</ul>
      ` };
    },

    progress(m) {
      if (m.empty) return { title: 'התקדמות', html: emptySnapshot() };
      return { title: 'התקדמות', html: `
        <p class="hint">התקדמות בכל רמה שקיימת בחוזה: האקדמיה ← מסלול ← כשירות. בכל רמה — נלמד והוכח בנפרד.</p>
        <h2 class="sec-title">האקדמיה כולה</h2>
        ${measures(m.measure)}
        <h2 class="sec-title">לפי מסלול</h2>
        <ul class="list">${m.tracks.map((t) => `<li><a class="use-row" href="${trackHref(t.id)}" style="display:block">
            <span class="use-tx"><b>${esc(t.title)}</b><small>${t.measure.total ? t.measure.total + ' כשירויות' : 'אין עדיין כשירויות'}</small></span>
            ${t.measure.total ? measures(t.measure, true) : ''}</a></li>`).join('')}</ul>
        <h2 class="sec-title">לפי כשירות</h2>
        <ul class="list">${m.competencies.map((c) => compRow(c, m, true)).join('') || '<li>' + emptyLine('אין כשירויות.') + '</li>'}</ul>
        <h2 class="sec-title">שלבים · מודולים · נושאים</h2>
        ${noContract('התקדמות ברמות האלה')}
      ` };
    },

    tasks(m, which) {
      const sel = TASK[which] ? which : (m.tasks.find((t) => t.status === 'active') ? 'active' : 'planned');
      const list = m.tasks.filter((t) => t.status === sel);
      return { title: 'משימות', html: `
        <div class="seg-tabs" role="tablist">${Object.keys(TASK).map((k) => `<button type="button" role="tab" aria-selected="${k === sel}" data-href="#/tasks/${k}">${TASK[k].label} <small>${m.taskCounts[k] || ''}</small></button>`).join('')}</div>
        ${sel === 'blocked' && list.length ? noContract('סיבת החסימה ותלות בין משימות') : ''}
        <ul class="list" style="margin-top:8px">${list.map((t) => taskRow(t, m)).join('') || '<li>' + emptyLine(m.empty ? 'אין עדיין משימות באקדמיה.' : 'אין משימות ' + TASK[sel].label + '.') + '</li>'}</ul>
      ` };
    },

    evidence(m) {
      const withEv = m.competencies.filter((c) => c.evidenceRefs.length || c.lastAssessedAt)
        .sort((a, b) => String(b.lastAssessedAt || '').localeCompare(String(a.lastAssessedAt || '')));
      return { title: 'מבחנים וראיות', html: `
        <p class="hint">מה שהחוזה מכיל: הפניות לראיות (evidenceRefs) ומועד ההערכה האחרונה לכל כשירות. ההפניות מוצגות כטקסט.</p>
        <ul class="list">${withEv.map((c) => `<li><a class="use-row" href="${trackHref(c.trackId)}">
            <span class="use-ic" aria-hidden="true">${c.evidenceRefs.length ? '🧾' : '📝'}</span>
            <span class="use-tx"><b>${esc(c.title)}</b><span class="row-meta">${chip(c.status)}</span><small>${esc(trackTitle(m, c.trackId))} · ${assessedText(c)}</small>
              ${c.evidenceRefs.length ? `<span class="refs">${c.evidenceRefs.map((r) => `<code title="${esc(r)}">${esc(r)}</code>`).join('')}</span>` : `<small>${evidenceText(c)}</small>`}</span>
            <span class="use-go" aria-hidden="true">‹</span></a></li>`).join('') || '<li>' + emptyLine('אין עדיין הערכות או ראיות.') + '</li>'}</ul>
        <h2 class="sec-title">מבחנים והערכות</h2>
        ${noContract('מבחן / הערכה / ראיה כאובייקט', 'אין ציון, מעריך, סוג מבחן או תוצאה — רק הפניה ומועד.')}
      ` };
    },

    gaps(m) {
      if (m.empty) return { title: 'פערים והמלצות', html: emptySnapshot() };
      // רק מסלולים שכבר התחילו — שם דרישת קדם פתוחה היא פער. השאר מסומנים 🧱 בסילבוס.
      const unmet = m.unmetPrerequisites.filter((u) => u.started);
      const waiting = new Set(m.unmetPrerequisites.filter((u) => !u.started).map((u) => u.trackId)).size;
      return { title: 'פערים והמלצות', html: `
        <h2 class="sec-title">פערי ידע (${m.gaps.length})</h2>
        <ul class="list">${m.gaps.map((c) => compRow(c, m, true)).join('') || '<li>' + emptyLine('אין כשירות שמסומנת "זוהה פער" או "נדרשת הערכה מחדש".') + '</li>'}</ul>
        <h2 class="sec-title">דרישות קדם שנעקפו (${unmet.length})</h2>
        <ul class="list">${unmet.map((u) => row({ href: trackHref(u.prerequisiteId), icon: '🧱', title: esc(u.prerequisiteTitle),
            sub: 'נדרש ל: ' + esc(u.trackTitle) + ' (כבר התחיל)', end: '<span class="derived">נגזר</span>' })).join('') || '<li>' + emptyLine('אין מסלול שהתחיל לפני שדרישות הקדם שלו הוכחו.') + '</li>'}</ul>
        <p class="hint" style="margin-top:6px">${waiting ? `ועוד ${waiting} מסלולים שטרם התחילו ממתינים לדרישות קדם — מסומנים 🧱 ב<a href="#/syllabus">סילבוס</a>. ` : ''}"הוכח" למסלול = יש בו כשירויות וכולן הוכחו במעשה. זו הגדרה של הדשבורד — החוזה לא מגדיר סיום מסלול.</p>
        <h2 class="sec-title">המלצות להמשך <span class="derived">נגזר</span></h2>
        <p class="hint">נגזרות אוטומטית מהפערים, מדרישות הקדם ומהמשימות החסומות — לא המלצה של מדריך.</p>
        <ul class="list">${m.recommendations.map(recRow).join('') || '<li>' + emptyLine('אין המלצות.') + '</li>'}</ul>
      ` };
    },

    timeline(m) {
      const days = [];
      for (const e of m.timeline) {
        const day = fmtDate(e.at);
        if (!days.length || days[days.length - 1].day !== day) days.push({ day, items: [] });
        days[days.length - 1].items.push(e);
      }
      return { title: 'ציר זמן', html: `
        <p class="hint">מה שיש בחוזה: מועד עדכון ה-snapshot ומועד ההערכה האחרונה של כל כשירות — לא היסטוריה מלאה.</p>
        ${days.map((d) => `<div class="day">${d.day}</div><ul class="list">${d.items.map((e) => e.kind === 'snapshot'
          ? row({ icon: '🔄', title: esc(e.title), sub: fmtDate(e.at, true) })
          : row({ href: trackHref(e.ref.trackId), icon: '📝', title: esc(e.title), sub: 'הערכה אחרונה · ' + esc(e.trackTitle) + ' · ' + fmtDate(e.at, true), meta: chip(e.status) })).join('')}</ul>`).join('')
          || emptyLine('אין עדיין אירועים.')}
        <h2 class="sec-title">היסטוריית שינויים</h2>
        ${noContract('אירועי ציר זמן', 'רואים רק את המצב האחרון, לא "ממה למה".')}
      ` };
    },
  };

  CA.screens = screens;
})();
