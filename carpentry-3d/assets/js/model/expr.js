// נוסחאות במתכונים: שפה קטנה ובטוחה — בלי eval ובלי גישה לשום דבר מחוץ למשתנים.
//
//   W - 2*T                    חשבון: + - * / % וסוגריים
//   cols > 1 && doors == 'wood' השוואות (== != < <= > >=), וגם (&&), או (||), לא (!)
//   i % 2 == 0 ? 'left' : 'right'   תנאי מקוצר
//   min(a, b) max round floor ceil abs sqrt clamp(x, lo, hi) if(c, a, b)
//
// משתנה שלא קיים — שגיאה עם שמו (לא 0 שקט), כדי שטעות הקלדה תיראה מיד במסך.
// הנוסחה מהודרת פעם אחת לפונקציה, ואז מחושבת בכל בנייה מול טבלת המשתנים.

export class ExprError extends Error {}

const FUNCS = {
  min: Math.min, max: Math.max, round: Math.round, floor: Math.floor, ceil: Math.ceil, abs: Math.abs, sqrt: Math.sqrt,
  clamp: (x, lo, hi) => Math.min(hi, Math.max(lo, x)),
};

function tokenize(src) {
  const out = [];
  let i = 0;
  while (i < src.length) {
    const c = src[i];
    if (/\s/.test(c)) { i += 1; continue; }
    if (/[0-9.]/.test(c)) {
      const m = /^(\d+\.?\d*|\.\d+)/.exec(src.slice(i));
      if (!m) throw new ExprError(`מספר לא תקין ליד "${src.slice(i, i + 6)}"`);
      out.push({ t: 'num', v: Number(m[1]) }); i += m[1].length; continue;
    }
    if (/[A-Za-z_֐-׿]/.test(c)) {
      const m = /^[A-Za-z_֐-׿][A-Za-z0-9_֐-׿]*/.exec(src.slice(i));
      out.push({ t: 'id', v: m[0] }); i += m[0].length; continue;
    }
    if (c === "'" || c === '"') {
      const j = src.indexOf(c, i + 1);
      if (j < 0) throw new ExprError('מחרוזת בלי מרכאות סוגרות');
      out.push({ t: 'str', v: src.slice(i + 1, j) }); i = j + 1; continue;
    }
    const two = src.slice(i, i + 2);
    if (['==', '!=', '<=', '>=', '&&', '||'].includes(two)) { out.push({ t: 'op', v: two }); i += 2; continue; }
    if ('+-*/%()<>!?:,'.includes(c)) { out.push({ t: 'op', v: c }); i += 1; continue; }
    if (c === '=') throw new ExprError('להשוואה כותבים == (שני סימני שוויון)');
    throw new ExprError(`תו לא מוכר: "${c}"`);
  }
  return out;
}

// פרסר ירידה רקורסיבית → עץ של פונקציות (scope) => ערך
function parse(src) {
  const toks = tokenize(src);
  let k = 0;
  const peek = () => toks[k];
  const isOp = (v) => toks[k] && toks[k].t === 'op' && toks[k].v === v;
  const expect = (v) => { if (!isOp(v)) throw new ExprError(`חסר "${v}"`); k += 1; };

  function ternary() {
    const c = or();
    if (!isOp('?')) return c;
    k += 1; const a = ternary(); expect(':'); const b = ternary();
    return (s) => (truthy(c(s)) ? a(s) : b(s));
  }
  function or() { let l = and(); while (isOp('||')) { k += 1; const a = l, b = and(); l = (s) => (truthy(a(s)) || truthy(b(s)) ? 1 : 0); } return l; }
  function and() { let l = cmp(); while (isOp('&&')) { k += 1; const a = l, b = cmp(); l = (s) => (truthy(a(s)) && truthy(b(s)) ? 1 : 0); } return l; }
  function cmp() {
    let l = add();
    while (peek() && peek().t === 'op' && ['==', '!=', '<', '<=', '>', '>='].includes(peek().v)) {
      const op = toks[k++].v, a = l, b = add();
      l = (s) => { const x = a(s), y = b(s); return (op === '==' ? x == y : op === '!=' ? x != y : op === '<' ? x < y : op === '<=' ? x <= y : op === '>' ? x > y : x >= y) ? 1 : 0; };   // eslint-disable-line eqeqeq
    }
    return l;
  }
  function add() {
    let l = mul();
    while (isOp('+') || isOp('-')) { const op = toks[k++].v, a = l, b = mul(); l = op === '+' ? (s) => num(a(s)) + num(b(s)) : (s) => num(a(s)) - num(b(s)); }
    return l;
  }
  function mul() {
    let l = unary();
    while (isOp('*') || isOp('/') || isOp('%')) {
      const op = toks[k++].v, a = l, b = unary();
      l = op === '*' ? (s) => num(a(s)) * num(b(s)) : op === '/' ? (s) => { const d = num(b(s)); if (d === 0) throw new ExprError('חלוקה באפס'); return num(a(s)) / d; } : (s) => num(a(s)) % num(b(s));
    }
    return l;
  }
  function unary() {
    if (isOp('-')) { k += 1; const a = unary(); return (s) => -num(a(s)); }
    if (isOp('+')) { k += 1; return unary(); }
    if (isOp('!')) { k += 1; const a = unary(); return (s) => (truthy(a(s)) ? 0 : 1); }
    return atom();
  }
  function atom() {
    const t = toks[k];
    if (!t) throw new ExprError('הנוסחה נגמרה באמצע');
    k += 1;
    if (t.t === 'num') return () => t.v;
    if (t.t === 'str') return () => t.v;
    if (t.t === 'op' && t.v === '(') { const e = ternary(); expect(')'); return e; }
    if (t.t === 'id') {
      if (isOp('(')) {
        k += 1;
        const args = [];
        if (!isOp(')')) { args.push(ternary()); while (isOp(',')) { k += 1; args.push(ternary()); } }
        expect(')');
        if (t.v === 'if') {
          if (args.length !== 3) throw new ExprError('if צריך שלושה ערכים: if(תנאי, אם כן, אם לא)');
          const [c, a, b] = args;
          return (s) => (truthy(c(s)) ? a(s) : b(s));
        }
        const f = FUNCS[t.v];
        if (!f) throw new ExprError(`פונקציה לא מוכרת: ${t.v}`);
        return (s) => f(...args.map((a) => num(a(s))));
      }
      if (t.v === 'true') return () => 1;
      if (t.v === 'false') return () => 0;
      const name = t.v;
      return (s) => { if (!Object.prototype.hasOwnProperty.call(s, name)) throw new ExprError(`משתנה לא מוכר: ${name}`); return s[name]; };
    }
    throw new ExprError(`לא צפוי כאן: "${t.v}"`);
  }

  if (!toks.length) throw new ExprError('נוסחה ריקה');
  const fn = ternary();
  if (k < toks.length) throw new ExprError(`מיותר בסוף: "${toks[k].v}"`);
  return fn;
}
const truthy = (v) => (typeof v === 'string' ? v !== '' && v !== 'none' && v !== '0' : !!v);
function num(v) {
  if (typeof v === 'number') return v;
  if (typeof v === 'boolean') return v ? 1 : 0;
  const n = Number(v);
  if (!Number.isFinite(n)) throw new ExprError(`"${v}" אינו מספר`);
  return n;
}

const cache = new Map();
/** מהדר נוסחה (עם מטמון). זורק ExprError על תחביר שגוי. */
export function compile(src) {
  const key = String(src ?? '').trim();
  if (cache.has(key)) return cache.get(key);
  const fn = parse(key);
  if (cache.size > 2000) cache.clear();
  cache.set(key, fn);
  return fn;
}
/** מחשב נוסחה מול משתנים. מספר נשאר מספר; מחרוזת — מחרוזת. */
export function evaluate(src, scope) {
  if (typeof src === 'number') return src;
  return compile(src)(scope);
}
/** נוסחה שחייבת לצאת מספר סופי. */
export function evalNum(src, scope) {
  const v = num(evaluate(src, scope));
  if (!Number.isFinite(v)) throw new ExprError('התוצאה אינה מספר סופי');
  return v;
}
export const evalBool = (src, scope) => truthy(evaluate(src, scope));

/** טקסט עם {נוסחה} בתוכו: "מדף {i+1}" → "מדף 3". מספרים מעוגלים לשלם/עשירית. */
export function interpolate(text, scope) {
  return String(text ?? '').replace(/\{([^}]+)\}/g, (_, e) => {
    const v = evaluate(e, scope);
    return typeof v === 'number' ? String(Math.round(v * 10) / 10) : String(v);
  });
}

/** בדיקת תחביר בלבד — לעורך: מחזיר הודעת שגיאה או null. */
export function syntaxError(src) {
  try { compile(src); return null; } catch (e) { return e.message; }
}
