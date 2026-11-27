/* ניהול אינטגרציות — למנהל בלבד. מדבר רק עם api/integrations.php. לעולם לא מציג, לא שומר ולא מדפיס את האסימון.
   כל הודעה נכתבת כטקסט בלבד (textContent), לא כ-HTML. השדה מתנקה מיד אחרי שמירה מוצלחת. */
'use strict';
(function () {
  const $ = (id) => document.getElementById(id);
  const API = 'api/integrations.php';

  function say(el, text, kind) {
    el.textContent = text;
    el.className = 'note' + (kind ? ' ' + kind : '');
  }

  /** קריאה ל-API. מחזיר {status, body}. בלי רישום של גוף הבקשה. */
  async function call(payload) {
    const init = { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (payload) {
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(payload);
    }
    let res;
    try {
      res = await fetch(API, init);
    } catch (e) {
      return { status: 0, body: { ok: false, code: 'offline' } };
    }
    let body;
    try { body = await res.json(); } catch (e) { body = { ok: false, code: 'internal' }; }
    return { status: res.status, body };
  }

  /** לא מנהל: הטופס והכפתורים מוסרים מהדף, לא רק מוסתרים. */
  function showDenied() {
    $('panel').remove();
    $('denied').hidden = false;
  }

  function renderStatus(body) {
    if (!body.pathConfigured) {
      say($('token-state'), 'נתיב אחסון האסימון לא הוגדר בשרת. מנהל המערכת צריך להגדיר אותו לפני השמירה.', 'bad');
      $('save').disabled = true;
      return;
    }
    $('save').disabled = false;
    if (body.tokenStored) say($('token-state'), 'אסימון שמור בשרת.', 'ok');
    else say($('token-state'), 'אין אסימון שמור בשרת.', '');
  }

  async function load() {
    const r = await call(null);
    if (r.status === 403) return showDenied();
    $('panel').hidden = false;
    if (r.status !== 200 || !r.body.ok) {
      say($('token-state'), 'לא הצלחנו לקרוא את סטטוס האינטגרציות. נסו שוב מאוחר יותר.', 'bad');
      $('save').disabled = true;
      return;
    }
    renderStatus(r.body);
  }

  const SAVE_ERR = {
    token_invalid: 'האסימון אינו בפורמט תקין: לפחות 20 תווים, בלי רווחים.',
    not_configured: 'נתיב אחסון האסימון לא הוגדר בשרת.',
    forbidden: 'למנהל בלבד, או שהבקשה ממקור לא מורשה.',
    offline: 'אין חיבור לשרת.',
  };

  $('token-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const field = $('token');
    const token = field.value.trim();
    if (!token) return say($('save-msg'), 'נא להזין אסימון.', 'bad');
    $('save').disabled = true;
    const r = await call({ action: 'save_token', token });
    // השדה מתנקה מיד, בלי קשר לתוצאה — האסימון לא נשאר בדף
    field.value = '';
    $('save').disabled = false;
    if (r.status === 200 && r.body.ok) {
      say($('save-msg'), 'האסימון נשמר בשרת.', 'ok');
      const s = await call(null);
      if (s.status === 200 && s.body.ok) renderStatus(s.body);
      return;
    }
    if (r.status === 403 && r.body.code === 'forbidden') return showDenied();
    say($('save-msg'), SAVE_ERR[r.body.code] || 'השמירה נכשלה. נסו שוב.', 'bad');
  });

  $('sync').addEventListener('click', async () => {
    say($('sync-msg'), 'מסנכרן…', '');
    const r = await call({ action: 'sync' });
    if (r.status === 403) return showDenied();
    if (r.status === 200 && r.body.status === 'no_source_connected') {
      return say($('sync-msg'), 'אין מקור דיווחים מחובר. לא בוצע סנכרון.', 'bad');
    }
    if (r.status === 501) return say($('sync-msg'), 'מקורות נתונים עדיין לא נתמכים.', 'bad');
    if (r.status === 0) return say($('sync-msg'), 'אין חיבור לשרת.', 'bad');
    say($('sync-msg'), 'הסנכרון נכשל. נסו שוב מאוחר יותר.', 'bad');
  });

  load();
})();
