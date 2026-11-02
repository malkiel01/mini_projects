# איכות התרגום במכשיר — לפני ואחרי (12e.6)

השוואה שביקש האפיון (§5.11.1): 10 קטעים אמיתיים באנגלית מהפרויקט — מהסקיל `claude-tasks-live`, הטקסט שהצ'אטים קוראים, עם קוד, נתיבים, מזהים ו-§ —
כי בקונטיינר אין גישה להודעות שבלוח החי. אותו מודל (`sivelsguy/opus-mt-en-he-onnx@fc3befc`, q8, WASM), אותה מכונה.

| מדד | לפני (קטעים בין מזהים) | אחרי (משפטים עם מסמנים) |
| --- | --- | --- |
| יחידות שנשלחו למודל | 107 | 41 |
| מהן קטעים של פחות מ-3 מילים | 41 | 0 |
| מזהים שנשמרו (קוד, נתיב, #n, §) | 46/48 | 48/48 |
| תווי זבל "□" בפלט | 12 | 0 |
| מילים באנגלית שנשארו (בלי קוד ומזהים) | 23 | 20 |
| זמן כולל ל-10 הקטעים | 5.1 ש' | 5.8 ש' |

## דוגמאות

### קטע 3

**מקור:** Everything goes through `claude-tasks/bridge/link.sh`, which sends the hosting's bot-protection cookie for you, and `X-Worker-Route: chat` (stage 6, spec §7): this chat works the `chat` route only. A project or channel switched to ☁️ Platform is answered by Platform (once a quota exists) — its tasks will not come to you, and that is deliberate. Approvals (`policy`) are per project — pass the `task_id`; see `claude-tasks-worker` §3a.

**לפני:** הכל עובר. `claude-tasks/bridge/link.sh`מה ששולח את עוגיית הגנת הרובוטים של המארחת בשבילך, `X-Worker-Route: chat` (שלב 6, מפרט §7): השיחה הזאת עובדת. `chat` נתיב בלבד. פרוייקט או ערוץ שהוחלפו ל פלטה זו נענית על ידי פלטפורמה (פעם אחת יש מכסה) □ משימותיה לא יגיעו אליך, וזה מכוון. אישורים ()`policy`) הם לכל פרויקט □ לעבור את `task_id`; רואה? `claude-tasks-worker` §3a.

**אחרי:** הכל עובר דרך `claude-tasks/bridge/link.sh`, אשר שולח את עוגיית הגנת הרובוטים של המארח בשבילך, וX`X-Worker-Route: chat` (שלב 6, מפרט §7): השיחה הזאת עובדת רק במסלול אקס-אקס-0. `chat` פרויקט או ערוץ שהוחלפו לרציף ☁️, נענות על ידי פלטפורמה (ברגע שמכסה קיימת) משימותיה לא יגיעו אליך, וזה מכוון. — Approvals (`policy`) are per project — pass the `task_id`; see `claude-tasks-worker` §3a.

### קטע 4

**מקור:** One `send_later`, 60 minutes out, with the message: *"Board check: run `claude-tasks/bridge/link.sh update`, then `claude-tasks/bridge/link.sh status` and `link.sh pending`, handle anything waiting, and make sure the listener is running."* `status` re-registers by itself, silently and to the same channel, when the container was replaced (the link file is gone but the board knows this session). When it fires, do exactly that and schedule the next one. Stop scheduling when you disconnect. "Make sure it is running" means: start it **once** if it is not running and the owner has the listeners on — if it exits 4 (back-off), leave it; the next hourly check tries again.

**לפני:** אחת `send_later`60 דקות, עם ההודעה: *"Board check: רוץ `claude-tasks/bridge/link.sh update`אז `claude-tasks/bridge/link.sh status` ו `link.sh pending`תטפל בכל מה שמחכה, ותוודא שהמאזינה תהיה "ריצה." `status` Re-Risters by own, silty and to the same anase analy, when the contain was boxed (the link הקובץ נעלם אבל הלוח יודע את הפגישה הזאת. כשזה יורה, תעשה בדיוק את זה ותקבע לו תור. הבא בתור. תפסיק לתזמן כשאתה מתנתק. "תוודא שזה פועל" פירושו: תתניעי את זה. **פעם אחת** אם זה לא רץ והבעלים יש המאזינים על □ אם זה יוצא 4 (גב-off), להשאיר אותו; הבדיקה בשעה הקרובה תנסה שוב.

**אחרי:** אחת `send_later`, 60 דקות החוצה, עם ההודעה: *"Board לבדוק: הפעל `claude-tasks/bridge/link.sh update`, ואז `claude-tasks/bridge/link.sh status` ו-`link.sh pending`, טפל בכל דבר הממתין, וודא שהמאזין פועל."* `status` re-re-registers לבדו, בשקט ובאותו ערוץ, כאשר המכל הוחלף (קובץ הקישור נעלם אבל הלוח יודע את ההפעלה הזאת). כשזה יורה, תעשה בדיוק את זה ותקבע תור בפעם הבאה. תפסיק לתזמן כשאתה מתנתק. "תוודא שזה פועל" פירושו: תתחיל את זה פעם אחת אם זה לא פועל והבעלים יש את המאזינים ב— אם זה יוצא 4 (גב-off), להשאיר אותו; הבדיקה בשעה הקרובה תנסה שוב.

### קטע 8

**מקור:** **The project id never comes from the environment** (spec §5.9.1). It comes from the prompt the owner copied from the board (⚙ → "🆕 צ'אט חדש"), which already contains the exact `link.sh register …` command. `CLAUDE_TASKS_PROJECT` is ignored — `link.sh` warns if it is set; pass the warning on to the owner (it is a leftover to delete).

**לפני:** **הפרויקט הזה לא מגיע מהסביבה.** (PAC §5.9.1). זה בא מהמסירה שבעל הבית העתיק לוח () שכבר מכיל את המדויק `link.sh register …` פקודה. `CLAUDE_TASKS_PROJECT` הוא התעלם □ `link.sh` אזהרה אם היא נקבעה; העבר את האזהרה לבעלים (זה שאריות למחוק).

**אחרי:** הפרויקט לא מגיע מהסביבה (הספק §5.9.1). הוא מגיע מהסדר שהבעלים העתיק מהלוח (⚙ → "🆕 צ'אט חדש"), שכבר מכיל את הפקודה `link.sh register …` המדויקת. `CLAUDE_TASKS_PROJECT` הוא התעלם — `link.sh` מזהיר אם הוא מוגדר; העבר את האזהרה לבעלים (זה שאריות למחוק).

### קטע 9

**מקור:** The Stop hook reports the chat's size to the board every 5 minutes (`channel-health`: transcript bytes, turns, compactions). When it gets long the owner sees 🟡 / 🔴 and may press "🔁 צ'אט המשך". You then get a `handoff_request`:

**לפני:** וו Stop מדווח גודל השיחה ללוח כל 5 דקות (`channel-health`: תעתיק בתים, תורים, * (1968). כאשר הוא מקבל ארוך הבעלים רואה □ / □ ויכול ללחוץ " □ Ch'004 (0) " אז אתה מקבל `handoff_request`:

**אחרי:** וו Stop מדווח גודל השיחה ללוח כל 5 דקות (`channel-health`: Triple Bytes, turns, lomptions). כאשר הוא מקבל ארוך הבעלים רואה 🟡 / 🔴 ויכול ללחוץ "🔁 צ'אט המשך". אתה מקבל `handoff_request`:

## מה עדיין חלש

- שמות ומונחים טכניים (Approvals, re-registers) נשארים לפעמים באנגלית או מתורגמים מילולית ("המכל" ל-container).
- הדגשה בתוך משפט יורדת בתרגום (המילים מתורגמות, הכוכביות לא נשלחות).
- זו מגבלה של המודל עצמו; ההמרה שלנו מהמודל הרשמי תוחלף רק אם בהשוואה הזו האיכות לא יורדת (§5.11.1).

## 12e.7 — ההמרה שלנו מול ההמרות הקיימות

אותם 10 קטעים, אותו מפרק (12e.6), אותה מכונה, transformers.js 3.7.6 ‏q8 על WASM — רק המודל מתחלף.
"שלנו" = `claude-tasks/tools/models/convert.sh` על המודלים הרשמיים של Helsinki-NLP (בדיוק הקבצים שה-workflow מעלה).

| | עברית: `sivelsguy/opus-mt-en-he-onnx` | עברית: שלנו (`Helsinki-NLP/opus-mt-en-he@b3df900`) | ספרדית: `Xenova/opus-mt-en-es` | ספרדית: שלנו (`Helsinki-NLP/opus-mt-en-es@5bc4493`) |
| --- | --- | --- | --- | --- |
| מזהים שנשמרו | 48/48 | 48/48 | 48/48 | 48/48 |
| "□" | 0 | 0 | 0 | 0 |
| מילים באנגלית שנשארו (בעברית) | 20 | 18 | — | — |
| משפטים זהים / שונים | | 29 / 12 | | 18 / 23 |
| זמן ל-10 הקטעים | 4.5 ש' | 5.0 ש' | 23.0 ש' | 4.7 ש' |
| הורדה | 147MB | 147MB | 113MB | 146MB |

**ההכרעה: מחליפים בשתי השפות.** האיכות לא יורדת: המדדים שווים או טובים יותר, והמשפטים השונים חצי-חצי — לטובתנו
"מספר הזיהוי של הפרויקט לעולם לא מגיע מהסביבה" (הקהילתי: "הפרויקט לא מגיע מהסביבה"), "תעתיק בתים, תורים" (הקהילתי:
"Triple Bytes, turns, lomptions"), "בקשה קצרה אחת"; לרעתנו "הצ'ופן-הבחנת" ו-"(""Kewname )" במקום "Ex0 ender" שהיה גרוע
בלאו הכי. בספרדית: פנייה עקבית ב-"tú" (הקיים מערבב "usted" ו-"tú"), "reflejan cada aviso" במקום "espejo cada propietario";
פעם אחת "board" נשאר באנגלית.

**ספרדית פי 4.9 מהירה, ו-33MB גדולה יותר — מאותה סיבה.** ב-Marian ה-lm_head חולק משקל עם ה-embedding, והייצוא כותב
אותו כ-`Transpose(embedding)` שרץ בכל טוקן. כך הוא נשאר לא מכומת ("Ignore MatMul due to non constant B") — 41–45ms לטוקן
במקום 6–12. ההמרה של Xenova לספרדית בדיוק כזו. `fold_q8.py` מקפל את ה-Transpose לקבוע לפני הכימות: הקבוע (65K×512
ב-int8) הוא 33MB, והטוקן יורד ל-6ms. onnxslim היה מקפל בעצמו, אבל עם קיפול הקבועים שלו הענף של ה-If במפענח המאוחד נשבר
בזמן ריצה ("Starts must be a 1-D array") — לכן הוא רץ בלי הסקת צורות ובלי קיפול קבועים. `smoke.mjs` ב-workflow נכשל
מעל 25ms לטוקן, כדי שזה לא יחזור בשקט.
