# רכיבים — סימון מוכן

כל הרכיבים משתמשים ב-`tokens.css`. דף שלם שמחבר את כולם: `../assets/starter.html`.

## תוכן
1. שלד הדף — כותרת דביקה, לשוניות, `main`
2. רשימה — שורות (`.list` / `.card`)
3. שורת רשומה לחיצה (`.use-row`) ואריחים (`.tiles`)
4. כרטיס סיכום ולשוניות בתוך מסך
5. גיליון עריכה (`dialog`) ושדות
6. בורר מקטעים, כדוריות, מתג
7. טוסט, ריק, שגיאה
8. JS קטן שצריך: `--vvh`, חזרה בתוך האפליקציה, טוסט
9. צופה קבצים בתוך האפליקציה

---

## 1. שלד הדף
```html
<!doctype html>
<html lang="he" dir="rtl">
<head>
  <meta charset="utf-8">
  <!-- resizes-content: באנדרואיד הדף מתקצר כשהמקלדת נפתחת -->
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
  <meta name="theme-color" content="#2d4a8a">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <a class="skip" href="#main">דילוג לתוכן</a>
  <header class="top">
    <button class="icon-btn" id="back" aria-label="חזרה" hidden>→</button>   <!-- ב-RTL "חזרה" מצביעה ימינה -->
    <div class="titles"><h1>שם המסך</h1><div class="sub">פרט משני</div></div>
    <button class="icon-btn" aria-label="הגדרות">⚙️</button>
  </header>
  <nav class="tabs" aria-label="לשוניות">
    <a href="#/a" aria-current="page">ראשון</a><a href="#/b">שני</a><a href="#/c">שלישי</a>
  </nav>
  <main id="main" tabindex="-1"> … </main>
</body>
</html>
```

## 2. רשימה — שורות, לא כרטיסים צפים
```html
<div class="toolbar">
  <input type="search" placeholder="חיפוש" aria-label="חיפוש">
  <button class="btn primary"><span aria-hidden="true">+</span> פרויקט</button>   <!-- פעולה עיקרית — עם מילה -->
</div>
<ul class="list">
  <li class="card" tabindex="0" role="button">
    <span class="avatar" style="--h:210" aria-hidden="true">ד״ל</span>
    <div class="body">
      <div class="l1"><span>דירת לוי</span><span class="t">10:42</span></div>
      <div class="l2">אינסטלציה<i class="sep" aria-hidden="true">·</i>בוצעו 3 מתוך 9</div>
    </div>
    <span class="chip ok">פעיל</span>
  </li>
</ul>
<p class="empty">עוד אין פרויקטים. "+ פרויקט" כדי להתחיל.</p>   <!-- מצב ריק: מה עושים עכשיו -->
```
- `.l1` שורה אחת (… כשארוך), `.t` — זמן בקצה. `.l2` — אפור, עם `·` מפריד.
- `--h` לאווטאר — גוון מתוך השם (hash → 0–359), כך שכל אחד מקבל צבע קבוע.

## 3. שורת רשומה ואריחים
```html
<ul class="list">
  <li><button class="use-row" type="button">
    <span class="use-ic" aria-hidden="true">🧾</span>
    <span class="use-tx"><b>הצעת מחיר</b><small>אבי חשמל · חשמל</small></span>
    <span class="use-go" aria-hidden="true">‹</span>
  </button></li>
</ul>

<p class="hint">לשייך את הקובץ ל…</p>
<div class="tiles">
  <button class="tile" type="button"><span class="tile-ic" aria-hidden="true">🧾</span>הצעת מחיר</button>
  <button class="tile" type="button"><span class="tile-ic" aria-hidden="true">📝</span>חוזה עם ספק</button>
</div>
```

## 4. סיכום קודם, ולשוניות למסך ארוך
```html
<div class="seg-tabs" role="tablist">
  <button role="tab" aria-selected="true">📊 סיכום</button>
  <button role="tab" aria-selected="false">👷 ספקים <small>4</small></button>
  <button role="tab" aria-selected="false">💳 אבני דרך <small>7</small></button>
</div>

<div class="summary">
  <div class="line"><span class="muted">התקבל</span> <b>₪120,000</b> <span class="muted">מתוך ₪684,308</span><span class="pct">18%</span></div>
  <span class="track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="18"><span style="inline-size:18%"></span></span>
  <div class="chips"><span class="chip warn">2 לאישור</span><span class="chip bad">1 באיחור</span></div>
</div>

<dl class="cells">
  <div><dt>סוכם</dt><dd>₪684,308</dd></div>
  <div><dt>שולם</dt><dd>₪120,000</dd></div>
</dl>
```
- הלשונית האחרונה שנבחרה — ב-`sessionStorage`, כדי שחזרה למסך לא תאפס.

## 5. גיליון עריכה
```html
<dialog id="edit-dlg" aria-labelledby="edit-title">
  <form method="dialog" novalidate>
    <header class="sheet-head">
      <button type="button" class="icon-btn" data-close aria-label="סגירה">✕</button>
      <h2 id="edit-title">עריכה</h2>
      <button type="submit" class="btn primary">שמירה</button>          <!-- תמיד בהישג יד -->
    </header>
    <div class="sheet-body">
      <div class="fields">
        <label class="f wide"><span>שם *</span><input name="name" required maxlength="120"></label>
        <label class="f"><span>טלפון</span><input name="phone" type="tel" dir="ltr" inputmode="tel"></label>
        <label class="f"><span>סכום (₪)</span><input name="amount" inputmode="decimal" dir="ltr"></label>
      </div>
      <label class="switch"><input type="checkbox" name="visible"><span>גלוי ללקוח</span></label>
      <section class="group" aria-labelledby="g2"><h3 id="g2">עוד <small>אופציונלי</small></h3> … </section>
      <p class="error" role="alert" hidden></p>
    </div>
  </form>
</dialog>
```
- בטלפון (<600px) — גיליון מלא; במחשב — חלון באמצע. הגוף נגלל, הכותרת לא.
- מספרים, טלפונים, מיילים — `dir="ltr"` ו-`inputmode` מתאים.
- עריכה של פריט קיים — כפתור ✏️ (אייקון), לא "עריכת…".

## 6. בורר מקטעים, כדוריות, מתג
```html
<fieldset class="seg" aria-label="מתי משלמים">
  <label><input type="radio" name="due" value="date" checked><span>בתאריך</span></label>
  <label><input type="radio" name="due" value="stage"><span>בגמר השלב</span></label>
  <label><input type="radio" name="due" value="pct"><span>בהתקדמות</span></label>
</fieldset>
<!-- הרבה אפשרויות: <fieldset class="seg grid" style="--cols:4"> — לא לתת להן לשבור שורה -->

<div class="chips">
  <label><input type="checkbox" value="1" checked><span>אינסטלציה</span></label>
  <label><input type="checkbox" value="2"><span>חשמל</span></label>
</div>
```

## 7. טוסט, ריק, שגיאה
```html
<div class="toast" role="status" hidden></div>
<p class="empty">התיקייה ריקה. "+ העלאה" או גרירה לכאן.</p>
<p class="error" role="alert">הקובץ גדול מ-100MB</p>
```

## 8. JS קטן שצריך
```js
// --vvh: הגובה הנראה בפועל (בלי המקלדת) — הגיליון מתקצר והכול נגלל, גם באייפון
(() => { const vv = window.visualViewport; if (!vv) return;
  const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
  vv.addEventListener('resize', set); set(); })();

// טוסט
function toast(msg, err = false) {
  const el = document.querySelector('.toast'); el.textContent = msg; el.classList.toggle('err', err); el.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}

// חזרה בתוך האפליקציה: למסך הקודם, עד הראשי — ובראשי אין חץ (לא יוצאים מהאפליקציה)
const back = document.getElementById('back');
function setBack(isHome) { back.hidden = isHome; }
back.addEventListener('click', () => history.length > 1 ? history.back() : (location.hash = '#/'));

// סגירת חלון מכפתור ✕
document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });
```

## 9. צפייה בקבצים בתוך האפליקציה
המימוש המלא: `project-manager/assets/js/viewer.js` במאגר `mini_projects` (PDF ב-pdf.js מקומי
ב-`assets/vendor/pdfjs`, נטען רק כשצריך; תמונה; סרטון). מה לקחת ממנו:
- `Viewer.mount(box, file)` — צופה בתוך חלון: עמוד בקנבס, ‹ › "עמוד X מתוך N", − 100% +, ⛶.
- `Viewer.open(file)` — מסך מלא בתוך האפליקציה (dialog על כל המסך) עם ✕ ו-⬇️.
- לוכד קליקים על קישורי קבצים → נפתחים בצופה, לא בלשונית חדשה.
- צביטה: `touch-action: pan-x pan-y` על החלון + `preventDefault` בתנועת שתי אצבעות — מגדיל רק את
  המסמך, לא את האתר. בהגדלה גוללים בעמוד; בלי — החלקה מחליפה עמוד; לחיצה כפולה — זום.
- הבמה ב-`direction: ltr` (חשבון גלילה פשוט גם בעברית); קנבס עד ~16 מיליון פיקסלים (זיכרון בטלפון).
