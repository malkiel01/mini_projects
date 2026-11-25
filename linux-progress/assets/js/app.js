/* ללימוד סייבר התקפי — מושך מ-api/progress.php (שקורא מהריפו בצד השרת). בלי סודות בדפדפן. */
(function () {
  'use strict';

  const STATUS_LABEL = { not_started: 'לא התחלתי', in_progress: 'בתרגול', learned_pending_verification: 'נלמד, ממתין לאימות', verified: 'אומת' };
  const LESSON_LABEL = { not_started: 'לא התחלתי', in_progress: 'בתרגול', completed: 'הושלם', verified: 'אומת' };
  const MESSAGES = {
    no_token: 'עדיין לא הוגדר סוד לקריאה. הגדר אותו במסך ההגדרות.',
    no_file: 'עדיין אין קובץ התקדמות בריפו. אחרי שייווצר, הנתונים יופיעו כאן.',
    unauthorized: 'הסוד לא מתאים לריפו או שאין לו הרשאת קריאה.',
    invalid: 'קובץ ההתקדמות בריפו בפורמט שגוי, ולכן לא מוצג.',
    unavailable: 'לא הצלחתי לטעון כרגע. נסה שוב מאוחר יותר.',
  };
  const REFRESH_MS = 5 * 60 * 1000;
  const $ = (id) => document.getElementById(id);
  let timer = null;

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = String(text);
    return n;
  }

  function ring(percent) {
    const r = 46, c = 2 * Math.PI * r;
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 120 120');
    svg.setAttribute('class', 'ring');
    svg.setAttribute('aria-hidden', 'true');
    const bg = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    bg.setAttribute('cx', '60'); bg.setAttribute('cy', '60'); bg.setAttribute('r', r); bg.setAttribute('class', 'ring-bg');
    const fg = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    fg.setAttribute('cx', '60'); fg.setAttribute('cy', '60'); fg.setAttribute('r', r); fg.setAttribute('class', 'ring-fg');
    fg.setAttribute('stroke-dasharray', String(c));
    fg.setAttribute('stroke-dashoffset', String(c * (1 - Math.min(100, percent) / 100)));
    svg.append(bg, fg);
    return svg;
  }

  function explainer() {
    const box = el('div', 'explain');
    box.append(el('h2', 'sec', 'איך לקרוא את הדף'));
    const list = el('ul', 'legend');
    const items = [
      ['legend-none', 'תרגול', 'רשומת תרגול בלבד. לא משלימה יחידה ולא מודול.'],
      ['legend-done', 'הושלם', 'יחידה שמנהל האקדמיה אישר השלמתה.'],
      ['legend-verified', 'אומת', 'מודול שמנהל האקדמיה אישר אימות שלו.'],
    ];
    for (const [cls, name, desc] of items) {
      const li = el('li', cls);
      li.append(el('strong', '', name), document.createTextNode(' — ' + desc));
      list.append(li);
    }
    box.append(list);
    return box;
  }

  function lessonsBlock(lessons) {
    const ul = el('ul', 'lessons');
    for (const l of lessons) {
      const li = el('li', 'lesson');
      li.append(el('span', 'kind', l.kind), el('span', 'chip', LESSON_LABEL[l.status] || l.status), el('strong', '', l.title));
      if (l.objectives && l.objectives.length) {
        const ob = el('ul', 'objectives');
        for (const o of l.objectives) ob.append(el('li', '', o));
        li.append(ob);
      }
      ul.append(li);
    }
    return ul;
  }

  function renderSummary(sum) {
    const box = $('syllabus');
    box.textContent = '';
    if (!sum) { box.hidden = true; return; }
    box.hidden = false;
    box.append(explainer());

    const o = sum.overall;
    const top = el('div', 'overall');
    const big = el('div', 'overall-ring');
    big.append(ring(o.percentLessonsCompleted), el('div', 'ring-label', o.percentLessonsCompleted + '%'));
    const text = el('div', 'overall-text');
    text.append(
      el('p', 'big', `הושלמו ${o.lessonsCompleted} מתוך ${o.lessonsTotal} יחידות`),
      el('p', 'muted', `אומתו ${o.modulesVerified} מתוך ${o.modulesTotal} מודולים (החלטת מנהל)`),
      el('p', 'muted', `בתרגול ${o.modulesInProgress} מודולים · ממתינים לאימות ${o.modulesLearnedPending}`),
    );
    if (sum.pace) text.append(el('p', 'muted', `פעילות מתועדת: ${sum.pace.modulesTouchedPerWeek} מודולים שנגעתי בהם בשבוע (מאז ${sum.pace.since})`));
    if (sum.decisions && sum.decisions.error) text.append(el('p', 'error', 'קובץ ההחלטות לא נקרא: ' + sum.decisions.error + '. לא נספרת אף החלטה.'));
    top.append(big, text);
    box.append(top);

    box.append(el('h2', 'sec', 'מסלולים'));
    for (const t of sum.tracks) {
      const card = el('details', 'track');
      const head = el('summary', 'track-head');
      head.append(el('span', 'track-title', t.title), el('span', 'track-pct', `${t.lessonsCompleted}/${t.lessonsTotal} יחידות · ${t.percentLessons}%`));
      card.append(head);
      const bar = el('div', 'bar');
      const fill = el('div', 'fill');
      fill.style.width = Math.min(100, t.percentLessons) + '%';
      bar.append(fill);
      card.append(bar);

      const mods = el('ul', 'modules');
      for (const m of t.modules) {
        const li = el('li', 'module status-' + m.status);
        const row = el('div', 'module-head');
        row.append(el('span', 'module-title', `${m.id} ${m.title}`), el('span', 'chip', STATUS_LABEL[m.status]));
        li.append(row);
        if (m.lessons.length) li.append(lessonsBlock(m.lessons));
        mods.append(li);
      }
      card.append(mods);
      box.append(card);
    }
    if (sum.unmapped) box.append(el('p', 'muted small', `${sum.unmapped} רשומות לא מקושרות למודול בתוכנית (לא נספרות בהתקדמות).`));
    if (sum.practice && sum.practice.lessonMismatch) box.append(el('p', 'muted small', `${sum.practice.lessonMismatch} רשומות עם יחידה שלא תואמת את המודול שלה.`));
  }


  // ---------- מדדים, תחזית, סימולטור ואבני דרך ----------
  const LAB_SHARE = 0.33;


  function fmtDate(d) {
    return d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: 'numeric' });
  }

  // חישוב תחזית לפי הנחות: כמה שבועות נשארו, כשהחלק המעשי חייב להתבצע במחשב.
  function forecastWeeks(remainingModules, totalModules, totalHours, hoursPerDay, daysPerWeek, phoneShare) {
    const remainingHours = (remainingModules / totalModules) * totalHours;
    const weekly = hoursPerDay * daysPerWeek;
    if (weekly <= 0) return null;
    const pcWeekly = weekly * (1 - phoneShare);
    const weeksAll = remainingHours / weekly;
    const weeksLab = pcWeekly > 0 ? (remainingHours * LAB_SHARE) / pcWeekly : Infinity;
    const weeks = Math.max(weeksAll, weeksLab);
    if (!isFinite(weeks)) return null;
    const date = new Date();
    date.setDate(date.getDate() + Math.ceil(weeks * 7));
    return { weeks, months: weeks / 4.345, date };
  }

  function renderMetrics(sum) {
    const box = $('metrics');
    box.textContent = '';
    if (!sum || !sum.metrics) { box.hidden = true; return; }
    box.hidden = false;
    const m = sum.metrics.measured;
    box.append(el('h2', 'sec', 'מדדים (רק מה שנמדד)'));
    const grid = el('div', 'kpis');
    const kpis = [
      ['יחידות שהושלמו', m.percentLessonsCompleted + '%', `${m.lessonsCompleted} מתוך ${m.lessonsTotal}`],
      ['מודולים שאומתו', m.percentModulesVerified + '%', `${m.modulesVerified} מתוך ${m.modulesTotal}`],
      ['בתרגול בלבד', String(m.modulesInProgress), `רשומות תרגול: ${m.practiceRecords} · אימותי יכולת: ${m.capabilityChecks}`],
      ['ימים פעילים', String(m.activeDays), m.daysSinceLast === null ? 'עדיין לא נרשם' : `התרגול האחרון לפני ${m.daysSinceLast} ימים`],
    ];
    for (const [label, value, sub] of kpis) {
      const c = el('div', 'kpi');
      c.append(el('div', 'kpi-label', label), el('div', 'kpi-value', value), el('div', 'kpi-sub', sub));
      grid.append(c);
    }
    box.append(grid);

    const win = el('ul', 'windows');
    for (const k of ['d7', 'd14', 'd30']) {
      const w = m.windows[k];
      win.append(el('li', '', `${w.days} ימים אחרונים: ${w.items} תרגולים (${w.perWeek} בשבוע)`));
    }
    box.append(win);

    box.append(el('p', 'sub-title', 'תרגולים לפי שבוע (8 שבועות אחרונים)'));
    const bars = el('div', 'weekbars');
    const max = Math.max(1, ...m.weekly.map((w) => w.items));
    for (const w of m.weekly) {
      const col = el('div', 'wbar');
      const fill = el('div', 'wfill');
      fill.style.height = Math.round((w.items / max) * 100) + '%';
      fill.title = `${w.items} תרגולים`;
      col.append(fill, el('span', 'wlabel', w.items));
      bars.append(col);
    }
    box.append(bars);

    const gaps = el('div', 'gaps');
    gaps.append(el('p', 'sub-title', 'מה עדיין לא נמדד'));
    const gl = el('ul', 'gap-list');
    for (const v of Object.values(sum.metrics.missing)) gl.append(el('li', '', v));
    gaps.append(gl);
    box.append(gaps);
  }

  function renderForecast(sum) {
    const box = $('forecast');
    box.textContent = '';
    if (!sum || !sum.metrics) { box.hidden = true; return; }
    box.hidden = false;
    const fb = sum.metrics.forecastBasis;
    const remaining = fb.remainingModules;
    const total = fb.totalModules;
    box.append(el('h2', 'sec', 'תחזית סיום'));
    box.append(el('p', 'warn', `רמת ביטחון: ${fb.confidence}. התחזית מבוססת על הנחות עבודה של מנהל האקדמיה, לא על זמן לימוד שנמדד.`));

    const cards = el('div', 'scenarios');
    const sim = { h: 3, d: 6, p: 0.5 };
    const scen = Object.entries(fb.scenarios);
    const paint = () => {
      cards.textContent = '';
      for (const [key, sc] of scen) {
        const f = forecastWeeks(remaining, total, sc.totalHours, sim.h, sim.d, sim.p);
        const c = el('div', 'scenario');
        c.append(el('div', 'scen-name', sc.label));
        if (!f) {
          c.append(el('div', 'scen-date', 'לא ניתן לחשב'));
        } else {
          c.append(el('div', 'scen-date', fmtDate(f.date)), el('div', 'scen-sub', `${f.months.toFixed(1)} חודשים · ${sc.totalHours} שעות בהנחה`));
        }
        cards.append(c);
      }
    };
    box.append(cards);

    box.append(el('h3', 'sub-title', 'סימולטור (לא משנה את הנתונים)'));
    const form = el('div', 'sim');
    const row = (label, min, max, step, value, key, fmt) => {
      const wrap = el('label', 'sim-row');
      const out = el('span', 'sim-out', fmt(value));
      const input = document.createElement('input');
      input.type = 'range'; input.min = min; input.max = max; input.step = step; input.value = value;
      input.addEventListener('input', () => { sim[key] = Number(input.value); out.textContent = fmt(sim[key]); paint(); });
      wrap.append(el('span', 'sim-label', label), input, out);
      return wrap;
    };
    form.append(
      row('שעות לימוד ביום', 1, 8, 0.5, sim.h, 'h', (v) => `${v} שעות`),
      row('ימי לימוד בשבוע', 1, 7, 1, sim.d, 'd', (v) => `${v} ימים`),
      row('חלק מהזמן בטלפון (תיאוריה)', 0, 1, 0.1, sim.p, 'p', (v) => `${Math.round(v * 100)}%`),
    );
    box.append(form);
    box.append(el('p', 'muted small', 'תרגולים מעשיים דורשים מחשב. הנחת העבודה: שליש מהזמן הוא מעבדה.'));
    box.append(cards);
    paint();
  }

  function renderMilestones(sum) {
    const box = $('milestones');
    box.textContent = '';
    if (!sum || !sum.milestones) { box.hidden = true; return; }
    box.hidden = false;
    box.append(el('h2', 'sec', 'אבני דרך מקצועיות'));
    box.append(el('p', 'warn', 'מדדי ניהול בלבד: כיסוי מודולים מתוך התוכנית. אבני הדרך אינן מדד מומחיות, והתנאים הנוספים ממתינים לאישור מנהל.'));
    const list = el('ul', 'milestones-list');
    for (const ms of sum.milestones) {
      const pct = ms.requiredModules ? Math.round((ms.verifiedModules / ms.requiredModules) * 100) : 0;
      const li = el('li', 'ms');
      li.append(el('div', 'ms-title', ms.title), el('div', 'ms-sub', `מודולים מאומתים ${ms.verifiedModules}/${ms.requiredModules} · תנאי נוסף: ${ms.extraCondition} (${ms.extraStatus})`));
      const bar = el('div', 'bar'); const fill = el('div', 'fill'); fill.style.width = pct + '%'; bar.append(fill);
      li.append(bar);
      list.append(li);
    }
    box.append(list);
  }

  function renderItems(items) {
    const list = $('items');
    list.textContent = '';
    if (!items.length) {
      $('empty').textContent = 'עדיין אין תרגולים מתועדים.';
      $('empty').hidden = false;
      $('summary').textContent = '';
      return;
    }
    $('empty').hidden = true;
    const sorted = [...items].sort((a, b) => String(b.date).localeCompare(String(a.date)));
    const verified = items.filter((i) => i.status === 'verified').length;
    $('summary').textContent = `התרגולים שביצעתי: ${items.length} · אומתו ${verified}`;
    for (const it of sorted) {
      const li = el('li', 'item status-' + (it.status === 'verified' ? 'verified' : 'done'));
      const top = el('div', 'top');
      top.append(el('strong', '', it.topic), el('span', 'chip', it.status === 'verified' ? 'אומת' : 'נלמד'));
      li.append(top, el('div', 'date', it.date));
      if (it.note) li.append(el('p', 'note', it.note));
      list.append(li);
    }
  }

  function load() {
    fetch('api/progress.php', { cache: 'no-store', credentials: 'same-origin' })
      .then((r) => {
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
      })
      .then((data) => {
        ['state', 'error'].forEach((id) => { $(id).hidden = true; });
        $('admin-link').hidden = true;
        if (data.status === 'ok') {
          renderItems(Array.isArray(data.items) ? data.items : []);
          renderSummary(data.summary);
          renderMetrics(data.summary);
          renderForecast(data.summary);
          renderMilestones(data.summary);
          return;
        }
        show('state', MESSAGES[data.status] || MESSAGES.unavailable);
        if (data.status === 'no_token') $('admin-link').hidden = false;
      })
      .catch(() => show('error', 'לא הצלחתי לטעון את הדף.'));
  }

  function show(id, text) {
    const n = $(id);
    n.textContent = text;
    n.hidden = false;
  }

  function start() {
    load();
    clearInterval(timer);
    timer = setInterval(load, REFRESH_MS);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) start(); });
  start();
})();
