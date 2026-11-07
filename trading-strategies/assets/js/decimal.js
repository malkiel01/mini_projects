// מספרים עשרוניים מדויקים בדפדפן — BigInt, בלי float (אפיון 3.3).
// משמש את מחשבון גודל הפוזיציה ואת התצוגה המקדימה בטופס. מקור האמת לתוצאות — השרת (lib/decimal.php).
// מספר = { n: BigInt, s: קנה מידה } — הערך הוא n / 10^s.

const RE = /^(-)?(\d{1,18})(?:[.,](\d{1,10}))?$/;

export function parse(v) {
  if (typeof v !== 'string') return null;
  const m = RE.exec(v.trim().replace(/−/g, '-'));
  if (!m) return null;
  const frac = m[3] || '';
  return { n: BigInt((m[1] || '') + m[2] + frac), s: frac.length };
}

const P10 = k => 10n ** BigInt(k);
const align = (a, b) => {
  const s = Math.max(a.s, b.s);
  return [a.n * P10(s - a.s), b.n * P10(s - b.s), s];
};
export const add = (a, b) => { const [x, y, s] = align(a, b); return { n: x + y, s }; };
export const sub = (a, b) => { const [x, y, s] = align(a, b); return { n: x - y, s }; };
export const mul = (a, b) => ({ n: a.n * b.n, s: a.s + b.s });
export const abs = a => ({ n: a.n < 0n ? -a.n : a.n, s: a.s });
export const sign = a => (a.n > 0n ? 1 : a.n < 0n ? -1 : 0);
export const cmp = (a, b) => sign(sub(a, b));

/** a ÷ b ל-scale ספרות; mode: 'half' (חצי-למעלה, מהאפס) או 'down' (חיתוך — לא לעבור את הסיכון) */
export function div(a, b, scale = 10, mode = 'half') {
  if (b.n === 0n) return null;
  const num = a.n * P10(b.s + scale + 1);
  const den = b.n * P10(a.s);
  let q = num / den;                      // BigInt — חיתוך לכיוון האפס
  const neg = q < 0n;
  if (neg) q = -q;
  const last = q % 10n;
  q /= 10n;
  if (mode === 'half' && last >= 5n) q += 1n;
  return { n: neg ? -q : q, s: scale };
}

export function str(a) {
  if (!a) return '';
  const neg = a.n < 0n;
  let d = (neg ? -a.n : a.n).toString().padStart(a.s + 1, '0');
  let out = a.s ? d.slice(0, -a.s) + '.' + d.slice(-a.s) : d;
  if (a.s) out = out.replace(/\.?0+$/, '');
  return (neg && out !== '0' ? '-' : '') + out;
}

/** להצגה: מפריד אלפים, ועד maxFrac ספרות אחרי הנקודה (מעוגל) */
export function fmt(v, maxFrac = 2) {
  const a = typeof v === 'string' ? parse(v) : v;
  if (!a) return v == null ? '' : String(v);
  const r = a.s > maxFrac ? div(a, { n: 1n, s: 0 }, maxFrac) : a;
  const t = str(r);
  const neg = t.startsWith('-');
  const [i, f] = (neg ? t.slice(1) : t).split('.');
  return (neg ? '-' : '') + i.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (f ? '.' + f : '');
}
