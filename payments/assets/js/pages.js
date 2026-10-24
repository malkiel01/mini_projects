'use strict';
// דפי הלקוח (תשלום, "החשבון שלי"): אישור לפני פעולה רגישה — כפתור עם data-confirm.
document.addEventListener('click', e => {
  const b = e.target.closest('[data-confirm]');
  if (b && !confirm(b.dataset.confirm)) e.preventDefault();
});
