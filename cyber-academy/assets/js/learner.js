/* ההתקדמות שלי — מסך הלומד המחובר. הנתונים מ-api/progress.php (השרת קובע את הזהות; הלקוח לא שולח מזהה).
   שמות המיומנויות והשיעורים — מהסילבוס (חוזה v2) בלבד. כל מספר כאן הוא ספירה, לא אחוז.
   שלוש קטגוריות נפרדות: מאומת / ממתין לאימות (דיווח) / לא הוערך. נלמד ו"הוכח במעשה" — רק ממה שאומת.
   הכול מוצג אחרי טעינה; עד אז שלד. בלי אחוזים, בלי 0 במצב ריק. */
'use strict';
window.CA = window.CA || {};
(function () {
  const { esc, STATUS, chip, measures, row, emptyLine, fmtDate } = CA.ui;
  const GAP = ['gap_identified', 'reassessment_required'];

  // הודעות שגיאה לפי code יציב מהשרת (api/progress.php). לא מציגים נתיב, תוכן או פרטי שרת.
  const LEARN_ERR = {
    forbidden: ['🔒', 'אין הרשאה לראות את ההתקדמות', 'החשבון שלך לא מורשה לצפות בהתקדמות, או שההתחברות פגה. התחברו מחדש.'],
    progress_unavailable: ['🗄️', 'ההתקדמות עדיין לא זמינה', 'נתוני ההתקדמות לא מחוברים בשרת. מנהל המערכת צריך להגדיר את הגישה אליהם.'],
    progress_invalid: ['📄', 'נתוני ההתקדמות אינם תקינים', 'לא מוצגים, כדי לא להציג מצב שגוי. המנהל צריך לבדוק את הרישום.'],
    offline: ['📡', 'אין חיבור לשרת', 'בדקו את החיבור לאינטרנט ונסו שוב.'],
    internal: ['⚠️', 'לא הצלחנו לטעון את ההתקדמות', 'נסו שוב. אם זה חוזר — דווחו למפתח.'],
  };

  function errCode(r) {
    if (r.status === 0) return 'offline';
    const c = r.body && r.body.code;
    if (c && LEARN_ERR[c]) return c;
    if (r.status === 401 || r.status === 403) return 'forbidden';
    return 'internal';
  }

  /** מיפוי הסילבוס: סדר השיעורים כפי שהוא בתוכנית (שלב ← מסלול ← מודול ← שיעור), רק מסלולים מאושרים. */
  function indexSyllabus(m) {
    const skillTitle = {};   // כל המיומנויות בסילבוס — לשמות
    const skillIds = [];
    const lessons = [];      // שיעורים מסלולים מאושרים, בסדר התוכנית
    const lessonInfo = {};   // שיעורים בכל הסילבוס — לשמות בלבד
    const modById = {}; m.modules.forEach((md) => { modById[md.id] = md; });
    const trackById = {}; m.tracks.forEach((t) => { trackById[t.id] = t; });
    const seenMod = new Set();

    for (const md of m.modules) {
      for (const k of md.skills) { if (!(k.id in skillTitle)) { skillTitle[k.id] = k.title; skillIds.push(k.id); } }
      for (const l of md.lessons) lessonInfo[l.id] = { title: l.title, module: md.title };
    }
    const visit = (md, approved) => {
      if (!md || seenMod.has(md.id)) return;
      seenMod.add(md.id);
      if (!approved) return;
      for (const l of md.lessons) lessons.push({ id: l.id, title: l.title, module: md.title, track: md.trackId });
    };
    const stages = [...m.stages].sort((a, b) => a.order - b.order);
    for (const st of stages) {
      for (const tid of st.trackIds) {
        const t = trackById[tid];
        if (!t) continue;
        for (const mid of t.moduleIds) visit(modById[mid], t.curriculumStatus === 'approved');
      }
    }
    // מודולים שלא הגיעו דרך מסלול — לא מסלול מאושר, ולכן לא נכנסים לרשימה
    m.modules.forEach((md) => visit(md, false));
    const trackTitle = (id) => (trackById[id] || {}).title || '';
    return { skillTitle, skillIds, lessons, lessonInfo, trackTitle };
  }

  /** השיעור או המיומנות הבאים — נגזרים בבירור: קודם פער, אחרת השיעור הבא בסדר הסילבוס. */
  function nextStep(p, idx, completed) {
    const gaps = Object.entries(p.skills).filter(([, s]) => s.category === 'verified' && GAP.includes(s.status));
    if (gaps.length) {
      const [id, s] = gaps[0];
      return { title: 'הערכה מחדש: ' + (idx.skillTitle[id] || id), sub: 'נגזר מהפער שסומן' };
    }
    if (!idx.lessons.length) return null;
    const next = idx.lessons.find((l) => !completed.has(l.id));
    if (!next) return { title: 'כל השיעורים בסילבוס המאושר הושלמו', sub: 'נגזר: אין שיעור פתוח בסדר הסילבוס' };
    return { title: next.title, sub: 'נגזר מסדר הסילבוס' };
  }

  function render(p, idx) {
    const skills = p ? p.skills : {};
    const entries = Object.entries(skills);
    const verified = entries.filter(([, s]) => s.category === 'verified');
    const verifiedN = verified.length;
    const reportedN = entries.filter(([, s]) => s.category === 'reported').length;
    const notAssessedN = idx.skillIds.length
      ? Math.max(0, idx.skillIds.length - verifiedN - reportedN)
      : (p ? p.counts.notAssessed : 0);
    const completedIds = p ? p.lessonsCompleted : [];
    const completed = new Set(completedIds);
    const gapRows = verified.filter(([, s]) => GAP.includes(s.status));
    const next = nextStep(p || { skills: {} }, idx, completed);
    const name = (id) => idx.skillTitle[id] || id;

    const cat = (ic, label, sub, end) => `<li><div class="use-row lrn-cat">
        <span class="use-ic" aria-hidden="true">${ic}</span>
        <span class="use-tx"><b>${esc(label)}</b><small>${sub}</small></span>
        ${end ? `<span class="row-end">${end}</span>` : ''}</div></li>`;

    const categories = `<ul class="list">
        ${cat('✅', 'מאומת', verifiedN ? 'הסטטוס מבוסס על הערכה מאושרת' : 'עדיין אין הערכות מאומתות',
          verifiedN ? `<b>${verifiedN}</b>` : '')}
        ${cat('⏳', 'ממתין לאימות', reportedN ? 'דיווח מורה, עוד לא אומת — לא נספר כשליטה' : 'אין דיווחים ממתינים',
          reportedN ? `<b>${reportedN}</b>` : '')}
        ${cat('○', 'לא הוערך', notAssessedN ? 'מיומנויות בסילבוס בלי הערכה' : 'כל המיומנויות הוערכו',
          notAssessedN ? `<b>${notAssessedN}</b>` : '')}
      </ul>`;

    const measure = verifiedN
      ? `<p class="hint">שני מדדים נפרדים, מתוך המיומנויות שאומתו בלבד.</p>${measures({ learned: p.learned, proven: p.proven, total: verifiedN })}`
      : `<div class="nocontract">📭 <b>אין עדיין נלמד או הוכח במעשה</b> — המדדים מופיעים רק אחרי הערכה מאומתת.</div>`;

    const verifiedList = verifiedN
      ? `<ul class="list">${verified.map(([id, s]) => row({ icon: '🎯', title: esc(name(id)), sub: 'הערכה מאושרת', meta: chip(s.status) })).join('')}</ul>
         <div class="nocontract" style="margin-top:8px">פירוט ההערכות והראיות המאושרות — מי הערך, מתי ואיזו ראיה — יוצג כאן בהמשך.</div>`
      : emptyLine('עדיין אין הערכות מאומתות.');

    const gaps = gapRows.length
      ? `<ul class="list">${gapRows.map(([id, s]) => row({ icon: '🩹', title: esc(name(id)), sub: 'הערכה מאושרת · נדרש טיפול', meta: chip(s.status) })).join('')}</ul>`
      : emptyLine('אין מיומנות שסומנה "זוהה פער" או "נדרשת הערכה מחדש".');

    const nextHtml = next
      ? `<ul class="list">${row({ icon: '▶️', title: esc(next.title), sub: esc(next.sub), end: '<span class="derived">נגזר</span>' })}</ul>`
      : emptyLine('אין שיעורים בסילבוס המאושר עדיין — אין משימה נגזרת.');

    const doneLessons = idx.lessons.filter((l) => completed.has(l.id));
    const doneIds = new Set(doneLessons.map((l) => l.id));
    // שיעור שהושלם אבל אינו בסילבוס המאושר — מוצג בשמו או במזהה, לא נעלם
    const extra = completedIds.filter((id) => !idx.lessons.some((l) => l.id === id))
      .map((id) => ({ id, title: (idx.lessonInfo[id] || {}).title || id, module: (idx.lessonInfo[id] || {}).module || '' }));
    const doneAll = [...doneLessons, ...extra];
    const notDone = idx.lessons.filter((l) => !doneIds.has(l.id));
    const lessonRow = (l, ok) => row({ icon: ok ? '✅' : '○', title: esc(l.title), sub: esc(l.module || ''),
      meta: ok ? '<span class="chip ok">הושלם</span>' : '<span class="chip">לא הושלם</span>' });
    const lessonsHtml = `
      <h2 class="sec-title">שיעורים שהושלמו${doneAll.length ? ` (${doneAll.length})` : ''}</h2>
      ${doneAll.length ? `<ul class="list">${doneAll.map((l) => lessonRow(l, true)).join('')}</ul>`
        : emptyLine('עדיין אין שיעור שהושלם. שיעור נחשב שהושלם רק עם ראיה מאומתת ואישור.')}
      ${notDone.length ? `<details class="lrn-more"><summary>שיעורים שלא הושלמו (${notDone.length})</summary>
        <ul class="list">${notDone.map((l) => lessonRow(l, false)).join('')}</ul></details>` : ''}`;

    return `
      <p class="hint">נלמד והוכח במעשה — שני מדדים נפרדים, ורק ממה שאומת. בלי אחוזים.</p>
      ${p && p.updatedAt ? `<p class="lrn-meta">עודכן ${esc(fmtDate(p.updatedAt, true))}</p>` : ''}
      ${p ? '' : '<div class="nocontract" style="margin-bottom:8px">📭 <b>אין עדיין רישום התקדמות עבורך</b> — כל מה שמופיע כאן הוא "לא הוערך".</div>'}
      <h2 class="sec-title">מצב המיומנויות</h2>
      ${categories}
      <h2 class="sec-title">נלמד ו"הוכח במעשה"</h2>
      ${measure}
      <h2 class="sec-title">הערכות וראיות מאושרות</h2>
      ${verifiedList}
      <h2 class="sec-title">פערי ידע</h2>
      ${gaps}
      <h2 class="sec-title">המשימה הבאה <span class="derived">נגזר</span></h2>
      ${nextHtml}
      ${lessonsHtml}`;
  }

  function errorHtml(r) {
    const code = errCode(r);
    const [icon, title, todo] = LEARN_ERR[code];
    return `<div class="state" role="alert" data-state="learner-${code}">
        <div class="state-ic" aria-hidden="true">${icon}</div>
        <h2>${esc(title)}</h2>
        <p class="state-todo">${esc(todo)}</p>
        <button type="button" class="btn primary" data-learner-retry>⟳ נסה שוב</button>
      </div>`;
  }

  /** מסך ההתקדמות שלי. html ראשוני = שלד; after — טוען את הנתונים לאחר שהמסך הוצב. */
  function screen(m) {
    const idx = indexSyllabus(m);
    return {
      title: 'ההתקדמות שלי',
      back: '#/progress',
      html: `<div data-learner-root>${CA.states.skeleton()}</div>`,
      after: (main) => mount(main, idx),
    };
  }

  async function mount(main, idx) {
    const root = main.querySelector('[data-learner-root]');
    if (!root) return;
    const load = async () => {
      root.innerHTML = CA.states.skeleton();
      const r = await CA.api.progress();
      if (!root.isConnected) return;  // המשתמש עבר מסך — לא כותבים על מסך אחר
      if (r.status !== 200 || !r.body || !r.body.ok) { root.innerHTML = errorHtml(r); return; }
      root.innerHTML = render(r.body.progress, idx);
    };
    root.addEventListener('click', (e) => {
      if (e.target.closest('[data-learner-retry]')) load();
    });
    load();
  }

  CA.learner = { screen, indexSyllabus, render };
})();
