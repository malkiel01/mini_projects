// בודק JSON Schema קטן — רק המילים שהסכמות של mbe משתמשות בהן. אותו קוד ב-PHP (src/php/schema.php), ואותן
// בעיות באותו סדר: { path: '/surfaces/0/id', message }. מילה שלא מוכרת כאן בסכמה — שגיאת פיתוח (זורקת).

const KNOWN = new Set(['$id', '$schema', 'title', 'description', '$defs', '$ref', 'type', 'required', 'properties',
    'additionalProperties', 'items', 'const', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum',
    'exclusiveMinimum', 'minItems', 'maxItems']);

export const MAX_SCHEMA_ISSUES = 100;

export function validateSchema(value, schema) {
    const issues = [];
    check(value, schema, schema, '', issues);
    return issues;
}

function typeOf(v) {
    if (v === null) return 'null';
    if (Array.isArray(v)) return 'array';
    if (typeof v === 'number') return Number.isInteger(v) ? 'integer' : 'number';
    return typeof v;
}

function typeMatches(v, t) {
    const actual = typeOf(v);
    return actual === t || (t === 'number' && actual === 'integer');
}

function check(v, s, root, path, issues) {
    if (issues.length >= MAX_SCHEMA_ISSUES) return;
    for (const k of Object.keys(s)) if (!KNOWN.has(k)) throw new Error(`schema: מילה לא נתמכת "${k}"`);
    const add = message => issues.push({ path: path || '/', message });
    if (s.$ref) {
        const m = /^#\/\$defs\/([A-Za-z0-9_-]+)$/.exec(s.$ref);
        if (!m || !root.$defs?.[m[1]]) throw new Error(`schema: $ref לא ידוע ${s.$ref}`);
        check(v, root.$defs[m[1]], root, path, issues);
    }
    if (s.type !== undefined) {
        const types = Array.isArray(s.type) ? s.type : [s.type];
        if (!types.some(t => typeMatches(v, t))) return add(`צריך להיות ${types.join(' או ')}`);
    }
    if ('const' in s && v !== s.const) add(`צריך להיות ${JSON.stringify(s.const)}`);
    if (typeof v === 'string') {
        const len = [...v].length;
        if (s.minLength !== undefined && len < s.minLength) add(`קצר מ-${s.minLength} תווים`);
        if (s.maxLength !== undefined && len > s.maxLength) add(`ארוך מ-${s.maxLength} תווים`);
        if (s.pattern !== undefined && !new RegExp(s.pattern, 'u').test(v)) add('לא בתבנית הנדרשת');
    }
    if (typeof v === 'number') {
        if (s.minimum !== undefined && v < s.minimum) add(`קטן מ-${s.minimum}`);
        if (s.maximum !== undefined && v > s.maximum) add(`גדול מ-${s.maximum}`);
        if (s.exclusiveMinimum !== undefined && v <= s.exclusiveMinimum) add(`צריך להיות גדול מ-${s.exclusiveMinimum}`);
    }
    if (Array.isArray(v)) {
        if (s.minItems !== undefined && v.length < s.minItems) add(`פחות מ-${s.minItems} פריטים`);
        if (s.maxItems !== undefined && v.length > s.maxItems) return add(`יותר מ-${s.maxItems} פריטים`);
        if (s.items) v.forEach((x, i) => check(x, s.items, root, `${path}/${i}`, issues));
    }
    if (typeOf(v) === 'object') {
        for (const r of s.required || []) if (!Object.hasOwn(v, r)) add(`חסר השדה "${r}"`);
        for (const k of Object.keys(v).sort()) { // ממוין — אותו סדר בעיות כמו ב-PHP
            const sub = s.properties && Object.hasOwn(s.properties, k) ? s.properties[k] : s.additionalProperties;
            if (sub === false) add(`שדה לא מותר "${k}"`);
            else if (sub && sub !== true) check(v[k], sub, root, `${path}/${pointer(k)}`, issues);
        }
    }
}

function pointer(k) {
    return k.replace(/~/g, '~0').replace(/\//g, '~1');
}
