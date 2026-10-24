/*
 * כפתור תשלום לכל אתר (פרק 12.6). שתי שורות:
 *   <script src="https://mbe-plus.com/mini_projects/payments/assets/embed.js" async></script>
 *   <a class="mbe-pay" href="https://mbe-plus.com/mini_projects/payments/pay/link.php?k=XXXXXXXXXX">לתשלום</a>
 * הכפתור פותח את קישור התשלום בחלון קטן (בטלפון — באותו חלון). אין כאן קוד סודי ואין סכום — הכול נקבע בקישור.
 * החלטות (מה שולם) — רק מההודעה החתומה לשרת שלך, לא מהדפדפן.
 */
(function () {
  'use strict';
  var css = '.mbe-pay{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:0 20px;border-radius:10px;'
    + 'background:#2d4a8a;color:#fff!important;font:600 16px/1 system-ui,-apple-system,"Segoe UI",Arial,sans-serif;text-decoration:none!important;cursor:pointer}'
    + '.mbe-pay:hover{filter:brightness(1.1)}.mbe-pay:focus-visible{outline:3px solid #9db4e8;outline-offset:2px}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  function ok(href) { try { var u = new URL(href, location.href); return u.protocol === 'https:' && /\/pay\/link\.php$/.test(u.pathname); } catch (e) { return false; } }
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a.mbe-pay');
    if (!a || !ok(a.href) || window.innerWidth < 700) return; // בטלפון — ניווט רגיל
    e.preventDefault();
    var w = 460, h = 760, x = Math.max(0, (screen.width - w) / 2), y = Math.max(0, (screen.height - h) / 2);
    var win = window.open(a.href, 'mbe-pay', 'width=' + w + ',height=' + h + ',left=' + x + ',top=' + y);
    if (!win) location.href = a.href; // חוסם חלונות — ניווט רגיל
  });
})();
