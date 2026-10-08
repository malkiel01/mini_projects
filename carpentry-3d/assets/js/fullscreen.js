// מסך מלא לתצוגת התלת מימד — משותף לאפליקציה ולדף הצפייה של הלקוח.
//
// קודם מנסים מסך מלא אמיתי של הדפדפן (Fullscreen API). איפה שאין (אייפון
// מאפשר אותו רק לווידאו) או שהבקשה נדחתה — "מסך מלא" ב-CSS: המכל נפרש על
// כל החלון מעל כל השאר (המחלקה is-fs). בשני המקרים המחלקה is-fs על המכל,
// כדי שה-CSS יסתיר את מה שלא צריך (פאנל החלק, הסיכום) ויפנה מקום לתלת מימד.
// Esc וכפתור החזרה של הטלפון יוצאים. מי שמנהל את ההיסטוריה בעצמו קורא קודם
// ל-handlePop() ב-popstate שלו: true = הצעד הזה היה של המסך המלא, לא לטפל בו.

/**
 * @param target  האלמנט שנפרש
 * @param button  הכפתור שמחליף מצב
 * @param onChange(on) נקרא אחרי כל שינוי (למשל כדי להתאים את המצלמה)
 */
export function wireFullscreen(target, button, onChange = () => {}) {
  let pseudo = false, expectPop = false;
  const native = () => document.fullscreenElement === target || document.webkitFullscreenElement === target;
  const isOn = () => native() || pseudo;
  const sync = () => {
    const on = isOn();
    target.classList.toggle('is-fs', on);
    document.body.classList.toggle('has-fs', on);
    button.classList.toggle('is-on', on);
    button.textContent = on ? '✕' : '⛶';
    button.title = on ? 'יציאה ממסך מלא (Esc)' : 'מסך מלא';
    button.setAttribute('aria-label', button.title);
    onChange(on);
  };
  async function enter() {
    const req = target.requestFullscreen || target.webkitRequestFullscreen;
    if (req) {
      try { await req.call(target, { navigationUI: 'hide' }); return; } catch { /* נדחה — ממשיכים ל-CSS */ }
    }
    pseudo = true;
    history.pushState({ ...(history.state || {}), fs: true }, '');
    sync();
  }
  async function exit() {
    if (native()) { const ex = document.exitFullscreen || document.webkitExitFullscreen; try { await ex.call(document); } catch { /* כבר יצא */ } return; }
    if (pseudo) { pseudo = false; sync(); if (history.state?.fs) { expectPop = true; history.back(); } }
  }
  button.addEventListener('click', () => (isOn() ? exit() : enter()));
  document.addEventListener('fullscreenchange', sync);
  document.addEventListener('webkitfullscreenchange', sync);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && pseudo) exit(); });
  /** לקרוא מתוך popstate. מחזיר true אם הצעד שייך למסך המלא (כבר טופל). */
  function handlePop() {
    if (expectPop) { expectPop = false; return true; }
    if (pseudo) { pseudo = false; sync(); return true; }
    return false;
  }
  sync();
  return { enter, exit, isOn, handlePop };
}
