// מתכון מובנה: שולחן (שלב 3 — הועבר מ-templates/table.js).
//
// פלטה, ארבע רגליים מעץ מלא, ומסגרת (אפרון) מתחת לפלטה שמחברת אותן, וקורות
// חיזוק לבחירה. X לאורך השולחן, Z לרוחבו (קדימה = הצד הארוך הקדמי).
// tools/model-check.js מוודא שהמתכון בונה בדיוק מה שהקוד הישן בנה.

export default {
  key: 'table',
  name: 'שולחן',
  description: 'פלטה, ארבע רגליים ומסגרת. אוכל, עבודה או קפה.',
  laborHours: 7,
  params: [
    { key: 'length', label: 'אורך', type: 'mm', min: 500, max: 3600, default: 1600, group: 'מידות' },
    { key: 'width', label: 'רוחב', type: 'mm', min: 400, max: 1500, default: 900, group: 'מידות' },
    { key: 'height', label: 'גובה', type: 'mm', min: 300, max: 1100, default: 750, group: 'מידות' },
    { key: 'topMaterial', label: 'הפלטה', type: 'material', use: 'board', default: 'board:veneer-oak-18', group: 'חומרים' },
    { key: 'topT', label: 'עובי הפלטה', type: 'mm', min: 18, max: 60, default: 30, group: 'חומרים', hint: 'כולל עיבוי, אם יש' },
    { key: 'legMaterial', label: 'הרגליים והמסגרת', type: 'material', use: 'solid', default: 'board:solid-oak', group: 'חומרים' },
    { key: 'edgeMaterial', label: 'קנט לפלטה', type: 'material', use: 'edge', default: 'edge:veneer-0.5', group: 'חומרים' },
    { key: 'legSize', label: 'חתך הרגל', type: 'mm', min: 40, max: 120, default: 70, group: 'מבנה' },
    { key: 'wheels', label: 'גלגלים', type: 'material', use: 'wheel', default: 'none', group: 'מבנה', hint: 'גלגל מתחת לכל רגל; השולחן מורם בגובהו' },
    { key: 'legInset', label: 'הרגל מקצה הפלטה', type: 'mm', min: 0, max: 400, default: 60, group: 'מבנה' },
    { key: 'apronH', label: 'גובה המסגרת', type: 'mm', min: 0, max: 200, default: 80, group: 'מבנה', hint: '0 = ללא מסגרת' },
    { key: 'apronT', label: 'עובי המסגרת', type: 'mm', min: 18, max: 50, default: 25, group: 'מבנה' },
    { key: 'apronSetback', label: 'נסיגת המסגרת מפני הרגל', type: 'mm', min: 0, max: 40, default: 10, group: 'מבנה' },
    { key: 'stretcher', label: 'קורת חיזוק תחתונה', type: 'enum', default: 'none', group: 'מבנה', options: [{ id: 'none', name: 'ללא' }, { id: 'long', name: 'לאורך' }, { id: 'h', name: 'צורת H' }] },
    { key: 'edgeMode', label: 'מידת הקנט', type: 'enum', default: 'subtract', group: 'חיבורים', options: [{ id: 'subtract', name: 'יורדת מהמידה' }, { id: 'add', name: 'נוספת למידה' }] },
  ],
  vars: [
    { name: 'L', expr: 'length' },
    { name: 'W', expr: 'width' },
    { name: 'H', expr: 'height' },
    { name: 's', expr: 'legSize' },
    { name: 'inset', expr: 'legInset' },
    { name: 'legY1', expr: 'H - topT' },
    { name: 'ay', expr: 'legY1 - apronH' },
    { name: 'at', expr: 'apronT' },
    { name: 'sb', expr: 'apronSetback' },
    { name: 'lenX', expr: 'L - 2 * inset - 2 * s' },
    { name: 'lenZ', expr: 'W - 2 * inset - 2 * s' },
    { name: 'sy', expr: 'max(100, H * 0.2)' },
  ],
  components: [
    { id: 'top', name: 'פלטה', material: 'topMaterial', x: '0', y: 'H - topT', z: '0', w: 'L', h: 'topT', d: 'W', axis: 'y', grain: 'x', qtyKey: 'top', edges: 'front,back,left,right' },
    { id: 'leg', name: 'רגל', material: 'legMaterial', repeat: '4', index: 'i',
      x: 'i % 2 == 0 ? inset : L - inset - s', y: '0', z: 'i < 2 ? inset : W - inset - s', w: 's', h: 'legY1', d: 's', axis: 'x', grain: 'y',
      qtyKey: 'leg-{round(box_h)}', note: "{i == 0 ? 'שמאל-אחור' : (i == 1 ? 'ימין-אחור' : (i == 2 ? 'שמאל-קדימה' : 'ימין-קדימה'))}", edges: 'none' },
    { id: 'apron-front', name: 'מסגרת ארוכה', material: 'legMaterial', when: 'apronH > 0', x: 'inset + s', y: 'ay', z: 'W - inset - sb - at', w: 'lenX', h: 'apronH', d: 'at', axis: 'z', grain: 'x', qtyKey: 'apron-{round(lenX)}', edges: 'none' },
    { id: 'apron-back', name: 'מסגרת ארוכה', material: 'legMaterial', when: 'apronH > 0', x: 'inset + s', y: 'ay', z: 'inset + sb', w: 'lenX', h: 'apronH', d: 'at', axis: 'z', grain: 'x', qtyKey: 'apron-{round(lenX)}', edges: 'none' },
    { id: 'apron-left', name: 'מסגרת קצרה', material: 'legMaterial', when: 'apronH > 0', x: 'inset + sb', y: 'ay', z: 'inset + s', w: 'at', h: 'apronH', d: 'lenZ', axis: 'x', grain: 'z', qtyKey: 'apron-{round(lenZ)}', edges: 'none' },
    { id: 'apron-right', name: 'מסגרת קצרה', material: 'legMaterial', when: 'apronH > 0', x: 'L - inset - sb - at', y: 'ay', z: 'inset + s', w: 'at', h: 'apronH', d: 'lenZ', axis: 'x', grain: 'z', qtyKey: 'apron-{round(lenZ)}', edges: 'none' },
    { id: 'stretcher-long', name: 'קורת חיזוק לאורך', material: 'legMaterial', when: "apronH > 0 && stretcher != 'none'", x: 'inset + s', y: 'sy', z: 'W / 2 - at / 2', w: 'lenX', h: 'apronH', d: 'at', axis: 'z', grain: 'x', qtyKey: 'stretcher-long', edges: 'none' },
    { id: 'stretcher-left', name: 'קורת חיזוק לרוחב', material: 'legMaterial', when: "apronH > 0 && stretcher == 'h'", x: 'inset + sb', y: 'sy', z: 'inset + s', w: 'at', h: 'apronH', d: 'lenZ', axis: 'x', grain: 'z', qtyKey: 'stretcher-{round(lenZ)}', edges: 'none' },
    { id: 'stretcher-right', name: 'קורת חיזוק לרוחב', material: 'legMaterial', when: "apronH > 0 && stretcher == 'h'", x: 'L - inset - sb - at', y: 'sy', z: 'inset + s', w: 'at', h: 'apronH', d: 'lenZ', axis: 'x', grain: 'z', qtyKey: 'stretcher-{round(lenZ)}', edges: 'none' },
  ],
  hardware: [
    { id: 'leg-bolts', name: 'רגליות / פלטות חיבור', material: 'hw:leg-adjust', qty: '4' },
  ],
  wheels: { param: 'wheels', x0: '0', x1: 'L', y0: '0', z0: '0', z1: 'W', inset: 'legSize / 2 + legInset' },
  warnings: [
    { when: '!(apronH > 0) && L > 1200', text: 'שולחן ארוך בלי מסגרת — הפלטה עלולה להתכופף; מומלץ מסגרת או עיבוי' },
    { when: 'L / topT > 60', text: 'פלטה באורך {L} ובעובי {topT} — יחס גדול מ-60, מומלץ לעבות' },
    { when: 'H - topT - apronH < 600 && H > 650', text: 'מתחת למסגרת נשארים פחות מ-600 מ"מ — צפוף לברכיים' },
  ],
  bounds: { w: 'L', h: 'H + lift', d: 'W' },
};
