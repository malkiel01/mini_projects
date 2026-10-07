/* התפריט להדפסה: מצייר את התפריט מהמערכת בדיוק במבנה של התפריט המודפס,
   ומוציא PDF. הכול בדפדפן — בלי שרת ובלי ספריות.

   המידות לקוחות מקובץ ה-PDF המקורי, ביחידות שלו: רוחב הדף = 1052 יחידות
   (A4 = 1052×1495). הציור נעשה ב-300dpi (2480 פיקסלים לרוחב).
   - הכותרת הכהה, צדי המסגרת והפס התחתון — תמונות מהמקור (assets/print/).
   - העלים והסלסולים בפינות — וקטורים מהמקור (assets/print/corners.json).
   - הלוגו, איש הקשר והטלפונים — מהגדרות המערכת.
   - הקטגוריות והמנות — מהתפריט, בלי מנות מוסתרות.
   - עיצוב הכתב (גופן, עובי, גודל, רוחב) — לכותרות ולמנות בנפרד, מהגדרות המערכת.
   תפריט ארוך מ-A4 לא נדחס: הדף מתארך. */
(() => {
    'use strict';

    const W = 1052;                 // רוחב הדף ביחידות המקור
    const A4 = 1495;                // גובה A4
    const HEAD = 241;               // גובה הכותרת הכהה
    const FOOT = 195;               // גובה התמונה התחתונה (קצות המסגרת והפס)
    const PT = 595.28 / W;          // יחידה → נקודת PDF
    const TOP = 273;                // קו הבסיס של הכותרת הראשונה בכל עמודה (מראש הדף)

    const C = {
        paper:  '#fbf8ed',
        gold:   '#ad7e2b',          // קווים ועיגולים
        fill:   '#e4bd69',          // מעוינים מלאים
        title:  '#9d6b16',          // כותרות הקטגוריות
        text:   '#171a1b',          // המנות
        phone:  '#ecc373',          // איש קשר וטלפונים בכותרת
    };
    // שתי העמודות. מימין — הראשונה (בעברית קוראים מימין)
    const COLS = [
        { from: 557, to: 993, circle: 983, text: 963, mid: 778, bottom: 118.8 },
        { from: 62,  to: 497, circle: 478, text: 458, mid: 283, bottom: 33.6 },
    ];
    const PITCH_MAX = 32.4;         // מרווח שורות כשיש מקום (כמו בסלטים במקור), בגודל כתב 100%
    const PITCH_MIN = 27;           // צפוף מזה — מאריכים את הדף
    const ITEM_FONT = 23, TITLE_FONT = 42;
    const MAX_TEXT = 396;           // רוחב מרבי לשם מנה; ארוך יותר — יורד שורה

    /* הגופנים לבחירה. עובי: טווח (גופן משתנה) או רשימה. הקבצים ב-assets/fonts/. */
    const FONTS = {
        'noto-serif': { name: 'נוטו סריף — הכותרות המקוריות', w: [100, 900] },
        'frank-ruhl': { name: 'פרנק רוהל — קלאסי', w: [300, 900] },
        'david':      { name: 'דוד — קלאסי מעוגל', w: [400, 500, 700], perWeight: true },
        'suez':       { name: 'סואץ — מודגש וחגיגי', w: [400, 400] },
        'secular':    { name: 'סקולר — מודרני מודגש', w: [400, 400] },
        'rubik':      { name: 'רוביק — המנות המקוריות', w: [300, 900] },
        'heebo':      { name: 'היבו — נקי ומודרני', w: [100, 900] },
        'assistant':  { name: 'אסיסטנט — עדין', w: [200, 800] },
        'noto-sans':  { name: 'נוטו סאנס — פשוט', w: [100, 900] },
    };
    const WEIGHT_NAMES = { 100: 'דק מאוד', 200: 'דק', 300: 'קל', 400: 'רגיל', 500: 'בינוני', 600: 'חצי מודגש', 700: 'מודגש', 800: 'מודגש מאוד', 900: 'שחור' };
    /** העיצוב המקורי — כמו בקובץ. size ו-width באחוזים. */
    const DEFAULT_STYLE = {
        title: { font: 'noto-serif', weight: 700, size: 100, width: 100 },
        item:  { font: 'rubik', weight: 400, size: 100, width: 100 },
    };
    const RANGES = { hebrew: 'U+0590-05FF, U+200C-2010, U+20AA, U+25CC, U+FB1D-FB4F', latin: 'U+0000-00FF, U+2000-206F' };

    /** העוביים שיש לגופן: [400, 500, 700] או 100…900. */
    function weightsOf(id) {
        const f = FONTS[id];
        if (f.perWeight) return f.w;
        const out = [];
        for (let w = f.w[0]; w <= f.w[1]; w += 100) out.push(w);
        return out;
    }

    /** עיצוב תקין: גופן מוכר, העובי הקרוב שקיים בגופן, וגבולות לגודל ולרוחב. */
    function cleanStyle(style) {
        const out = {};
        for (const part of ['title', 'item']) {
            const s = { ...DEFAULT_STYLE[part], ...(style?.[part] || {}) };
            if (!FONTS[s.font]) s.font = DEFAULT_STYLE[part].font;
            const ws = weightsOf(s.font);
            s.weight = ws.reduce((a, b) => Math.abs(b - s.weight) < Math.abs(a - s.weight) ? b : a);
            s.size = Math.min(150, Math.max(60, Number(s.size) || 100));
            s.width = Math.min(130, Math.max(70, Number(s.width) || 100));
            out[part] = s;
        }
        return out;
    }

    const loadedFonts = new Map();
    /** טוען גופן (עברית + ספרות ולטינית) — פעם אחת לכל גופן. */
    function loadFont(base, id) {
        if (!loadedFonts.has(id)) {
            const f = FONTS[id];
            const files = f.perWeight
                ? f.w.flatMap(w => ['hebrew', 'latin'].map(sub => [`${id}-${sub}-${w}.woff2`, String(w), sub]))
                : ['hebrew', 'latin'].map(sub => [`${id}-${sub}.woff2`, f.w[0] === f.w[1] ? String(f.w[0]) : `${f.w[0]} ${f.w[1]}`, sub]);
            loadedFonts.set(id, Promise.all(files.map(async ([file, weight, sub]) => {
                const face = new FontFace('MP-' + id, `url(${base}fonts/${file})`, { weight, unicodeRange: RANGES[sub] });
                document.fonts.add(await face.load());
            })));
        }
        return loadedFonts.get(id);
    }

    let st = cleanStyle(null);      // העיצוב של הציור הנוכחי
    const titleSize = () => TITLE_FONT * st.title.size / 100;
    const itemSize = () => ITEM_FONT * st.item.size / 100;
    const itemK = () => st.item.size / 100;

    let assets = null;

    const loadImage = src => new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('טעינת תמונה נכשלה: ' + src));
        img.src = src;
    });

    /** תמונות, וקטורים וגופנים — פעם אחת. base: תיקיית assets/ ביחס לדף. */
    async function loadAssets(base) {
        if (assets) return assets;
        await loadFont(base, 'rubik');          // שורת איש הקשר והטלפונים
        const [header, side, bottom, corners] = await Promise.all([
            loadImage(base + 'print/header.jpg'),
            loadImage(base + 'print/side.jpg'),
            loadImage(base + 'print/bottom.jpg'),
            fetch(base + 'print/corners.json').then(r => r.json()),
        ]);
        assets = { header, side, bottom, corners };
        return assets;
    }

    /* ── פריסה ───────────────────────────────────────────────────── */

    let measureCtx = null;
    /** רוחב טקסט בגופן, בעובי, בגודל וברוחב (מתיחה אופקית) שנבחרו. */
    function measure(text, size, spec) {
        measureCtx ??= document.createElement('canvas').getContext('2d');
        measureCtx.font = `${spec.weight} ${size}px MP-${spec.font}`;
        measureCtx.direction = 'rtl';
        return measureCtx.measureText(text).width * spec.width / 100;
    }

    /** טקסט עם מתיחה אופקית: align = 'right' / 'center'. */
    function drawText(ctx, text, x, y, size, spec, color, align) {
        ctx.save();
        ctx.translate(x, y);
        ctx.scale(spec.width / 100, 1);
        ctx.font = `${spec.weight} ${size}px MP-${spec.font}`;
        ctx.direction = 'rtl';
        ctx.textAlign = align;
        ctx.fillStyle = color;
        ctx.fillText(text, 0, 0);
        ctx.restore();
    }

    /** שם ארוך מדי — מתפצל בין מילים לכמה שורות. */
    function wrap(text) {
        if (measure(text, itemSize(), st.item) <= MAX_TEXT) return [text];
        const lines = [];
        let line = '';
        for (const word of text.split(' ')) {
            const next = line ? line + ' ' + word : word;
            if (line && measure(next, itemSize(), st.item) > MAX_TEXT) { lines.push(line); line = word; } else line = next;
        }
        if (line) lines.push(line);
        return lines;
    }

    /** קטגוריה → שורות: { text, circle }. מנה עם אפשרויות מקבלת שורת "לבחירה". */
    function categoryLines(cat) {
        const out = [];
        for (const item of cat.items) {
            wrap((item.extra ? '*' : '') + item.name).forEach((t, i) => out.push({ text: t, circle: i === 0 }));
            if (item.options?.length) {
                wrap('לבחירה - ' + item.options.join(' - ')).forEach((t, i) => out.push({ text: t, circle: i === 0 }));
            }
        }
        return out;
    }

    /* מרווחים קבועים, מותאמים לגודל הכתב: כותרת גדולה יותר צריכה יותר מקום
       מעליה (ושורת המנה הראשונה — מתחתיה). */
    const GAP = {
        top:   () => TOP + 0.75 * (titleSize() - TITLE_FONT),
        first: i => (i ? 31 : 34) + 0.25 * (titleSize() - TITLE_FONT) + 0.75 * (itemSize() - ITEM_FONT),
        sep:   () => 23 + 0.25 * (itemSize() - ITEM_FONT),
        title: () => 51 + 0.75 * (titleSize() - TITLE_FONT),
    };

    /** הגובה הקבוע של עמודה (בלי מרווחי השורות), ומספר מרווחי השורות. */
    function columnShape(cats) {
        let fixed = 0, gaps = 0;
        cats.forEach((cat, i) => {
            if (i) fixed += GAP.sep() + GAP.title();    // קו מפריד, ואז הכותרת הבאה
            fixed += GAP.first(i);                       // מהכותרת לשורה הראשונה
            gaps += cat.lines.length - 1;
        });
        return { fixed, gaps };
    }

    /** כמה גובה צריך עמודה במרווח נתון (עד קו הבסיס האחרון + השוליים שלה). */
    function needed(shape, col, pitch) {
        return GAP.top() + shape.fixed + shape.gaps * pitch + col.bottom;
    }

    /**
     * מחלק את הקטגוריות לשתי עמודות (לפי הסדר), כך שהדף יהיה הכי קצר,
     * וקובע את גובה הדף ואת מרווח השורות בכל עמודה.
     */
    function layout(menu) {
        const cats = menu.categories
            .map(c => ({ name: c.name, lines: categoryLines({ ...c, items: c.items.filter(i => !i.hidden) }) }))
            .filter(c => c.lines.length);
        let best = null;
        const splits = cats.length > 1 ? [...Array(cats.length - 1).keys()].map(k => k + 1) : [cats.length];
        for (const k of splits) {
            const parts = [cats.slice(0, k), cats.slice(k)];
            const h = Math.max(...parts.map((p, i) => p.length ? needed(columnShape(p), COLS[i], PITCH_MIN * itemK()) : 0));
            if (!best || h < best.h) best = { h, parts };
        }
        const height = Math.max(A4, Math.ceil(best?.h ?? A4));
        const columns = (best?.parts ?? [[], []]).map((p, i) => {
            const shape = columnShape(p);
            const room = height - COLS[i].bottom - GAP.top() - shape.fixed;
            const k = itemK();
            const pitch = shape.gaps ? Math.min(PITCH_MAX * k, Math.max(PITCH_MIN * k, room / shape.gaps)) : PITCH_MAX * k;
            return { cats: p, pitch };
        });
        return { height, columns, hasExtra: menu.categories.some(c => c.items.some(i => i.extra && !i.hidden)) };
    }

    /* ── ציור ────────────────────────────────────────────────────── */

    function diamond(ctx, x, y, hw, hh, filled = true) {
        ctx.beginPath();
        ctx.moveTo(x, y - hh); ctx.lineTo(x + hw, y); ctx.lineTo(x, y + hh); ctx.lineTo(x - hw, y);
        ctx.closePath();
        if (filled) { ctx.fillStyle = C.fill; ctx.fill(); }
        ctx.lineWidth = 0.9; ctx.strokeStyle = C.gold; ctx.stroke();
    }

    function hline(ctx, x1, x2, y, w = 1) {
        ctx.beginPath(); ctx.moveTo(x1, y); ctx.lineTo(x2, y);
        ctx.lineWidth = w; ctx.strokeStyle = C.gold; ctx.stroke();
    }

    /** "◆ —— כותרת —— ◆" במרכז העמודה. */
    function drawTitle(ctx, col, text, y) {
        let size = titleSize();
        while (size > 20 && measure(text, size, st.title) > col.to - col.from - 110) size -= 1;
        const w = measure(text, size, st.title);
        const center = (col.from + col.to) / 2;
        const lineY = y - 12.5;
        const l = center - w / 2 - 18, r = center + w / 2 + 18;
        hline(ctx, col.from, l - 14, lineY);
        hline(ctx, r + 14, col.to, lineY);
        diamond(ctx, l, lineY, 4.55, 6.5);
        diamond(ctx, r, lineY, 4.55, 6.5);
        drawText(ctx, text, center, y, size, st.title, C.title, 'center');
    }

    /** "—— • ◇ • ——" בין קטגוריות. */
    function drawSeparator(ctx, col, y) {
        const m = col.mid;
        hline(ctx, col.from - 1, m - 16, y);
        hline(ctx, m + 16, col.to, y);
        diamond(ctx, m, y, 6.3, 9, false);
        ctx.fillStyle = C.gold;
        for (const x of [m - 16, m + 16]) { ctx.beginPath(); ctx.arc(x, y, 1.65, 0, Math.PI * 2); ctx.fill(); }
    }

    function drawColumn(ctx, col, { cats, pitch }) {
        let y = GAP.top();
        const k = itemK();
        cats.forEach((cat, i) => {
            if (i) {
                drawSeparator(ctx, col, y + GAP.sep());
                y += GAP.sep() + GAP.title();
            }
            drawTitle(ctx, col, cat.name, y);
            y += GAP.first(i);
            cat.lines.forEach((line, j) => {
                if (j) y += pitch;
                if (line.circle) {
                    ctx.beginPath(); ctx.arc(col.circle, y - 7.2 * k, 9.5 * Math.min(1.2, Math.max(0.8, k)), 0, Math.PI * 2);
                    ctx.lineWidth = 1.25; ctx.strokeStyle = C.gold; ctx.stroke();
                }
                drawText(ctx, line.text, col.text, y, itemSize(), st.item, C.text, 'right');
            });
        });
    }

    /** הוקטורים של הפינות התחתונות — בקואורדינטות המקור (y מלמטה). */
    function drawCorners(ctx, ops, height) {
        ctx.save();
        ctx.translate(0, height);
        ctx.scale(1, -1);
        for (const [op, v] of ops) {
            if (op === 'RG') ctx.strokeStyle = v;
            else if (op === 'rg') ctx.fillStyle = v;
            else if (op === 'w') ctx.lineWidth = v;
            else {
                const p = new Path2D(v);
                if (op === 'B') ctx.fill(p, 'evenodd');
                ctx.stroke(p);
            }
        }
        ctx.restore();
    }

    /** הלוגו — חתוך לגבולות הציור (בלי הרקע שמסביב), ומותאם לתיבה בכותרת. */
    function drawLogo(ctx, img) {
        const probe = document.createElement('canvas');
        const k = Math.min(1, 600 / img.naturalWidth);
        probe.width = Math.round(img.naturalWidth * k);
        probe.height = Math.round(img.naturalHeight * k);
        const pc = probe.getContext('2d', { willReadFrequently: true });
        pc.drawImage(img, 0, 0, probe.width, probe.height);
        const { data, width, height } = pc.getImageData(0, 0, probe.width, probe.height);
        const bg = [data[0], data[1], data[2], data[3]];
        let x0 = width, y0 = height, x1 = -1, y1 = -1;
        for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
                const i = (y * width + x) * 4;
                const diff = bg[3] < 20 ? data[i + 3] : Math.abs(data[i] - bg[0]) + Math.abs(data[i + 1] - bg[1]) + Math.abs(data[i + 2] - bg[2]);
                if (diff > 40) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
            }
        }
        if (x1 < 0) { x0 = 0; y0 = 0; x1 = width - 1; y1 = height - 1; }
        const sx = x0 / k, sy = y0 / k, sw = (x1 - x0 + 1) / k, sh = (y1 - y0 + 1) / k;
        // התיבה בכותרת: כמו הלוגו במקור (רוחב עד 330, גובה עד 124, מתחיל 26 מלמעלה)
        const scale = Math.min(330 / sw, 124 / sh);
        const dw = sw * scale, dh = sh * scale;
        ctx.save();
        ctx.globalCompositeOperation = 'lighten';    // רקע כהה של הלוגו נבלע ברקע הכותרת
        ctx.drawImage(img, sx, sy, sw, sh, W / 2 - dw / 2, 26 + (124 - dh) / 2, dw, dh);
        ctx.restore();
    }

    /** "חמדי בן עזרא. 055-2423846 / 02-585-5302" — מתחת ללוגו. */
    function drawContact(ctx, business) {
        const phones = (business.phones || []).join(' / ');
        const text = [business.owner ? business.owner + '.' : '', phones].filter(Boolean).join(' ');
        if (!text) return;
        const spec = { font: 'rubik', weight: 700, width: 100 };
        let size = 22;
        while (size > 14 && measure(text, size, spec) > 560) size -= 1;
        drawText(ctx, text, W / 2, 183.5, size, spec, C.phone, 'center');
    }

    function drawFootnote(ctx, note, height) {
        const y = height - 45;
        const text = '* ' + note;
        const size = 19 * itemK();
        const w = measure(text, size, st.item);
        const x = 686;                       // מרכז ההערה, כמו במקור
        drawText(ctx, text, x, y, size, st.item, C.text, 'center');
        const l = x - w / 2, r = x + w / 2;
        hline(ctx, l - 64, l - 28, y - 8, 0.8);
        hline(ctx, r + 27, r + 65, y - 8, 0.8);
        diamond(ctx, l - 17.7, y - 8, 2.8, 4);
        diamond(ctx, r + 17, y - 8, 2.8, 4);
    }

    /**
     * מצייר את התפריט. menu: התפריט מהמערכת; logo: תמונה טעונה; style: עיצוב הכתב;
     * preview: ציור מהיר ברזולוציה נמוכה (לתצוגה מקדימה בזמן כיוונון).
     * מחזיר { canvas, height } — height ביחידות המקור (A4 = 1495).
     */
    async function render(menu, logo, base = '../assets/', style = null, preview = false) {
        const s = cleanStyle(style);
        const a = await loadAssets(base);
        await Promise.all([loadFont(base, s.title.font), loadFont(base, s.item.font)]);
        st = s;
        const plan = layout(menu);
        const H = plan.height;
        // 300dpi; בדף ארוך מאוד — פחות, כדי לא לעבור את גבול הקנבס של ספארי (16 מיליון פיקסלים)
        const S = preview ? 1 : Math.min(2480 / W, Math.sqrt(16e6 / (W * H)));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(W * S);
        canvas.height = Math.round(H * S);
        const ctx = canvas.getContext('2d');
        ctx.scale(S, S);
        ctx.fillStyle = C.paper;
        ctx.fillRect(0, 0, W, H);

        ctx.drawImage(a.header, 0, 0, W, HEAD);
        ctx.drawImage(a.side, 0, HEAD, W, H - FOOT - HEAD);
        ctx.drawImage(a.bottom, 0, H - FOOT, W, FOOT);
        drawCorners(ctx, a.corners, H);
        if (logo) drawLogo(ctx, logo);
        drawContact(ctx, menu.business || {});

        // הקו האמצעי ושלושת המעוינים שעליו
        ctx.beginPath(); ctx.moveTo(526, 261); ctx.lineTo(526, H - 41);
        ctx.lineWidth = 0.85; ctx.strokeStyle = C.gold; ctx.stroke();
        for (const y of [264, (264 + H - 57) / 2, H - 57]) diamond(ctx, 526, y, 6.3, 9);

        plan.columns.forEach((c, i) => drawColumn(ctx, COLS[i], c));
        if (plan.hasExtra) drawFootnote(ctx, menu.extraNote || 'תוספת תשלום', H);
        return { canvas, height: H };
    }

    /* ── PDF ─────────────────────────────────────────────────────── */

    /** PDF של עמוד אחד, שכולו התמונה (JPEG). בגודל A4 לרוחב; הגובה לפי התפריט. */
    async function toPdf({ canvas, height }, title = 'תפריט') {
        const jpeg = new Uint8Array(await (await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.92))).arrayBuffer());
        // כמו במקור: הרוחב 595.28 נקודות, והגובה כך ש-1495 יחידות = A4 בדיוק (841.89)
        const w = (W * PT).toFixed(2), h = (height * 841.89 / A4).toFixed(2);
        const enc = new TextEncoder();
        const parts = [];
        const offsets = [];
        let size = 0;
        const push = chunk => { const b = typeof chunk === 'string' ? enc.encode(chunk) : chunk; parts.push(b); size += b.length; };
        const obj = (n, body) => { offsets[n] = size; push(`${n} 0 obj\n`); body(); push('\nendobj\n'); };
        // כותרת המסמך ב-UTF-16 (כדי שעברית תוצג נכון ב"מאפייני המסמך")
        const utf16 = '<FEFF' + [...title].map(ch => ch.codePointAt(0).toString(16).padStart(4, '0')).join('') + '>';
        const content = `q ${w} 0 0 ${h} 0 0 cm /Im0 Do Q`;

        push('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');
        obj(1, () => push('<< /Type /Catalog /Pages 2 0 R >>'));
        obj(2, () => push('<< /Type /Pages /Kids [3 0 R] /Count 1 >>'));
        obj(3, () => push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>`));
        obj(4, () => {
            push(`<< /Type /XObject /Subtype /Image /Width ${canvas.width} /Height ${canvas.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpeg.length} >>\nstream\n`);
            push(jpeg);
            push('\nendstream');
        });
        obj(5, () => push(`<< /Length ${content.length} >>\nstream\n${content}\nendstream`));
        obj(6, () => push(`<< /Title ${utf16} /Producer (catering-menu) >>`));
        const xref = size;
        push(`xref\n0 7\n0000000000 65535 f \n${offsets.slice(1).map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('')}`);
        push(`trailer\n<< /Size 7 /Root 1 0 R /Info 6 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
        return new Blob(parts, { type: 'application/pdf' });
    }

    window.MenuPdf = { render, toPdf, A4, FONTS, WEIGHT_NAMES, DEFAULT_STYLE, weightsOf, cleanStyle };
})();
