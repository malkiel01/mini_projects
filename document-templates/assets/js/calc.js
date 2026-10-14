// שלב 11: שדות מחושבים ותנאי הצגה — זהה ל-lib/calc.php (נבדקות מול אותם
// ווקטורים ב-tests/calc-vectors.json). הדפדפן מחשב לתצוגה מיידית; השרת מחשב
// מחדש בכל שמירה, ורק הערך שלו נשמר.
(function (root) {
  'use strict';

  class CalcError extends Error {}
  const ISO = /^\d{4}-\d{2}-\d{2}$/;
  const under = (s) => String(s).trim().replace(/\s+/g, '_');

  function tokens(s) {
    const out = [];
    const ch = [...s];
    let i = 0;
    while (i < ch.length) {
      const c = ch[i];
      if (/\s/.test(c)) { i++; continue; }
      if (/[0-9.]/.test(c)) {
        let j = i;
        while (j < ch.length && /[0-9.]/.test(ch[j])) j++;
        const n = ch.slice(i, j).join('');
        if (!/^(\d+\.?\d*|\.\d+)$/.test(n)) throw new CalcError('מספר לא תקין: ' + n);
        out.push(['num', parseFloat(n)]); i = j; continue;
      }
      if (/[\p{L}_"']/u.test(c)) {
        let j = i;
        while (j < ch.length && /[\p{L}\p{N}_."']/u.test(ch[j])) j++;
        out.push(['id', ch.slice(i, j).join('')]); i = j; continue;
      }
      const two = c + (ch[i + 1] || '');
      if (['>=', '<=', '!=', '=='].includes(two)) { out.push(['op', two === '==' ? '=' : two]); i += 2; continue; }
      if ('+-*/(),=<>'.includes(c)) { out.push(['op', c]); i++; continue; }
      throw new CalcError('תו לא צפוי: ' + c);
    }
    return out;
  }

  const num = (v) => {
    if (Array.isArray(v)) return v.reduce((s, x) => s + num(x), 0);
    if (typeof v === 'string' && ISO.test(v)) return Date.parse(v + 'T00:00:00Z') / 86400000;
    if (typeof v === 'number') return v;
    const n = typeof v === 'string' && v.trim() !== '' ? Number(v) : NaN;
    return Number.isFinite(n) ? n : 0;
  };
  const isNum = (v) => typeof v === 'number' || (typeof v === 'string' && v.trim() !== '' && Number.isFinite(Number(v)));
  // PHP round: חצי — הלאה מאפס
  const roundTo = (x, n) => { const k = Math.pow(10, n); const r = Math.round(Math.abs(x) * k + 1e-9) / k; return x < 0 ? -r : r; };

  function fn(name, a) {
    const need = (k) => { if (a.length < k) throw new CalcError(`ל-${name} חסרים ערכים`); };
    switch (name) {
      case 'sum': need(1); return a.reduce((s, x) => s + num(x), 0);
      case 'count': need(1); return Array.isArray(a[0]) ? a[0].length : (a[0] === '' ? 0 : 1);
      case 'round': need(1); return roundTo(num(a[0]), Math.trunc(num(a[1] ?? 0)));
      case 'min': need(1); return Math.min(...a.map(num));
      case 'max': need(1); return Math.max(...a.map(num));
      case 'if': need(3); return num(a[0]) !== 0 ? a[1] : a[2];
      case 'days': {
        need(2);
        if (typeof a[0] !== 'string' || !ISO.test(a[0])) return '';
        return new Date(Date.parse(a[0] + 'T00:00:00Z') + Math.round(num(a[1])) * 86400000).toISOString().slice(0, 10);
      }
      case 'diff': need(2); return Math.round(num(a[1]) - num(a[0]));
    }
    throw new CalcError('פונקציה לא מוכרת: ' + name);
  }

  /** מחשב ביטוי. lookup(name) → מספר | מחרוזת | מערך | null (לא מוכר). */
  function evaluate(expr, lookup) {
    const t = tokens(String(expr || ''));
    let p = 0;
    const peek = () => t[p];
    const isOp = (x, ...ops) => x && x[0] === 'op' && ops.includes(x[1]);
    const eat = (op) => {
      const x = t[p];
      if (op && !isOp(x, op)) throw new CalcError(`חסר "${op}"`);
      p++;
      return x;
    };
    function cmp() {
      const a = add();
      const x = peek();
      if (isOp(x, '=', '!=', '>', '<', '>=', '<=')) {
        eat();
        const b = add();
        let eq;
        if ((x[1] === '=' || x[1] === '!=') && !isNum(a) && !isNum(b)) eq = String(Array.isArray(a) ? '' : a) === String(Array.isArray(b) ? '' : b);
        else eq = Math.abs(num(a) - num(b)) < 1e-9;
        const r = { '=': eq, '!=': !eq, '>': num(a) > num(b), '<': num(a) < num(b), '>=': num(a) >= num(b), '<=': num(a) <= num(b) }[x[1]];
        return r ? 1 : 0;
      }
      return a;
    }
    function add() {
      let a = mul();
      for (let x = peek(); isOp(x, '+', '-'); x = peek()) { eat(); const b = mul(); a = x[1] === '+' ? num(a) + num(b) : num(a) - num(b); }
      return a;
    }
    function mul() {
      let a = unary();
      for (let x = peek(); isOp(x, '*', '/'); x = peek()) {
        eat(); const b = unary();
        a = x[1] === '*' ? num(a) * num(b) : (num(b) === 0 ? 0 : num(a) / num(b));
      }
      return a;
    }
    function unary() { if (isOp(peek(), '-')) { eat(); return -num(atom()); } return atom(); }
    function atom() {
      const x = eat();
      if (!x) throw new CalcError('הביטוי נגמר באמצע');
      if (x[0] === 'num') return x[1];
      if (isOp(x, '(')) { const v = cmp(); eat(')'); return v; }
      if (x[0] !== 'id') throw new CalcError('לא צפוי: ' + x[1]);
      if (isOp(peek(), '(')) {
        eat('(');
        const args = [];
        if (!isOp(peek(), ')')) { args.push(cmp()); while (isOp(peek(), ',')) { eat(); args.push(cmp()); } }
        eat(')');
        return fn(x[1].toLowerCase(), args);
      }
      const v = lookup(x[1]);
      if (v === null || v === undefined) throw new CalcError('לא מוכר: ' + x[1]);
      return v;
    }
    if (!t.length) return '';
    const v = cmp();
    if (p < t.length) throw new CalcError('מיותר בסוף: ' + t[p][1]);
    return v;
  }

  /** מספר → מחרוזת לשמירה, כמו calc_str ב-PHP. */
  function str(v) {
    if (typeof v === 'string') return v;
    if (Array.isArray(v)) return '';
    let r = roundTo(Number(v) || 0, 2);
    if (Math.abs(r) < 1e-9) r = 0;
    return r.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
  }

  function display(v, format) {
    v = String(v ?? '');
    if (v === '') return '';
    if (format === 'date') return ISO.test(v) ? v.split('-').reverse().join('/') : v;
    if (!isNum(v)) return v;
    const group = (n, d) => Number(n).toFixed(d).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (format === 'money') return group(v, 2) + ' ₪';
    const dec = v.includes('.') ? v.split('.')[1].length : 0;
    return group(v, dec);
  }

  const names = (f) => [f.key, f.placeholder, f.label && under(f.label)].filter(Boolean);
  function rows(v) {
    try { const r = v ? JSON.parse(v) : []; return Array.isArray(r) ? r.filter(x => x && typeof x === 'object') : []; }
    catch (e) { return []; }
  }
  const col = (f, name) => (f.columns || []).find(c => c.key === name || c.name === name || under(c.label) === name) || null;

  function lookup(fields, values, tableField, row) {
    const byName = {};
    for (const f of fields) for (const n of names(f)) if (!(n in byName)) byName[n] = f;
    return (name) => {
      let c;
      if (tableField && row && (c = col(tableField, name))) return row[c.key] ?? '';
      if (name.includes('.')) {
        const [t, cn] = [name.slice(0, name.indexOf('.')), name.slice(name.indexOf('.') + 1)];
        const f = byName[t];
        if (!f || f.type !== 'table' || !(c = col(f, cn))) return null;
        return rows(values[f.key]).map(r => r[c.key] ?? '');
      }
      const f = byName[name];
      if (!f) return null;
      const v = values[f.key] ?? '';
      if (f.type === 'table') return rows(v);
      if (f.type === 'checkbox') return v === '1' ? 1 : 0;
      return v;
    };
  }

  function check(expr, fields, tableField) {
    if (!String(expr || '').trim()) return null;
    try {
      const row = tableField ? Object.fromEntries((tableField.columns || []).map(c => [c.key, ''])) : null;
      evaluate(expr, lookup(fields, {}, tableField, row));
      return null;
    } catch (e) {
      if (e instanceof CalcError) return e.message;
      throw e;
    }
  }

  function tableCompute(f, fields, values, rs) {
    return rs.map(r => {
      const out = { ...r };
      for (const c of f.columns || []) {
        if (c.type !== 'calc') continue;
        try { out[c.key] = str(evaluate(c.formula || '', lookup(fields, values, f, out))); }
        catch (e) { if (!(e instanceof CalcError)) throw e; out[c.key] = ''; }
      }
      return out;
    });
  }

  /** מחשב את כל השדות המחושבים; מחזיר רק מה שהשתנה. */
  function all(schema, values) {
    const fields = schema.fields || [];
    const calcs = fields.filter(f => f.type === 'calc');
    const v = { ...values };
    for (let pass = 0; pass <= calcs.length; pass++) {
      let changed = false;
      for (const f of calcs) {
        let r;
        try { r = str(evaluate(f.formula || '', lookup(fields, v))); }
        catch (e) { if (!(e instanceof CalcError)) throw e; r = ''; }
        if ((v[f.key] ?? '') !== r) { v[f.key] = r; changed = true; }
      }
      if (!changed) break;
    }
    const out = {};
    for (const f of calcs) if ((values[f.key] ?? '') !== v[f.key]) out[f.key] = v[f.key];
    return out;
  }

  function shown(cond, fields, values) {
    if (!cond || !cond.key) return true;
    const f = fields.find(x => x.key === cond.key);
    if (!f) return true;
    let v = String(values[f.key] ?? '');
    if (f.type === 'table') v = rows(v).length ? '1' : '';
    let want = String(cond.value ?? '');
    if (f.type === 'checkbox') { v = v === '1' ? '1' : ''; want = ['1', 'כן', 'true'].includes(want) ? '1' : ''; }
    switch (cond.op || 'eq') {
      case 'filled': return v.trim() !== '';
      case 'empty': return v.trim() === '';
      case 'neq': return v.trim() !== want.trim();
      default: return v.trim() === want.trim();
    }
  }

  /** מפתחות השדות שמוצגים עכשיו. */
  function visibleKeys(schema, values) {
    const fields = schema.fields || [];
    const on = Object.fromEntries(fields.map(f => [f.key, true]));
    for (let pass = 0; pass < 5; pass++) {
      let changed = false;
      for (const f of fields) {
        const c = f.showIf;
        const ok = shown(c, fields, values) && (!c || !c.key || on[c.key] !== false);
        if (ok !== on[f.key]) { on[f.key] = ok; changed = true; }
      }
      if (!changed) break;
    }
    return new Set(fields.filter(f => on[f.key]).map(f => f.key));
  }

  root.DTCalc = { evaluate, str, display, lookup, check, tableCompute, all, shown, visibleKeys, rows, CalcError };
})(typeof window !== 'undefined' ? window : globalThis);
