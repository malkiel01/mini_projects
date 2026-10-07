// מה שמשותף לתבניות: רשימות הפרמטרים החוזרות (חומרים, חיבורים, דלתות),
// ספי האזהרות, ועזרים קטנים. תבנית מרכיבה מהן את הרשימה שלה.

import { material } from '../materials.js';

export const boardT = (id) => material(id).t || 18;

export const LIMITS = { shelfSpan18: 800, shelfSpan25: 1000, doorWidth: 600, heightUnanchored: 2200, glassMinDepth: 250, drawerMaxWidth: 1000 };

/** חומרי הגוף, המדפים, הגב והקנט. */
export function materialParams({ body = 'board:melamine-oak-18', shelves = true, back = true } = {}) {
  const out = [
    { key: 'bodyMaterial', label: 'חומר הגוף', type: 'material', kind: 'board', back: false, solid: false, top: false, default: body, group: 'חומרים' },
  ];
  if (shelves) out.push({ key: 'shelfMaterial', label: 'חומר המדפים', type: 'material', kind: 'board', back: false, solid: false, top: false, default: body, group: 'חומרים' });
  if (back) out.push({ key: 'backMaterial', label: 'חומר הגב', type: 'material', kind: 'board', back: true, default: 'board:back-hdf-6', group: 'חומרים' });
  out.push({ key: 'edgeMaterial', label: 'קנט', type: 'material', kind: 'edge', default: 'edge:pvc-1', group: 'חומרים' });
  return out;
}

/** דלתות: סוג, גובה, חומר, זכוכית, צירים, ידיות. `sliding` מוסיף הזזה. */
export function doorParams({ sliding = false, glass = true, height = true } = {}) {
  const options = [{ id: 'none', name: 'ללא' }, { id: 'wood', name: 'עץ' }];
  if (glass) options.push({ id: 'glass', name: 'ויטרינה' });
  if (sliding) options.push({ id: 'sliding', name: 'הזזה' });
  const show = { doorType: options.filter((o) => o.id !== 'none').map((o) => o.id) };
  const out = [{ key: 'doorType', label: 'דלתות', type: 'enum', default: 'none', group: 'דלתות', options }];
  if (height) out.push({ key: 'doorHeight', label: 'גובה הדלתות', type: 'mm', min: 0, max: 3000, default: 0, group: 'דלתות', hint: '0 = לכל הגובה', showIf: { doorType: ['wood', 'glass'] } });
  out.push({ key: 'doorMaterial', label: 'חומר הדלתות', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'דלתות', showIf: show });
  if (glass) out.push({ key: 'glassType', label: 'זכוכית', type: 'material', kind: 'glass', default: 'glass:clear-4', group: 'דלתות', showIf: { doorType: ['glass'] } });
  out.push({ key: 'hinge', label: 'צירים', type: 'enum', default: 'hw:hinge-110', group: 'דלתות', showIf: { doorType: ['wood', 'glass'] },
    options: [{ id: 'hw:hinge-110', name: '110°' }, { id: 'hw:hinge-165', name: '165°' }, { id: 'hw:hinge-glass', name: 'לוויטרינה' }] });
  if (sliding) out.push({ key: 'slidingLeaves', label: 'כנפי הזזה', type: 'int', min: 2, max: 4, default: 2, group: 'דלתות', showIf: { doorType: ['sliding'] } });
  out.push({ key: 'handle', label: 'ידיות', type: 'enum', default: 'hw:handle-bar-128', group: 'דלתות', showIf: show,
    options: [{ id: 'none', name: 'ללא (לחיצה)' }, { id: 'hw:handle-bar-128', name: 'מוט 128' }, { id: 'hw:handle-knob', name: 'כפתור' }] });
  return out;
}

/** מגירות: ארגז, תחתית, מסילות. */
export function drawerParams() {
  return [
    { key: 'drawerBoxMaterial', label: 'ארגז המגירה', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:melamine-white-18', group: 'מגירות' },
    { key: 'drawerBottomMaterial', label: 'תחתית המגירה', type: 'material', kind: 'board', back: true, default: 'board:back-hdf-6', group: 'מגירות' },
    { key: 'slide', label: 'מסילות', type: 'enum', default: 'hw:slide-std', group: 'מגירות',
      options: [{ id: 'hw:slide-std', name: 'רגילות' }, { id: 'hw:slide-tandem', name: 'טנדם, טריקה שקטה' }] },
  ];
}

/** החלטות החיבור — נשאלות בכל מופע. */
export function joineryParams({ shelves = true } = {}) {
  const out = [
    { key: 'sideT', label: 'עובי הדפנות', type: 'mm', min: 12, max: 40, default: 18, group: 'חיבורים' },
    { key: 'shelfT', label: 'עובי המדפים', type: 'mm', min: 12, max: 40, default: 18, group: 'חיבורים' },
    { key: 'sidesOverTop', label: 'דפנות ↔ גג', type: 'enum', default: 'sides', group: 'חיבורים',
      options: [{ id: 'sides', name: 'הדפנות עוברות' }, { id: 'top', name: 'הגג עובר' }] },
    { key: 'backMode', label: 'גב', type: 'enum', default: 'groove', group: 'חיבורים',
      options: [{ id: 'groove', name: 'בחריץ' }, { id: 'overlay', name: 'מולבש מאחור' }, { id: 'none', name: 'ללא' }] },
    { key: 'backGrooveDepth', label: 'עומק החריץ', type: 'mm', min: 4, max: 15, default: 8, group: 'חיבורים', showIf: { backMode: ['groove'] } },
    { key: 'backInset', label: 'החריץ מהקצה האחורי', type: 'mm', min: 5, max: 50, default: 10, group: 'חיבורים', showIf: { backMode: ['groove'] } },
  ];
  if (shelves) out.push({ key: 'shelvesMode', label: 'מדפים', type: 'enum', default: 'adjustable', group: 'חיבורים',
    options: [{ id: 'adjustable', name: 'מתכווננים (פינים)' }, { id: 'fixed', name: 'קבועים (בחריץ)' }] });
  out.push({ key: 'edgeMode', label: 'מידת הקנט', type: 'enum', default: 'subtract', group: 'חיבורים',
    options: [{ id: 'subtract', name: 'יורדת מהמידה' }, { id: 'add', name: 'נוספת למידה' }] });
  return out;
}

/** אזהרות כלליות על גוף: גובה, מפתח מדף. */
export function bodyWarnings({ H, colW, shelfT, hasShelves }, L = LIMITS) {
  const w = [];
  const spanLimit = shelfT >= 25 ? L.shelfSpan25 : L.shelfSpan18;
  if (hasShelves && colW > spanLimit) w.push(`מדף ברוחב ${Math.round(colW)} מ"מ בעובי ${shelfT} — מעבר ל-${spanLimit} המומלצים ללא תמיכה`);
  if (H > L.heightUnanchored) w.push(`גובה ${H} מ"מ — מעל ${L.heightUnanchored} מומלץ עיגון לקיר`);
  return w;
}
