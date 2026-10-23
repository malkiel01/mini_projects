package com.mbeplus.guardedbrowser

/**
 * כלים לדפדפן — "תוספים" של מגדלור (בעל המערכת, 2026-10-23).
 *
 *   תוספים של Chrome (קובצי CRX, ‏chrome.* API) לא נטענים ב-WebView של אנדרואיד — זה מנוע אחר, בלי מערכת
 *   הרחבות. לכן הכלים כאן הם של מגדלור: כל כלי הוא סקריפט שמוזרק לדף שעל המסך, לפי בקשת המשתמש, ונכנס לתפריט ⋮.
 *   כלי חדש = רשומה חדשה ברשימה.
 *
 *   כלל: כלי לא פותח דבר שהמדיניות סוגרת. הסקריפט רץ בדף שכבר אושר, וכל משאב שהוא טוען עובר ב-
 *   shouldInterceptRequest כמו כל משאב אחר; חריג יחיד — שרתי התרגום (BrowserMath.isTranslateHost), כמשאב בלבד.
 */
object BrowserTools {

    data class Tool(val id: String, val label: String, val js: String, val needsTranslateHosts: Boolean = false)

    /** תרגום הדף לעברית — Google Translate Website Translator, בלי לצאת מהדף ובלי פרוקסי. */
    private val TRANSLATE_JS = """
        (function(){
          try {
            if (window.__mgTr) { var s0 = document.querySelector('select.goog-te-combo'); if (s0) { s0.value = 'iw'; s0.dispatchEvent(new Event('change')); } return; }
            window.__mgTr = 1;
            var d = document.createElement('div'); d.id = 'mg_translate'; d.style.display = 'none'; document.body.appendChild(d);
            window.mgTrInit = function () {
              new google.translate.TranslateElement({ pageLanguage: 'auto', includedLanguages: 'iw', autoDisplay: false }, 'mg_translate');
              var t = setInterval(function () {
                var s = document.querySelector('select.goog-te-combo');
                if (s && s.options.length) { s.value = 'iw'; s.dispatchEvent(new Event('change')); clearInterval(t); }
              }, 300);
              setTimeout(function () { clearInterval(t); }, 15000);
            };
            var sc = document.createElement('script');
            sc.src = 'https://translate.google.com/translate_a/element.js?cb=mgTrInit';
            sc.onerror = function () { try { GBMedia.note('translate', 'load_failed'); } catch (e) {} };
            document.head.appendChild(sc);
          } catch (e) { try { GBMedia.note('translate', String(e)); } catch (e2) {} }
        })()
    """.trimIndent()

    /** מצב כהה לדף — היפוך צבעים, בלי להפוך תמונות ווידאו. לחיצה שנייה מחזירה. */
    private val DARK_JS = """
        (function(){
          var id = 'mg_dark', old = document.getElementById(id);
          if (old) { old.remove(); return; }
          var st = document.createElement('style'); st.id = id;
          st.textContent = 'html{filter:invert(1) hue-rotate(180deg);background:#fff}' +
            'img,video,picture,canvas,iframe,svg image{filter:invert(1) hue-rotate(180deg)}';
          document.documentElement.appendChild(st);
        })()
    """.trimIndent()

    val ALL = listOf(
        Tool("translate", "🌐 תרגום לעברית", TRANSLATE_JS, needsTranslateHosts = true),
        Tool("dark", "🌙 מצב כהה לדף", DARK_JS),
    )
}
