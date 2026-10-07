// שדות מספר בכל האפליקציה: חיצים (−/+) ליד כל שדה, והקשה על המספר מסמנת אותו
// כולו — כך שההקלדה הבאה מחליפה אותו בלי למחוק קודם.
//
// עובד על כל `input[type=number]` שנכנס ל-DOM (MutationObserver), בלי שכל מסך
// יצטרך לקרוא לזה. הצעד של החיצים: `data-step` אם יש, אחרת `step`, אחרת 1 —
// כך שדה במ"מ קופץ ב-10 בחיצים אבל עדיין מקבל כל מספר שלם בהקלדה. לחיצה
// ארוכה על חץ ממשיכה לקפוץ. החיצים משגרים `input` ו-`change`, כמו הקלדה.

const KEEP_SELECTION_MS = 400;

function enhance(input) {
  if (input.dataset.num || input.closest('.num')) return;
  input.dataset.num = '1';
  const wrap = document.createElement('span');
  wrap.className = 'num';
  input.replaceWith(wrap);
  wrap.append(makeBtn(input, -1), input, makeBtn(input, +1));
}

function makeBtn(input, dir) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'num__btn';
  b.tabIndex = -1;
  b.textContent = dir > 0 ? '+' : '−';
  b.setAttribute('aria-label', dir > 0 ? 'הגדלה' : 'הקטנה');
  let timer = null, interval = null;
  const stop = () => { clearTimeout(timer); clearInterval(interval); timer = interval = null; };
  b.addEventListener('pointerdown', (e) => {
    e.preventDefault();                       // לא לגנוב פוקוס מהשדה ולא להקפיץ מקלדת
    if (input.disabled || input.readOnly) return;
    step(input, dir);
    timer = setTimeout(() => { interval = setInterval(() => step(input, dir), 80); }, 450);
  });
  for (const ev of ['pointerup', 'pointerleave', 'pointercancel']) b.addEventListener(ev, stop);
  return b;
}

function step(input, dir) {
  const inc = Number(input.dataset.step) || Number(input.step) || 1;
  const cur = input.value === '' ? Number(input.placeholder) || 0 : Number(input.value);
  let next = cur + dir * inc;
  if (inc >= 1) next = Math.round(next / inc) * inc;              // ליישר לכפולה של הצעד
  else next = Math.round(next * 100) / 100;
  if (input.min !== '' && next < Number(input.min)) next = Number(input.min);
  if (input.max !== '' && next > Number(input.max)) next = Number(input.max);
  if (next === cur && input.value !== '') return;
  input.value = String(next);
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

export function watchNumbers(root = document.body) {
  root.querySelectorAll('input[type=number]').forEach(enhance);
  new MutationObserver((muts) => {
    for (const m of muts) for (const n of m.addedNodes) {
      if (n.nodeType !== 1) continue;
      if (n.matches?.('input[type=number]')) enhance(n);
      else n.querySelectorAll?.('input[type=number]').forEach(enhance);
    }
  }).observe(root, { childList: true, subtree: true });

  // הקשה על המספר מסמנת אותו כולו. הבחירה נשמרת גם כשה-mouseup/touchend
  // שאחרי הפוקוס היה מבטל אותה (ההתנהגות הרגילה של הדפדפן).
  let focusedAt = 0;
  root.addEventListener('focusin', (e) => {
    const el = e.target;
    if (!(el instanceof HTMLInputElement) || el.type !== 'number') return;
    focusedAt = Date.now();
    requestAnimationFrame(() => { if (document.activeElement === el) el.select(); });
  });
  root.addEventListener('mouseup', (e) => {
    const el = e.target;
    if (el instanceof HTMLInputElement && el.type === 'number' && Date.now() - focusedAt < KEEP_SELECTION_MS) e.preventDefault();
  });
  root.addEventListener('click', (e) => {
    // שדה שכבר בפוקוס: הקשה נוספת מסמנת שוב את הכול
    const el = e.target;
    if (el instanceof HTMLInputElement && el.type === 'number' && Date.now() - focusedAt >= KEEP_SELECTION_MS) el.select();
  });
}
