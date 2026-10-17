// מייצר אייקון PNG לכל אפליקציה (מהאימוג'י שב-app.json), בשביל ה-manifest שהפלטפורמה נותנת
// לאפליקציה שאין לה manifest משלה (platform/pwa.php). רץ ידנית, בפיתוח — התוצאות בגיט:
//   node platform/tools/app-icons.mjs        (צריך playwright; בסביבת הפיתוח הוא קיים)
// אפליקציה חדשה בלי אייקון כאן — ה-manifest משתמש באייקון של הפלטפורמה.
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
let chromium;
try { ({ chromium } = require('playwright')); }
catch { ({ chromium } = createRequire('/opt/node22/lib/node_modules/')('playwright')); }

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const out = join(root, 'platform/assets/app-icons');
const hue = (t) => { let h = 0; for (const ch of t) h = (h * 31 + ch.codePointAt(0)) % 360; return h; };   // כמו ב-app.js

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 512, height: 512 } });
for (const dir of readdirSync(root)) {
    const file = join(root, dir, 'app.json');
    if (!existsSync(file)) continue;
    const app = JSON.parse(readFileSync(file, 'utf8'));
    // רקע מלא (מתאים גם ל-maskable — העיגול של אנדרואיד חותך את השוליים), אימוג'י במרכז.
    await page.setContent(`<body style="margin:0"><div style="width:512px;height:512px;display:grid;place-items:center;
        background:hsl(${hue(app.id)} 70% 92%);font:300px/1 'Noto Color Emoji','Apple Color Emoji',sans-serif">${app.icon}</div></body>`);
    for (const size of [512, 192]) {
        await page.setViewportSize({ width: 512, height: 512 });
        const buf = await page.screenshot({ clip: { x: 0, y: 0, width: 512, height: 512 } });
        if (size === 512) { require('node:fs').writeFileSync(join(out, `${app.id}-512.png`), buf); continue; }
        // 192 — הקטנה בדפדפן עצמו
        const b64 = buf.toString('base64');
        await page.setContent(`<body style="margin:0"><img src="data:image/png;base64,${b64}" style="width:192px;height:192px;display:block"></body>`);
        await page.setViewportSize({ width: 192, height: 192 });
        require('node:fs').writeFileSync(join(out, `${app.id}-192.png`), await page.screenshot({ clip: { x: 0, y: 0, width: 192, height: 192 } }));
    }
    console.log(app.id, app.icon);
}
await browser.close();
