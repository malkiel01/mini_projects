# מסמך העברה מלא — אפליקציית קטלוג ספרים יהודיים

> **מטרת המסמך:** להעביר את כל ההקשר, ההחלטות והקוד שנוצרו עד כה לצ'אט/סביבה חדשה.
> אם אתה מודל שקורא את המסמך הזה — זהו המפרט המחייב. כל ההחלטות למטה כבר סוכמו עם הלקוח ואין צורך לשאול עליהן שוב.

---

## 1. תיאור הפרויקט

אפליקציית **קטלוג ספרים** לקהילה היהודית, המשלבת:

- **צפייה בספרים דיגיטליים אונליין** — חלקם חינמיים, חלקם בתשלום
- **חנות ספרים** — ספרים פיזיים שניתן לראות עליהם תסקיר ולרכוש/להזמין
- **תכנים דינמיים יומיים** — הלכה יומית, חוק לישראל, זמנים
- **אזור ניהול** — הבעלים מנהל את כל התוכן והנתונים בצורה דינמית

---

## 2. החלטות טכניות סופיות

| רכיב | החלטה | הערות |
|------|--------|-------|
| פלטפורמה | **PWA** | Android + iOS + Web, מ-codebase אחד |
| Backend | **PHP / Laravel 10** | **חשוב:** נבחר PHP ולא Python בגלל תמיכה מוגבלת של Bluehost ב-Python |
| Database | **MySQL 8.0+** | utf8mb4_unicode_ci חובה לעברית |
| Hosting | **Bluehost** (cPanel) | אילוץ מרכזי שהכתיב את בחירת PHP |
| עורך תוכן | **TinyMCE 6** | ספרייה קיימת עם תמיכה מצוינת ב-RTL עברי |
| Authentication | שם משתמש+סיסמה **+ Google OAuth** | רק Google. רישום **אינו חובה** לצפייה |
| פונט ברירת מחדל | **David** | פונטים נוספים מותאמים אישית יועלו ע"י הלקוח |
| כיווניות | RTL מלא | `<html lang="he" dir="rtl">` |

---

## 3. סיכום כל תשובות הלקוח (איפיון)

### ספרים דיגיטליים
- פורמט תצוגה: **HTML** (לא PDF)
- כל ספר = **קובץ HTML אחד ארוך** (לא מחולק לפרקים נפרדים)
- גלילה: **לא רציפה** — יש מעבר בין מקטעים דרך נקודות ציון
- **אף ספר לא קיים כרגע** — הלקוח יעלה את כל התוכן דרך אזור הניהול
- פיצ'רים נדרשים בקורא:
  - סימניות (bookmarks)
  - חיפוש בתוך הספר
  - שינוי גודל טקסט
  - שינוי צבע (טקסט ורקע)
  - שינוי רוחב שורה
  - שינוי עובי טקסט (font-weight)
  - נקודות ציון למעבר בין מקטעים

### עקרון מרכזי — תגיות בלתי נראות
> "ניצור סימנים בלתי נראים בתוך הטקסט בשביל לממש הנחיות כמו הגדרות עיצוביות או נקודות ציון למעבר בין מקטעים"

זהו **הרעיון הליבתי** של המערכת: המנהל מטמיע תגיות מותאמות ומוסתרות בתוך ה-HTML, והקורא (frontend) מפרסר אותן ומממש אותן. ראה סעיף 6.

### תכנים דינמיים
- **הלכה יומית**, **חוק לישראל**, **זמנים** — כולם דרך API
- ה-APIs **ייבנו על ידינו** (לא חיצוניים קיימים) — כלומר backend שלנו מספק אותם
- התוכן שלהם יועלה **דרך אותו עורך HTML** של אזור הניהול

### חנות
- תשלומים / משלוחים / מלאי — **ינוהל בהמשך**
- כרגע: לבנות **טמפלט מותאם בלבד**, מסך שמור לעתיד

### משתמשים
- רישום **אינו חובה** — אפשר לצפות בספרים כאורח
- משתמשים רשומים יכולים: לשמור העדפות, לשמור סימניות, לרכוש מהחנות
- העדפות שנשמרות: **הכל** — גודל פונט, עובי, צבע טקסט, צבע רקע, רוחב שורה, סימניות, מיקום קריאה אחרון
- אורחים: שמירה ב-LocalStorage בלבד

### אזור ניהול
- **URL אישי** למנהל שדרכו הוא מעדכן הכל
- מסכים נדרשים:
  1. העלאת תוכן לסידור
  2. העלאת תוכן לתהילים
  3. ניהול הלכה יומית **לפי תאריכים** (לכל תאריך תוכן אחר)
  4. ניהול חוק לישראל **לפי תאריכים** (לכל תאריך תוכן אחר)
  5. מסך ניהול חנות (מבנה בלבד, ינוהל בהמשך)
- העורך: TinyMCE עם **כפתורים ייעודיים** להוספת התגיות המוסתרות:
  - "הוסף סימניה"
  - "הוסף נקודת ציון"
  - "הוסף הגדרות עיצוב"
- **Preview חובה** — תצוגה מקדימה של איך הספר ייראה לפני פרסום

### עיצוב
- הלקוח **ישלח בהמשך צילום מסכים** שימחיש את העיצוב הרצוי
- עד אז — עיצוב זמני עם פלטת כחול/זהב (ראה CSS בסעיף 8)

---

## 4. מסך הכניסה — המפרט המקורי

> "מסך הכניסה יכיל תפריט עם מספר כפתורים. **התפריט לא יהיה תפריט עליון, אלא יוצג במרכז המסך**"

ששת הכפתורים:

| כפתור | יעד |
|-------|-----|
| **סידור** | ספר דיגיטלי חינמי |
| **תהילים** | ספר דיגיטלי חינמי |
| **חנות ספרים** | קישור לחנות |
| **הלכה יומית** | עמוד דינמי, טקסט משתנה לפי תאריך |
| **חוק לישראל** | עמוד דינמי, טקסט משתנה לפי תאריך |
| **זמנים** | עמוד דינמי, טקסט משתנה |

---

## 5. מבנה מסד הנתונים

### 5.1 ERD (טקסטואלי)

```
users (1) ──< (N) user_preferences
users (1) ──< (N) bookmarks >── (1) books
users (1) ──< (N) reading_progress >── (1) books

daily_halacha  [עצמאי, מפתח לפי date]
daily_chok     [עצמאי, מפתח לפי date]
```

### 5.2 סקריפט SQL מלא

```sql
-- users
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255),
    google_id VARCHAR(255) UNIQUE,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_google_id (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- books
CREATE TABLE books (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    type ENUM('siddur','tehilim','digital','physical') NOT NULL,
    is_free BOOLEAN DEFAULT TRUE,
    price DECIMAL(10,2) DEFAULT 0.00,
    content_html LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_preferences
CREATE TABLE user_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    font_family VARCHAR(100) DEFAULT 'David',
    font_size INT DEFAULT 16,
    font_weight INT DEFAULT 400,
    text_color VARCHAR(7) DEFAULT '#000000',
    background_color VARCHAR(7) DEFAULT '#FFFFFF',
    line_width INT DEFAULT 600,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_pref (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- bookmarks
CREATE TABLE bookmarks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    position VARCHAR(255) NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_user_book (user_id, book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- daily_halacha
CREATE TABLE daily_halacha (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date DATE UNIQUE NOT NULL,
    content_html LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- daily_chok
CREATE TABLE daily_chok (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date DATE UNIQUE NOT NULL,
    content_html LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- reading_progress
CREATE TABLE reading_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    last_position VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_book_progress (user_id, book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 6. מערכת התגיות המוסתרות (הליבה)

המנהל מטמיע בתוך ה-HTML של הספר תגיות מותאמות. הן **לא נראות למשתמש** — ה-parser ב-frontend ממיר אותן ל-`<div>` / `<span>` עם `data-*` attributes.

### 6.1 שלושת סוגי התגיות

**1. סימניה / נקודת ציון:**
```html
<bookmark id="unique-id" title="שם הסימניה">
    תוכן הטקסט...
</bookmark>
```

**2. מקטע (למעבר בין חלקים):**
```html
<section id="section-id" title="כותרת המקטע">
    תוכן המקטע...
</section>
```

**3. הגדרות עיצוב נקודתיות:**
```html
<style-marker font-size="20" font-weight="700" color="#8B0000">
    טקסט מיוחד
</style-marker>
```

### 6.2 המרה ב-parser

| תגית מקור | תוצאה ב-DOM |
|-----------|--------------|
| `<bookmark id="x" title="y">` | `<div class="bookmark-section" data-bookmark-id="x" data-bookmark-title="y">` |
| `<section id="x" title="y">` | `<div class="content-section" data-section-id="x" data-section-title="y">` |
| `<style-marker ...>` | `<span class="styled-text" style="...">` |

הקוד המלא של ה-parser נמצא בסעיף 8.4.

---

## 7. מבנה ספריות הפרויקט

```
sefarim-app/
├── backend/                        # Laravel
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── API/
│   │   │   │   │   ├── BookController.php
│   │   │   │   │   ├── DailyContentController.php
│   │   │   │   │   └── UserPreferencesController.php
│   │   │   │   └── Admin/
│   │   │   │       ├── BookManagementController.php
│   │   │   │       └── ContentManagementController.php
│   │   │   └── Middleware/AdminAuth.php
│   │   ├── Models/
│   │   │   ├── User.php  Book.php  Bookmark.php
│   │   │   ├── DailyHalacha.php  DailyChok.php
│   │   │   └── UserPreference.php
│   │   └── Services/
│   │       ├── BookParser.php
│   │       └── ZmanimAPI.php
│   ├── database/migrations/
│   ├── routes/{api.php, web.php}
│   └── config/services.php         # Google OAuth
│
├── frontend/                       # PWA
│   ├── index.html                  # תפריט ראשי
│   ├── css/
│   │   ├── main.css  reader.css
│   │   └── fonts/david.woff2 + custom-fonts/
│   ├── js/
│   │   ├── app.js  reader.js  bookmarks.js
│   │   ├── preferences.js  auth.js
│   ├── pages/
│   │   ├── siddur.html  tehilim.html  halacha.html
│   │   ├── chok.html  zmanim.html  store.html
│   ├── admin/
│   │   ├── index.html  upload-siddur.html
│   │   ├── upload-tehilim.html
│   │   ├── manage-halacha.html  manage-chok.html
│   ├── manifest.json
│   ├── sw.js                       # Service Worker
│   └── icons/
│
└── docs/
    ├── api-documentation.md
    ├── database-schema.md
    └── deployment-guide.md
```

---

## 8. קוד שכבר נכתב

### 8.1 Wireframe — מסך הבית

```
┌─────────────────────────────────────┐
│          📚 קטלוג ספרים      [התחבר]│
├─────────────────────────────────────┤
│         ┌──────────┐  ┌──────────┐ │
│         │  סידור   │  │ תהילים   │ │
│         └──────────┘  └──────────┘ │
│         ┌──────────┐  ┌──────────┐ │
│         │חנות ספרים│  │הלכה יומית│ │
│         └──────────┘  └──────────┘ │
│         ┌──────────┐  ┌──────────┐ │
│         │חוק לישראל│  │  זמנים   │ │
│         └──────────┘  └──────────┘ │
└─────────────────────────────────────┘
```

### 8.2 index.html (מסך הבית)

```html
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="קטלוג ספרים יהודיים דיגיטליים">
    <title>קטלוג ספרים</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="stylesheet" href="/css/main.css">
    <link rel="icon" href="/icons/icon-192.png">
</head>
<body>
    <div class="container">
        <header>
            <div class="logo"><h1>📚 קטלוג ספרים</h1></div>
            <div class="auth-buttons">
                <button id="loginBtn" class="btn-secondary">התחבר</button>
            </div>
        </header>

        <main class="menu-grid">
            <a href="/pages/siddur.html" class="menu-card">
                <div class="menu-icon">📖</div>
                <h2>סידור</h2><p>סידור תפילה מלא</p>
            </a>
            <a href="/pages/tehilim.html" class="menu-card">
                <div class="menu-icon">🙏</div>
                <h2>תהילים</h2><p>ספר תהילים מלא</p>
            </a>
            <a href="/pages/store.html" class="menu-card">
                <div class="menu-icon">🛒</div>
                <h2>חנות ספרים</h2><p>רכישת ספרים פיזיים</p>
            </a>
            <a href="/pages/halacha.html" class="menu-card">
                <div class="menu-icon">📚</div>
                <h2>הלכה יומית</h2><p>הלכה ליום זה</p>
            </a>
            <a href="/pages/chok.html" class="menu-card">
                <div class="menu-icon">📜</div>
                <h2>חוק לישראל</h2><p>לימוד יומי</p>
            </a>
            <a href="/pages/zmanim.html" class="menu-card">
                <div class="menu-icon">🕐</div>
                <h2>זמנים</h2><p>זמני היום</p>
            </a>
        </main>
    </div>

    <div id="loginModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>התחברות</h2>
            <form id="loginForm">
                <input type="email" placeholder="אימייל" required>
                <input type="password" placeholder="סיסמה" required>
                <button type="submit" class="btn-primary">התחבר</button>
            </form>
            <div class="divider">או</div>
            <button id="googleLogin" class="btn-google">
                <img src="/icons/google.svg" alt="Google"> התחבר עם Google
            </button>
            <p class="register-link">
                אין לך חשבון? <a href="/pages/register.html">הירשם כאן</a>
            </p>
        </div>
    </div>

    <script src="/js/app.js"></script>
    <script src="/js/auth.js"></script>
</body>
</html>
```

### 8.3 css/main.css

```css
@font-face {
    font-family: 'David';
    src: url('/css/fonts/david.woff2') format('woff2');
    font-weight: normal;
    font-style: normal;
    font-display: swap;
}

:root {
    --primary-color: #2c5f8d;
    --secondary-color: #6b9bc3;
    --accent-color: #d4af37;
    --text-color: #333;
    --bg-color: #f5f5f5;
    --card-bg: #ffffff;
    --shadow: 0 2px 8px rgba(0,0,0,0.1);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'David', 'Arial', sans-serif;
    background: linear-gradient(135deg, var(--bg-color) 0%, #e8e8e8 100%);
    color: var(--text-color);
    min-height: 100vh;
    direction: rtl;
}

.container { max-width: 1200px; margin: 0 auto; padding: 20px; }

header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 0; margin-bottom: 40px;
}

.logo h1 { font-size: 2rem; color: var(--primary-color); }

.menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 30px; margin-top: 50px;
}

.menu-card {
    background: var(--card-bg); border-radius: 15px;
    padding: 40px 20px; text-align: center;
    box-shadow: var(--shadow);
    transition: transform .3s, box-shadow .3s;
    text-decoration: none; color: inherit;
}
.menu-card:hover { transform: translateY(-5px); box-shadow: 0 4px 16px rgba(0,0,0,.15); }
.menu-icon { font-size: 3rem; margin-bottom: 15px; }
.menu-card h2 { font-size: 1.5rem; margin-bottom: 10px; color: var(--primary-color); }
.menu-card p { color: #666; font-size: .9rem; }

.btn-primary, .btn-secondary, .btn-google {
    padding: 12px 24px; border: none; border-radius: 8px;
    cursor: pointer; font-size: 1rem; font-family: inherit;
    transition: background .3s;
}
.btn-primary { background: var(--primary-color); color: #fff; }
.btn-primary:hover { background: #234a6d; }
.btn-secondary { background: transparent; color: var(--primary-color); border: 2px solid var(--primary-color); }
.btn-secondary:hover { background: var(--primary-color); color: #fff; }
.btn-google {
    background: #fff; border: 1px solid #ddd; display: flex;
    align-items: center; justify-content: center; gap: 10px; width: 100%;
}
.btn-google:hover { background: #f8f8f8; }

.modal {
    display: none; position: fixed; z-index: 1000;
    left: 0; top: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,.5);
}
.modal-content {
    background: #fff; margin: 10% auto; padding: 30px;
    border-radius: 15px; max-width: 400px; position: relative;
}
.close { position: absolute; left: 20px; top: 15px; font-size: 28px; cursor: pointer; }

#loginForm input {
    width: 100%; padding: 12px; margin: 10px 0;
    border: 1px solid #ddd; border-radius: 8px; font-family: inherit;
}
.divider { text-align: center; margin: 20px 0; color: #999; }
.register-link { text-align: center; margin-top: 20px; font-size: .9rem; }

@media (max-width: 768px) {
    .menu-grid { grid-template-columns: 1fr; gap: 20px; }
    header { flex-direction: column; gap: 15px; }
}
```

### 8.4 js/reader.js — מנוע הקורא + Parser התגיות

```javascript
class BookReader {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.bookData = null;
        this.currentPosition = 0;
        this.preferences = this.loadPreferences();
    }

    async loadBook(bookId) {
        try {
            const response = await fetch(`/api/books/${bookId}`);
            this.bookData = await response.json();
            this.renderBook();
            this.applyPreferences();
            this.setupEventListeners();
        } catch (error) {
            console.error('Error loading book:', error);
            this.container.innerHTML = '<p class="error">שגיאה בטעינת הספר</p>';
        }
    }

    renderBook() {
        let html = this.bookData.content_html;

        // <bookmark>
        html = html.replace(
            /<bookmark id="([^"]+)"(?:\s+title="([^"]*)")?>(.*?)<\/bookmark>/gs,
            (m, id, title, content) =>
                `<div class="bookmark-section" data-bookmark-id="${id}" data-bookmark-title="${title || ''}">${content}</div>`
        );

        // <section>
        html = html.replace(
            /<section id="([^"]+)"(?:\s+title="([^"]*)")?>(.*?)<\/section>/gs,
            (m, id, title, content) =>
                `<div class="content-section" data-section-id="${id}" data-section-title="${title || ''}">${content}</div>`
        );

        // <style-marker>
        html = html.replace(
            /<style-marker(?:\s+font-size="([^"]*)")?(?:\s+font-weight="([^"]*)")?(?:\s+color="([^"]*)")?>(.*?)<\/style-marker>/gs,
            (m, fontSize, fontWeight, color, content) => {
                let style = '';
                if (fontSize)   style += `font-size:${fontSize}px;`;
                if (fontWeight) style += `font-weight:${fontWeight};`;
                if (color)      style += `color:${color};`;
                return `<span class="styled-text" style="${style}">${content}</span>`;
            }
        );

        this.container.innerHTML = html;
        this.restoreReadingPosition();
    }

    applyPreferences() {
        const p = this.preferences;
        this.container.style.fontFamily      = p.fontFamily || 'David';
        this.container.style.fontSize        = (p.fontSize || 16) + 'px';
        this.container.style.fontWeight      = p.fontWeight || 400;
        this.container.style.color           = p.textColor || '#000000';
        this.container.style.backgroundColor = p.bgColor || '#FFFFFF';
        this.container.style.maxWidth        = (p.lineWidth || 600) + 'px';
    }

    loadPreferences() {
        const saved = localStorage.getItem('readerPreferences');
        return saved ? JSON.parse(saved) : {};
    }

    savePreferences(prefs) {
        localStorage.setItem('readerPreferences', JSON.stringify(prefs));
        if (this.isUserLoggedIn()) {
            fetch('/api/user/preferences', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getAuthToken()}`
                },
                body: JSON.stringify(prefs)
            });
        }
    }

    setupEventListeners() {
        window.addEventListener('scroll', this.debounce(() => {
            this.saveReadingPosition();
        }, 1000));

        document.getElementById('searchInput')?.addEventListener('input', (e) => {
            this.searchInBook(e.target.value);
        });
    }

    searchInBook(query) {
        if (!query) { this.clearHighlights(); return; }
        const content = this.container.innerHTML;
        const regex = new RegExp(`(${query})`, 'gi');
        this.container.innerHTML = content.replace(regex, '<span class="highlight">$1</span>');
    }

    clearHighlights() {
        this.container.querySelectorAll('.highlight')
            .forEach(el => { el.outerHTML = el.innerHTML; });
    }

    saveReadingPosition() {
        const position = window.scrollY;
        localStorage.setItem(`book-${this.bookData.id}-position`, position);
        if (this.isUserLoggedIn()) {
            fetch('/api/reading-progress', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getAuthToken()}`
                },
                body: JSON.stringify({ book_id: this.bookData.id, position })
            });
        }
    }

    restoreReadingPosition() {
        const position = localStorage.getItem(`book-${this.bookData.id}-position`);
        if (position) window.scrollTo(0, parseInt(position));
    }

    debounce(func, wait) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func(...args), wait);
        };
    }

    isUserLoggedIn() { return !!localStorage.getItem('authToken'); }
    getAuthToken()   { return localStorage.getItem('authToken'); }
}

document.addEventListener('DOMContentLoaded', () => {
    const bookId = new URLSearchParams(window.location.search).get('id') || 'siddur';
    new BookReader('readerContent').loadBook(bookId);
});
```

### 8.5 Wireframe — מסך הקורא

```
┌─────────────────────────────────────────┐
│ ← [הגדרות] [סימניות] [חיפוש]     סידור │
├─────────────────────────────────────────┤
│     ┌─────────────────────────┐        │
│     │   ברכות השחר            │        │
│     │   <bookmark id="b1">    │        │
│     │   ברוך אתה ה'...        │        │
│     │   </bookmark>           │        │
│     │   <section id="s1">     │        │
│     │   ...טקסט הסידור...     │        │
│     │   </section>            │        │
│     └─────────────────────────┘        │
└─────────────────────────────────────────┘

פאנל הגדרות צף:
┌──────────────────────┐
│   התאמה אישית        │
│ פונט: [David ▼]      │
│ גודל: [-] 16 [+]     │
│ עובי: [-] 400 [+]    │
│ צבע טקסט: [⬛]       │
│ צבע רקע: [⬜]        │
│ רוחב שורה: [____]    │
│ [שמור העדפות]        │
└──────────────────────┘
```

### 8.6 pages/siddur.html (מבנה מסך הקורא)

```html
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>סידור תפילה</title>
    <link rel="stylesheet" href="/css/main.css">
    <link rel="stylesheet" href="/css/reader.css">
</head>
<body>
    <header class="reader-header">
        <button class="btn-icon" onclick="history.back()"><span>←</span></button>
        <h1 class="book-title">סידור תפילה</h1>
        <div class="header-actions">
            <button id="searchBtn" class="btn-icon" title="חיפוש">🔍</button>
            <button id="bookmarksBtn" class="btn-icon" title="סימניות">🔖</button>
            <button id="settingsBtn" class="btn-icon" title="הגדרות">⚙️</button>
        </div>
    </header>

    <div id="searchBar" class="search-bar hidden">
        <input type="text" id="searchInput" placeholder="חפש בספר...">
        <button id="searchCloseBtn">✕</button>
    </div>

    <main class="reader-content" id="readerContent">
        <div class="loading">טוען...</div>
    </main>

    <aside id="settingsPanel" class="settings-panel hidden">
        <h3>התאמה אישית</h3>
        <div class="setting-group">
            <label>פונט:</label>
            <select id="fontFamily">
                <option value="David">David</option>
                <option value="Arial">Arial</option>
                <option value="Times New Roman">Times New Roman</option>
            </select>
        </div>
        <div class="setting-group">
            <label>גודל טקסט:</label>
            <div class="slider-control">
                <button class="btn-small" data-action="decrease" data-target="fontSize">-</button>
                <span id="fontSizeValue">16</span>
                <button class="btn-small" data-action="increase" data-target="fontSize">+</button>
            </div>
        </div>
        <div class="setting-group">
            <label>עובי טקסט:</label>
            <div class="slider-control">
                <button class="btn-small" data-action="decrease" data-target="fontWeight">-</button>
                <span id="fontWeightValue">400</span>
                <button class="btn-small" data-action="increase" data-target="fontWeight">+</button>
            </div>
        </div>
        <div class="setting-group">
            <label>צבע טקסט:</label>
            <input type="color" id="textColor" value="#000000">
        </div>
        <div class="setting-group">
            <label>צבע רקע:</label>
            <input type="color" id="bgColor" value="#FFFFFF">
        </div>
        <div class="setting-group">
            <label>רוחב שורה (px):</label>
            <input type="range" id="lineWidth" min="400" max="1000" value="600">
            <span id="lineWidthValue">600</span>
        </div>
        <button id="savePreferences" class="btn-primary">שמור העדפות</button>
        <button id="resetPreferences" class="btn-secondary">איפוס</button>
    </aside>

    <aside id="bookmarksPanel" class="bookmarks-panel hidden">
        <h3>הסימניות שלי</h3>
        <div id="bookmarksList"></div>
        <button id="addBookmark" class="btn-primary">הוסף סימניה</button>
    </aside>

    <div id="bookmarkModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>הוסף סימניה</h2>
            <form id="bookmarkForm">
                <label>הערה (אופציונלי):</label>
                <textarea id="bookmarkNote" rows="3"></textarea>
                <button type="submit" class="btn-primary">שמור</button>
            </form>
        </div>
    </div>

    <script src="/js/reader.js"></script>
    <script src="/js/bookmarks.js"></script>
    <script src="/js/preferences.js"></script>
</body>
</html>
```

### 8.7 css/reader.css

```css
.reader-header {
    position: sticky; top: 0;
    background: var(--primary-color); color: #fff;
    padding: 15px 20px;
    display: flex; justify-content: space-between; align-items: center;
    z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.book-title { font-size: 1.3rem; flex-grow: 1; text-align: center; }
.header-actions { display: flex; gap: 10px; }

.btn-icon {
    background: rgba(255,255,255,.2); border: none; color: #fff;
    width: 40px; height: 40px; border-radius: 50%;
    cursor: pointer; font-size: 1.2rem; transition: background .3s;
}
.btn-icon:hover { background: rgba(255,255,255,.3); }

.search-bar { background: #f0f0f0; padding: 10px 20px; display: flex; gap: 10px; align-items: center; }
.search-bar.hidden { display: none; }
#searchInput { flex-grow: 1; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-family: inherit; }
#searchCloseBtn { background: none; border: none; font-size: 1.5rem; cursor: pointer; }

.reader-content {
    max-width: 600px; margin: 40px auto; padding: 20px;
    background: #fff; border-radius: 10px; box-shadow: var(--shadow);
    line-height: 1.8; font-size: 16px; min-height: 70vh;
}
.reader-content .loading { text-align: center; padding: 50px; color: #999; }

[data-bookmark-id] { position: relative; padding-right: 25px; }
[data-bookmark-id]::before {
    content: '🔖'; position: absolute; right: 0; font-size: .8rem; opacity: .5;
}
[data-section-id] { scroll-margin-top: 80px; }
.highlight { background-color: yellow; padding: 2px 0; }

.settings-panel {
    position: fixed; left: 20px; top: 100px; width: 300px;
    background: #fff; border-radius: 15px; padding: 20px;
    box-shadow: 0 4px 16px rgba(0,0,0,.2); z-index: 99;
    max-height: 80vh; overflow-y: auto;
}
.settings-panel.hidden { display: none; }
.setting-group { margin-bottom: 20px; }
.setting-group label { display: block; margin-bottom: 8px; font-weight: bold; font-size: .9rem; }
.setting-group select,
.setting-group input[type="color"],
.setting-group input[type="range"] {
    width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 8px; font-family: inherit;
}
.slider-control { display: flex; align-items: center; gap: 10px; }
.btn-small {
    width: 30px; height: 30px; border: 1px solid var(--primary-color);
    background: #fff; color: var(--primary-color);
    border-radius: 5px; cursor: pointer; font-size: 1.2rem;
}
.btn-small:hover { background: var(--primary-color); color: #fff; }
.slider-control span { min-width: 40px; text-align: center; font-weight: bold; }

input[type="range"] { -webkit-appearance: none; height: 6px; background: #ddd; border-radius: 3px; outline: none; }
input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none; width: 18px; height: 18px;
    background: var(--primary-color); border-radius: 50%; cursor: pointer;
}

.bookmarks-panel {
    position: fixed; right: 20px; top: 100px; width: 300px;
    background: #fff; border-radius: 15px; padding: 20px;
    box-shadow: 0 4px 16px rgba(0,0,0,.2); z-index: 99;
    max-height: 80vh; overflow-y: auto;
}
.bookmarks-panel.hidden { display: none; }
#bookmarksList { margin-bottom: 20px; }
.bookmark-item { padding: 10px; border-bottom: 1px solid #eee; cursor: pointer; transition: background .3s; }
.bookmark-item:hover { background: #f8f8f8; }
.bookmark-item .bookmark-position { font-size: .8rem; color: var(--primary-color); font-weight: bold; }
.bookmark-item .bookmark-note { font-size: .85rem; color: #666; margin-top: 5px; }
.bookmark-item .bookmark-date { font-size: .75rem; color: #999; margin-top: 5px; }

@media (max-width: 768px) {
    .settings-panel, .bookmarks-panel {
        position: fixed; left: 0; right: 0; top: auto; bottom: 0;
        width: 100%; border-radius: 15px 15px 0 0; max-height: 70vh;
    }
    .reader-content { margin: 20px; padding: 15px; }
}
```

---

## 9. מה עדיין לא נבנה — רשימת המשך

הצ'אט הקודם הגיע עד סוף "חלק 5". להלן מה שנותר:

### 9.1 Backend (Laravel) — לא נכתב עדיין
- [ ] Migrations של Laravel (המרה מה-SQL בסעיף 5.2)
- [ ] Models: `User`, `Book`, `Bookmark`, `DailyHalacha`, `DailyChok`, `UserPreference`, `ReadingProgress` + יחסי Eloquent
- [ ] Controllers: `BookController`, `DailyContentController`, `UserPreferencesController`
- [ ] Admin Controllers: `BookManagementController`, `ContentManagementController`
- [ ] Middleware `AdminAuth` + ה-URL האישי של המנהל
- [ ] `BookParser.php` — parser צד שרת (גיבוי / SEO)
- [ ] `ZmanimAPI.php` — שירות הזמנים
- [ ] Laravel Sanctum + Google OAuth (Socialite)

### 9.2 API Endpoints — לתכנן ולתעד
מוצע:
```
GET  /api/books/{slug}              קבלת ספר מלא
GET  /api/halacha/{date?}           הלכה יומית (ברירת מחדל: היום)
GET  /api/chok/{date?}              חוק לישראל
GET  /api/zmanim?lat=&lng=          זמני היום
POST /api/auth/register
POST /api/auth/login
GET  /api/auth/google/redirect
GET  /api/auth/google/callback
GET  /api/user/preferences
POST /api/user/preferences
GET  /api/bookmarks?book_id=
POST /api/bookmarks
DELETE /api/bookmarks/{id}
POST /api/reading-progress

--- Admin (מאחורי AdminAuth) ---
POST /api/admin/books/{slug}        עדכון תוכן ספר
POST /api/admin/halacha             שמירת הלכה לתאריך
POST /api/admin/chok                שמירת חוק לתאריך
GET  /api/admin/preview             תצוגה מקדימה
```

### 9.3 Frontend — לא נכתב עדיין
- [ ] `js/bookmarks.js` — לוגיקת סימניות מלאה
- [ ] `js/preferences.js` — חיבור פאנל ההגדרות לקורא
- [ ] `js/auth.js` — התחברות, רישום, Google OAuth
- [ ] `js/app.js` — לוגיקת מסך הבית
- [ ] `pages/tehilim.html`, `halacha.html`, `chok.html`, `zmanim.html`, `store.html`
- [ ] `manifest.json` + `sw.js` (Service Worker, offline caching)
- [ ] אייקונים ל-PWA

### 9.4 אזור הניהול — לא נכתב עדיין
- [ ] `admin/index.html` — דשבורד
- [ ] `admin/upload-siddur.html` + `upload-tehilim.html`
- [ ] `admin/manage-halacha.html` + `manage-chok.html` (עם בורר תאריכים / לוח שנה)
- [ ] אינטגרציית **TinyMCE 6** עם:
  - הגדרת RTL עברי
  - **plugin מותאם** עם 3 כפתורים: "הוסף סימניה" / "הוסף נקודת ציון" / "הוסף הגדרות עיצוב"
  - מצב source code לעריכת HTML ידנית
- [ ] מסך **Preview** — מריץ את אותו parser של `reader.js`
- [ ] מסך ניהול חנות (שלד בלבד)

### 9.5 פריסה
- [ ] מדריך התקנה מקומית
- [ ] מדריך העלאה ל-Bluehost דרך cPanel
- [ ] הגדרת `.env`, MySQL, HTTPS, credentials של Google OAuth

---

## 10. נקודות פתוחות / דורשות החלטה

1. **צילומי המסכים מהלקוח** — טרם התקבלו. העיצוב הנוכחי זמני.
2. **הפונטים המותאמים** — הלקוח יספק קבצי פונט. `David` הוא ברירת המחדל.
3. **"גלילה לא רציפה"** — יש להבהיר מול הלקוח: האם הכוונה למעבר בין מקטעים דרך נקודות הציון (כפי שהובן), או משהו אחר.
4. **מיקום גיאוגרפי לזמנים** — לא הוגדר אם הזמנים מבוססים geolocation או עיר קבועה.
5. **חנות** — כל התשלומים/משלוחים/מלאי נדחו לשלב מאוחר יותר.
6. **טיפול בספרים בתשלום** — מנגנון ההרשאות/paywall טרם אופיין.

---

## 11. הנחיה לצ'אט הבא

> העבר את המסמך הזה במלואו כהודעה ראשונה, והוסף:
>
> "זהו מסמך העברה מפרויקט קיים. כל ההחלטות בו סופיות. אנא המשך מסעיף 9 — התחל ב[מה שתבחר: Backend / אזור הניהול / השלמת ה-Frontend]."
