# הרכבת השכבות המשותפת (`render-core`)

איך שכבות נראות יחד, בכל החבילה: מצבי מיזוג ושקיפות בלינארי, עם אותה נוסחה ב-JS, ב-GLSL (WebGL2) וב-WGSL (WebGPU).
יש גם מרכיב שעובד במעבד, כחלופה כשאין כרטיס גרפי, ולתמונות ממוזערות ולבודק. בלי תלויות, והכול נבדק ב-node, חוץ
מבחירת המנוע.

נחלץ מהרסטר (`raster-studio/assets/js/engine/{webgl2,webgpu,budget}.js`). פריט **מ4** ב-`docs/spec/work-order.md`.

**מה לא כאן:** המנועים עצמם (`WebGL2Renderer`, `WebGPURenderer`) נשארו ברסטר. הם מנועי **עריכה**: אריחים, משיכות מכחול,
בחירה, Warp, סגנונות שכבה, שכבות התאמה ומילוי. כל אחד מהם מקבל מכאן את נוסחת המיזוג ואת ההמרה של הצבע, וכך יש מקור
אחד ל"איך זה נראה". אפליקציה אחרת שצריכה מנוע GPU בונה אותו מעל אותם קטעי שיידר.

## מבנה

```
src/js/index.js       נקודת כניסה אחת (re-export של הכול)
src/js/blend.js       BLEND_MODES, blendIndex, blendChannel, blendOver, GLSL_BLEND, WGSL_BLEND
src/js/color.js       srgbToLinear, linearToSrgb, SRGB_TO_LINEAR, GLSL_DECODE / GLSL_ENCODE / GLSL_COLOR, WGSL_COLOR
src/js/composite.js   composite — עץ שכבות → תמונה, במעבד
src/js/backend.js     webgl2Supported, webgpuAvailable, pickBackend — רק בדפדפן
src/js/budget.js      deviceBudget, suggestSize, megapixels, perfFactor, perfLabel, perfCacheValid
tests/*.test.mjs      node --test creative-suite/render-core/tests/*.test.mjs
tools/sync.php        מעתיק את src/js לאפליקציות שב-apps.json; ‎--check — שער בפריסה
apps.json             מי משתמש
```

**עורכים רק כאן**, מריצים `php creative-suite/render-core/tools/sync.php`, ומעלים את המקור ואת העותקים באותו commit.
עותק ששונה בתוך אפליקציה — הפריסה נעצרת.

## הצטרפות

1. `apps.json`: `"<app>": { "js": "assets/js/render-core" }` — תיקייה שכולה של המודול (הסנכרון מוחק בה קבצים זרים).
2. `php creative-suite/render-core/tools/sync.php`.
3. `import { composite, BLEND_MODES } from './render-core/index.js';` (או קובץ בודד) — בלי `import` מחוץ לתיקיית האפליקציה.
4. יש service worker? להוסיף את הקבצים לרשימת השלד ולהעלות את גרסת המטמון.
5. לסמן את התא בשורה של `render-core` ב-`docs/integration.md`.

## המודל

- **צבע:** הקלט (פיקסלים, צבעים) ב-sRGB של 8 ביט, עם אלפא **ישר**. המיזוג **בלינארי ומוכפל באלפא**. 50% לבן על שחור
  נותן 188, לא 128, בדיוק כמו ברסטר.
- **מצבי מיזוג:** W3C Compositing and Blending Level 1 — מיזוג נפרד לכל ערוץ, ואחריו source-over. הסדר ב-`BLEND_MODES`
  קבוע, כי האינדקס הוא מה שהשיידר מקבל. מצב לא מוכר מתנהג כמו `normal`. היום: `normal` · `multiply` · `screen` ·
  `overlay`. מצב חדש נוסף כאן, בשלוש השפות, ובבדיקה.
- **קבוצה מבודדת:** הילדים מורכבים לבד על שקוף, ואז התוצאה נממזגת עם האטימות, המצב והמסכה של הקבוצה (כמו ב-ORA וברסטר).
- **מסכה:** גווני אפור של 8 ביט. 255 = גלוי, 0 = מוסתר, 128 ≈ חצי (כמו בפוטושופ).
- **שגיאות:** `message` בעברית שמתחיל ב-`render-core:`, ו-`code` קבוע באנגלית.

## ה-API

| פונקציה | מה |
| --- | --- |
| `composite({ width, height, layers }, { linear }?)` → `Uint8ClampedArray` | העץ (מלמטה למעלה) → width×height×4, sRGB עם אלפא ישר (ל-`ImageData`, ל-`encodePNG` של image-io). `linear: true` → `Float32Array` לינארי מוכפל. גודל לא תקין → `code: 'bad-size'` |
| שכבה | `{ rgba, w, h, x = 0, y = 0, opacity = 1, visible = true, blend = 'normal', mask? }`. מה שחורג מהמסמך נחתך |
| קבוצה | `{ type: 'group', children, opacity, visible, blend, mask? }` |
| מסכה | `{ data, w, h, x = 0, y = 0, outside = 0, enabled = true }`, במערכת של המסמך. `outside` — הערך מחוץ למסכה |
| `BLEND_MODES`, `blendIndex(name)` | הרשימה, ושם → אינדקס לשיידר |
| `blendChannel(mode, Cb, Cs)` | B(Cb, Cs) לערוץ אחד (לא מוכפלים, 0–1) |
| `blendOver(out, b, s, mode)` | פיקסל: b, s — `[r, g, b, a]` לינאריים מוכפלים (s כבר עם אטימות ומסכה) |
| `GLSL_BLEND` / `WGSL_BLEND` | `blendOver(b, s, mode)` (+ `screenB`, `hardLight`) להכללה בשיידר |
| `srgbToLinear(v)` / `linearToSrgb(l)` / `SRGB_TO_LINEAR` | 0–255 ↔ 0–1; טבלה ל-8 ביט |
| `GLSL_DECODE` / `GLSL_ENCODE` / `GLSL_COLOR` / `WGSL_COLOR` | `dec` / `enc` על `vec3` |
| `await pickBackend({ prefer }?)` → `'webgpu' \| 'webgl2' \| 'canvas'` | **דפדפן.** המנוע הטוב ביותר. `prefer: 'webgl2'` / `'canvas'` מכריח פשוט יותר. `'canvas'` = `composite` במעבד |
| `webgl2Supported()` / `await webgpuAvailable()` | **דפדפן.** WebGL2 עם float שאפשר לצייר אליו; מתאם WebGPU אמיתי |
| `deviceBudget({ deviceMemory, mobile, perf })` | כמה פיקסלים המכשיר מחזיק: `{ bytes, maxPixels(layers), memGB, guessed, perf }` |
| `suggestSize(w, h, maxPixels)` | גודל באותו יחס שנכנס, או `null` |
| `perfFactor(score)` / `perfLabel(score)` / `perfCacheValid(c, key)` | מדידת הביצועים במכשיר → מקדם לתקציב |

## איך נבדק שזו אותה נוסחה

- `tests/render-core.test.mjs`: ההמרות, כל מצב מיזוג, אטימות, מסכה, קבוצה ותקציב (13 בדיקות).
- בבדיקות הדפדפן של הרסטר: `composite` מול מה שהמנוע מצייר, בכל מצב, עם קבוצה מבודדת ואטימות. ±1 בכל ערוץ, גם
  ב-WebGL2 וגם ב-WebGPU (`RS_RENDERER=webgpu`).

## מי משתמש

| אפליקציה | מה |
| --- | --- |
| `raster-studio` | שני המנועים: נוסחת המיזוג, `enc` / `dec`, `srgbToLinear`, `webgl2Supported` ו-`BLEND_MODES` (גם בלוח השכבות); התקציב בעורך |

שאר האפליקציות מצטרפות בתור שלהן (`work-order.md` §3). מועמדים: תצוגת שכבות `raster` בבודק של הליבה, העימוד וסטודיו
העיצוב (שכבות רסטר בעמוד), והמיזוג לפריט בווקטורי.

## לא כאן (עדיין)

- מצבי מיזוג נוספים (darken, lighten, color-dodge, hue…) — יחד עם הרסטר, בשלוש השפות.
- 16 / 32 ביט, CMYK ו-Lab — עם `color-core`.
- מנוע GPU כללי לצפייה בלבד (בלי עריכה) לאפליקציות אחרות — כשאחת תצטרך אותו.
