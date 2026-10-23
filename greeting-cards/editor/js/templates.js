// תבניות מוכנות: נקודת התחלה במקום דף ריק. בלי תמונות — רק צורות, אייקונים וטקסט, כך שהן קלות, נטענות
// מיד, וכל רקע (מהצ'אט או מהמכשיר) נכנס מתחתן. כל תבנית עוברת את normalizeDoc כמו כל ייבוא.

const T = (id, text, x, y, w, size, color, extra = {}) => ({
    id, type: 'text', role: extra.role || 'body', text, x, y, w, h: size * 1.3,
    style: { font: extra.font || 'Heebo', size, weight: extra.weight || 400, color, align: extra.align || 'center', dir: 'rtl', lineHeight: extra.lineHeight || 1.15, ...(extra.style || {}) },
});
const R = (id, x, y, w, h, fill, extra = {}) => ({ id, type: 'shape', shape: 'rect', role: 'decoration', x, y, w, h, fill, radius: 0, ...extra });
const E = (id, x, y, w, h, fill, extra = {}) => ({ id, type: 'shape', shape: 'ellipse', role: 'decoration', x, y, w, h, fill, ...extra });
const I = (id, icon, x, y, s, color, extra = {}) => ({ id, type: 'icon', role: 'decoration', icon, x, y, w: s, h: s, color, strokeWidth: 1.5, ...extra });

export const TEMPLATES = [
    {
        id: 'sale', label: 'מודעת מבצע',
        doc: {
            version: 1, title: 'מבצע', page: { width: 1080, height: 1350, background: '#101b3d' },
            layers: [
                E('c1', -260, -220, 900, 900, '#1c2f6b'),
                E('c2', 620, 820, 760, 760, '#26408f'),
                T('t1', 'מבצע\nסוף עונה', 90, 210, 900, 170, '#ffffff', { role: 'title', font: 'Secular One', lineHeight: 1 }),
                E('b1', 660, 680, 320, 320, '#ffd166'),
                T('t2', '50%', 670, 778, 300, 100, '#101b3d', { role: 'subtitle', font: 'Rubik', weight: 700 }),
                T('t3', 'על כל הקולקציה — עד מוצאי שבת', 90, 720, 520, 54, '#e8ecff', { align: 'right' }),
                R('p1', 240, 1120, 600, 110, '#ffd166', { radius: 55 }),
                T('t4', 'לפרטים: 050-0000000', 240, 1143, 600, 52, '#101b3d', { role: 'cta', weight: 700 }),
            ],
        },
    },
    {
        id: 'invite', label: 'הזמנה לאירוע',
        doc: {
            version: 1, title: 'הזמנה', page: { width: 1080, height: 1350, background: '#fbf6ec' },
            layers: [
                R('f1', 60, 60, 960, 1230, '#00000000', { stroke: { width: 6, color: '#b8924a' }, radius: 24 }),
                R('f2', 84, 84, 912, 1182, '#00000000', { stroke: { width: 2, color: '#b8924a' }, radius: 16 }),
                I('i1', 'heart', 480, 170, 120, '#b8924a', { filled: true }),
                T('t1', 'בשמחה רבה\nנשמח לראותכם', 120, 330, 840, 64, '#5b4a2a', { font: 'Frank Ruhl Libre' }),
                T('t2', 'דנה ויוסי', 120, 520, 840, 150, '#3b2f16', { role: 'title', font: 'Frank Ruhl Libre', weight: 700 }),
                T('t3', 'בחתונתם', 120, 700, 840, 64, '#5b4a2a', { font: 'Frank Ruhl Libre' }),
                T('t4', 'יום חמישי, 12 בנובמבר\nקבלת פנים 19:30', 120, 860, 840, 52, '#3b2f16', { lineHeight: 1.4 }),
                T('t5', 'אולמי הגן, רחוב הדקל 5', 120, 1060, 840, 44, '#7a6644'),
            ],
        },
    },
    {
        id: 'shana', label: 'שנה טובה (לנמענים)',
        doc: {
            version: 1, title: 'שנה טובה', page: { width: 1500, height: 1050, background: '#7a1023' },
            layers: [
                E('c1', 950, -300, 900, 900, '#8f1a2e'),
                I('i1', 'sparkles', 1180, 110, 180, '#ffd166'),
                I('i2', 'candy', 120, 760, 160, '#ffd166'),
                T('t1', 'שנה טובה\nומתוקה', 150, 210, 1200, 170, '#ffd166', { role: 'title', font: 'Suez One', lineHeight: 1 }),
                T('t2', 'ל{{שם}} ולכל המשפחה', 150, 640, 1200, 70, '#ffffff', { role: 'subtitle', weight: 700 }),
                T('t3', 'בברכת כתיבה וחתימה טובה', 150, 760, 1200, 52, '#f5d6db'),
            ],
            merge: { columns: ['שם'], rows: [{ 'שם': 'משפחת כהן' }, { 'שם': 'דנה' }], nameColumn: 'שם', phoneColumn: null, fileColumn: 'שם' },
        },
    },
    {
        id: 'birthday', label: 'יום הולדת',
        doc: {
            version: 1, title: 'יום הולדת', page: { width: 1080, height: 1080, background: '#fff4e0' },
            layers: [
                I('i1', 'balloon', 70, 80, 220, '#e0457b', { filled: true }),
                I('i2', 'balloon', 800, 120, 200, '#2d4a8a', { filled: true }),
                I('i3', 'confetti', 820, 760, 180, '#e19a00'),
                I('i4', 'cake', 440, 640, 200, '#e0457b'),
                T('t1', 'יום הולדת\nשמח!', 140, 260, 800, 150, '#2d4a8a', { role: 'title', font: 'Secular One', lineHeight: 1 }),
                T('t2', '{{שם}}', 140, 560, 800, 70, '#e0457b', { role: 'subtitle', weight: 700 }),
                T('t3', 'מאחלים לך שנה של אושר והגשמה', 140, 900, 800, 44, '#5d6475'),
            ],
        },
    },
    {
        id: 'story', label: 'סטורי הודעה',
        doc: {
            version: 1, title: 'סטורי', page: { width: 1080, height: 1920, background: '#0e7c66' },
            layers: [
                R('r1', 0, 1300, 1080, 620, '#0a5c4c'),
                I('i1', 'bell', 440, 260, 200, '#ffffff'),
                T('t1', 'הודעה\nחשובה', 90, 560, 900, 180, '#ffffff', { role: 'title', font: 'Rubik', weight: 700, lineHeight: 1 }),
                T('t2', 'השבוע נפתחת ההרשמה לחוגים — מספר המקומות מוגבל', 120, 1000, 840, 60, '#d9f3ec', { lineHeight: 1.35 }),
                R('p1', 240, 1500, 600, 120, '#ffffff', { radius: 60 }),
                T('t3', 'להרשמה ‹', 240, 1527, 600, 56, '#0e7c66', { role: 'cta', weight: 700 }),
            ],
        },
    },
    {
        id: 'business', label: 'פלייר עסק',
        doc: {
            version: 1, title: 'פלייר', page: { width: 1080, height: 1350, background: '#ffffff' },
            layers: [
                R('r1', 0, 0, 1080, 520, '#2d4a8a'),
                T('t1', 'שם העסק', 90, 150, 900, 130, '#ffffff', { role: 'title', font: 'Rubik', weight: 700 }),
                T('t2', 'משפט אחד שאומר מה אתם עושים הכי טוב', 90, 330, 900, 50, '#dbe4ff'),
                I('i1', 'check', 870, 640, 80, '#2d4a8a'), T('s1', 'שירות מהיר ואמין', 150, 650, 700, 52, '#16192b', { align: 'right' }),
                I('i2', 'check', 870, 760, 80, '#2d4a8a'), T('s2', 'מחירים הוגנים', 150, 770, 700, 52, '#16192b', { align: 'right' }),
                I('i3', 'check', 870, 880, 80, '#2d4a8a'), T('s3', 'אחריות מלאה', 150, 890, 700, 52, '#16192b', { align: 'right' }),
                R('l1', 90, 1060, 900, 2, '#e3e6eb'),
                I('i4', 'phone', 870, 1120, 70, '#2d4a8a'), T('c1', '050-0000000', 450, 1128, 400, 48, '#16192b', { role: 'cta', weight: 700, align: 'right' }),
                I('i5', 'map-pin', 870, 1210, 70, '#2d4a8a'), T('c2', 'רחוב הרצל 1, תל אביב', 300, 1218, 550, 44, '#5d6475', { align: 'right' }),
            ],
        },
    },
];
