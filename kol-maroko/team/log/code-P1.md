# יומן קוד — P1 (אנשים ותפקידים)

- 2026-11-12 | התחלה: ענף `claude/kol-maroko-p1` מ-main (9fb94c5). קראתי PROTOCOL, owner-brief, P1, decisions (P1), handoff (M4-BC), auth.md, sso.md §6.
- 2026-11-12 | שרת: `lib/login.php` (חדש — המקום היחיד שקורא PLATFORM_USER), `users` + `sessions` + `kolm_sess`, CSRF של ה-session;
  `lib/auth.php` — חמשת התפקידים ו-`ACTION_ROLES`, `authorize()` אחד ב-api.php (בלי require_role מפוזר); `lib/members.php` — אנשים דרך provision;
  `lib/build.php` — הודעות לאדם, "כל פרסום מחכה לאישורי", `owner_settings`; agent.php — שורה ב-users + `AGENT_ACTIONS`. מיגרציה 23.
- 2026-11-12 | ממשק: `assets/js/people.js` (👥 אנשים, ⚙️ בעל האתר, 🔔, "לקריאה בלבד"); `mountBlock` עבר מ-publish.js (handoff M4-BC).
- 2026-11-12 | תקלה שנמצאה בבדיקות: בקשות מקבילות בטעינה ראשונה פותחות כמה sessions → מתקבל CSRF של כל session חי של אותו משתמש.
  a3.cjs: בקשות `<audio>` יוצאות בלי extraHTTPHeaders ← בלי זהות ← ה-session נמחק (לפי האפיון) — הבדיקה שולחת עכשיו גם את העוגייה.
- הכרעות קטנות (לבקרה): `site_switch` (המפסק) נשאר מפרסם — §2.2 מחלק רק את 36 ה-'owner'; ב-⚙️ מוצג לקריאה. מי שהוסר ונכנס שוב —
  נשאר removed (לא חוזר ל"ביקשו גישה"). בעל האתר = platform_admin בכל בקשה; שורה site_owner בלי admin — בלי תפקיד. disk_cap → בעל האתר (D13).
