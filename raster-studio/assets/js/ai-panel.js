// ✨ בינה מלאכותית בעורך — מעל layer-ai.js (ושם — שכבת הספקים של ענן החבילה). כל פעולה — צעד ביטול אחד:
// - ✂️ הסרת רקע: מסכת שכבה מהתוצאה (לא הורס — אפשר לתקן במכחול על המסכה).
// - 🧩 פירוק לשכבות: קבוצה חדשה — כל עצם שכבה, והרקע מושלם (אם הספק יודע).
// - 🪄 מילוי בבחירה: שכבה חדשה מעל, עם מסכה מהבחירה.
// - 🖼️ יצירת תמונה: שכבה חדשה בגודל הקנבס (גם שקופה).
// בלי חיבור / בלי ספק — הסבר, חיבור לענן, ו"אפשר דרך הצ'אט (סקיל)".

import { newMask, TILE } from './engine/doc.js';
import { decodeImage } from './engine/webgl2.js';
import { encodePNG } from './engine/png.js';
import { rasterTile } from './engine/selection.js';
import { layerPNG } from './export.js';
import { createLayerAI } from './layer-ai.js';
import { sheet } from './ui-core/dialog.js';
import { h } from './ui-core/dom.js';

const ERR = {
  'no-provider': 'אין ספק בינה מלאכותית זמין — בעל המערכת מגדיר אותו בניהול הענן',
  'not-allowed': 'אין לך הרשאה לפעולה הזו — בעל המערכת נותן אותה בניהול הענן',
  'not-connected': 'לא מחובר לענן החבילה',
  quota: 'נגמרה המכסה החודשית',
  'too-big': 'התמונה גדולה מדי לספק',
  aborted: 'בוטל',
};

export function createAIPanel({ doc, renderer, history, cloud, toast, onChange, beforeOpen }) {
  let ai = createLayerAI({ cloud });
  let busy = null;   // AbortController של הפעולה שרצה

  const png = bytes => new Blob([bytes], { type: 'image/png' });
  /** מה שרואים (כל השכבות) כ-PNG */
  const composite = async d => png(await encodePNG(await renderer.readPixels(d, 0, 0, d.width, d.height), d.width, d.height));
  /** הבחירה כמסכת גוונים בגודל הקנבס (לבן — נבחר) */
  async function selectionPNG(d) {
    const W = d.width, H = d.height, out = new Uint8ClampedArray(W * H * 4);
    for (let i = 3; i < out.length; i += 4) out[i] = 255;
    for (let ty = 0; ty * TILE < H; ty++) for (let tx = 0; tx * TILE < W; tx++) {
      const t = rasterTile(d.selection, tx, ty);
      if (!t) continue;
      for (let y = 0; y < TILE && ty * TILE + y < H; y++) for (let x = 0; x < TILE && tx * TILE + x < W; x++) {
        const v = t[y * TILE + x], o = ((ty * TILE + y) * W + tx * TILE + x) * 4;
        out[o] = out[o + 1] = out[o + 2] = v;
      }
    }
    return png(await encodePNG(out, W, H));
  }
  /** PNG → שכבה חדשה (או מסכה) */
  async function drawInto(d, target, blob) {
    const bmp = await decodeImage(blob, [d.width, d.height]);
    await renderer.drawImage(d, target, bmp, 0, 0);
    bmp.close();
  }

  const pixelLayer = l => l && l.type !== 'group' && !l.foreign && !l.adjust && !l.fill;

  const ACTIONS = {
    async removeBackground(d, signal) {
      const l = d.active;
      if (!pixelLayer(l)) throw new Error('בחרו שכבת פיקסלים');
      if (l.locked) throw new Error('השכבה נעולה');
      if (l.mask) throw new Error('לשכבה כבר יש מסכה — מחקו אותה קודם (🎭)');
      const r = await ai.removeBackground(png(await layerPNG(renderer, d, l)), { signal });
      const m = newMask(l);
      await drawInto(d, m, r.mask);
      l.mask = m;
      history.mask(d, l, null, m, 'הסרת רקע');
      return 'הרקע הוסר — כמסכה על השכבה (אפשר לתקן במכחול על המסכה)';
    },
    async decompose(d, signal) {
      const r = await ai.decompose(await composite(d), { maxLayers: 8, signal });
      if (!r.layers.length) throw new Error('לא נמצאו עצמים בתמונה');
      const g = d.addGroup({ name: 'פירוק לשכבות' });
      const bg = d.addLayer({ name: 'רקע', parent: g });
      await drawInto(d, bg, r.background);
      for (const L of r.layers) await drawInto(d, d.addLayer({ name: L.label, parent: g }), L.png);
      d.activeId = g.id;
      history.addLayer(d, g, 'פירוק לשכבות');
      return `${r.layers.length} שכבות בקבוצה "פירוק לשכבות"`;
    },
    async fill(d, signal, prompt) {
      if (!d.selection) throw new Error('קודם בוחרים אזור');
      const r = await ai.fill(await composite(d), await selectionPNG(d), { prompt, signal });
      const l = d.addLayer({ name: prompt ? `מילוי: ${prompt.slice(0, 30)}` : 'מילוי AI', index: d.indexOf(d.active) + 1 });
      await drawInto(d, l, r.png);
      l.mask = newMask(l);
      renderer.fill(d, l.mask, [0, 0, 0]);
      renderer.freeTextures(renderer.fillSelection(d, l.mask, 'paint', [255, 255, 255], 1).values());
      history.addLayer(d, l, 'מילוי AI');
      return 'נוסף כשכבה חדשה, בגבולות הבחירה';
    },
    async generate(d, signal, prompt, transparent) {
      if (!prompt) throw new Error('כתבו מה ליצור');
      const r = await ai.generate(prompt, { width: d.width, height: d.height, transparent, signal });
      const l = d.addLayer({ name: prompt.slice(0, 40), index: d.indexOf(d.active) + 1 });
      await drawInto(d, l, r.png);
      history.addLayer(d, l, 'יצירת תמונה');
      return 'נוספה שכבה חדשה';
    },
  };

  const ROWS = [
    ['removeBackground', '✂️ הסרת רקע', 'מהשכבה הפעילה — כמסכה, אפשר לתקן אחר כך'],
    ['decompose', '🧩 פירוק לשכבות', 'כל עצם בתמונה — שכבה משלו, והרקע מושלם'],
    ['fill', '🪄 מילוי בבחירה', 'מה שבבחירה מתמלא לפי התיאור (או לפי הסביבה)'],
    ['generate', '🖼️ יצירת תמונה', 'שכבה חדשה מהתיאור'],
  ];

  async function show() {
    const d = doc();
    if (!d) return;
    beforeOpen?.();
    const [caps, st] = await Promise.all([ai.capabilities(), cloud?.status?.().catch(() => null) ?? null]);
    const any = ROWS.some(([k]) => caps[k]);
    const s = sheet({
      title: '✨ בינה מלאכותית',
      body: body => {
        if (!any) {
          const connected = st?.suite?.connected;
          body.append(h('p.hint', connected ? 'אין ספק זמין לך עכשיו — בעל המערכת מגדיר ספק והרשאה בניהול הענן.'
            : 'הפעולות האלה רצות דרך ענן החבילה. אפשר להתחבר אליו, או לעשות אותן דרך הצ\'אט (סקיל).'));
          if (!connected && cloud?.connect) body.append(h('button.btn.primary', { type: 'button', onclick: () => cloud.connect() }, '☁️ חיבור לענן החבילה'));
        }
        const promptEl = h('textarea', { name: 'prompt', rows: 2, placeholder: 'תיאור (למילוי וליצירה) — למשל: שמיים כחולים עם עננים', dir: 'auto' });
        const transEl = h('input', { type: 'checkbox', name: 'transparent' });
        const status = h('p.hint', { role: 'status', hidden: true });
        const stop = h('button.btn.ghost', { type: 'button', hidden: true, onclick: () => busy?.abort() }, '✕ עצירה');
        const btns = ROWS.map(([k, label, hint]) => h('button.btn.ai-act', {
          type: 'button', disabled: !caps[k], 'data-ai': k,
          title: caps[k] ? hint : 'לא זמין — דרך הצ\'אט (סקיל)',
          onclick: () => go(k),
        }, label, h('small', caps[k] ? hint : 'לא זמין — דרך הצ\'אט (סקיל)')));
        body.append(
          h('label.field', h('span', 'תיאור'), promptEl),
          h('label.opt-check', transEl, h('span', 'יצירה עם רקע שקוף')),
          h('div.ai-acts', ...btns),
          h('div.row-btns', status, stop));

        async function go(k) {
          if (busy) return;
          busy = new AbortController();
          for (const b of btns) b.disabled = true;
          status.hidden = false; stop.hidden = false;
          status.textContent = 'עובד… (זה יכול לקחת כמה שניות)';
          try {
            const msg = await ACTIONS[k](doc(), busy.signal, promptEl.value.trim(), transEl.checked);
            renderer.invalidate(doc());
            onChange('ai');
            s.close();
            toast(msg);
          } catch (e) {
            status.textContent = ERR[e.code] ?? e.message ?? 'תקלה';
            for (const b of btns) b.disabled = !caps[b.dataset.ai];
          } finally {
            busy = null;
            stop.hidden = true;
          }
        }
      },
      onClose: () => busy?.abort(),
    });
    return s;
  }

  return {
    show,
    /** לבדיקות: ענן מדומה */
    setCloud(c) { cloud = c; ai = createLayerAI({ cloud: c }); },
  };
}

