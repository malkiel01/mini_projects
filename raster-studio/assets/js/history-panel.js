// 🕘 היסטוריה: ארבע לשוניות בחלון אחד —
// - צעדים: כל רשומות הביטול / הביצוע; הקשה על צעד — חזרה אליו (כמה ביטולים / ביצועים ברצף).
// - תמונות מצב: המסמך כולו (‎.mbe בזיכרון) ברגע מסוים; שחזור מחליף את המסמך — ולפניו נשמרת תמונת מצב "לפני השחזור",
//   כך שאפשר לחזור. נשמרות עד שהדף נסגר.
// - פעולות: הקלטה של תיקונים ופילטרים (מה ש"✓ החלה" בלוח התיקונים עושה), ניגון על השכבה הפעילה, ועיבוד אצווה —
//   כמה קבצים, אותה פעולה, והורדה כ-ZIP. הפעולות נשמרות במכשיר (localStorage).
// - גרסאות: הגרסאות בשרת (החלון הקיים).

import { RasterDocument } from './engine/doc.js';
import { decodeImage } from './engine/webgl2.js';
import { zipStore } from './engine/zip.js';
import { ADJUSTMENTS } from './adjust-panel.js';
import { exportPNG, exportJPG, exportWEBP, fileName } from './export.js';
import { sheet } from './ui-core/dialog.js';
import { h } from './ui-core/dom.js';

const STORE = 'raster-studio:actions';
const loadActions = () => { try { return JSON.parse(localStorage.getItem(STORE)) || []; } catch { return []; } };
const saveActions = list => { try { localStorage.setItem(STORE, JSON.stringify(list)); } catch { /* מצב פרטי — רק לסשן */ } };

/** שם צעד בפעולה */
export const stepLabel = s => (s.type === 'adjust' ? ADJUSTMENTS[s.v.kind]?.label ?? s.v.kind : s.type);

/**
 * תיקון / פילטר על השכבה הפעילה של d, כמו "✓ החלה". history — לרשומה (או null — בלי, וה-before משתחרר).
 * מחזיר אם משהו השתנה.
 */
export function runAdjust(renderer, d, v, history) {
  const layer = d.active;
  if (!layer || layer.type === 'group' || layer.foreign || layer.adjust || layer.fill) throw new Error('בחרו שכבת פיקסלים');
  if (layer.locked) throw new Error('השכבה נעולה');
  if (renderer.constructor.adjustNoop?.(v)) return false;
  renderer.setAdjust(d, layer, v);
  const before = renderer.commitAdjust(d);
  if (!before.size) return false;
  if (history) history.pixels(d, layer, before, ADJUSTMENTS[v.kind]?.label ?? 'תיקון');
  else renderer.freeTextures(before.values());
  return true;
}

function freeDoc(renderer, d) {
  for (const l of d.rasters()) renderer.freeLayer(l);
  if (d._comp) renderer.freeTextures(d._comp.values());
}

export function createHistoryPanel({ doc, setDoc, renderer, history, toast, onChange, buildSnapshot, openSnapshot, showVersions, beforeOpen }) {
  const snaps = [];          // { name, at, bytes }
  let actions = loadActions();   // [{ name, steps: [{ type: 'adjust', v }] }]
  let recording = null;      // { name, steps } בזמן הקלטה

  /** נקרא מלוח התיקונים אחרי "✓ החלה" — בזמן הקלטה הצעד נכנס לפעולה */
  function record(step) {
    if (!recording) return;
    recording.steps.push(structuredClone(step));
    toast(`🔴 הוקלט: ${stepLabel(step)} (${recording.steps.length})`);
  }

  const time = new Intl.DateTimeFormat('he-IL', { timeStyle: 'short' });

  function show(tab = 'steps') {
    const d = doc();
    if (!d) return;
    beforeOpen?.();
    let s = null;
    const body = h('div.hist-body');
    const tabs = [['steps', '📜 צעדים'], ['snaps', '📸 תמונות מצב'], ['actions', '🎬 פעולות'], d.serverVersion ? ['versions', '🕘 גרסאות'] : null].filter(Boolean);
    const bar = h('div.seg-tabs', { role: 'tablist', 'aria-label': 'היסטוריה' }, ...tabs.map(([k, label]) => h('button', {
      type: 'button', role: 'tab', 'data-hist-tab': k, onclick: () => (k === 'versions' ? (s.close(), showVersions()) : render(k)),
    }, label)));

    function render(k) {
      tab = k;
      for (const b of bar.children) b.setAttribute('aria-selected', String(b.dataset.histTab === k));
      body.replaceChildren(...({ steps, snaps: snapsTab, actions: actionsTab })[k]());
    }

    // ---- צעדים ----
    function steps() {
      const undo = history.undoStack, redo = history.redoStack;
      if (!undo.length && !redo.length) return [h('p.hint', 'עוד אין צעדים — כל פעולה במסמך נרשמת כאן.')];
      const goTo = n => {   // n — כמה צעדים בביטול (אחרי המעבר)
        while (history.undoStack.length > n) history.undo();
        while (history.undoStack.length < n && history.canRedo) history.redo();
        onChange('history');
        render('steps');
      };
      const row = (label, n, state) => h('li', h('button.hist-step', {
        type: 'button', 'data-step': n, 'aria-current': state === 'now' ? 'step' : null, class: state === 'redo' ? 'hist-step undone' : 'hist-step',
        onclick: () => goTo(n),
      }, label));
      return [h('p.hint', 'הקשה על צעד — חזרה אליו (הצעדים שאחריו — לביצוע מחדש, עד הפעולה הבאה).'), h('ol.hist-steps',
        row('פתיחה', 0, undo.length ? 'done' : 'now'),
        ...undo.map((e, i) => row(e.label, i + 1, i === undo.length - 1 ? 'now' : 'done')),
        ...[...redo].reverse().map((e, i) => row(e.label, undo.length + i + 1, 'redo')))];
    }

    // ---- תמונות מצב ----
    function snapsTab() {
      const take = async () => {
        const n = snaps.length + 1;
        snaps.push({ name: `תמונת מצב ${n}`, at: new Date(), bytes: await buildSnapshot() });
        toast(`📸 נשמרה תמונת מצב ${n}`);
        render('snaps');
      };
      const restore = async i => {
        const sn = snaps[i];
        snaps.push({ name: `לפני השחזור של "${sn.name}"`, at: new Date(), bytes: await buildSnapshot() });
        const old = doc();
        setDoc(await openSnapshot(sn.bytes, old));
        freeDoc(renderer, old);
        onChange('snapshot');
        toast(`שוחזר: ${sn.name} (המצב הקודם — ב"לפני השחזור")`);
        s.close();
      };
      return [
        h('p.hint', 'כל המסמך ברגע אחד. שחזור מחליף את המסמך ומתחיל היסטוריה חדשה — ולפניו נשמרת תמונת מצב של מה שהיה. נשמרות עד שסוגרים את הדף.'),
        h('div.hist-row', h('button.btn.primary', { type: 'button', 'data-snap': 'take', onclick: take }, '📸 תמונת מצב עכשיו')),
        snaps.length ? h('ul.versions', ...snaps.map((sn, i) => h('li',
          h('span.v-tx', h('b', sn.name), h('small', `${time.format(sn.at)} · ${(sn.bytes.length / 1024 / 1024).toFixed(1)}MB`)),
          h('button.btn', { type: 'button', 'data-snap-restore': i, onclick: () => restore(i) }, 'שחזור'),
          h('button.icon-btn', { type: 'button', 'aria-label': 'מחיקה', onclick: () => { snaps.splice(i, 1); render('snaps'); } }, '🗑️'))))
          : h('p.hint', 'עוד אין תמונות מצב.'),
      ];
    }

    // ---- פעולות ----
    function actionsTab() {
      const out = [h('p.hint', 'הקלטה: "⏺ הקלטה", ואז תיקונים ופילטרים בלוח 🎚️ ("✓ החלה" — צעד). ניגון — על השכבה הפעילה, צעד ביטול אחד. אצווה — אותה פעולה על כמה קבצים, והורדה כ-ZIP.')];
      if (recording) {
        out.push(h('div.hist-row',
          h('span.hint', `🔴 מקליט "${recording.name}" — ${recording.steps.length} צעדים`),
          h('button.btn.primary', { type: 'button', 'data-rec': 'stop', onclick: () => {
            if (recording.steps.length) { actions.push(recording); saveActions(actions); toast(`נשמרה הפעולה "${recording.name}"`); }
            recording = null;
            render('actions');
          } }, '⏹ סיום'),
          h('button.btn.ghost', { type: 'button', onclick: () => { recording = null; render('actions'); } }, '✕ ביטול')));
      } else {
        const name = h('input', { name: 'action-name', placeholder: 'שם לפעולה', value: `פעולה ${actions.length + 1}`, dir: 'auto' });
        out.push(h('div.hist-row', name, h('button.btn.primary', { type: 'button', 'data-rec': 'start', onclick: () => {
          recording = { name: name.value.trim() || `פעולה ${actions.length + 1}`, steps: [] };
          s.close();
          toast('🔴 מקליט — תיקונים ופילטרים בלוח 🎚️; 🕘 ← פעולות ← ⏹ לסיום');
        } }, '⏺ הקלטה')));
      }
      out.push(actions.length ? h('ul.versions', ...actions.map((a, i) => h('li',
        h('span.v-tx', h('b', a.name), h('small', a.steps.map(stepLabel).join(' ← '))),
        h('button.btn', { type: 'button', 'data-play': i, onclick: () => { s.close(); play(a); } }, '▶ ניגון'),
        h('button.btn', { type: 'button', 'data-batch': i, onclick: () => { s.close(); batch(a); } }, '🗂️ אצווה'),
        h('button.icon-btn', { type: 'button', 'aria-label': 'מחיקה', onclick: () => { actions.splice(i, 1); saveActions(actions); render('actions'); } }, '🗑️'))))
        : h('p.hint', 'עוד אין פעולות שמורות.'));
      return out;
    }

    // לחיצה בתוך החלון מחליפה את התוכן (לשונית, צעד) — והחלון משנה גודל באמצע הלחיצה. בלי עצירה, הבדיקה של
    // ui-core "לחיצה מחוץ לחלון סוגרת" רואה את הנקודה מחוץ למלבן החדש וסוגרת אותו.
    const inner = h('div.hist-wrap', { onclick: e => e.stopPropagation() }, bar, body);
    s = sheet({ title: '🕘 היסטוריה', wide: true, body: b => b.append(inner) });
    render(tab);
    return s;
  }

  /** ניגון על המסמך הפתוח: צעד ביטול אחד לכל הפעולה */
  function play(a) {
    const d = doc();
    try {
      history.group(a.name, () => { for (const st of a.steps) runAdjust(renderer, d, st.v, history); });
      renderer.invalidate(d);
      onChange('action');
      toast(`▶ "${a.name}" — ${a.steps.length} צעדים`);
    } catch (e) {
      renderer.setAdjust(d, null, null);
      toast(e.message, true);
    }
  }

  /** אצווה: קבצים מהמכשיר → כל אחד: מסמך זמני, הפעולה, הורדה כ-PNG / JPG / WebP → ZIP אחד */
  function batch(a) {
    const files = h('input', { type: 'file', multiple: true, accept: 'image/png,image/jpeg,image/webp,image/avif' });
    const fmt = h('select', { name: 'batch-format' }, h('option', { value: 'png' }, 'PNG'), h('option', { value: 'jpg' }, 'JPG'), h('option', { value: 'webp' }, 'WebP'));
    const status = h('p.hint', { role: 'status' });
    const bs = sheet({
      title: `🗂️ אצווה: ${a.name}`, submit: '▶ הרצה', label: 'אצווה',
      body: [h('label.field', h('span', 'קבצים'), files), h('label.field', h('span', 'פורמט'), fmt), status],
      onSubmit: async () => {
        const list = [...files.files];
        if (!list.length) throw new Error('בחרו קבצים');
        const out = [];
        for (const [i, f] of list.entries()) {
          status.textContent = `${i + 1} / ${list.length} — ${f.name}`;
          out.push(await runBatchFile(a, f, fmt.value));
        }
        const zip = zipStore(out.map(o => ({ name: o.name, data: o.data })));
        const { download } = await import('./export.js');
        download(new Blob([zip], { type: 'application/zip' }), fileName(a.name, 'zip'));
        toast(`🗂️ ${list.length} קבצים — ירד ZIP`);
      },
    });
    return bs;
  }

  async function runBatchFile(a, file, kind) {
    const bmp = await decodeImage(file);
    const d = new RasterDocument(bmp.width, bmp.height, file.name.replace(/\.[a-z0-9]+$/i, ''));
    try {
      await renderer.drawImage(d, d.addLayer({ name: d.title }), bmp, 0, 0);
      bmp.close();
      for (const st of a.steps) runAdjust(renderer, d, st.v, null);
      renderer.invalidate(d);
      const blob = kind === 'jpg' ? await exportJPG(renderer, d) : kind === 'webp' ? await exportWEBP(renderer, d) : await exportPNG(renderer, d);
      return { name: fileName(d.title, kind), data: new Uint8Array(await blob.arrayBuffer()) };
    } finally {
      freeDoc(renderer, d);
    }
  }

  return {
    show, record,
    get recording() { return !!recording; },
    /** לבדיקות */
    _actions: () => actions, _snaps: () => snaps, runBatchFile,
  };
}
