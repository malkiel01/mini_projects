# בקשה לצ'אט של מגדלור — חיבור לתוסף התשלומים

**מאת:** הצ'אט של תוסף התשלומים · **תאריך:** 2026-10-25 · **הכרעת בעל המערכת:** מגדלור — האפליקציה הראשונה שמתחברת.
**לא נגעתי בקוד של מגדלור.** כאן — מה לחבר, איפה, ולמה. המדריך הכללי: `payments/docs/connect.md`.

## מה כבר יש אצלכם (מהסקירה)

- `accounts` — המשפחה: `expires_at`, `level_max` (browser / apps / device), `modules`, `max_children`, `max_devices`,
  ושלבי תוקף `ok / soon / grace / released` (`expiryStage` ב-`lib/policy.php`, `SPEC.md` סעיף 10).
- `accountExtend($id, $months)` — הארכה מהתוקף הנוכחי (`lib/accounts.php:216`).
- היום ההארכה ידנית במסך המפתח. החיבור מחליף את המקור — לא את הלוגיקה.

## מה לחבר

1. **להעתיק** את `payments/sdk/php/PaymentsClient.php` (גרסה 1.1.0 ומעלה) ל-`guarded-browser/lib/PaymentsClient.php`, ובבנאי — `'app' => 'guarded-browser'`.
2. **סודות** (בהגדרות של מגדלור, לא בגיט): קוד סודי `sk_test_…` וסוד הודעות `whsec_…` — בעל המערכת יוצר אותם בתוסף:
   מערכות ← "+ מערכת" (מזהה `guarded-browser`, סוג "אפליקציה שלי") ← "+ סודי · בדיקות", "+ סוד webhook".
3. **`external_id` = `family:<accounts.id>`.** בפתיחת חשבון / שינוי מייל של ההורה: `$pay->customer('family:12', ['name' => …, 'email' => <מייל ההורה>])`.
4. **מוצרים** (בעל המערכת מגדיר בתוסף, לא בקוד):

   | קוד | סוג | מה נותן (grants) |
   | --- | --- | --- |
   | `migdalor_browser` | מנוי | `{"features":{"level":"browser"},"limits":{"children":3,"devices":3}}` |
   | `migdalor_apps` | מנוי | `{"features":{"level":"apps"},"limits":{"children":3,"devices":3}}` |
   | `migdalor_device` | מנוי | `{"features":{"level":"device"},"limits":{"children":3,"devices":3}}` |
   | `extra_child` | כמות במנוי | `{"limits":{"children":1}}` |
   | `extra_device` | כמות במנוי | `{"limits":{"devices":1}}` |

5. **מסך "המנוי" אצל ההורה:** כפתור "לשדרוג / לתשלום" → `$pay->checkout('family:12', 'subscription', [['product' => 'migdalor_apps'], ['product' => 'extra_device', 'quantity' => 1]], $return, $cancel)` → הפניה ל-`url`.
   בחזרה (`?checkout=…`): `$pay->checkoutStatus($_GET['checkout'])` — ורק אם `completed`, מסך "תודה".
6. **"ניהול המנוי / ביטול"** → `header('Location: ' . $pay->portalUrl('family:12', $return))`.
   **חובה בחוק (§14ט): קישור בולט "ביטול מנוי" בדף הבית של מגדלור** (ובדף ההורה) שמוביל לשם.
7. **הודעות** — `api.php?action=payments_webhook` (ב-`"public"` של `guarded-browser/app.json`):

   ```php
   $event = $pay->webhook();                                   // מאמת, או זורק → 401
   if ($event['type'] === 'entitlement.changed') {
       $id = (int)substr($event['external_id'], 7);            // family:12 → 12
       migdalor_apply_entitlement($id, $pay->entitlement($event['external_id'], true));
   }
   ```

8. **`migdalor_apply_entitlement`** (חדש אצלכם):
   - `active && state ∈ {active, trialing}` → `level_max = features.level`, `max_children = limits.children`,
     `max_devices = limits.devices`, ו-`expires_at = until` (סוף התקופה ששולמה, כתאריך) — במקום `accountExtend`.
   - `state = past_due` → לא לגעת: התוקף עוד בתוקף, ושלב `grace` שלכם ממשיך כרגיל.
   - `!active` (בוטל / לא שולם) → לא לקצר את `expires_at`: הוא כבר מסמן את סוף מה ששולם, ומשם `soon → grace → released`
     הקיים עושה את השאר — כולל השחרור החתום של המכשיר.
   - **לבדוק גם בלי הודעה:** פעם ביום (או בכניסת ההורה) — `entitlement(..., true)`, למקרה שהודעה אבדה.
9. **בדיקה** בסביבת בדיקות: כרטיס `4242 4242 4242 4242` עובר; `4000 0000 0000 0341` עובר ונדחה בחידוש (לבדוק `past_due` → `grace`).
   בממשק התוסף, בכרטיס המערכת: יומן קריאות והודעות שנשלחו.

## מה לא לעשות

- לא לשמור מחירים בקוד של מגדלור — הם בתוסף (`$pay->catalog()` אם צריך להציג).
- לא לסמוך על החזרה מדף התשלום — רק `checkoutStatus` / `entitlement` / הודעה חתומה.
- לא `require` לקבצי התוסף — רק העותק של הספרייה.
- לא להכניס את הקוד הסודי לאפליקציית האנדרואיד — רק לשרת של מגדלור.

## אחרי שזה עובד בבדיקות

בעל המערכת מאשר את המערכת "לכסף אמיתי" בתוסף ויוצר `sk_live_…`; מחליפים סוד — ושום דבר אחר.
