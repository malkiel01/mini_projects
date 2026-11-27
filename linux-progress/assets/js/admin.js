/* מסך הגדרות: שמירת הסוד בשרת בלבד. השדה מתנקה מיד אחרי שמירה, והסוד לא נשמר בדפדפן ולא נרשם. */
(function () {
  'use strict';

  const API = 'api/progress.php';
  const MSG = {
    saved: 'הסוד נשמר בשרת.',
    token_invalid: 'הסוד אינו בפורמט תקין: לפחות 20 תווים, בלי רווחים.',
    forbidden: 'אין הרשאה לשמור.',
    not_configured: 'שמירת הסוד נכשלה בשרת.',
    error: 'שמירה נכשלה. נסה שוב מאוחר יותר.',
  };

  const field = document.getElementById('token');
  const msg = document.getElementById('save-msg');

  function say(text, ok) {
    msg.textContent = text;
    msg.hidden = false;
    msg.style.color = ok ? '' : '#b3261e';
  }

  document.getElementById('token-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const token = field.value.trim();
    if (!token) return say('נא להזין סוד.', false);
    let res;
    try {
      res = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_token', token }),
      });
    } catch (_) {
      field.value = '';
      return say(MSG.error, false);
    }
    field.value = '';
    const body = await res.json().catch(() => ({}));
    if (res.ok && body.ok) return say(MSG.saved, true);
    say(MSG[body.code] || MSG.error, false);
  });
})();
