/*
 * 🛡️ ערכת ההתקפה מול האתר (פרוסה M2; 07 §6, H1–H15). רצה **מתוך החבילה**, כפי שהתוסף מגיש אותה בכתובת של העמוד.
 *
 * אותן בדיקות של ה-probe בערכה של התצוגה החיה (assets/js/attack-kit.js, F4 — הקובץ ההוא לקריאה בלבד; זה עותק מותאם):
 * עוגייה, אחסון, מסמך ההורה, ניווט הלשונית, ובקשה עם העוגיות של המחובר — כאן מול נקודות של וורדפרס במקום api.php.
 * מה שאי אפשר לראות מבפנים (H5, H6 בכתובת ישירה, H7, H10, H14, ומה שהשרת קיבל ב-H2/H3/H15) — נבדק מבחוץ
 * (tests/browser/m2.cjs, ובאתר — הבודק, לפי הטבלה שבעמוד).
 *
 * התוצאה: טבלה ✓ / ✗ בעמוד, ושורה אחת במסוף בפורמט קבוע:  KOLKIT2 {"framed":…,"checks":[{"id","name","ok","text"}]}
 */
'use strict';
(function () {
  const checks = [];
  const add = (id, name, ok, text) => checks.push({ id, name, ok: !!ok, text: String(text) });
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const timeout = (p, ms, v) => Promise.race([p, wait(ms).then(() => v)]);
  let framed = true;
  try { framed = self !== top; } catch { framed = true; }

  async function run() {
    // I1 / H11 — מקור אטום, גם כשהקובץ נפתח ישירות בלשונית משלו
    add('I1', 'מקור אטום (ארגז חול בכותרת)', self.origin === 'null', 'origin = ' + self.origin);

    // H1 — אחסון של האתר
    let v;
    try { v = document.cookie; add('H1', 'document.cookie — חריגה', false, 'נקרא: ' + JSON.stringify(v).slice(0, 80)); } catch (e) { add('H1', 'document.cookie — חריגה', true, e.name); }
    try { localStorage.getItem('x'); add('H1', 'localStorage — חריגה', false, 'נקרא'); } catch (e) { add('H1', 'localStorage — חריגה', true, e.name); }
    try { sessionStorage.getItem('x'); add('H1', 'sessionStorage — חריגה', false, 'נקרא'); } catch (e) { add('H1', 'sessionStorage — חריגה', true, e.name); }
    const idb = await timeout(new Promise(res => {
      try { const q = indexedDB.open('kolkit'); q.onsuccess = () => { try { q.result.close(); } catch { /* */ } res('open'); }; q.onerror = () => res('error'); } catch (e) { res('throw'); }
    }), 3000, 'timeout');
    add('H1', 'indexedDB — חריגה או שגיאה', idb !== 'open', idb);

    // H2 — /wp-json/wp/v2/users/me עם העוגיות: לעולם לא נתוני מחובר
    try {
      const r = await fetch('/wp-json/wp/v2/users/me', { credentials: 'include' });
      const t = await r.text();
      let j = null; try { j = JSON.parse(t); } catch { /* */ }
      const logged = r.ok && j && +j.id > 0;
      add('H2', 'users/me עם credentials — אנונימי או חסום', !logged, r.status + ' · ' + (logged ? 'נתוני מחובר!' : 'אנונימי'));
    } catch (e) { add('H2', 'users/me עם credentials — אנונימי או חסום', true, 'נחסם (' + e.name + ')'); }

    // H3 — admin-ajax.php עם פעולה שדורשת התחברות
    try {
      const body = new URLSearchParams({ action: 'heartbeat', 'data[wp-auth-check]': 'true', interval: '60' });
      const r = await fetch('/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'include', body });
      const t = await r.text();
      const logged = /ADMIN-AJAX-LOGGED-IN|"wp-auth-check":true/.test(t);
      add('H3', 'admin-ajax.php כמחובר — נכשל', !logged, r.status + ' · ' + (logged ? 'עבר כמחובר!' : 'לא מחובר'));
    } catch (e) { add('H3', 'admin-ajax.php כמחובר — נכשל', true, 'נחסם (' + e.name + ')'); }

    // H4 — המסמך שמסביב, ניווט הלשונית, החלון שפתח אותנו
    if (framed) {
      try { void parent.document.title; add('H4', 'parent.document — חריגה', false, 'נקרא!'); } catch (e) { add('H4', 'parent.document — חריגה', true, e.name); }
      try { top.location = '/__kolkit-pwned/'; add('H4', 'top.location = … — נחסם', false, 'הוצב'); } catch (e) { add('H4', 'top.location = … — נחסם', true, e.name); }
    } else {
      add('H4', 'parent.document / top.location', true, 'העמוד הוא המסמך העליון (M-A) — אין מסמך של האתר סביבו');
    }
    if (window.opener) {
      try { void window.opener.document.title; add('H4', 'window.opener.document — חריגה', false, 'נקרא!'); } catch (e) { add('H4', 'window.opener.document — חריגה', true, e.name); }
      try { window.opener.location = '/__kolkit-pwned/'; add('H4', 'window.opener.location = … — נחסם', false, 'הוצב'); } catch (e) { add('H4', 'window.opener.location = … — נחסם', true, e.name); }
    } else {
      add('H4', 'window.opener', true, 'אין');
    }

    // H15 — טופס ששולח לאתר (נבדק בצד השרת: הגיע בלי עוגיות), וקישור target=_top
    const sink = document.createElement('iframe');
    sink.name = 'kolkit-sink';
    sink.style.cssText = 'width:1px;height:1px;border:0;position:absolute;left:-9999px';
    document.body.append(sink);
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/wp-admin/admin-ajax.php';
    form.target = 'kolkit-sink';
    form.innerHTML = '<input name="action" value="kolkit_h15"><input name="kolkit" value="form">';
    document.body.append(form);
    try { form.submit(); add('H15', 'טופס לאתר — נשלח כבקשה של אתר זר', true, 'נשלח; העוגיות — בצד השרת'); } catch (e) { add('H15', 'טופס לאתר', true, 'נחסם (' + e.name + ')'); }
    form.remove();
    if (framed) {
      const a = document.createElement('a');
      a.href = '/__kolkit-pwned/';
      a.target = '_top';
      document.body.append(a);
      let err = 'לא נזרק';
      try { a.click(); } catch (e) { err = e.name; }
      a.remove();
      await wait(400);
      add('H15', 'קישור target=_top — הניווט העליון נחסם', true, err + ' · העמוד שמסביב — נבדק מבחוץ');
    }

    // H5 — סגנון שמסתיר הכול ושכבה מעל הכול: בתוך העמוד בלבד. "שאר האתר ללא שינוי" — נבדק מבחוץ
    const st = document.createElement('style');
    st.textContent = '*{display:none!important}';
    const cover = document.createElement('div');
    cover.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:#f0f';
    document.head.append(st);
    document.documentElement.append(cover);
    await wait(150);
    st.remove();
    cover.remove();
    add('H5', 'סגנון עוין ושכבה מעל הכול — בתוך העמוד בלבד', true, 'הוזרקו והוסרו; שאר האתר — נבדק מבחוץ');

    await wait(500);                        // שהטופס יגיע לשרת
    render();
    console.log('KOLKIT2 ' + JSON.stringify({ framed, opener: !!window.opener, checks }));
  }

  function render() {
    const t = document.getElementById('kit-table');
    const ok = checks.every(c => c.ok);
    document.getElementById('kit-sum').textContent = (ok ? '✓ כל ' : '✗ נכשלו ' + checks.filter(c => !c.ok).length + ' מתוך ') + checks.length + ' הבדיקות שבתוך העמוד'
      + (framed ? ' · בתוך מסגרת' : ' · מסמך עליון');
    for (const c of checks) {
      const tr = document.createElement('tr');
      tr.className = c.ok ? 'ok' : 'bad';
      for (const s of [c.ok ? '✓' : '✗', c.id, c.name, c.text]) { const td = document.createElement('td'); td.textContent = s; tr.append(td); }
      t.append(tr);
    }
  }

  run().catch(e => { add('RUN', 'הריצה עצמה', false, e && e.message); render(); console.log('KOLKIT2 ' + JSON.stringify({ framed, checks })); });
})();
