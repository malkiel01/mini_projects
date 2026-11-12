# יומן קוד — M7b (תיקון: הכותרות של wp_remote_* בתוסף)

- 2026-11-12 — ענף `claude/kol-maroko-m7b` מ-main (482ff4a). הבאג מהשטח: פרסום ראשון — "הוחלו 0, נכשלו 1".
  הסיבה: `(array)wp_remote_retrieve_headers($r)` ב-v2-pull.php — בוורדפרס הכותרות הן אובייקט (CaseInsensitiveDictionary),
  וה-cast מחזיר את המאפיין הפנימי. Content-Range אבד ← כל גוש: blob_http "טווח לא צפוי מהכלי". "בדוק חיבור" עבד (סטטוס + JSON בלבד).
  חיפוש בכל התוסף: v2-admin.php:251 — מעביר את האובייקט בלי cast (תקין); שאר הגישות ל-$r['headers'] — למערכים שלנו.
- הוורדפרס המדומה (tests/plugin/wp.php, tests/fake-wp.php) מחזיר עכשיו אובייקט (Kol_Test_Header_Dict: ArrayAccess + IteratorAggregate).
  **לפני התיקון** (אותן בדיקות, הקוד של 2.2.0):
```
❌ M7 קצה לקצה מול התוסף האמיתי: פרסום ⇒ משיכה ⇒ applied ⇒ העמוד מוגש מהגרסה החדשה ⇒ "✓ הוחל"; אופן ההצבה דרך התור
   TestFail: {"at":1794519010,"why":"test","ok":true,"applied":0,"failed":1,"pending":false,"text":"✓ הוחלו 0, נכשלו 1","code":"","cursor":1012,"results":[{"seq":1012,"state":"failed","code":"blob_http","message":"טווח לא צפוי מהכלי"}]}: ציפיתי ל-[true,1,0], התקבל [true,0,1] (run.php:30)
❌ M7 קצה לקצה בתוסף: manifest (גושים לפי hash) + mount — מוחלים, הדיווח applied, הסמן מתקדם, ההגשה מהגרסה החדשה
   TestFail: {"at":1794519014,"why":"test","ok":true,"applied":1,"failed":1,"pending":false,"text":"✓ הוחלו 1, נכשלו 1","code":"","cursor":4,"results":[{"seq":3,"state":"failed","code":"blob_http","message":"טווח לא צפוי מהכלי"},{"seq":4,"state":"applied","code":"","message":""}]}: ציפיתי ל-[true,2,0], התקבל [true,1,1] (run.php:30)
❌ M7 H20: גוש שה-sha256 שלו אינו תואם (שרת זדוני) — נמחק, נמשך שוב, אחרי 3 — failed: blob_mismatch; הגרסה שבאוויר לא נגעה
❌ M7 H23: הפניה (302) מ-pull.php לדומיין אחר — לא נעקבת; "בדוק חיבור" מראה אותה
❌ M7 H27: ריצה שנקטעה באמצע גוש גדול — הבאה ממשיכה מאותו בייט (Range), והמצביע מוחלף רק בסוף
❌ M7 קצב: 429 מהכלי באמצע ההורדה — לא נכשל; ממשיך (משיכה נוספת בעוד דקה), ובסוף מוחל
   TestFail: {"at":1794519014,"why":"test","ok":true,"applied":0,"failed":1,"pending":false,"text":"✓ הוחלו 0, נכשלו 1","code":"","cursor":50,"results":[{"seq":50,"state":"failed","code":"blob_http","message":"טווח לא צפוי מהכלי"}]}: ציפיתי ל-[0,0,true], התקבל [0,1,false] (run.php:30)
נכשלו 6 מתוך 361
```
  **אחרי התיקון:** run.php 362 ✓ ×2, KOL_V2_REAL=1 362 ✓ ×2 · m7 31 ✓ · m4bc 43 ✓ · check-apps ✓ · sync --check ✓.
- הכרעת הבקרה: blob_http / הפניה / timeout בהורדה — "ממשיך בריצה הבאה" (pull_net_tries לכל seq) עד 3 ריצות רצופות, ורק אז failed.
  blob_mismatch — כמו קודם. בדיקה: "M7b תקלת רשת בהורדה" (500 פעם אחת ← מוחל; קבוע ← pending, pending, failed).
- התוסף 2.2.1 (גם readme.txt), KOL_PLUGIN_EXPECTED 2.2.1.
