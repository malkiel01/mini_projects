/* שכבת ה-UI קוראת רק את ה-API של האפליקציה — לעולם לא את GitHub ישירות. */
'use strict';
window.CA = window.CA || {};
CA.api = {
  /** @returns {Promise<{status:number, body:object}>} */
  async snapshot() {
    // ?demo=empty — תצוגת המצב הריק (רק במקור הדוגמה; השרת מתעלם ממנו אחרת)
    const demo = new URLSearchParams(location.search).get('demo') === 'empty' ? '?demo=empty' : '';
    let res;
    try {
      res = await fetch('api/snapshot.php' + demo, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
    } catch (e) {
      return { status: 0, body: { ok: false, error: 'אין חיבור לשרת' } };
    }
    let body;
    try { body = await res.json(); } catch (e) { body = { ok: false, error: 'תשובה לא צפויה מהשרת (' + res.status + ')' }; }
    return { status: res.status, body };
  },
};
