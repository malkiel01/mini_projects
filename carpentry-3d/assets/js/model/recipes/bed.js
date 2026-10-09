// מתכון מובנה: מיטה (שלב 3 — הועבר מ-templates/bed.js).
//
// מסגרת סביב המזרן (שתי דפנות ארוכות, ראש ורגל), ראש מיטה, קורת תמיכה
// מרכזית, לטות ורגליים. המזרן אינו חלק — הוא נקנה, לא נחתך — ולכן אינו
// ברשימה; מידותיו הן הקלט. X לרוחב המיטה, Z לאורכה (ראש המיטה מאחור), Y למעלה.
// tools/model-check.js מוודא שהמתכון בונה בדיוק מה שהקוד הישן בנה.

const EDGE_PARAM = { key: 'edgeMode', label: 'מידת הקנט', type: 'enum', default: 'subtract', group: 'חיבורים', options: [{ id: 'subtract', name: 'יורדת מהמידה' }, { id: 'add', name: 'נוספת למידה' }] };

export default {
  key: 'bed',
  name: 'מיטה',
  description: 'מסגרת סביב המזרן, ראש מיטה, לטות וקורת תמיכה.',
  laborHours: 9,
  params: [
    { key: 'mattressW', label: 'רוחב המזרן', type: 'mm', min: 700, max: 2000, default: 1600, group: 'מידות' },
    { key: 'mattressL', label: 'אורך המזרן', type: 'mm', min: 1600, max: 2200, default: 2000, group: 'מידות' },
    { key: 'mattressH', label: 'גובה המזרן', type: 'mm', min: 100, max: 400, default: 250, group: 'מידות', hint: 'לקביעת גובה המסגרת' },
    { key: 'frameTop', label: 'גובה פני הלטות מהרצפה', type: 'mm', min: 150, max: 600, default: 300, group: 'מידות' },
    { key: 'headboardH', label: 'גובה ראש המיטה', type: 'mm', min: 0, max: 1500, default: 1000, group: 'מידות', hint: '0 = ללא' },
    { key: 'gap', label: 'מרווח סביב המזרן', type: 'mm', min: 0, max: 40, default: 10, group: 'מידות' },
    { key: 'frameMaterial', label: 'המסגרת וראש המיטה', type: 'material', use: 'panel', default: 'board:veneer-oak-18', group: 'חומרים' },
    { key: 'frameT', label: 'עובי המסגרת', type: 'mm', min: 18, max: 40, default: 25, group: 'חומרים' },
    { key: 'slatMaterial', label: 'הלטות והקורה', type: 'material', use: 'solid', default: 'board:solid-beech', group: 'חומרים' },
    { key: 'legMaterial', label: 'הרגליים', type: 'material', use: 'solid', default: 'board:solid-oak', group: 'חומרים' },
    { key: 'edgeMaterial', label: 'קנט', type: 'material', use: 'edge', default: 'edge:veneer-0.5', group: 'חומרים' },
    { key: 'frameH', label: 'גובה דופן המסגרת', type: 'mm', min: 80, max: 400, default: 200, group: 'מבנה', hint: 'מעל הלטות: מכסה חלק מהמזרן' },
    { key: 'slatW', label: 'רוחב לטה', type: 'mm', min: 50, max: 120, default: 80, group: 'מבנה' },
    { key: 'slatGap', label: 'מרווח בין לטות', type: 'mm', min: 20, max: 100, default: 50, group: 'מבנה' },
    { key: 'slatT', label: 'עובי לטה', type: 'mm', min: 12, max: 30, default: 18, group: 'מבנה' },
    { key: 'legSize', label: 'חתך הרגל', type: 'mm', min: 40, max: 120, default: 60, group: 'מבנה' },
    { key: 'legs', label: 'רגליים', type: 'enum', default: '6', group: 'מבנה', options: [{ id: '4', name: '4 (בפינות)' }, { id: '6', name: '6 (+ באמצע)' }] },
    EDGE_PARAM,
  ],
  vars: [
    { name: 't', expr: 'frameT' },
    { name: 'g', expr: 'gap' },
    { name: 'innerW', expr: 'mattressW + 2 * g' },
    { name: 'innerL', expr: 'mattressL + 2 * g' },
    { name: 'W', expr: 'innerW + 2 * t' },
    { name: 'L', expr: 'innerL + t + t' },
    { name: 'slatTop', expr: 'frameTop' },
    { name: 'frameY1', expr: 'slatTop + frameH' },
    { name: 'frameY0', expr: 'max(0, slatTop - slatT - 40)' },
    { name: 'fh', expr: 'frameY1 - frameY0' },
    { name: 'supportY', expr: 'slatTop - slatT - 30' },
    { name: 'beamW', expr: '60' },
    { name: 'pitch', expr: 'slatW + slatGap' },
    { name: 'slatN', expr: 'floor((innerL - slatGap) / pitch)' },
    { name: 'start', expr: 't + (innerL - (slatN * pitch - slatGap)) / 2' },
    { name: 's', expr: 'legSize' },
    { name: 'legN', expr: "legs == '6' ? 6 : 4" },
  ],
  components: [
    { id: 'rail-L', name: 'דופן ארוכה', material: 'frameMaterial', x: '0', y: 'frameY0', z: 't', w: 't', h: 'fh', d: 'innerL', axis: 'x', grain: 'z', qtyKey: 'rail-long', edges: 'top,front' },
    { id: 'rail-R', name: 'דופן ארוכה', material: 'frameMaterial', x: 'W - t', y: 'frameY0', z: 't', w: 't', h: 'fh', d: 'innerL', axis: 'x', grain: 'z', qtyKey: 'rail-long', edges: 'top,front' },
    { id: 'rail-foot', name: 'דופן רגל המיטה', material: 'frameMaterial', x: '0', y: 'frameY0', z: 't + innerL', w: 'W', h: 'fh', d: 't', axis: 'z', grain: 'x', qtyKey: 'rail-foot', edges: 'top,left,right,front' },
    { id: 'headboard', name: 'ראש מיטה', material: 'frameMaterial', when: 'headboardH > 0', x: '0', y: '0', z: '0', w: 'W', h: 'headboardH', d: 't', axis: 'z', grain: 'y', qtyKey: 'headboard', edges: 'top,left,right,front' },
    { id: 'rail-head', name: 'דופן ראש', material: 'frameMaterial', when: '!(headboardH > 0)', x: '0', y: 'frameY0', z: '0', w: 'W', h: 'fh', d: 't', axis: 'z', grain: 'x', qtyKey: 'rail-head', edges: 'top,left,right' },
    { id: 'ledger-L', name: 'פס תמיכה ללטות', material: 'slatMaterial', x: 't', y: 'supportY', z: 't', w: '30', h: '30', d: 'innerL', axis: 'x', grain: 'z', qtyKey: 'ledger', edges: 'none' },
    { id: 'ledger-R', name: 'פס תמיכה ללטות', material: 'slatMaterial', x: 'W - t - 30', y: 'supportY', z: 't', w: '30', h: '30', d: 'innerL', axis: 'x', grain: 'z', qtyKey: 'ledger', edges: 'none' },
    { id: 'beam', name: 'קורת תמיכה מרכזית', material: 'slatMaterial', x: 'W / 2 - beamW / 2', y: 'supportY - 30', z: 't', w: 'beamW', h: '60', d: 'innerL', axis: 'x', grain: 'z', qtyKey: 'beam', edges: 'none' },
    { id: 'slat', name: 'לטה', material: 'slatMaterial', repeat: 'slatN', index: 'i', x: 't', y: 'slatTop - slatT', z: 'start + i * pitch', w: 'innerW', h: 'slatT', d: 'slatW', axis: 'y', grain: 'x', qtyKey: 'slat', edges: 'none' },
    // רגליים: ארבע בפינות, ואופציונלית שתיים באמצע (מתחת לקורה)
    { id: 'leg', name: 'רגל', material: 'legMaterial', repeat: 'legN', index: 'i',
      x: 'i >= 4 ? W / 2 - s / 2 : (i % 2 == 0 ? t : W - t - s)', y: '0', z: 'i == 4 ? t : (i == 5 ? t + innerL - s : (i < 2 ? t : t + innerL - s))',
      w: 's', h: 'supportY', d: 's', axis: 'x', grain: 'y', qtyKey: 'leg-{round(box_h)}', edges: 'none' },
  ],
  hardware: [
    { id: 'bed-bolts', name: 'חיבורי מיטה / רגליות', material: 'hw:leg-adjust', qty: 'legN' },
  ],
  warnings: [
    { when: "mattressW > 1400 && legs == '4'", text: 'מיטה רחבה על 4 רגליים — הקורה המרכזית תתכופף; מומלץ 6' },
    { when: 'frameH > mattressH', text: 'דופן המסגרת ({frameH}) גבוהה מהמזרן ({mattressH}) — המזרן ישקע בתוכה' },
  ],
  bounds: { w: 'W', h: 'max(frameY1, headboardH)', d: 'L' },
};
