'use strict';
// תוסף התשלומים — שלד: לשוניות לפי הכתובת (#), ורשימת השלבים מתוך האפיון (docs/spec/11-roadmap.md).

const STAGES = [
  ['שלד', 'רישום, מסך, מסד ריק, החרגה בפריסה', 'now'],
  ['ליבה בלי כסף', 'מערכות וקודים, לקוחות, מוצרים ומחירים, זכאות, שימוש, ארנק, סולק מדומה', ''],
  ['דף תשלום ו"החשבון שלי"', 'מנויים חודשיים ושנתיים, ניסיון, מבצעים, ביטול, הודעות לפי החוק', ''],
  ['סולק אמיתי ראשון', 'Morning והסליקה שלה, בסביבת בדיקות', ''],
  ['חשבוניות', 'Morning — קבלות, חשבוניות מס/קבלה, זיכויים', ''],
  ['ספריות, קישורים וכללים', 'קודי גישה, קישורי תשלום, כפתור, מנוע הכללים, תיעוד', ''],
  ['מנויים מאושרים', 'אזור ניהול לכל מי שהמנהל מאשר', ''],
  ['מגדלור מתחבר', 'האפליקציה הראשונה, בצ\'אט שלה', ''],
  ['עלייה לאוויר', 'תשלום אמיתי קטן והחזר, בדיקה משפטית', ''],
  ['התרחבות', 'סולקים ואפליקציות נוספים, ביט, הוראת קבע, דוחות', ''],
];

const list = document.getElementById('stages');
STAGES.forEach(([title, sub, state], i) => {
  const li = document.createElement('li');
  li.className = 'card';
  li.innerHTML = `<span class="stage-n" aria-hidden="true">${i + 1}</span>
    <div class="body"><div class="l1"><span></span></div><div class="l2"></div></div>
    ${state === 'now' ? '<span class="chip warn">עכשיו</span>' : ''}`;
  li.querySelector('.l1 span').textContent = title;
  li.querySelector('.l2').textContent = sub;
  list.append(li);
});

function show() {
  const id = (location.hash || '#overview').slice(1);
  const target = document.getElementById('p-' + id) ? id : 'overview';
  for (const p of document.querySelectorAll('.panel')) p.hidden = p.id !== 'p-' + target;
  for (const a of document.querySelectorAll('.tabs a')) {
    if (a.getAttribute('href') === '#' + target) a.setAttribute('aria-current', 'page');
    else a.removeAttribute('aria-current');
  }
}
addEventListener('hashchange', show);
show();
