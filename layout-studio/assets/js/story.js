// עורך הטקסט של שטף (כמו Story Editor של InDesign) — ProseMirror, בשמות הצמתים של עורך התמלילים (TipTap):
// paragraph, heading {level 1–3}, text, hardBreak; סימונים: bold, italic, underline.
// לכל paragraph / heading יש attrs.id — מזהה פסקה (mbe-format.md סעיף 3): נשמר בעריכה ובשינוי סגנון; בפיצול החלק השני
// מקבל חדש; כפול מהדבקה — העותק מקבל חדש (התוסף ids למטה).
// כך מסמך של עורך התמלילים נכנס בלי תרגום, וטקסט שנכתב כאן נשמר כ-JSON שהוא מבין.
//
// ProseMirror (MIT) — assets/vendor/prosemirror, נטען רק כשפותחים את העורך.

import { paraId, PARA_ID, withParaIds } from './frames.js';

let PM = null;
const pm = async () => (PM ||= await import('../vendor/prosemirror/prosemirror.mjs'));

let schema = null;
export function makeSchema(P) {
  return schema ||= new P.Schema({
    nodes: {
      doc: { content: 'block+' },
      paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null } }, parseDOM: [{ tag: 'p' }], toDOM: () => ['p', 0] },
      heading: {
        group: 'block', content: 'inline*', attrs: { level: { default: 1 }, id: { default: null } }, defining: true,
        parseDOM: [1, 2, 3].map(l => ({ tag: 'h' + l, attrs: { level: l } })),
        toDOM: n => ['h' + n.attrs.level, 0],
      },
      text: { group: 'inline' },
      hardBreak: { inline: true, group: 'inline', selectable: false, parseDOM: [{ tag: 'br' }], toDOM: () => ['br'] },
    },
    marks: {
      bold: { parseDOM: [{ tag: 'strong' }, { tag: 'b' }], toDOM: () => ['strong', 0] },
      italic: { parseDOM: [{ tag: 'em' }, { tag: 'i' }], toDOM: () => ['em', 0] },
      underline: { parseDOM: [{ tag: 'u' }], toDOM: () => ['u', 0] },
    },
  });
}

const BLOCKS = new Set(['paragraph', 'heading']);
const INLINE = new Set(['text', 'hardBreak']);
const MARKS = new Set(['bold', 'italic', 'underline']);

/**
 * התאמת מסמך לסכמה: צומת שלא מוכר (רשימה, טבלה, תמונה…) — הטקסט שבו הופך לפסקה; סימון לא מוכר — יורד.
 * מחזיר { doc, lost } — lost: מה הומר (לשאלה לפני עריכה). בלי עריכה — המסמך המקורי נשאר כמו שהוא.
 */
export function sanitize(doc) {
  const lost = new Set();
  const textOf = n => n.type === 'text' ? n.text || '' : (n.content || []).map(textOf).join(' ');
  const inline = nodes => (nodes || []).flatMap(n => {
    if (!INLINE.has(n.type)) { lost.add(n.type); const t = textOf(n); return t ? [{ type: 'text', text: t }] : []; }
    if (n.type !== 'text') return [{ type: n.type }];
    if (!n.text) return [];
    const marks = (n.marks || []).filter(m => MARKS.has(m.type) || (lost.add('mark:' + m.type), false)).map(m => ({ type: m.type }));
    return [{ type: 'text', text: n.text, ...(marks.length ? { marks } : {}) }];
  });
  const id = n => (typeof n.attrs?.id === 'string' && PARA_ID.test(n.attrs.id) ? { id: n.attrs.id } : {});
  const blocks = (doc?.content || []).map(n => {
    if (n.type === 'heading') return { type: 'heading', attrs: { level: Math.min(3, Math.max(1, n.attrs?.level || 1)), ...id(n) }, content: inline(n.content) };
    if (n.type === 'paragraph') return { type: 'paragraph', attrs: id(n), content: inline(n.content) };
    lost.add(n.type);
    const t = textOf(n);
    return { type: 'paragraph', content: t ? [{ type: 'text', text: t }] : [] };
  }).map(b => (b.content.length ? b : { type: b.type, ...(b.attrs ? { attrs: b.attrs } : {}) }));
  return { doc: { type: 'doc', content: blocks.length ? blocks : [{ type: 'paragraph' }] }, lost: [...lost] };
}

// מזהי פסקה: אחרי כל שינוי — פסקה בלי מזהה מקבלת חדש; מזהה כפול — נשאר אצל הפסקה שהייתה במקום שלו לפני השינוי
// (בפיצול — החלק הראשון; בהדבקה — המקור), והאחרת מקבלת חדש
export function idsPlugin(P) {
  return new P.Plugin({
    appendTransaction(trs, oldState, state) {
      if (!trs.some(t => t.docChanged)) return null;
      const before = new Map();
      oldState.doc.descendants((n, pos) => { if (n.attrs?.id) before.set(n.attrs.id, pos); return n.isBlock && !n.isTextblock; });
      const blocks = [];
      state.doc.descendants((n, pos) => { if (n.isTextblock && 'id' in n.attrs) blocks.push({ n, pos }); return n.isBlock && !n.isTextblock; });
      const homeOf = id => { const was = before.get(id); return was === undefined ? null : trs.reduce((p, t) => t.mapping.map(p, -1), was); };
      const owner = new Map();   // מזהה → המיקום שזכאי לו
      for (const { n, pos } of blocks) {
        const id = n.attrs.id;
        if (!id) continue;
        if (!owner.has(id) || pos === homeOf(id)) owner.set(id, pos);
      }
      // שינוי סגנון (setBlockType) מחליף את הצומת בלי המזהה — הפסקה שבמקום של מזהה שנעלם מקבלת אותו בחזרה
      const orphanAt = new Map();
      for (const id of before.keys()) if (!owner.has(id)) { const h = homeOf(id); if (h !== null && !orphanAt.has(h)) orphanAt.set(h, id); }
      let tr = null;
      const used = new Set(owner.keys());
      for (const { n, pos } of blocks) {
        if (n.attrs.id && owner.get(n.attrs.id) === pos) continue;
        const back = !n.attrs.id && orphanAt.get(pos);
        if (back && !used.has(back)) {
          used.add(back);
          (tr ||= state.tr).setNodeMarkup(pos, null, { ...n.attrs, id: back });
          continue;
        }
        let fresh;
        do fresh = paraId(); while (used.has(fresh));
        used.add(fresh);
        (tr ||= state.tr).setNodeMarkup(pos, null, { ...n.attrs, id: fresh });
      }
      return tr && tr.setMeta('addToHistory', false);
    },
  });
}

/**
 * פתיחת העורך בתוך host. מחזיר { getDoc(), destroy(), view }.
 * onState(info) — אחרי כל שינוי/תזוזה: { style: 'body'|'h1'…, bold } — לסרגל הכלים.
 */
export async function openStory(host, doc, { onState } = {}) {
  const P = await pm();
  const S = makeSchema(P);
  const node = S.nodeFromJSON(withParaIds(sanitize(doc).doc));
  const blockStyle = state => {
    const n = state.selection.$from.parent;
    return n.type.name === 'heading' ? 'h' + n.attrs.level : 'body';
  };
  const isBold = state => {
    const { from, to, empty, $from } = state.selection;
    return empty ? !!S.marks.bold.isInSet(state.storedMarks || $from.marks()) : state.doc.rangeHasMark(from, to, S.marks.bold);
  };
  const report = state => onState && onState({ style: blockStyle(state), bold: isBold(state) });
  const setStyle = key => key === 'body' ? P.setBlockType(S.nodes.paragraph) : P.setBlockType(S.nodes.heading, { level: Number(key.slice(1)) });

  const ids = idsPlugin(P);

  const state = P.EditorState.create({
    doc: node,
    plugins: [
      ids,
      P.history(),
      P.keymap({
        'Mod-z': P.undo, 'Mod-y': P.redo, 'Mod-Shift-z': P.redo,
        'Mod-b': P.toggleMark(S.marks.bold),
        'Mod-0': setStyle('body'), 'Mod-1': setStyle('h1'), 'Mod-2': setStyle('h2'), 'Mod-3': setStyle('h3'),
        'Shift-Enter': (st, dispatch) => { dispatch && dispatch(st.tr.replaceSelectionWith(S.nodes.hardBreak.create()).scrollIntoView()); return true; },
      }),
      P.keymap(P.baseKeymap),
    ],
  });
  host.replaceChildren();
  const view = new P.EditorView(host, {
    state,
    attributes: { dir: 'rtl', lang: 'he', class: 'story-pm', spellcheck: 'false', 'aria-label': 'הטקסט' },
    dispatchTransaction(tr) { view.updateState(view.state.apply(tr)); report(view.state); },
  });
  report(view.state);
  return {
    view,
    getDoc: () => view.state.doc.toJSON(),
    command(name) {
      const cmd = name === 'bold' ? P.toggleMark(S.marks.bold) : setStyle(name);
      cmd(view.state, view.dispatch, view);
      view.focus();
    },
    destroy: () => view.destroy(),
  };
}
