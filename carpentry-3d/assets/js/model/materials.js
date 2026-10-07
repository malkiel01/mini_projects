// ספריית החומרים — הזריעה מהקוד.
//
// בשלב 3 הרשימה הזו עוברת למסד הנתונים ונעשית דינמית (הוספה, שינוי, הסרה
// במסך); מה שכאן הוא הערכים ההתחלתיים. המודל מתייחס לחומר רק דרך המזהה,
// והתצוגה היא שמחליטה איך הוא נראה. כך אפשר להחליף צבע או טקסטורה בלי
// לגעת בגאומטריה.
//
// `color` הוא צבע ההדמיה הבסיסי. `kind` קובע איך החומר נספר ומתומחר:
// לוח — לפי שטח; קנט — לפי מטר; זכוכית — לפי שטח; פרזול — ליחידה.

export const MATERIALS = {
  'board:melamine-white-18': { kind: 'board', name: 'מלמין לבן 18', t: 18, color: 0xf2f0ea, sheet: [2800, 2070] },
  'board:melamine-oak-18':   { kind: 'board', name: 'מלמין אלון 18', t: 18, color: 0xc9a46c, sheet: [2800, 2070] },
  'board:veneer-oak-18':     { kind: 'board', name: 'פורניר אלון 18', t: 18, color: 0xd1a86e, sheet: [2500, 1250] },
  'board:veneer-walnut-18':  { kind: 'board', name: 'פורניר אגוז 18', t: 18, color: 0x6b4a33, sheet: [2500, 1250] },
  'board:mdf-paint-18':      { kind: 'board', name: 'MDF לצבע בתנור 18', t: 18, color: 0xe8e4dc, sheet: [2800, 2070] },
  'board:mdf-paint-22':      { kind: 'board', name: 'MDF לצבע בתנור 22', t: 22, color: 0xe8e4dc, sheet: [2800, 2070] },
  'board:back-hdf-6':        { kind: 'board', name: 'גב HDF 6', t: 6, color: 0xd9cdb8, sheet: [2800, 2070] },
  'board:back-mdf-8':        { kind: 'board', name: 'גב MDF 8', t: 8, color: 0xdcd2bf, sheet: [2800, 2070] },
  'edge:pvc-1':              { kind: 'edge', name: 'קנט PVC 1 מ"מ', t: 1 },
  'edge:pvc-2':              { kind: 'edge', name: 'קנט PVC 2 מ"מ', t: 2 },
  'edge:veneer-0.5':         { kind: 'edge', name: 'קנט פורניר 0.5', t: 0.5 },
  'glass:clear-4':           { kind: 'glass', name: 'זכוכית שקופה 4', t: 4, color: 0xbfe0ea, opacity: 0.35 },
  'glass:frosted-4':         { kind: 'glass', name: 'זכוכית חלבית 4', t: 4, color: 0xe6eef0, opacity: 0.7 },
  'glass:smoked-4':          { kind: 'glass', name: 'זכוכית מעושנת 4', t: 4, color: 0x5a5e62, opacity: 0.5 },
  'hw:hinge-110':            { kind: 'hardware', name: 'ציר 110°' },
  'hw:hinge-165':            { kind: 'hardware', name: 'ציר 165°' },
  'hw:hinge-glass':          { kind: 'hardware', name: 'ציר לוויטרינה' },
  'hw:handle-bar-128':       { kind: 'hardware', name: 'ידית מוט 128' },
  'hw:handle-knob':          { kind: 'hardware', name: 'ידית כפתור' },
  'hw:shelf-pin':            { kind: 'hardware', name: 'פין מדף' },
};

/** החומרים מסוג נתון, לבניית רשימות בחירה. */
export function materialsOfKind(kind) {
  return Object.entries(MATERIALS)
    .filter(([, m]) => m.kind === kind)
    .map(([id, m]) => ({ id, name: m.name }));
}

export function material(id) {
  return MATERIALS[id] || { kind: 'unknown', name: id, color: 0xff00ff };
}
