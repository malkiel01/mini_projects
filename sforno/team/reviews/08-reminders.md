# ביקורת — פרוסה 08 (PR #1794)

בקרה (2), 22.11 ~20:58 UTC, עם סוקר עומק (Push, VAPID, ספק חיצוני). סבב 1 — **לתיקון (🟡), בלי 🔴**.

🤖 run.php 12/12, check-apps, sync --check, php -l, node --check — ירוקים; אין שינוי מחוץ ל-`sforno/`. נמצא תקין: VAPID ב-`data/vapid.json`
(0600, נוצר אטומית, לא ביומן); JWT ES256 (aud, exp 12h, sub); הצפנת RFC 8291 נכונה (salt אקראי, HKDF, padding); SSRF — https/443
בלבד, רשימת מארחים, בלי redirect; עוגיית `sf_push` (32 בתים, sha256, Secure/HttpOnly/Lax); CSRF בכל POST; `sf_push_own` מסנן לפי בעלים;
התוכן מוצפן מקצה לקצה ולא ביומן.

## 🟡 לתיקון
1. **אין תקרה לקורא מחובר** (`lib/reminders.php` — `SF_REM_MAX` חל רק על אורח; `sf_rem_schedule` נקרא מ-`sync.php:133-139`).
   קורא עם 20,000 פריטי `reminder` + מכשירים מזויפים → עומס CPU/מסד בכל sync-push (עד 120/דקה), והתור (200/דקה) מפיל תזכורות של
   אחרים ל-`missed`. תיקון: `SF_REM_MAX` גם לקורא (ב-`sf_rem_owner_ctx` / בתכנון); תקרת מכשירים פעילים לבעלים (למשל 10) ב-`sf_push_subscribe`;
   בדיקה ש-`p256dh` נקודה תקפה (`sf_ec_pub`) ב-subscribe. טסטים.
2. **אורחים לא מתנקים** — שורות אורח (`reader_id IS NULL`) שבוטלו / gone / `last_seen_at` ישן מ-`SF_PUSH_TTL` נשארות עם endpoint ו-`guest_data`
   (מיקום, שמות לתפילה). תיקון: ניקוי במשימת `reminders.plan` (כולל ה-occurrences שלהן). טסט.
3. **מחיקת חשבון** (05) — רק `revoke` למכשירי Push; למחוק את `push_devices` וה-occurrences הממתינים של הקורא ב-account-delete. טסט.

## ⚪ הערות
- השתלטות על endpoint ידוע מעוגייה אחרת דורסת `guest_data` — סיכון נמוך (endpoint לא ניחוש); הקשחה: לקבל רק אם `p256dh`/`auth` תואמים.
- endpoint מזויף נשרף אחרי שליחה אחת (404/410 → gone).
