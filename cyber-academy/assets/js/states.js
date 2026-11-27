/* מצבים שאינם נתונים: טעינה ושגיאות. code יציב מהשרת (lib/api.php) או מהלקוח (offline). */
'use strict';
window.CA = window.CA || {};
(function () {
  const { esc } = CA.ui;

  const STATES = {
    forbidden:           { icon: '🔒', title: 'אין הרשאה לדשבורד', what: 'החשבון שלך בפלטפורמה לא מורשה לאפליקציה הזו, או שההתחברות פגה.', todo: 'התחברו מחדש. אם זה חוזר — בקשו מהמנהל אישור ישיר לאפליקציה בממשק המנהל.' },
    source_disabled:     { icon: '🔌', title: 'החיבור למאגר האקדמיה כבוי', what: 'המקור "מאגר האקדמיה" כבוי בשלב הזה — אין חיבור לנתונים אישיים לפני שפרטיות המאגר אומתה.', todo: 'אין מה לעשות כאן. הפעלה — רק בהחלטת הבעלים, בעדכון של הדשבורד.' },
    source_unauthorized: { icon: '🔑', title: 'אין גישה למאגר האקדמיה', what: 'האסימון חסר, לא תקף (פג או בוטל), או שאין לו הרשאת קריאה למאגר.', todo: 'מנהל המערכת מחליף אסימון fine-grained עם Contents: Read למאגר האקדמיה בלבד, בהגדרות השרת.' },
    source_not_found:    { icon: '🔍', title: 'המאגר או קובץ הנתונים לא נמצאו', what: 'הנתיב או המאגר לא קיימים. במאגר פרטי GitHub עונה "לא נמצא" גם כשאין לאסימון גישה.', todo: 'מנהל המערכת בודק את שם המאגר, הענף והנתיב בהגדרות השרת, ואת הרשאות האסימון.' },
    source_unavailable:  { icon: '🌐', title: 'מאגר האקדמיה לא זמין כרגע', what: 'תקלת רשת, שגיאה בצד GitHub, או שנגמרה מכסת הפניות.', todo: 'נסו שוב בעוד כמה דקות.' },
    contract_invalid:    { icon: '📄', title: 'הנתונים לא תואמים לחוזה', what: 'ה-snapshot לא עומד בחוזה v1, ולכן לא מוצג — כדי לא להציג מצב שגוי.', todo: 'מי שמפיק את ה-snapshot באקדמיה מתקן לפי רשימת השגיאות.' },
    contract_version:    { icon: '🔢', title: 'גרסת חוזה לא נתמכת', what: 'ה-snapshot נכתב בגרסת חוזה שהדשבורד עוד לא מכיר.', todo: 'צריך לעדכן את הדשבורד לחוזה החדש. עד אז לא מוצג דבר.' },
    offline:             { icon: '📡', title: 'אין חיבור לשרת', what: 'הבקשה לא הגיעה לשרת של הדשבורד.', todo: 'בדקו את החיבור לאינטרנט ונסו שוב.' },
    internal:            { icon: '⚠️', title: 'שגיאה בשרת', what: 'השרת לא הצליח להכין את תמונת המצב.', todo: 'נסו שוב. אם זה חוזר — דווחו למפתח.' },
  };

  /** תשובה גולמית → code. השרת קובע; כשאין code (שער, HTML, רשת) — הלקוח מסווג. */
  function codeOf(r) {
    if (r.status === 0) return 'offline';
    const b = r.body || {};
    if (b.code && STATES[b.code]) return b.code;
    if (r.status === 401 || r.status === 403) return 'forbidden';
    return 'internal';
  }

  // "$.path: הודעה" — הנתיב באנגלית בבידוד, ההודעה בעברית מימין לשמאל
  const errLine = (e) => {
    const m = /^(\$[^:]*): ([\s\S]*)$/.exec(String(e));
    return m ? `<li><bdi dir="ltr">${esc(m[1])}</bdi> — ${esc(m[2])}</li>` : `<li>${esc(e)}</li>`;
  };

  function errorView(r) {
    const code = codeOf(r);
    const s = STATES[code];
    const b = r.body || {};
    const errs = code === 'contract_invalid' && Array.isArray(b.errors) ? b.errors : [];
    return { title: s.title, html: `
      <div class="state" role="alert" data-state="${code}">
        <div class="state-ic" aria-hidden="true">${s.icon}</div>
        <h2>${esc(s.title)}</h2>
        <p>${esc(s.what)}</p>
        ${b.error && code !== 'internal' ? `<p class="state-detail">${esc(b.error)}</p>` : ''}
        ${errs.length ? `<ul class="errors">${errs.map(errLine).join('')}</ul>${b.errorCount > errs.length ? `<p class="muted">ועוד ${b.errorCount - errs.length}…</p>` : ''}` : ''}
        <p class="state-todo"><b>מה עושים:</b> ${esc(s.todo)}</p>
        <button type="button" class="btn primary" data-retry>⟳ נסה שוב</button>
      </div>` };
  }

  const skeleton = () => `<div class="skel" aria-busy="true" aria-label="טוען את תמונת המצב">
      <div class="measures big"><div class="sk sk-card"></div><div class="sk sk-card"></div></div>
      <div class="sk sk-block"></div>
      <div class="sk sk-line"></div><div class="sk sk-row"></div><div class="sk sk-row"></div><div class="sk sk-row"></div>
    </div>`;

  CA.states = { STATES, codeOf, errorView, skeleton };
})();
