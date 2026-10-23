// תבניות מוכנות: נקודת התחלה במקום דף ריק. בלי תמונות — רק צורות, אלמנטים, אייקונים, מדרגים וטקסט,
// כך שהן קלות, נטענות מיד, וכל רקע (מהצ'אט או מהמכשיר) נכנס מתחתן. כל תבנית עוברת את normalizeDoc
// כמו כל ייבוא. ‏cat — הקטגוריה בבוחר (TEMPLATE_CATS).

export const TEMPLATE_CATS = ['חגים', 'אירועים', 'ימי הולדת', 'עסקים ומבצעים', 'רשתות חברתיות', 'ברכות'];

const T = (id, text, x, y, w, size, color, extra = {}) => ({
    id, type: 'text', role: extra.role || 'body', text, x, y, w, h: size * 1.3, rotation: extra.rotation || 0,
    style: { font: extra.font || 'Heebo', size, weight: extra.weight || 400, color, align: extra.align || 'center', dir: 'rtl', lineHeight: extra.lineHeight || 1.15, ...(extra.style || {}) },
});
const R = (id, x, y, w, h, fill, extra = {}) => ({ id, type: 'shape', shape: 'rect', role: 'decoration', x, y, w, h, fill, radius: 0, ...extra });
const E = (id, x, y, w, h, fill, extra = {}) => ({ id, type: 'shape', shape: 'ellipse', role: 'decoration', x, y, w, h, fill, ...extra });
const I = (id, icon, x, y, s, color, extra = {}) => ({ id, type: 'icon', role: 'decoration', icon, x, y, w: s, h: s, color, strokeWidth: 1.5, ...extra });
/** אלמנט מעוצב (elements.js) במילוי. */
const L = (id, element, x, y, w, h, fill, extra = {}) => ({ id, type: 'shape', shape: 'element', element, role: 'decoration', x, y, w, h, fill, ...extra });
/** אלמנט בקו בלבד (מסגרות, קווים). */
const F = (id, element, x, y, w, h, color, width, extra = {}) => L(id, element, x, y, w, h, '#00000000', { stroke: { width, color }, ...extra });
const lin = (angle, ...colors) => ({ type: 'linear', angle, colors });
const rad = (...colors) => ({ type: 'radial', colors });
const page = (width, height, background, gradient) => ({ width, height, background, ...(gradient ? { gradient } : {}) });
const shadow = (blur, y, color) => ({ shadow: { x: 0, y, blur, color } });

export const TEMPLATES = [
    // ─── חגים ──────────────────────────────────────────────────────────────
    {
        id: 'rosh-hashana', cat: 'חגים', label: 'ראש השנה — רימון',
        doc: {
            version: 1, title: 'שנה טובה', page: page(1080, 1350, '#7a0f2b', rad('#a4133c', '#4a0418')),
            layers: [
                L('sb', 'sunburst', -260, -100, 1600, 1600, '#ffffff0d'),
                F('fr', 'frame-double', 60, 60, 960, 1230, '#e9c46a', 4),
                L('sp1', 'sparkle', 150, 170, 70, 70, '#e9c46a'), L('sp2', 'sparkle', 860, 230, 50, 50, '#e9c46a'),
                E('glow', 340, 190, 400, 400, '#ffffff14'),
                I('ic', 'apple', 410, 250, 260, '#ffd166', { filled: true, ...shadow(30, 10, '#00000055') }),
                T('t1', 'שנה טובה', 90, 620, 900, 190, '#ffd166', { role: 'title', font: 'Suez One', ...{ style: { shadow: { x: 0, y: 8, blur: 24, color: '#00000066' } } } }),
                T('t2', 'ומתוקה', 90, 830, 900, 140, '#ffffff', { role: 'subtitle', font: 'Amatic SC', weight: 700 }),
                L('dv', 'divider-diamond', 290, 1010, 500, 50, '#e9c46a'),
                T('t3', 'כתיבה וחתימה טובה\nמאחלים משפחת כהן', 140, 1090, 800, 46, '#f5d6db', { lineHeight: 1.45 }),
            ],
        },
    },
    {
        id: 'shana', cat: 'חגים', label: 'שנה טובה לנמענים',
        doc: {
            version: 1, title: 'שנה טובה', page: page(1500, 1050, '#7a1023', lin(135, '#8f1a2e', '#5c0a1c')),
            layers: [
                E('c1', 950, -300, 900, 900, '#ffffff10'),
                L('ds', 'confetti-dots', 1050, 60, 380, 380, '#ffd16655'),
                I('i1', 'sparkles', 1180, 110, 180, '#ffd166'),
                I('i2', 'candy', 120, 760, 160, '#ffd166'),
                T('t1', 'שנה טובה\nומתוקה', 150, 210, 1200, 170, '#ffd166', { role: 'title', font: 'Suez One', lineHeight: 1 }),
                T('t2', 'ל{{שם}} ולכל המשפחה', 150, 640, 1200, 70, '#ffffff', { role: 'subtitle', weight: 700 }),
                L('dv', 'divider-heart', 500, 735, 500, 40, '#ffd166'),
                T('t3', 'בברכת כתיבה וחתימה טובה', 150, 800, 1200, 52, '#f5d6db'),
            ],
            merge: { columns: ['שם'], rows: [{ 'שם': 'משפחת כהן' }, { 'שם': 'דנה' }], nameColumn: 'שם', phoneColumn: null, fileColumn: 'שם' },
        },
    },
    {
        id: 'sukkot', cat: 'חגים', label: 'חג סוכות',
        doc: {
            version: 1, title: 'חג סוכות שמח', page: page(1080, 1350, '#fff6dc', lin(180, '#fff6dc', '#ffe9a8')),
            layers: [
                L('w1', 'wave-bottom', -20, 1010, 1120, 360, '#7cb518'),
                L('w2', 'wave-bottom', -20, 1110, 1120, 260, '#4f772d', { flipX: true }),
                I('l1', 'leaf', 90, 90, 150, '#4f772d', { filled: true, rotation: -20 }), I('l2', 'leaf-2', 830, 120, 170, '#7cb518', { rotation: 25 }),
                I('l3', 'lemon', 760, 860, 160, '#e9b200', { filled: true }), I('l4', 'plant-2', 160, 870, 150, '#4f772d'),
                L('st', 'stars-3', 390, 330, 300, 100, '#e9b200'),
                T('t1', 'חג סוכות\nשמח', 90, 440, 900, 170, '#31572c', { role: 'title', font: 'Secular One', lineHeight: 1 }),
                T('t2', 'מועדים לשמחה, חגים וזמנים לששון', 90, 810, 900, 50, '#4f5d2f'),
                T('t3', 'משפחת לוי', 90, 1220, 900, 50, '#ffffff', { role: 'cta', weight: 700 }),
            ],
        },
    },
    {
        id: 'hanukkah', cat: 'חגים', label: 'חנוכה — חג אורים',
        doc: {
            version: 1, title: 'חנוכה שמח', page: page(1080, 1350, '#0b1a3a', lin(180, '#0b1030', '#1c2f6b')),
            layers: [
                ...[[120, 120, 60], [900, 160, 80], [560, 90, 44], [300, 220, 36], [760, 260, 40]].map(([x, y, sz], i) => L('sp' + i, 'sparkle', x, y, sz, sz, '#ffd166')),
                ...[0, 1, 2, 3, 4, 5, 6, 7, 8].map(i => I('c' + i, 'candle', 85 + i * 102, i === 4 ? 300 : 360, 120, '#ffd166', { strokeWidth: 1.4 })),
                R('base', 110, 500, 860, 14, '#ffd166', { radius: 7 }),
                T('t1', 'חג אורים\nשמח', 90, 640, 900, 180, '#ffd166', { role: 'title', font: 'Suez One', lineHeight: 1, style: { shadow: { x: 0, y: 0, blur: 40, color: '#ffb70399' } } }),
                T('t2', 'שיהיה חג מלא אור, סופגניות ושמחה', 90, 1060, 900, 50, '#dbe4ff'),
                L('dv', 'divider-dots', 440, 1150, 200, 40, '#ffd166'),
                T('t3', 'חנוכה שמח!', 90, 1220, 900, 52, '#ffffff', { weight: 700 }),
            ],
        },
    },
    {
        id: 'tu-bishvat', cat: 'חגים', label: 'ט״ו בשבט',
        doc: {
            version: 1, title: 'ט״ו בשבט', page: page(1080, 1080, '#e8f6e0', rad('#f4fbef', '#c9ebb5')),
            layers: [
                L('b1', 'blob-1', 240, 120, 600, 600, '#a7d98a'),
                I('tr', 'tree', 340, 170, 400, '#2d6a4f', { strokeWidth: 1.4 }),
                I('a1', 'apple', 140, 140, 110, '#d62828', { filled: true }), I('a2', 'cherry', 830, 160, 110, '#9d0208', { filled: true }),
                I('a3', 'leaf', 860, 600, 110, '#40916c', { filled: true, rotation: 30 }), I('a4', 'leaf', 110, 600, 110, '#40916c', { filled: true, rotation: -30, flipX: true }),
                T('t1', 'ט״ו בשבט שמח', 90, 740, 900, 120, '#1b4332', { role: 'title', font: 'Secular One' }),
                T('t2', 'חג לאילנות — שתילה, פירות ואוויר צח', 90, 900, 900, 46, '#2d6a4f'),
            ],
        },
    },
    {
        id: 'purim', cat: 'חגים', label: 'פורים שמח',
        doc: {
            version: 1, title: 'פורים שמח', page: page(1080, 1350, '#7b2cbf', lin(150, '#9b5de5', '#f15bb5')),
            layers: [
                L('cd', 'confetti-dots', 0, 0, 1080, 1350, '#fee44066'),
                L('sb', 'starburst', 190, 170, 700, 700, '#fee440'),
                I('cr', 'crown', 400, 260, 280, '#7b2cbf', { strokeWidth: 1.6 }),
                T('t1', 'פורים\nשמח!', 190, 520, 700, 150, '#7b2cbf', { role: 'title', font: 'Secular One', lineHeight: 0.95 }),
                I('b1', 'balloon', 70, 900, 170, '#00f5d4', { filled: true }), I('b2', 'balloon', 840, 880, 170, '#fee440', { filled: true }),
                I('cf', 'confetti', 860, 90, 140, '#ffffff'),
                L('rb', 'ribbon', 140, 1000, 800, 150, '#ffffff'),
                T('t2', 'משלוח מנות באהבה', 200, 1040, 680, 58, '#7b2cbf', { role: 'subtitle', weight: 800 }),
                T('t3', 'ונהפוך הוא!', 90, 1220, 900, 48, '#ffffff', { font: 'Varela Round' }),
            ],
        },
    },
    {
        id: 'pesach', cat: 'חגים', label: 'פסח כשר ושמח',
        doc: {
            version: 1, title: 'חג פסח שמח', page: page(1080, 1350, '#f7f1e3', lin(180, '#fbf6ec', '#efe2c4')),
            layers: [
                F('f1', 'frame-arch', 120, 110, 840, 1130, '#9c6644', 5),
                F('f2', 'frame-arch', 150, 140, 780, 1070, '#c9a227', 2),
                L('lr', 'laurel', 360, 220, 360, 300, '#7f8f3a'),
                I('gl', 'glass-full', 470, 290, 140, '#7b1e3a', { filled: true }),
                T('t1', 'חג פסח\nכשר ושמח', 160, 560, 760, 130, '#5e3023', { role: 'title', font: 'Frank Ruhl Libre', weight: 700, lineHeight: 1.05 }),
                L('dv', 'divider-diamond', 290, 870, 500, 50, '#c9a227'),
                T('t2', 'חג של חירות, משפחה וביחד', 160, 950, 760, 48, '#7a5c3e'),
                T('t3', 'בברכה, משפחת אברהם', 160, 1060, 760, 44, '#9c6644', { weight: 700 }),
            ],
        },
    },
    {
        id: 'independence', cat: 'חגים', label: 'יום העצמאות',
        doc: {
            version: 1, title: 'חג עצמאות שמח', page: page(1080, 1350, '#ffffff', lin(180, '#ffffff', '#e3ecff')),
            layers: [
                R('s1', 0, 120, 1080, 60, '#0038b8'), R('s2', 0, 1170, 1080, 60, '#0038b8'),
                ...[[120, 330, 70], [880, 300, 90], [180, 900, 60], [860, 930, 80], [520, 260, 50]].map(([x, y, s], i) => L('sp' + i, 'sparkle', x, y, s, s, '#3a6ff7')),
                I('fl', 'flag', 400, 300, 280, '#0038b8', { strokeWidth: 1.6 }),
                T('t1', 'חג עצמאות\nשמח', 90, 600, 900, 160, '#0038b8', { role: 'title', font: 'Secular One', lineHeight: 1 }),
                L('rb', 'ribbon', 190, 960, 700, 110, '#0038b8'),
                T('t2', 'שנה של ביטחון, אחדות ושמחה', 250, 997, 580, 38, '#ffffff', { weight: 700 }),
            ],
        },
    },
    {
        id: 'shavuot', cat: 'חגים', label: 'שבועות',
        doc: {
            version: 1, title: 'חג שבועות שמח', page: page(1080, 1080, '#ffffff', lin(180, '#ffffff', '#e6f4ea')),
            layers: [
                L('w', 'wave-bottom', -20, 780, 1120, 320, '#95d5b2'),
                I('f1', 'flower', 120, 120, 150, '#f4a261'), I('f2', 'flower', 820, 160, 130, '#e76f51'), I('f3', 'leaf', 860, 640, 110, '#2d6a4f', { filled: true }),
                I('ch', 'cheese', 430, 150, 220, '#e9b200', { strokeWidth: 1.6 }),
                T('t1', 'חג שבועות שמח', 90, 420, 900, 120, '#1b4332', { role: 'title', font: 'Secular One' }),
                T('t2', 'חג הביכורים — עם הרבה גבינה ואהבה', 90, 580, 900, 48, '#2d6a4f'),
                T('t3', 'חג שמח!', 90, 930, 900, 56, '#ffffff', { weight: 800 }),
            ],
        },
    },
    {
        id: 'shabbat', cat: 'חגים', label: 'שבת שלום',
        doc: {
            version: 1, title: 'שבת שלום', page: page(1080, 1080, '#14213d', rad('#23356b', '#0b1328')),
            layers: [
                L('cd', 'confetti-dots', 0, 0, 1080, 500, '#ffffff22'),
                E('g', 290, 170, 500, 360, '#ffd16626'),
                I('c1', 'candle', 380, 200, 170, '#ffd166', { strokeWidth: 1.5 }), I('c2', 'candle', 530, 200, 170, '#ffd166', { strokeWidth: 1.5 }),
                T('t1', 'שבת שלום', 90, 480, 900, 170, '#ffffff', { role: 'title', font: 'Frank Ruhl Libre', weight: 700 }),
                L('dv', 'divider-diamond', 290, 700, 500, 50, '#ffd166'),
                T('t2', 'שבת של מנוחה, שקט ומשפחה', 90, 780, 900, 50, '#c9d4f2'),
            ],
        },
    },

    // ─── אירועים ───────────────────────────────────────────────────────────
    {
        id: 'invite', cat: 'אירועים', label: 'חתונה — קלאסי',
        doc: {
            version: 1, title: 'הזמנה', page: page(1080, 1350, '#fbf6ec'),
            layers: [
                F('f1', 'frame-round', 60, 60, 960, 1230, '#b8924a', 6),
                F('f2', 'frame-corners', 90, 90, 900, 1170, '#b8924a', 3),
                L('lr', 'laurel', 380, 140, 320, 270, '#b8924a'),
                I('i1', 'heart', 490, 230, 100, '#b8924a', { filled: true }),
                T('t1', 'בשמחה רבה\nנשמח לראותכם', 120, 430, 840, 60, '#5b4a2a', { font: 'Frank Ruhl Libre' }),
                T('t2', 'דנה ויוסי', 120, 590, 840, 150, '#3b2f16', { role: 'title', font: 'Frank Ruhl Libre', weight: 700 }),
                T('t3', 'בחתונתם', 120, 760, 840, 64, '#5b4a2a', { font: 'Frank Ruhl Libre' }),
                L('dv', 'divider-heart', 290, 870, 500, 40, '#b8924a'),
                T('t4', 'יום חמישי, 12 בנובמבר\nקבלת פנים 19:30', 120, 940, 840, 52, '#3b2f16', { lineHeight: 1.4 }),
                T('t5', 'אולמי הגן, רחוב הדקל 5', 120, 1110, 840, 44, '#7a6644'),
            ],
        },
    },
    {
        id: 'save-date', cat: 'אירועים', label: 'שמרו את התאריך',
        doc: {
            version: 1, title: 'שמרו את התאריך', page: page(1080, 1350, '#1f3d36', lin(180, '#24453d', '#13261f')),
            layers: [
                L('ar', 'arch', 190, 140, 700, 900, '#f4efe6'),
                F('af', 'frame-arch', 220, 170, 640, 840, '#c9a96e', 3),
                I('rg', 'rings', 450, 260, 180, '#c9a96e', { strokeWidth: 1.4 }),
                T('t0', 'שמרו את התאריך', 240, 470, 600, 50, '#6b5a3a', { weight: 700, style: { letterSpacing: 4 } }),
                T('t1', 'נועה\n&\nאיתי', 240, 550, 600, 110, '#1f3d36', { role: 'title', font: 'Frank Ruhl Libre', weight: 700, lineHeight: 0.95 }),
                T('t2', '14.06.27', 90, 1100, 900, 90, '#f4efe6', { role: 'subtitle', font: 'Rubik', weight: 700 }),
                T('t3', 'הזמנה רשמית תישלח בהמשך', 90, 1220, 900, 42, '#c9a96e'),
            ],
        },
    },
    {
        id: 'engagement', cat: 'אירועים', label: 'אירוסין',
        doc: {
            version: 1, title: 'מזל טוב על האירוסין', page: page(1080, 1080, '#ffe5ec', rad('#fff0f3', '#ffc2d1')),
            layers: [
                L('h', 'heart-big', 290, 120, 500, 450, '#ff8fab'),
                I('rg', 'rings', 420, 230, 240, '#ffffff', { strokeWidth: 1.6 }),
                L('s1', 'sparkle', 230, 140, 70, 70, '#ffffff'), L('s2', 'sparkle', 790, 420, 60, 60, '#ffffff'),
                T('t1', 'מזל טוב!', 90, 610, 900, 140, '#a4133c', { role: 'title', font: 'Suez One' }),
                T('t2', 'על האירוסין של רוני ועומר', 90, 790, 900, 56, '#590d22', { role: 'subtitle', weight: 700 }),
                T('t3', 'שתזכו לבנות בית נאמן בישראל', 90, 890, 900, 42, '#7d2e46'),
            ],
        },
    },
    {
        id: 'bar-mitzvah', cat: 'אירועים', label: 'בר מצווה',
        doc: {
            version: 1, title: 'בר מצווה', page: page(1080, 1350, '#0d1b3e', lin(180, '#14275a', '#0a142f')),
            layers: [
                L('sb', 'sunburst', -260, -360, 1600, 1600, '#ffffff08'),
                F('fr', 'frame-double', 70, 70, 940, 1210, '#d4af37', 3),
                L('st', 'stars-3', 390, 170, 300, 100, '#d4af37'),
                T('t0', 'בשמחה ובהתרגשות\nאנו מזמינים אתכם לבר המצווה של בננו', 120, 310, 840, 46, '#c8d3f0', { lineHeight: 1.45 }),
                T('t1', 'אורי', 120, 470, 840, 220, '#d4af37', { role: 'title', font: 'Suez One', style: { shadow: { x: 0, y: 6, blur: 20, color: '#00000088' } } }),
                L('dv', 'divider-diamond', 290, 760, 500, 50, '#d4af37'),
                T('t2', 'עלייה לתורה: שבת פרשת וירא\nבית הכנסת "אוהל משה"', 120, 840, 840, 46, '#ffffff', { lineHeight: 1.5 }),
                T('t3', 'מסיבה: מוצאי שבת, 20:00 | אולם הכרמל', 120, 1020, 840, 42, '#c8d3f0'),
                T('t4', 'רחל ודוד לוי', 120, 1130, 840, 50, '#d4af37', { weight: 700 }),
            ],
        },
    },
    {
        id: 'bat-mitzvah', cat: 'אירועים', label: 'בת מצווה',
        doc: {
            version: 1, title: 'בת מצווה', page: page(1080, 1350, '#f8e1f4', lin(160, '#fde2f3', '#e0c3fc')),
            layers: [
                L('b1', 'blob-2', -120, -120, 560, 560, '#ffffff66'), L('b2', 'blob-3', 700, 950, 520, 520, '#ffffff66'),
                I('cr', 'crown', 420, 170, 240, '#c9184a', { strokeWidth: 1.4 }),
                L('s1', 'sparkle', 330, 180, 60, 60, '#c9184a'), L('s2', 'sparkle', 700, 230, 50, 50, '#c9184a'),
                T('t0', 'מזמינים אתכם לחגוג איתנו', 90, 440, 900, 48, '#6a4c93'),
                T('t1', 'מאיה', 90, 520, 900, 230, '#c9184a', { role: 'title', font: 'Amatic SC', weight: 700 }),
                T('t2', 'בת מצווה', 90, 790, 900, 90, '#6a4c93', { role: 'subtitle', font: 'Secular One' }),
                R('p', 240, 960, 600, 170, '#ffffffcc', { radius: 30 }),
                T('t3', 'יום שישי, 3.4 | 12:00\nגן האירועים "הדר"', 240, 985, 600, 44, '#3c096c', { lineHeight: 1.5 }),
            ],
        },
    },
    {
        id: 'brit', cat: 'אירועים', label: 'נולד לנו בן',
        doc: {
            version: 1, title: 'נולד לנו בן', page: page(1080, 1080, '#e3f2fd', rad('#f5fbff', '#bbdefb')),
            layers: [
                L('c', 'circle-badge', 340, 110, 400, 400, '#ffffff'),
                I('bc', 'baby-carriage', 430, 190, 220, '#1e6091', { strokeWidth: 1.4 }),
                L('s1', 'stars-3', 60, 70, 240, 80, '#90caf9'), L('s2', 'stars-3', 780, 450, 240, 80, '#90caf9'),
                T('t1', 'מזל טוב!', 90, 560, 900, 130, '#1e6091', { role: 'title', font: 'Varela Round' }),
                T('t2', 'נולד לנו בן', 90, 720, 900, 70, '#184e77', { role: 'subtitle', weight: 800 }),
                T('t3', 'הברית תיערך ביום ראשון בשעה 9:00\nבית הכנסת המרכזי', 90, 840, 900, 40, '#34618a', { lineHeight: 1.5 }),
            ],
        },
    },
    {
        id: 'baby-girl', cat: 'אירועים', label: 'נולדה לנו בת',
        doc: {
            version: 1, title: 'נולדה לנו בת', page: page(1080, 1080, '#ffe5ec', rad('#fff5f7', '#ffccd5')),
            layers: [
                L('cd', 'confetti-dots', 0, 0, 1080, 1080, '#ff8fab33'),
                L('h1', 'heart-big', 380, 110, 320, 290, '#ff8fab'),
                I('bb', 'baby-bottle', 480, 160, 120, '#ffffff', { strokeWidth: 1.8 }),
                T('t1', 'ברוכה הבאה!', 90, 450, 900, 130, '#c9184a', { role: 'title', font: 'Amatic SC', weight: 700 }),
                T('t2', 'נולדה לנו בת — שירה', 90, 620, 900, 70, '#800f2f', { role: 'subtitle', weight: 800 }),
                L('dv', 'divider-heart', 290, 740, 500, 40, '#ff8fab'),
                T('t3', '3.2 ק"ג של אושר | ההורים הגאים: נועה ואבי', 90, 820, 900, 40, '#a4133c'),
            ],
        },
    },
    {
        id: 'housewarming', cat: 'אירועים', label: 'חנוכת בית',
        doc: {
            version: 1, title: 'חנוכת בית', page: page(1080, 1350, '#fff4e6', lin(180, '#fff4e6', '#ffd8a8')),
            layers: [
                L('hc', 'half-circle', 140, 160, 800, 400, '#ff922b'),
                I('hm', 'home-2', 390, 190, 300, '#ffffff', { strokeWidth: 1.6 }),
                R('gr', 0, 560, 1080, 12, '#e8590c'),
                T('t1', 'חנוכת בית', 90, 640, 900, 160, '#d9480f', { role: 'title', font: 'Secular One' }),
                T('t2', 'עברנו דירה! בואו לחגוג איתנו', 90, 840, 900, 60, '#5c3d1e', { role: 'subtitle', weight: 700 }),
                I('i1', 'calendar-event', 790, 980, 70, '#d9480f'), T('d1', 'שישי, 14:00', 290, 990, 480, 48, '#5c3d1e', { align: 'right' }),
                I('i2', 'map-pin', 790, 1080, 70, '#d9480f'), T('d2', 'רחוב האלון 12, חיפה', 190, 1090, 580, 48, '#5c3d1e', { align: 'right' }),
                I('i3', 'gift', 790, 1180, 70, '#d9480f'), T('d3', 'רק תביאו את עצמכם', 190, 1190, 580, 48, '#5c3d1e', { align: 'right' }),
            ],
        },
    },

    // ─── ימי הולדת ─────────────────────────────────────────────────────────
    {
        id: 'birthday', cat: 'ימי הולדת', label: 'יום הולדת שמח',
        doc: {
            version: 1, title: 'יום הולדת', page: page(1080, 1080, '#fff4e0'),
            layers: [
                L('cd', 'confetti-dots', 0, 0, 1080, 1080, '#e0457b22'),
                I('i1', 'balloon', 70, 80, 220, '#e0457b', { filled: true }),
                I('i2', 'balloon', 850, 50, 170, '#2d4a8a', { filled: true }),
                I('i3', 'confetti', 820, 760, 180, '#e19a00'),
                I('i4', 'cake', 440, 640, 200, '#e0457b'),
                T('t1', 'יום הולדת\nשמח!', 140, 260, 800, 150, '#2d4a8a', { role: 'title', font: 'Secular One', lineHeight: 1 }),
                T('t2', '{{שם}}', 140, 560, 800, 70, '#e0457b', { role: 'subtitle', weight: 700 }),
                T('t3', 'מאחלים לך שנה של אושר והגשמה', 140, 900, 800, 44, '#5d6475'),
            ],
        },
    },
    {
        id: 'birthday-kids', cat: 'ימי הולדת', label: 'מסיבת ילדים',
        doc: {
            version: 1, title: 'מסיבת יום הולדת', page: page(1080, 1350, '#4cc9f0', lin(180, '#4cc9f0', '#4361ee')),
            layers: [
                L('cd', 'confetti-dots', 0, 0, 1080, 1350, '#ffffff55'),
                I('b1', 'balloon', 60, 80, 230, '#f72585', { filled: true }), I('b2', 'balloon', 230, 40, 200, '#ffd60a', { filled: true }),
                I('b3', 'balloon', 800, 70, 230, '#80ffdb', { filled: true }),
                L('sb', 'starburst-sharp', 340, 300, 400, 400, '#ffd60a'),
                T('n', '5', 340, 365, 400, 230, '#f72585', { role: 'title', font: 'Rubik', weight: 700 }),
                T('t1', 'יואב חוגג!', 90, 740, 900, 150, '#ffffff', { role: 'title', font: 'Secular One', style: { stroke: { width: 6, color: '#3a0ca3' } } }),
                L('bb', 'bubble-round', 190, 940, 700, 300, '#ffffff'),
                T('t2', 'מסיבה בגן השעשועים\nשבת, 10:00 — מחכים לכם!', 230, 985, 620, 48, '#3a0ca3', { weight: 700, lineHeight: 1.4 }),
            ],
        },
    },
    {
        id: 'birthday-elegant', cat: 'ימי הולדת', label: 'יום הולדת 40 — אלגנטי',
        doc: {
            version: 1, title: 'יום הולדת 40', page: page(1080, 1350, '#111111', rad('#2b2b2b', '#0a0a0a')),
            layers: [
                L('cd', 'confetti-dots', 0, 0, 1080, 1350, '#d4af3740'),
                I('gl', 'glass-champagne', 440, 130, 200, '#d4af37', { strokeWidth: 1.3 }),
                T('n', '40', 90, 330, 900, 400, '#d4af37', { role: 'title', font: 'Frank Ruhl Libre', weight: 700, lineHeight: 1, style: { shadow: { x: 0, y: 10, blur: 30, color: '#d4af3744' } } }),
                L('dv', 'divider-diamond', 290, 790, 500, 50, '#d4af37'),
                T('t1', 'יום הולדת שמח, מיכל', 90, 870, 900, 80, '#ffffff', { role: 'subtitle', font: 'Frank Ruhl Libre' }),
                T('t2', 'ערב חגיגי | חמישי 21:00 | בר הגג', 90, 1010, 900, 44, '#bfa76a', { style: { letterSpacing: 2 } }),
                R('p', 340, 1130, 400, 90, '#d4af37', { radius: 45 }),
                T('t3', 'אישור הגעה', 340, 1150, 400, 46, '#111111', { role: 'cta', weight: 800 }),
            ],
        },
    },
    {
        id: 'birthday-story', cat: 'ימי הולדת', label: 'סטורי יום הולדת',
        doc: {
            version: 1, title: 'סטורי יום הולדת', page: page(1080, 1920, '#ff6a88', lin(170, '#ff9a8b', '#ff6a88', '#9b5de5')),
            layers: [
                L('cd', 'confetti-dots', 0, 0, 1080, 1920, '#ffffff55'),
                L('b1', 'blob-1', 140, 300, 800, 800, '#ffffff33'),
                I('ck', 'cake', 340, 420, 400, '#ffffff', { strokeWidth: 1.3 }),
                T('t1', 'היום זה\nהיום שלי!', 90, 1000, 900, 170, '#ffffff', { role: 'title', font: 'Secular One', lineHeight: 1, style: { shadow: { x: 0, y: 8, blur: 24, color: '#00000044' } } }),
                L('pl', 'pill', 240, 1460, 600, 130, '#ffffff'),
                T('t2', 'שלחו ברכה 💌', 240, 1490, 600, 60, '#9b5de5', { role: 'cta', weight: 800 }),
            ],
        },
    },

    // ─── עסקים ומבצעים ────────────────────────────────────────────────────
    {
        id: 'sale', cat: 'עסקים ומבצעים', label: 'מבצע סוף עונה',
        doc: {
            version: 1, title: 'מבצע', page: page(1080, 1350, '#101b3d', lin(160, '#1c2f6b', '#0b1330')),
            layers: [
                E('c1', -260, -220, 900, 900, '#ffffff0d'),
                E('c2', 620, 820, 760, 760, '#ffffff0d'),
                T('t1', 'מבצע\nסוף עונה', 90, 210, 900, 170, '#ffffff', { role: 'title', font: 'Secular One', lineHeight: 1 }),
                L('b1', 'starburst', 640, 640, 360, 360, '#ffd166', { rotation: 8 }),
                T('t2', '50%', 670, 745, 300, 110, '#101b3d', { role: 'subtitle', font: 'Rubik', weight: 700, rotation: 8 }),
                T('t3', 'על כל הקולקציה — עד מוצאי שבת', 90, 720, 520, 54, '#e8ecff', { align: 'right' }),
                F('ul', 'underline', 330, 860, 280, 40, '#ffd166', 6),
                R('p1', 240, 1120, 600, 110, '#ffd166', { radius: 55 }),
                T('t4', 'לפרטים: 050-0000000', 240, 1143, 600, 52, '#101b3d', { role: 'cta', weight: 700 }),
            ],
        },
    },
    {
        id: 'mega-sale', cat: 'עסקים ומבצעים', label: 'סייל ענק',
        doc: {
            version: 1, title: 'סייל ענק', page: page(1080, 1080, '#0d0d0d'),
            layers: [
                L('sb', 'sunburst', -260, -260, 1600, 1600, '#ffd60a14'),
                L('st', 'starburst-sharp', 240, 190, 600, 600, '#ffd60a'),
                T('t0', 'עד', 240, 290, 600, 60, '#0d0d0d', { weight: 800 }),
                T('t1', '70%', 240, 360, 600, 200, '#0d0d0d', { role: 'title', font: 'Rubik', weight: 700 }),
                T('t2', 'הנחה', 240, 570, 600, 70, '#0d0d0d', { weight: 800 }),
                T('t3', 'סייל ענק', 90, 820, 900, 110, '#ffffff', { role: 'subtitle', font: 'Secular One' }),
                T('t4', '72 שעות בלבד · באתר ובחנויות', 90, 960, 900, 44, '#ffd60a'),
            ],
        },
    },
    {
        id: 'business', cat: 'עסקים ומבצעים', label: 'פלייר עסק',
        doc: {
            version: 1, title: 'פלייר', page: page(1080, 1350, '#ffffff'),
            layers: [
                R('r1', 0, 0, 1080, 520, '#2d4a8a', { gradient: lin(135, '#2d4a8a', '#1b2d57') }),
                L('w', 'wave-bottom', -20, 400, 1120, 140, '#ffffff'),
                T('t1', 'שם העסק', 90, 130, 900, 130, '#ffffff', { role: 'title', font: 'Rubik', weight: 700 }),
                T('t2', 'משפט אחד שאומר מה אתם עושים הכי טוב', 90, 310, 900, 50, '#dbe4ff'),
                I('i1', 'circle-check', 870, 640, 80, '#2d4a8a'), T('s1', 'שירות מהיר ואמין', 150, 650, 700, 52, '#16192b', { align: 'right' }),
                I('i2', 'circle-check', 870, 760, 80, '#2d4a8a'), T('s2', 'מחירים הוגנים', 150, 770, 700, 52, '#16192b', { align: 'right' }),
                I('i3', 'circle-check', 870, 880, 80, '#2d4a8a'), T('s3', 'אחריות מלאה', 150, 890, 700, 52, '#16192b', { align: 'right' }),
                R('l1', 90, 1060, 900, 2, '#e3e6eb'),
                I('i4', 'phone', 870, 1120, 70, '#2d4a8a'), T('c1', '050-0000000', 450, 1128, 400, 48, '#16192b', { role: 'cta', weight: 700, align: 'right' }),
                I('i5', 'map-pin', 870, 1210, 70, '#2d4a8a'), T('c2', 'רחוב הרצל 1, תל אביב', 300, 1218, 550, 44, '#5d6475', { align: 'right' }),
            ],
        },
    },
    {
        id: 'grand-opening', cat: 'עסקים ומבצעים', label: 'פתיחה חגיגית',
        doc: {
            version: 1, title: 'פתיחה חגיגית', page: page(1080, 1350, '#fff8e7', rad('#ffffff', '#ffe8a3')),
            layers: [
                L('sb', 'sunburst', -260, -150, 1600, 1600, '#f4a2610f'),
                L('cd', 'confetti-dots', 0, 0, 1080, 700, '#e76f5166'),
                I('st', 'building-store', 390, 160, 300, '#264653', { strokeWidth: 1.4 }),
                L('rb', 'ribbon-fold', 90, 520, 900, 230, '#e76f51'),
                T('t1', 'פתיחה חגיגית!', 190, 565, 700, 92, '#ffffff', { role: 'title', font: 'Secular One' }),
                T('t2', 'הסניף החדש נפתח — ואתם מוזמנים!', 90, 800, 900, 50, '#264653', { weight: 700 }),
                L('bd', 'badge-scallop', 400, 920, 280, 280, '#2a9d8f', { rotation: -10 }),
                T('t3', 'מתנה\nלכל לקוח', 400, 1000, 280, 52, '#ffffff', { weight: 800, rotation: -10, lineHeight: 1.1 }),
                T('t4', 'רחוב הנביאים 8 · ראשון 10:00', 90, 1250, 900, 40, '#264653'),
            ],
        },
    },
    {
        id: 'coupon', cat: 'עסקים ומבצעים', label: 'קופון הנחה',
        doc: {
            version: 1, title: 'קופון', page: page(1500, 1050, '#fdf0d5'),
            layers: [
                R('bg', 80, 120, 1340, 810, '#ffffff', { radius: 40, stroke: { width: 4, color: '#c1121f' }, shadow: { x: 0, y: 12, blur: 40, color: '#00000022' } }),
                R('cut', 980, 150, 4, 750, '#c1121f55'),
                L('tg', 'price-tag', 1030, 360, 360, 180, '#c1121f'),
                T('pct', '20%', 1090, 395, 290, 100, '#ffffff', { font: 'Rubik', weight: 700 }),
                T('t1', 'קופון הנחה', 160, 220, 780, 130, '#c1121f', { role: 'title', font: 'Secular One' }),
                T('t2', 'על כל הקנייה בחנות — בהצגת הקופון בקופה', 160, 410, 780, 50, '#003049'),
                L('pl', 'pill', 300, 560, 500, 110, '#003049'),
                T('code', 'קוד: SALE20', 300, 585, 500, 54, '#fdf0d5', { weight: 700 }),
                T('t3', 'בתוקף עד סוף החודש · לא כפל מבצעים', 160, 760, 780, 38, '#669bbc'),
            ],
        },
    },
    {
        id: 'menu', cat: 'עסקים ומבצעים', label: 'תפריט עסקי',
        doc: {
            version: 1, title: 'תפריט', page: page(1080, 1350, '#1f1b16'),
            layers: [
                F('fr', 'frame-corners', 60, 60, 960, 1230, '#d4a373', 4),
                I('ch', 'chef-hat', 470, 110, 140, '#d4a373'),
                T('t1', 'תפריט עסקי', 90, 260, 900, 120, '#faedcd', { role: 'title', font: 'Frank Ruhl Libre', weight: 700 }),
                T('t0', 'בימים א׳–ה׳ | 12:00–17:00', 90, 400, 900, 40, '#d4a373'),
                L('dv', 'divider-diamond', 290, 460, 500, 50, '#d4a373'),
                ...[['שניצל ביתי עם פירה', '58 ₪'], ['פילה סלמון בתנור', '74 ₪'], ['פסטה שמנת ופטריות', '52 ₪'], ['סלט עוף חם', '56 ₪']].flatMap(([name, price], i) => [
                    T('n' + i, name, 300, 560 + i * 130, 620, 52, '#faedcd', { align: 'right', weight: 700 }),
                    T('p' + i, price, 160, 560 + i * 130, 160, 52, '#d4a373', { align: 'left', font: 'Rubik', weight: 700 }),
                    T('d' + i, 'כולל מנה ראשונה ושתייה', 300, 625 + i * 130, 620, 34, '#a89a85', { align: 'right' }),
                ]),
                T('t9', 'הזמנות: 03-0000000', 90, 1150, 900, 46, '#faedcd', { role: 'cta' }),
            ],
        },
    },
    {
        id: 'workshop', cat: 'עסקים ומבצעים', label: 'סדנה / הרצאה',
        doc: {
            version: 1, title: 'סדנה', page: page(1080, 1350, '#f1faee'),
            layers: [
                L('b1', 'blob-3', 480, -160, 760, 760, '#a8dadc'),
                L('b2', 'blob-2', -220, 900, 640, 640, '#e6394622'),
                I('pl', 'palette', 720, 150, 200, '#1d3557', { strokeWidth: 1.4 }),
                T('t0', 'סדנה חווייתית', 90, 160, 560, 50, '#e63946', { align: 'right', weight: 800 }),
                T('t1', 'ציור בצבעי מים\nלמתחילים', 90, 240, 580, 84, '#1d3557', { role: 'title', font: 'Secular One', align: 'right', lineHeight: 1.05 }),
                F('ul', 'underline', 380, 470, 310, 40, '#e63946', 7),
                I('i1', 'calendar-event', 880, 640, 70, '#457b9d'), T('d1', 'יום שלישי, 18:30–21:00', 260, 650, 600, 46, '#1d3557', { align: 'right' }),
                I('i2', 'map-pin', 880, 740, 70, '#457b9d'), T('d2', 'הסטודיו, רחוב העצמאות 20', 260, 750, 600, 46, '#1d3557', { align: 'right' }),
                I('i3', 'users', 880, 840, 70, '#457b9d'), T('d3', 'עד 12 משתתפים · חומרים כלולים', 90, 850, 770, 46, '#1d3557', { align: 'right' }),
                R('p1', 90, 1080, 900, 120, '#e63946', { radius: 60 }),
                T('t4', 'להרשמה: 050-0000000', 90, 1110, 900, 54, '#ffffff', { role: 'cta', weight: 800 }),
            ],
        },
    },

    // ─── רשתות חברתיות ────────────────────────────────────────────────────
    {
        id: 'story', cat: 'רשתות חברתיות', label: 'סטורי הודעה',
        doc: {
            version: 1, title: 'סטורי', page: page(1080, 1920, '#0e7c66', lin(180, '#11998e', '#0a5c4c')),
            layers: [
                L('w', 'wave-bottom', -20, 1250, 1120, 700, '#0a4d40'),
                E('g', 340, 200, 400, 400, '#ffffff1a'),
                I('i1', 'bell', 440, 300, 200, '#ffffff'),
                T('t1', 'הודעה\nחשובה', 90, 640, 900, 180, '#ffffff', { role: 'title', font: 'Rubik', weight: 700, lineHeight: 1 }),
                T('t2', 'השבוע נפתחת ההרשמה לחוגים — מספר המקומות מוגבל', 120, 1060, 840, 60, '#d9f3ec', { lineHeight: 1.35 }),
                R('p1', 240, 1560, 600, 120, '#ffffff', { radius: 60 }),
                T('t3', 'להרשמה ‹', 240, 1587, 600, 56, '#0e7c66', { role: 'cta', weight: 700 }),
            ],
        },
    },
    {
        id: 'quote', cat: 'רשתות חברתיות', label: 'ציטוט',
        doc: {
            version: 1, title: 'ציטוט', page: page(1080, 1080, '#f4f1ea'),
            layers: [
                L('b', 'blob-2', 640, -200, 640, 640, '#e9c46a55'),
                I('q', 'quote', 120, 140, 180, '#e76f51', { filled: true }),
                T('t1', 'האושר אינו יעד,\nהוא דרך חיים.', 120, 380, 840, 96, '#264653', { role: 'title', font: 'Frank Ruhl Libre', weight: 700, align: 'right', lineHeight: 1.2 }),
                R('ln', 840, 700, 120, 6, '#e76f51', { radius: 3 }),
                T('t2', 'מתוך הספר "ימים טובים"', 120, 740, 840, 44, '#5d6475', { align: 'right' }),
                T('t3', '@שם_העסק', 120, 940, 840, 38, '#9aa1b2', { align: 'left' }),
            ],
        },
    },
    {
        id: 'tips', cat: 'רשתות חברתיות', label: '5 טיפים',
        doc: {
            version: 1, title: 'טיפים', page: page(1080, 1350, '#ffffff'),
            layers: [
                R('top', 0, 0, 1080, 360, '#3a0ca3', { gradient: lin(135, '#4361ee', '#3a0ca3') }),
                L('bb', 'starburst', 830, 70, 200, 200, '#ffd60a', { rotation: 12 }),
                T('num', '5', 830, 105, 200, 120, '#3a0ca3', { font: 'Rubik', weight: 700, rotation: 12 }),
                T('t1', 'טיפים לבוקר\nפרודוקטיבי', 90, 80, 700, 90, '#ffffff', { role: 'title', font: 'Secular One', align: 'right', lineHeight: 1.05 }),
                ...['להתעורר באותה שעה', 'כוס מים לפני הקפה', '10 דקות תנועה', 'לכתוב 3 משימות ליום', 'בלי טלפון בחצי השעה הראשונה'].flatMap((tip, i) => [
                    E('b' + i, 900, 430 + i * 170, 90, 90, '#4361ee'),
                    T('i' + i, String(i + 1), 900, 445 + i * 170, 90, 54, '#ffffff', { font: 'Rubik', weight: 700 }),
                    T('s' + i, tip, 90, 448 + i * 170, 780, 50, '#16192b', { align: 'right', weight: 700 }),
                ]),
            ],
        },
    },
    {
        id: 'new-product', cat: 'רשתות חברתיות', label: 'השקת מוצר',
        doc: {
            version: 1, title: 'חדש', page: page(1080, 1350, '#06d6a0', lin(160, '#06d6a0', '#118ab2')),
            layers: [
                L('b1', 'blob-1', 140, 260, 800, 800, '#ffffff33'),
                I('pk', 'package', 340, 400, 400, '#ffffff', { strokeWidth: 1.3 }),
                L('sb', 'starburst', 700, 220, 280, 280, '#ffd166', { rotation: -12 }),
                T('nw', 'חדש!', 700, 315, 280, 80, '#073b4c', { font: 'Secular One', rotation: -12 }),
                T('t1', 'הגיע למלאי', 90, 110, 900, 110, '#073b4c', { role: 'title', font: 'Secular One' }),
                T('t2', 'שם המוצר החדש', 90, 1040, 900, 80, '#ffffff', { role: 'subtitle', weight: 800 }),
                T('t3', 'משלוח חינם בהזמנה ראשונה', 90, 1160, 900, 46, '#e9fff8'),
            ],
        },
    },
    {
        id: 'event-story', cat: 'רשתות חברתיות', label: 'סטורי אירוע',
        doc: {
            version: 1, title: 'סטורי אירוע', page: page(1080, 1920, '#0b1030', rad('#2b1b5e', '#0b1030')),
            layers: [
                L('sb', 'sunburst', -460, 0, 2000, 2000, '#ffffff08'),
                I('mc', 'microphone-2', 390, 260, 300, '#f72585', { strokeWidth: 1.3 }),
                T('t0', 'הופעה חיה', 90, 640, 900, 70, '#4cc9f0', { weight: 800, style: { letterSpacing: 6 } }),
                T('t1', 'לילה של\nמוזיקה', 90, 740, 900, 200, '#ffffff', { role: 'title', font: 'Secular One', lineHeight: 1, style: { shadow: { x: 0, y: 0, blur: 40, color: '#f72585' } } }),
                L('rb', 'banner-tab', 140, 1240, 800, 140, '#f72585'),
                T('t2', 'חמישי 22:00 · מועדון הבלוק', 160, 1278, 700, 56, '#ffffff', { weight: 700 }),
                T('t3', 'כרטיסים בקישור בביו', 90, 1600, 900, 50, '#c8b6ff'),
            ],
        },
    },

    // ─── ברכות ─────────────────────────────────────────────────────────────
    {
        id: 'thanks', cat: 'ברכות', label: 'תודה רבה',
        doc: {
            version: 1, title: 'תודה', page: page(1080, 1080, '#fff1e6', rad('#ffffff', '#ffd7ba')),
            layers: [
                I('f1', 'flower', 90, 90, 170, '#e5989b'), I('f2', 'flower', 830, 820, 160, '#b5838d'),
                I('lf', 'leaf', 860, 120, 110, '#6d6875', { filled: true, rotation: 30 }),
                L('h', 'heart-big', 400, 210, 280, 250, '#e5989b'),
                T('t1', 'תודה רבה!', 90, 500, 900, 160, '#6d2e46', { role: 'title', font: 'Amatic SC', weight: 700 }),
                T('t2', 'על כל העזרה, הסבלנות והלב הגדול', 90, 700, 900, 52, '#6d6875', { weight: 700 }),
                T('t3', 'באהבה, צוות הגן', 90, 800, 900, 44, '#b5838d'),
            ],
        },
    },
    {
        id: 'congrats', cat: 'ברכות', label: 'מזל טוב!',
        doc: {
            version: 1, title: 'מזל טוב', page: page(1080, 1080, '#ffffff'),
            layers: [
                L('cd1', 'confetti-dots', 0, 0, 1080, 1080, '#ef476f66'),
                L('cd2', 'confetti-dots', 1080, 1080, 1080, 1080, '#118ab266', { rotation: 180 }),
                L('st', 'starburst', 190, 190, 700, 700, '#ffd166'),
                I('tr', 'trophy', 440, 260, 200, '#073b4c', { strokeWidth: 1.4 }),
                T('t1', 'מזל טוב!', 190, 470, 700, 140, '#073b4c', { role: 'title', font: 'Secular One' }),
                T('t2', 'על ההצלחה הגדולה', 190, 640, 700, 52, '#073b4c', { weight: 700 }),
            ],
        },
    },
    {
        id: 'get-well', cat: 'ברכות', label: 'רפואה שלמה',
        doc: {
            version: 1, title: 'רפואה שלמה', page: page(1080, 1350, '#e8f4f8', lin(180, '#f1f9fc', '#cfe8f3')),
            layers: [
                L('b', 'blob-3', 240, 160, 600, 600, '#ffffff'),
                I('sn', 'sun', 400, 280, 280, '#f4a261', { strokeWidth: 1.4 }),
                L('cl', 'thought', 680, 140, 300, 260, '#ffffff'),
                T('t1', 'רפואה שלמה', 90, 800, 900, 140, '#2a6f97', { role: 'title', font: 'Varela Round' }),
                T('t2', 'מחכים לראות אותך חוזר לעצמך,\nחזק ובריא מתמיד', 90, 980, 900, 50, '#468faf', { lineHeight: 1.45 }),
                L('dv', 'divider-heart', 290, 1170, 500, 40, '#f4a261'),
            ],
        },
    },
    {
        id: 'love', cat: 'ברכות', label: 'אוהבים אותך',
        doc: {
            version: 1, title: 'אוהבים', page: page(1080, 1350, '#a4133c', lin(160, '#c9184a', '#590d22')),
            layers: [
                ...[[80, 120, 120], [860, 180, 90], [140, 1050, 100], [820, 1000, 140], [500, 90, 70]].map(([x, y, s], i) => I('h' + i, 'heart', x, y, s, '#ff8fab', { filled: true, opacity: 0.8 })),
                L('bh', 'heart-big', 240, 300, 600, 540, '#ffffff1f'),
                T('t1', 'אוהבים\nאותך', 90, 420, 900, 200, '#ffffff', { role: 'title', font: 'Amatic SC', weight: 700, lineHeight: 0.9 }),
                F('ul', 'underline', 340, 860, 400, 50, '#ffccd5', 8),
                T('t2', 'תמיד, בכל מצב', 90, 950, 900, 56, '#ffccd5', { weight: 700 }),
            ],
        },
    },
    {
        id: 'mothers-day', cat: 'ברכות', label: 'יום המשפחה',
        doc: {
            version: 1, title: 'יום המשפחה', page: page(1080, 1080, '#fefae0', lin(180, '#fefae0', '#faedcd')),
            layers: [
                F('r', 'ring', 290, 90, 500, 500, '#d4a373', 6),
                I('fm', 'users', 390, 190, 300, '#bc6c25', { strokeWidth: 1.4 }),
                T('t1', 'יום משפחה שמח', 90, 640, 900, 120, '#6b3e1a', { role: 'title', font: 'Secular One' }),
                T('t2', 'הדבר הכי חשוב בעולם — זה אתם', 90, 790, 900, 52, '#8d5a2b'),
                L('st', 'stars-3', 390, 900, 300, 100, '#d4a373'),
            ],
        },
    },
];
