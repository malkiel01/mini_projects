/**
 * ui-core/keys.js — קיצורי מקלדת שעובדים גם במקלדת עברית.
 *
 * הבעיה: ברסטר, בווקטורי ובסטודיו העיצוב הקיצורים נבדקים לפי e.key. כשהמקלדת בעברית, Ctrl+Z שולח e.key = 'ז',
 * והקיצור לא נורה. הפתרון (כמו בעורך התמלילים): להתאים לפי e.code — המקש הפיזי, בלי קשר לשפה.
 *
 *   const keys = shortcuts();                                    // מאזין אחד על document
 *   keys.add('Mod+Z', undo, { label: 'ביטול' });                 // Mod = Ctrl, או ⌘ במק
 *   keys.add('Mod+Shift+Z', redo);  keys.add('Mod+Y', redo);
 *   keys.add('V', () => setTool('select'));                      // בלי מגביל — לא נורה בתוך שדה טקסט
 *   keys.add('Mod+S', save, { inFields: true });                 // גם מתוך שדה
 *   keys.add('Mod+Shift?+=', zoomIn);                            // Shift? — עם או בלי (ב-+ צריך Shift)
 *   keys.add('Delete', remove, { when: () => hasSelection() });
 *   keys.title(button, 'Mod+Z')                                  // title "ביטול (Ctrl+Z)" + aria-keyshortcuts
 *
 *   matches(e, 'Mod+Z')   // למעבר הדרגתי: מחליפים את e.key === 'z' בשרשרת ה-if הקיימת, בלי לשנות שום דבר אחר
 *
 * צירוף: מגבילים (Mod / Ctrl / Meta / Alt / Shift, ו-Shift? לרשות) ומקש אחד. המקש: אות (Z → KeyZ), ספרה (0 → Digit0,
 * וגם בלוח המספרים), סימן (= - [ ] , . / ; ' ` \), או שם של e.code / e.key (Delete, Escape, Space, ArrowLeft, F2,
 * Enter, Tab, Backspace, Home, End, PageUp…).
 *
 * ברירות מחדל: לא בתוך שדה טקסט / contenteditable (שם Ctrl+Z הוא של השדה), לא בזמן הרכבת טקסט (IME), ולא כשחלון
 * פתוח והמקש נלחץ בתוכו. inFields / inDialogs דורסים. preventDefault — כשהקיצור נורה. repeat — מותר (חצים להזזה).
 */

const MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');

const SIGNS = { '=': 'Equal', '+': 'Equal', '-': 'Minus', '[': 'BracketLeft', ']': 'BracketRight', ',': 'Comma', '.': 'Period',
    '/': 'Slash', ';': 'Semicolon', "'": 'Quote', '`': 'Backquote', '\\': 'Backslash' };
// מקש עם תאום בלוח המספרים.
const ALSO = { Equal: ['NumpadAdd'], Minus: ['NumpadSubtract'], Slash: ['NumpadDivide'], Period: ['NumpadDecimal'], Enter: ['NumpadEnter'] };
for (let d = 0; d <= 9; d++) ALSO['Digit' + d] = ['Numpad' + d];
const NAMES = { Esc: 'Escape', Del: 'Delete', Space: 'Space', ' ': 'Space', Up: 'ArrowUp', Down: 'ArrowDown', Left: 'ArrowLeft', Right: 'ArrowRight' };

/** 'Mod+Shift?+Z' → { mod, ctrl, meta, alt, shift: true|false|null, codes: ['KeyZ'] } */
export function parse(combo) {
    const parts = String(combo).split('+');
    // 'Mod++' — המקש הוא '+'
    if (parts.length > 1 && parts[parts.length - 1] === '' && parts[parts.length - 2] === '') parts.splice(-2, 2, '+');
    const key = parts.pop();
    const c = { mod: false, ctrl: false, meta: false, alt: false, shift: false, key };
    for (const p of parts) {
        const m = p.toLowerCase();
        if (m === 'mod') c.mod = true;
        else if (m === 'ctrl' || m === 'control') c.ctrl = true;
        else if (m === 'meta' || m === 'cmd') c.meta = true;
        else if (m === 'alt' || m === 'option') c.alt = true;
        else if (m === 'shift') c.shift = true;
        else if (m === 'shift?') c.shift = null;
        else throw new Error(`keys: מגביל לא מוכר "${p}" ב-"${combo}"`);
    }
    let code;
    if (/^[a-z]$/i.test(key)) code = 'Key' + key.toUpperCase();
    else if (/^\d$/.test(key)) code = 'Digit' + key;
    else if (SIGNS[key]) code = SIGNS[key];
    else code = NAMES[key] || key;
    c.codes = [code, ...(ALSO[code] || [])];
    return c;
}

/** האם האירוע הוא הצירוף. לפי e.code — עובד בכל שפת מקלדת. */
export function matches(e, combo) {
    const c = typeof combo === 'string' ? parse(combo) : combo;
    if (!c.codes.includes(e.code) && !(c.codes[0] === e.key && !e.code)) return false;
    const ctrl = c.ctrl || (c.mod && !MAC), meta = c.meta || (c.mod && MAC);
    // במחשב שאינו מק, ⌘ (Meta) נספר כ-Mod — מקלדות מק מחוברות ל-Windows / לינוקס.
    const wantCtrlOrMeta = c.mod && !c.ctrl && !c.meta;
    if (wantCtrlOrMeta) { if (!(e.ctrlKey || e.metaKey)) return false; }
    else if (e.ctrlKey !== ctrl || e.metaKey !== meta) return false;
    if (e.altKey !== c.alt) return false;
    if (c.shift !== null && e.shiftKey !== c.shift) return false;
    return true;
}

const SIGN_OF = Object.fromEntries(Object.entries(SIGNS).filter(([s]) => s !== '+').map(([s, c]) => [c, s]));

/**
 * keyOf(e) — המקש הפיזי כאות לטינית קטנה, בלי קשר לשפת המקלדת: KeyZ → 'z', Digit1 / Numpad1 → '1',
 * Equal / NumpadAdd → '=', Minus → '-', BracketLeft → '['. מקש אחר (Escape, ArrowLeft, ' ') → e.key.toLowerCase().
 *
 * התיקון הכי קטן לשרשרת if קיימת: const k = e.key.toLowerCase()  →  const k = keyOf(e)
 */
export function keyOf(e) {
    const c = e.code || '';
    if (/^Key[A-Z]$/.test(c)) return c.slice(3).toLowerCase();
    if (/^(Digit|Numpad)\d$/.test(c)) return c.slice(-1);
    if (c === 'NumpadAdd') return '=';
    if (c === 'NumpadSubtract') return '-';
    if (SIGN_OF[c]) return SIGN_OF[c];
    return String(e.key || '').toLowerCase();
}

/** תווית לתצוגה: 'Mod+Shift+Z' → "Ctrl+Shift+Z" (או "⇧⌘Z" במק). */
export function label(combo) {
    const c = parse(combo);
    const k = c.key.length === 1 ? c.key.toUpperCase() : ({ Escape: 'Esc', Delete: 'Del', ArrowLeft: '←', ArrowRight: '→', ArrowUp: '↑', ArrowDown: '↓', Space: 'רווח' }[c.codes[0]] || c.key);
    if (MAC) return (c.ctrl ? '⌃' : '') + (c.alt ? '⌥' : '') + (c.shift ? '⇧' : '') + (c.mod || c.meta ? '⌘' : '') + k;
    return [c.mod || c.ctrl ? 'Ctrl' : '', c.meta ? 'Win' : '', c.alt ? 'Alt' : '', c.shift ? 'Shift' : '', k].filter(Boolean).join('+');
}

/** ערך ל-aria-keyshortcuts: 'Mod+Z' → "Control+Z" (או "Meta+Z" במק). */
export function ariaKeys(combo) {
    const c = parse(combo);
    const k = c.key.length === 1 ? c.key.toUpperCase() : c.codes[0];
    return [(c.mod && !MAC) || c.ctrl ? 'Control' : '', (c.mod && MAC) || c.meta ? 'Meta' : '', c.alt ? 'Alt' : '', c.shift ? 'Shift' : '', k]
        .filter(Boolean).join('+');
}

const isField = t => !!t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) && !/^(checkbox|radio|button|submit|range|color)$/.test(t.type || ''));

export function shortcuts(root = document) {
    const list = [];
    const onKey = e => {
        if (e.defaultPrevented || e.isComposing || e.keyCode === 229) return;
        const t = e.target;
        const inField = isField(t);
        const inDialog = !!t?.closest?.('dialog[open]');
        // הצירופים הארוכים קודם: Mod+Shift+Z לפני Mod+Z, גם אם נרשמו הפוך.
        for (const s of list) {
            if (inField && !s.inFields) continue;
            if (inDialog && !s.inDialogs) continue;
            if (!matches(e, s.parsed)) continue;
            if (s.when && !s.when(e)) continue;
            if (s.preventDefault !== false) e.preventDefault();
            s.fn(e);
            return;
        }
    };
    root.addEventListener('keydown', onKey);

    return {
        add(combo, fn, opts = {}) {
            for (const c of [combo].flat()) {
                const entry = { combo: c, parsed: parse(c), fn, ...opts };
                list.push(entry);
            }
            list.sort((a, b) => weight(b.parsed) - weight(a.parsed));
            return this;
        },
        remove(combo) {
            for (let i = list.length - 1; i >= 0; i--) if (list[i].combo === combo) list.splice(i, 1);
            return this;
        },
        /** title "תווית (Ctrl+Z)" ו-aria-keyshortcuts על כפתור. */
        title(el, combo, text = null) {
            const entry = list.find(s => s.combo === combo);
            const base = text || entry?.label || el.getAttribute('aria-label') || el.title || '';
            el.title = base ? `${base} (${label(combo)})` : label(combo);
            el.setAttribute('aria-keyshortcuts', ariaKeys(combo));
            return el;
        },
        list: () => list.map(({ combo, label: l }) => ({ combo, label: l, keys: label(combo) })),
        destroy: () => root.removeEventListener('keydown', onKey),
    };
}

const weight = c => (c.mod || c.ctrl || c.meta ? 4 : 0) + (c.alt ? 2 : 0) + (c.shift === true ? 1 : 0);
