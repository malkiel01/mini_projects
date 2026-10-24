/**
 * חלונות הטקסט — פסקה (הזחה, ריווח, עצירות טאב, שבירת עמודים), קישור, ספירת מילים, מספור, כיתוב, הפניה צולבת,
 * סימניה וטבלה חדשה. הוצאו מ-editor.js (F20 — הפרדת מודולים) כמו שהם; createTextDialogs נקרא פעם אחת בתוך
 * createWordEditor.
 */
import { TextSelection } from '../../vendor/tiptap.js';
import { safeHref, bookmarks, BOOKMARK_RE } from './extensions.js';
import { wordStats } from './view.js';
import { captions, newSeqId, SEQ_LABELS } from './caption.js';
import { tabsOf, ALIGNS as TAB_ALIGNS, LEADERS as TAB_LEADERS } from './tabs.js';
import { h, sheet, field, seg, sw, numInput } from './ui.js';

export function createTextDialogs({ editor, chain, pages }) {
    const ed = editor;
    function paragraphDialog() {
        const node = ed.state.selection.$from.parent;
        const a = node.attrs || {};
        const exact = /^exact:([\d.]+)$/.exec(a.lineHeight || '');
        // עצירות טאב (כמו "טאבים" ב-Word): מיקום בס"מ מהשוליים בצד ההתחלה, יישור, מוביל — שורה לכל עצירה
        const CM = 72 / 2.54;
        const tabList = h('div.tab-list');
        const tabRow = (t = {}) => {
            const row = h('div.tab-row',
                numInput('tab_pos', t.pos ? Math.round(t.pos / CM * 100) / 100 : '', { type: 'text', pattern: '[0-9]+([.,][0-9]+)?', 'aria-label': 'מיקום (ס"מ)', placeholder: 'ס"מ' }),
                h('select', { name: 'tab_align', 'aria-label': 'יישור' }, Object.entries(TAB_ALIGNS).map(([k, v]) => h('option', { value: k, selected: k === (t.align || 'start') }, v))),
                h('select', { name: 'tab_leader', 'aria-label': 'מוביל' }, Object.entries(TAB_LEADERS).map(([k, v]) => h('option', { value: k, selected: k === (t.leader || 'none') }, v))),
                h('button.icon-btn', { type: 'button', 'aria-label': 'מחיקת העצירה', onclick: () => row.remove() }, '✕'));
            tabList.append(row);
            return row;
        };
        tabsOf(a.tabs).forEach(tabRow);
        sheet('פסקה', body => {
            body.append(
                h('section.group', h('h3', 'כיוון'), seg('dir', { rtl: 'מימין לשמאל', ltr: 'משמאל לימין' }, a.dir || 'rtl', 'כיוון')),
                h('section.group', h('h3', 'ריווח שורות'),
                    seg('line', { '': 'רגיל', 1: '1', 1.15: '1.15', 1.5: '1.5', 2: '2', exact: 'מדויק' }, exact ? 'exact' : (a.lineHeight || ''), 'ריווח שורות'),
                    field('מדויק (נקודות)', numInput('exact', exact ? exact[1] : '', { min: 4, max: 200 }))),
                h('section.group', h('h3', 'ריווח והזחה ', h('small', 'בנקודות')),
                    h('div.fields.two',
                        field('לפני הפסקה', numInput('before', a.spaceBefore, { min: 0, max: 200 })),
                        field('אחרי הפסקה', numInput('after', a.spaceAfter, { min: 0, max: 200 })),
                        field('הזחה (צעדים של 1.27 ס"מ)', numInput('indent', a.indent || 0, { min: 0, max: 8, step: 1 })),
                        field('שורה ראשונה', h('select', { name: 'flk' }, Object.entries({ '': 'ללא', first: 'הזחה', hang: 'תלויה' })
                            .map(([k, t]) => h('option', { value: k, selected: k === (a.firstLine > 0 ? 'first' : a.firstLine < 0 ? 'hang' : '') }, t)))),
                        field('בכמה (נקודות)', numInput('flv', a.firstLine ? Math.abs(a.firstLine) : 36, { min: 0, max: 200 })))),
                // כמו "שורות ומעברי עמוד" ב-Word. כותרת — ממילא נשמרת עם הבא ובשלמותה
                h('section.group', h('h3', 'שבירת עמודים'),
                    sw('keepNext', 'שמירה עם הבא — לא נשארת לבד בתחתית העמוד', a.keepNext),
                    sw('keepLines', 'שמירת שורות יחד — לא נחצית בין עמודים', a.keepLines),
                    sw('breakBefore', 'מעבר עמוד לפני — מתחילה עמוד חדש', a.breakBefore)),
                h('section.group', h('h3', 'עצירות טאב ', h('small', 'ס"מ מהשוליים')), tabList,
                    h('button.btn', { type: 'button', onclick: () => tabRow().querySelector('input').focus() }, '+ עצירה'),
                    h('p.hint', 'מוביל — נקודות / מקפים / קו עד העצירה, כמו בתוכן עניינים. אחרי העצירה האחרונה — כל 1.27 ס"מ.')));
        }, form => {
            const f = new FormData(form);
            const line = f.get('line');
            const ex = parseFloat(f.get('exact'));
            const n = k => f.get(k) === '' ? null : Math.max(0, +f.get(k));
            chain().setBlockAttrs({
                dir: f.get('dir') === 'ltr' ? 'ltr' : null,
                lineHeight: line === 'exact' ? (ex > 0 ? 'exact:' + ex : null) : (line || null),
                spaceBefore: n('before'), spaceAfter: n('after'), indent: Math.min(8, Math.round(n('indent') || 0)),
                firstLine: f.get('flk') && n('flv') ? Math.min(200, n('flv')) * (f.get('flk') === 'hang' ? -1 : 1) : null,
                keepNext: f.get('keepNext') ? true : null, keepLines: f.get('keepLines') ? true : null, breakBefore: f.get('breakBefore') ? true : null,
                tabs: (t => t.length ? t : null)(tabsOf(f.getAll('tab_pos').map((p, i) => ({ pos: parseFloat(String(p).replace(',', '.')) * CM,
                    align: f.getAll('tab_align')[i], leader: f.getAll('tab_leader')[i] })))),
            }).run();
        });
    }

    /**
     * קישור (Ctrl+K): טקסט לתצוגה + כתובת (אתר / דוא"ל) או סימניה במסמך. בתוך קישור קיים — עריכה של כולו, ו"הסרת
     * הקישור". הטקסט שהשתנה מחליף את הבחירה עם העיצוב שבתחילתה.
     */
    function linkDialog() {
        const cur = editor.getAttributes('link').href || '';
        if (cur) chain().extendMarkRange('link').run();
        const { from, to, empty } = editor.state.selection;
        const text = empty ? '' : editor.state.doc.textBetween(from, to, ' ', '\ufffc');
        const plain = !text.includes('\ufffc');   // בבחירה תמונה / הערה — בלי שדה טקסט, רק כתובת
        const bms = bookmarks(editor.state.doc).filter(b => !b.name.startsWith('_'));
        sheet(cur ? 'עריכת קישור' : 'הוספת קישור', (body, dlg) => {
            body.append(
                plain ? field('טקסט לתצוגה', h('input', { name: 'text', value: text, maxlength: 500, autocomplete: 'off' })) : null,
                field('כתובת — אתר או דוא"ל', h('input', { name: 'href', type: 'text', dir: 'ltr', value: cur.startsWith('#') ? '' : cur.replace(/^mailto:/, ''),
                    placeholder: 'https://…', inputmode: 'url', autocomplete: 'off', spellcheck: 'false' })),
                bms.length ? field('או מקום במסמך (סימניה)', h('select', { name: 'bm' }, h('option', { value: '' }, '—'),
                    ...bms.map(b => h('option', { value: b.name, selected: cur === '#' + b.name }, b.name)))) : null,
                cur ? h('button.btn.ghost', { type: 'button', onclick: () => { chain().unsetLink().run(); dlg.close(); } }, 'הסרת הקישור') : null);
            setTimeout(() => body.querySelector(plain && !text ? '[name=text]' : '[name=href]')?.focus());
        }, form => {
            const f = new FormData(form);
            const href = safeHref(f.get('bm') ? '#' + f.get('bm') : f.get('href'));
            if (!href) throw new Error('כתובת לא תקינה — אתר (https://…), דוא"ל, או סימניה מהרשימה');
            const t = plain ? String(f.get('text') || '').trim() : '';
            if (plain && (empty || t !== text)) {
                const marks = editor.state.doc.resolve(from).marks().filter(m => m.type.name !== 'link').map(m => m.toJSON());
                const shown = t || href.replace(/^mailto:/, '').replace(/^#/, '');
                chain().insertContentAt({ from, to }, { type: 'text', text: shown, marks: [...marks, { type: 'link', attrs: { href } }] })
                    .setTextSelection(from + shown.length).unsetMark('link').run();
            } else chain().setTextSelection({ from, to }).setMark('link', { href }).run();
        }, 'אישור');
    }

    /** ספירת מילים: במסמך, ובבחירה (אם יש) — כמו החלון של Word. הערות השוליים לא נספרות. */
    function wordCountDialog() {
        const all = wordStats(editor.state.doc);
        const { from, to, empty } = editor.state.selection;
        const sel = empty ? null : wordStats(editor.state.doc, from, to);
        const n = v => v.toLocaleString('he-IL');
        const rows = [['עמודים', pages(), null], ['מילים', all.words, sel?.words], ['תווים (בלי רווחים)', all.charsNoSpaces, sel?.charsNoSpaces],
            ['תווים (כולל רווחים)', all.chars, sel?.chars], ['פסקאות', all.paragraphs, sel?.paragraphs]];
        sheet('ספירת מילים', body => {
            body.append(h('table.wc-table', h('thead', h('tr', h('th', ''), h('th', 'במסמך'), sel ? h('th', 'בבחירה') : null)),
                h('tbody', rows.map(([k, a, b]) => h('tr', h('th', { scope: 'row' }, k), h('td', n(a)), sel ? h('td', b == null ? '' : n(b)) : null)))),
                h('p.hint', 'הטקסט שבהערות השוליים לא נספר.'));
        }, null);
    }

    /** קביעת ערך המספור: הרשימה שבה הסמן מתחילה במספר הזה (ולא ממשיכה את הקודמת). */
    function numberingDialog() {
        const cur = editor.getAttributes('orderedList').start ?? 1;
        sheet('קביעת ערך המספור', body => {
            body.append(field('להתחיל ב-', numInput('start', cur, { min: 0, max: 9999, step: 1 })));
        }, form => {
            const n = parseInt(new FormData(form).get('start'), 10);
            if (!(n >= 0 && n <= 9999)) throw new Error('מספר בין 0 ל-9999');
            chain().setListNumbering({ start: n, cont: false }).run();
        }, 'אישור');
    }

    /** סימניות: הוספה במקום הסמן (שם קיים — עובר לכאן), ורשימה עם מעבר ומחיקה. */
    /**
     * הוספת כיתוב (כמו ב-Word): פסקה בסגנון "כיתוב" — התווית, המספר (seq) והטקסט — מתחת לפריט שבו הסמן או מעליו
     * (בטבלה — מעליה כברירת מחדל, כמו ב-Word העברי).
     */
    function captionDialog() {
        const sel = editor.state.selection;
        const inTable = editor.isActive('table') || sel.node?.type.name === 'table';
        const onImage = sel.node?.type.name === 'image' || editor.isActive('image');
        sheet('הוספת כיתוב', body => {
            body.append(
                seg('label', Object.fromEntries(SEQ_LABELS.map(l => [l, l])), inTable ? 'טבלה' : SEQ_LABELS[0], 'תווית'),
                field('טקסט הכיתוב (אחרי המספר)', h('input', { name: 'text', maxlength: 200, autocomplete: 'off', placeholder: onImage || !inTable ? 'למשל: מבנה המערכת' : '' })),
                seg('pos', { below: 'מתחת לפריט', above: 'מעל הפריט' }, inTable ? 'above' : 'below', 'מיקום'));
        }, form => {
            const f = new FormData(form);
            const label = SEQ_LABELS.includes(f.get('label')) ? f.get('label') : SEQ_LABELS[0];
            const text = String(f.get('text') || '').trim();
            const { state } = editor;
            const $f = state.selection.$from;
            // הבלוק העליון שבו הסמן (או הבלוק העליון שנבחר כולו — טבלה, תוכן עניינים)
            const [from, to] = $f.depth ? [$f.before(1), $f.after(1)] : [state.selection.from, state.selection.to];
            const at = f.get('pos') === 'above' ? from : to;
            const content = [{ type: 'text', text: label + ' ' }, { type: 'seq', attrs: { label, id: newSeqId() } }, ...(text ? [{ type: 'text', text: ': ' + text }] : [])];
            chain().insertContentAt(at, { type: 'paragraph', attrs: { pstyle: 'caption' }, content })
                .command(({ tr }) => { tr.setSelection(TextSelection.near(tr.doc.resolve(tr.doc.resolve(at + 1).end()))); return true; }).run();
        }, 'הוספה');
    }

    /** הפניה צולבת לכיתוב: "איור 2" או "2" — מתעדכנת לבד כשהמספרים משתנים. */
    function crossRefDialog() {
        const list = captions(editor.state.doc);
        sheet('הפניה צולבת', body => {
            if (!list.length) { body.append(h('p.none', 'אין עדיין כיתובים במסמך — הוספה ← כיתובים ← הוספת כיתוב')); return; }
            body.append(
                field('הפניה אל', h('select', { name: 'target' }, ...list.map(c => h('option', { value: c.id }, (c.text || `${c.label} ${c.n}`).slice(0, 80))))),
                seg('show', { full: 'תווית ומספר', num: 'מספר בלבד' }, 'full', 'להציג'));
        }, form => {
            if (!list.length) return;
            const f = new FormData(form);
            chain().insertContent({ type: 'xref', attrs: { target: String(f.get('target')), show: f.get('show') === 'num' ? 'num' : 'full' } }).run();
        }, 'הוספה');
    }

    function bookmarkDialog() {
        sheet('סימניה', (body, dlg) => {
            const ul = h('ul.bm-list');
            const paint = () => {
                ul.textContent = '';
                for (const b of bookmarks(editor.state.doc).filter(x => !x.name.startsWith('_'))) {
                    ul.append(h('li', h('span.bm-name', b.name),
                        h('button.btn.sm', { type: 'button', onclick: () => { dlg.close(); editor.commands.goToBookmark(b.name); editor.view.focus(); } }, 'מעבר'),
                        h('button.btn.sm.ghost', { type: 'button', 'aria-label': 'מחיקת ' + b.name, onclick: () => { editor.commands.deleteBookmark(b.name); paint(); } }, 'מחיקה')));
                }
                if (!ul.children.length) ul.append(h('li.none', 'אין עדיין סימניות במסמך'));
            };
            paint();
            body.append(field('שם — מתחיל באות, בלי רווחים', h('input', { name: 'name', maxlength: 40, autocomplete: 'off', spellcheck: 'false' })),
                h('section.group', h('h3', 'הסימניות במסמך'), ul));
        }, form => {
            const n = String(new FormData(form).get('name') || '').trim();
            if (!BOOKMARK_RE.test(n)) throw new Error('שם לא תקין: מתחיל באות, אותיות / ספרות / _ בלבד, עד 40 תווים');
            chain().setBookmark(n).run();
        }, 'הוספה');
    }

    function tableDialog() {
        sheet('הוספת טבלה', body => {
            body.append(h('div.fields.two', field('שורות', numInput('rows', 3, { min: 1, max: 100, step: 1 })), field('עמודות', numInput('cols', 3, { min: 1, max: 20, step: 1 }))),
                sw('header', 'שורת כותרת', true));
        }, form => {
            const f = new FormData(form);
            chain().insertTable({ rows: Math.max(1, Math.min(100, +f.get('rows') || 3)), cols: Math.max(1, Math.min(20, +f.get('cols') || 3)), withHeaderRow: !!f.get('header') }).run();
        }, 'הוספה');
    }

    return { paragraphDialog, linkDialog, wordCountDialog, numberingDialog, captionDialog, crossRefDialog, bookmarkDialog, tableDialog };
}
