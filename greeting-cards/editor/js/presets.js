// סגנונות מוכנים: טקסטים מעוצבים ורקעים (צבע אחיד ומדרגים). נבחרים בהקשה אחת, וכל פרט נשאר עריך אחר כך.

/**
 * סגנון טקסט: ‏size — חלק מהצלע הקצרה של הדף, כך שהסגנון נראה נכון בכל גודל.
 * ‏sample — מה שמופיע בכפתור ובטקסט החדש.
 */
export const TEXT_STYLES = [
    { id: 'bold-title', sample: 'כותרת חזקה', font: 'Secular One', weight: 400, size: 0.12, color: '#1b1e26' },
    { id: 'gold', sample: 'זהב ונוצץ', font: 'Suez One', weight: 400, size: 0.11, color: '#f2c14e', shadow: { x: 0, y: 0.06, blur: 0.18, color: '#00000088' } },
    { id: 'outline', sample: 'קו מתאר', font: 'Rubik', weight: 800, size: 0.12, color: '#ffffff', stroke: { width: 0.05, color: '#1b1e26' } },
    { id: 'elegant', sample: 'אלגנטי וקלאסי', font: 'Frank Ruhl Libre', weight: 700, size: 0.09, color: '#3b2f16' },
    { id: 'hand', sample: 'בכתב יד', font: 'Amatic SC', weight: 700, size: 0.13, color: '#2d4a8a' },
    { id: 'tag', sample: 'תגית צבעונית', font: 'Heebo', weight: 800, size: 0.055, color: '#ffffff', background: { color: '#e0457b', radius: 0.4, padding: 0.3 } },
    { id: 'highlight', sample: 'מודגש במרקר', font: 'Assistant', weight: 800, size: 0.06, color: '#1b1e26', background: { color: '#ffd166', radius: 0.1, padding: 0.18 } },
    { id: 'neon', sample: 'ניאון', font: 'Varela Round', weight: 400, size: 0.1, color: '#f2fbff', shadow: { x: 0, y: 0, blur: 0.35, color: '#29d3ff' } },
    { id: 'soft', sample: 'עדין ונקי', font: 'Assistant', weight: 400, size: 0.06, color: '#5d6475' },
    { id: 'shadow-pop', sample: 'קופץ מהדף', font: 'Rubik', weight: 800, size: 0.11, color: '#ff6b35', shadow: { x: 0.05, y: 0.05, blur: 0, color: '#1b1e26' } },
    { id: 'kids', sample: 'שמח וילדי', font: 'Varela Round', weight: 400, size: 0.1, color: '#7b2cbf', shadow: { x: 0.04, y: 0.04, blur: 0, color: '#ffd60a' } },
    { id: 'quote', sample: '"ציטוט מרגש"', font: 'Frank Ruhl Libre', weight: 400, size: 0.06, color: '#1b1e26', italic: true },
];

/** צבעים אחידים לרקע הדף — פלטה מאוזנת: בהירים, פסטל, עזים וכהים. */
export const SOLIDS = [
    '#ffffff', '#fbf6ec', '#f4f1ea', '#eef2f7', '#fde2e4', '#fff1c1', '#dff3e4', '#e3ecff',
    '#ffd166', '#ff8fab', '#7bdff2', '#b2f7ef', '#ef476f', '#06d6a0', '#118ab2', '#7b2cbf',
    '#1b1e26', '#101b3d', '#0e3b2e', '#4a1020', '#2d4a8a', '#0e7c66', '#7a1023', '#3b2f16',
];

/** מדרגים מוכנים לרקע הדף ולצורות. */
export const GRADIENTS = [
    { name: 'שקיעה', type: 'linear', angle: 160, colors: ['#ff7e5f', '#feb47b'] },
    { name: 'ורוד-סגול', type: 'linear', angle: 135, colors: ['#ff6a88', '#9b5de5'] },
    { name: 'אוקיינוס', type: 'linear', angle: 180, colors: ['#2193b0', '#6dd5ed'] },
    { name: 'לילה', type: 'linear', angle: 180, colors: ['#0f2027', '#203a43', '#2c5364'] },
    { name: 'זהב', type: 'linear', angle: 135, colors: ['#bf953f', '#fcf6ba', '#b38728'] },
    { name: 'מנטה', type: 'linear', angle: 160, colors: ['#d4fc79', '#96e6a1'] },
    { name: 'אפרסק', type: 'linear', angle: 180, colors: ['#ffecd2', '#fcb69f'] },
    { name: 'לבנדר', type: 'linear', angle: 180, colors: ['#e0c3fc', '#8ec5fc'] },
    { name: 'אש', type: 'linear', angle: 200, colors: ['#f12711', '#f5af19'] },
    { name: 'יער', type: 'linear', angle: 180, colors: ['#134e5e', '#71b280'] },
    { name: 'חציל', type: 'linear', angle: 150, colors: ['#41295a', '#2f0743'] },
    { name: 'שמיים', type: 'linear', angle: 180, colors: ['#a1c4fd', '#c2e9fb'] },
    { name: 'דבש', type: 'radial', colors: ['#fff6d5', '#f7c873'] },
    { name: 'זרקור', type: 'radial', colors: ['#3a4a8a', '#0b1030'] },
    { name: 'ורד', type: 'radial', colors: ['#fff0f3', '#ffb3c1'] },
    { name: 'בורדו', type: 'radial', colors: ['#a4133c', '#4a0418'] },
];

/** תצוגת מדרג ב-CSS — לכפתורי הבחירה. */
export function gradientCss(g) {
    return g.type === 'radial'
        ? `radial-gradient(circle, ${g.colors.join(', ')})`
        : `linear-gradient(${g.angle ?? 180}deg, ${g.colors.join(', ')})`;
}
