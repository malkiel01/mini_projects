// הערכת מחיר — המלצה, לא הצעה. נגזרת מרשימת החיתוך, הפרזול והתעריפים:
//   לוחות: שטח נטו × מחיר למ"ר (× מקדם פחת — הלוח נקנה שלם)
//   קנט:   מטרים של צדדים שמקבלים קנט × מחיר למטר
//   זכוכית: שטח × מחיר למ"ר
//   פרזול: כמות × מחיר ליחידה
//   עבודה: שעות משוערות של סוג המוצר × תעריף שעה
//   רווח:  אחוז על הכול
// המחיר של כל חומר הוא המומלץ מהספרייה, אלא אם לנגר יש "המחיר שלי".
// בלי DOM — רץ גם ב-node.

import { material } from './materials.js';
import { cutSize } from './blocks.js';

export const PRICING_DEFAULTS = {
  laborHour: 150,   // ₪ לשעה, כשלנגר אין תעריף משלו
  markup: 0.2,      // 20% רווח
  waste: 1.15,      // פחת: נקנים ~15% יותר ממה שנחתך
  kerf: 4,          // רוחב חיתוך במ"מ (לסידור הלוחות)
};

const round = (v) => Math.round(v * 100) / 100;

/**
 * @param model   תוצאת build (parts, hardware, values, template)
 * @param rates   { laborHour, markup, materials: { id: price } } — null/חסר = ברירת מחדל
 */
export function estimate(model, rates = {}) {
  const { parts, hardware, values, template } = model;
  const priceOf = (id) => {
    const my = rates.materials && rates.materials[id];
    const m = material(id);
    return { price: my != null ? my : (m.price || 0), unit: m.priceUnit || 'unit', mine: my != null, name: m.name };
  };

  // לוחות וזכוכית — מצטברים לפי חומר.
  const byMaterial = new Map();
  const edgeT = material(values.edgeMaterial || '').t || 0;
  let edgeMeters = 0;
  for (const p of parts) {
    const m = material(p.material);
    const c = cutSize(p);
    const area = (c.l * c.w) / 1e6;
    const row = byMaterial.get(p.material) || { id: p.material, name: m.name, kind: m.kind, area: 0, count: 0 };
    row.area += area; row.count += 1;
    byMaterial.set(p.material, row);
    // קנט: כל צד שמסומן, באורכו לאורך החלק.
    if (edgeT && m.kind === 'board') {
      const dims = { x: p.box.w, y: p.box.h, z: p.box.d };
      const axisOf = { front: 'z', back: 'z', top: 'y', bottom: 'y', left: 'x', right: 'x' };
      for (const [side, on] of Object.entries(p.edges || {})) {
        if (!on) continue;
        // אורך הצד = המידה בציר הניצב לו ולעובי.
        const other = ['x', 'y', 'z'].filter((a) => a !== p.axis && a !== axisOf[side]);
        edgeMeters += (other.length ? dims[other[0]] : 0) / 1000;
      }
    }
  }

  const lines = [];
  let materialsTotal = 0;
  for (const row of byMaterial.values()) {
    const { price, unit, mine } = priceOf(row.id);
    const qty = unit === 'm2' ? row.area * (row.kind === 'board' ? PRICING_DEFAULTS.waste : 1) : row.count;
    const total = qty * price;
    materialsTotal += total;
    lines.push({ group: row.kind === 'glass' ? 'זכוכית' : 'לוחות', name: row.name, qty: round(qty), unit: unit === 'm2' ? 'מ"ר' : 'יח׳', unitPrice: price, total: round(total), mine, note: unit === 'm2' && row.kind === 'board' ? `${round(row.area)} מ"ר נטו + פחת` : '' });
  }
  if (edgeMeters > 0 && values.edgeMaterial) {
    const { price, mine, name } = priceOf(values.edgeMaterial);
    const total = edgeMeters * price;
    materialsTotal += total;
    lines.push({ group: 'קנט', name, qty: round(edgeMeters), unit: 'מ׳', unitPrice: price, total: round(total), mine });
  }

  let hardwareTotal = 0;
  const hwBy = new Map();
  for (const h of hardware) {
    if (h.kind === 'info' || !h.material) continue;
    const r = hwBy.get(h.material) || { id: h.material, qty: 0 };
    r.qty += h.qty || 1;
    hwBy.set(h.material, r);
  }
  for (const r of hwBy.values()) {
    const { price, mine, name, unit } = priceOf(r.id);
    const total = r.qty * price;
    hardwareTotal += total;
    lines.push({ group: 'פרזול', name, qty: round(r.qty), unit: unit === 'm' ? 'מ׳' : 'יח׳', unitPrice: price, total: round(total), mine });
  }

  const laborHours = template.laborHours || 0;
  const laborRate = rates.laborHour != null ? rates.laborHour : PRICING_DEFAULTS.laborHour;
  const laborTotal = laborHours * laborRate;
  const markup = rates.markup != null ? rates.markup : PRICING_DEFAULTS.markup;
  const subtotal = materialsTotal + hardwareTotal + laborTotal;
  const markupTotal = subtotal * markup;

  return {
    lines,
    materials: round(materialsTotal), hardware: round(hardwareTotal),
    labor: { hours: laborHours, rate: laborRate, total: round(laborTotal), mine: rates.laborHour != null },
    subtotal: round(subtotal),
    markup: { rate: markup, total: round(markupTotal), mine: rates.markup != null },
    total: round(subtotal + markupTotal),
    edgeMeters: round(edgeMeters),
  };
}
