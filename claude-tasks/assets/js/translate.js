/*
 * 🌐 תרגום במכשיר — docs/spec.md §5.11, שלב 12e. המקור — אנגלית; היעד — שפת התרגום של המשתמש
 * (users.translate_lang): עברית או ספרדית. כל שפה — מודל משלה, שמורידים בנפרד.
 *
 * מנוע: (1) Translator API של Chrome כשיש (מחשב, Chrome 138+); (2) אחרת Opus-MT במכשיר דרך transformers.js
 * ב-Web Worker — רק אחרי שבעל המכשיר הוריד אותו (הגדרות ← חשבון). אין מנוע — 🌐 כבוי, עם הסיבה.
 * הטקסט לא יוצא מהמכשיר, וההודעה השמורה לא משתנה. התרגום נשמר ב-IndexedDB של המכשיר (מזהה + שפה),
 * יחד עם הבחירה "תרגום / מקור" לכל הודעה.
 * הדרגתי: כל משפט מוצג ברגע שהוא מוכן (§5.11.1) — onProgress(markdown, done, total).
 */
import { segment, assemble, needsTranslation } from './translate-text.js';

export { needsTranslation };

// שפה חדשה = שורה כאן (+ השפה ב-api.php translate-lang). כל מודל מוצמד ל-commit, עם הגודל המדויק
// של כל קובץ — המכנה של פס ההתקדמות קבוע, גם כשהשרת לא שולח גודל.
const ENC = 'onnx/encoder_model_quantized.onnx', DEC = 'onnx/decoder_model_merged_quantized.onnx';
export const TRANSLATE_LANGS = {
  // עברית: ההמרה הקהילתית (§5.11.1) — עד שההמרה שלנו מ-Helsinki-NLP תוגש מהשרת
  he: { name: 'עברית', heName: 'עברית', label: 'אנגלית ← עברית', from: 'אנגלית', id: 'sivelsguy/opus-mt-en-he-onnx',
        revision: 'fc3befc3af12cd004ae65e9c36ea77858c19157c', sizes: { [ENC]: 53150455, [DEC]: 94015921 } },
  // ספרדית: ההמרה של Xenova למודל הרשמי Helsinki-NLP/opus-mt-en-es
  es: { name: 'Español', heName: 'ספרדית', label: 'אנגלית ← ספרדית', from: 'אנגלית', id: 'Xenova/opus-mt-en-es',
        revision: '4b002a4c7edd54a7ced58877258b87f7efd3f892', sizes: { [ENC]: 52899742, [DEC]: 60212804 } },
};
export const TR_CREDIT = 'Opus-MT · University of Helsinki · Apache-2.0';
const CACHE = 'transformers-cache';

// השפה הנוכחית — של המשתמש (state.user.translate_lang); app.js קובע אותה בכניסה ובשינוי
let LANG = 'he';
export function setLang(lang) {
  if (TRANSLATE_LANGS[lang] && lang !== LANG) { LANG = lang; engine(true); }
}
export const lang = () => LANG;
export const model = (l = LANG) => {
  const m = TRANSLATE_LANGS[l];
  return { ...m, lang: l, files: Object.keys(m.sizes), approxMB: Math.round(Object.values(m.sizes).reduce((a, b) => a + b, 0) / 1e6) };
};
/** כנראה צריך תרגום לשפה הנוכחית. */
export const needs = (md) => needsTranslation(md, LANG);

/* ── איזה מנוע יש ───────────────────────────────────────────── */

let enginePromise = null;
/** ‏{ kind: 'api' | 'model' | null, reason } — נבדק פעם אחת; refresh אחרי הורדה/הסרה. */
export function engine(refresh = false) {
  if (refresh) enginePromise = null;
  enginePromise ??= (async () => {
    if ('Translator' in self) {
      try {
        const a = await Promise.race([
          Translator.availability({ sourceLanguage: 'en', targetLanguage: LANG }),
          new Promise((r) => setTimeout(() => r('timeout'), 4000)),
        ]);
        if (['available', 'downloadable', 'downloading'].includes(a)) return { kind: 'api', reason: 'התרגום המובנה של Chrome' };
      } catch { /* ממשיכים למודל */ }
    }
    if (await modelCached()) return { kind: 'model', reason: 'מודל במכשיר' };
    return { kind: null, reason: `אין מודל ל${TRANSLATE_LANGS[LANG].heName} במכשיר — הגדרות ← חשבון ← 🌐 תרגום במכשיר` };
  })();
  return enginePromise;
}

const fileUrl = (m, f) => `https://huggingface.co/${m.id}/resolve/${m.revision}/${f}`;
export async function modelCached(l = LANG) {
  const m = model(l);
  try {
    const c = await caches.open(CACHE);
    for (const f of m.files) if (!(await c.match(fileUrl(m, f)))) return false;
    return true;
  } catch { return false; }
}

/* ── ה-Worker ───────────────────────────────────────────────── */

let worker = null;
const jobs = new Map();   // job → { onSentence, resolve, reject }
let loadWaiters = [];
let jobSeq = 0;

function getWorker() {
  if (worker) return worker;
  worker = new Worker(new URL('./translate-worker.js', import.meta.url), { type: 'module' });
  // ה-Worker נפל (בטלפון — בדרך כלל זיכרון): הודעה ברורה, לא פס שנתקע
  worker.onerror = (e) => {
    const err = new Error(`תהליך התרגום נעצר${e?.message ? ` (${e.message})` : ''} — אולי חסר זיכרון בטלפון. סגרו לשוניות אחרות ונסו שוב.`);
    loadWaiters.forEach((w) => w.reject(err)); loadWaiters = [];
    jobs.forEach((j) => j.reject(err)); jobs.clear();
    worker = null;
  };
  worker.onmessage = ({ data }) => {
    if (data.type === 'progress' || data.type === 'ready') {
      loadWaiters.forEach((w) => (data.type === 'ready' ? w.resolve() : w.onProgress?.(data)));
      if (data.type === 'ready') loadWaiters = [];
      return;
    }
    const j = jobs.get(data.job);
    if (data.type === 'error') {
      if (j) { jobs.delete(data.job); j.reject(new Error(data.error)); }
      else { loadWaiters.forEach((w) => w.reject(new Error(data.error))); loadWaiters = []; }
      return;
    }
    if (!j) return;
    if (data.type === 'sentence') j.onSentence(data.i, data.text);
    if (data.type === 'done') { jobs.delete(data.job); j.resolve(); }
  };
  return worker;
}

/**
 * ⬇️ הורדת המודל (הסכמה מפורשת, §5.11.1). onProgress({ loaded, total, etaSec }) — סכום הקבצים הגדולים.
 * אחרי ההורדה המודל במטמון הדפדפן, ו-engine() יחזיר 'model'.
 */
export function downloadModel(onProgress) {
  const MODEL = model();
  const seen = {};
  const t0 = performance.now();
  const total = Object.values(MODEL.sizes).reduce((a, b) => a + b, 0);
  let shown = 0;   // הפס לא חוזר אחורה
  return new Promise((resolve, reject) => {
    loadWaiters.push({
      resolve: () => { engine(true); resolve(); },
      reject,
      onProgress: (p) => {
        if (!MODEL.files.includes(p.file)) return;
        // לכל קובץ — כמה ירד ממנו (לא יותר מהגודל הידוע); הסכום מול הגודל הקבוע של שניהם
        seen[p.file] = Math.max(seen[p.file] || 0, Math.min(p.loaded, MODEL.sizes[p.file]));
        const loaded = shown = Math.max(shown, Object.values(seen).reduce((a, x) => a + x, 0));
        const rate = loaded / Math.max(1, (performance.now() - t0) / 1000);
        // הערכת זמן רק אחרי שירד משהו — אחרת היא קופצת
        onProgress?.({ loaded, total, etaSec: loaded > 2e6 && rate > 0 ? Math.round((total - loaded) / rate) : null });
      },
    });
    getWorker().postMessage({ type: 'load', model: MODEL.id, revision: MODEL.revision });
  });
}

/** 🗑 הסרה של מודל השפה הנוכחית — הקבצים שלו בלבד, והתרגומים השמורים לשפה הזו. */
export async function removeModel() {
  const m = model();
  worker?.terminate(); worker = null; jobs.clear(); loadWaiters = [];
  try {
    const c = await caches.open(CACHE);
    for (const req of await c.keys()) if (req.url.includes(`/${m.id}/`)) await c.delete(req);
  } catch { /* אין */ }
  try {
    const keys = await idb('readonly', (s) => s.getAllKeys());
    await idb('readwrite', (s) => keys.filter((k) => String(k).endsWith(`:${m.lang}`)).forEach((k) => s.delete(k)));
  } catch { /* אין */ }
  engine(true);
}

/* ── IndexedDB: תרגומים שמורים, ובחירת התצוגה ───────────────── */

let dbp = null;
function openDb() {
  dbp ??= new Promise((resolve, reject) => {
    const r = indexedDB.open('ct-translate', 1);
    r.onupgradeneeded = () => r.result.createObjectStore('msgs');
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
  return dbp;
}
async function idb(mode, fn) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction('msgs', mode);
    const req = fn(tx.objectStore('msgs'));
    tx.oncomplete = () => resolve(req?.result);
    tx.onerror = () => reject(tx.error);
  });
}
const key = (id) => `${id}:${LANG}`;
/** ‏{ text, show } או null. */
export async function cached(id) {
  try { return (await idb('readonly', (s) => s.get(key(id)))) || null; } catch { return null; }
}
export async function setShow(id, show) {
  const rec = await cached(id);
  if (rec) try { await idb('readwrite', (s) => s.put({ ...rec, show }, key(id))); } catch { /* אין IndexedDB */ }
}

/* ── תרגום הודעה ────────────────────────────────────────────── */

/**
 * מתרגם Markdown משפט אחר משפט. onProgress(markdown, done, total) אחרי כל משפט. מחזיר את התרגום המלא.
 * stats: { firstMs, totalMs } — למדידה (§5.11.1: משפט ראשון ≤ 1 ש').
 */
export async function translate(id, md, onProgress, stats = {}) {
  const hit = await cached(id);
  if (hit?.text) { await setShow(id, true); onProgress?.(hit.text, 1, 1); return hit.text; }
  const eng = await engine();
  if (!eng.kind) throw new Error(eng.reason);
  const { parts, total } = segment(md);
  const done = [];
  const t0 = performance.now();
  const step = (i, text) => {
    done[i] = text;
    stats.firstMs ??= Math.round(performance.now() - t0);
    onProgress?.(assemble(parts, done), done.filter((x) => x !== undefined).length, total);
  };
  const list = parts.filter((p) => 'tr' in p).map((p) => p.tr);
  const MODEL = model();
  if (eng.kind === 'api') {
    const tr = await Translator.create({ sourceLanguage: 'en', targetLanguage: LANG });
    for (const [i, s] of list.entries()) step(i, await tr.translate(s));
    tr.destroy?.();
  } else if (list.length) {
    const job = ++jobSeq;
    await new Promise((resolve, reject) => {
      jobs.set(job, { onSentence: step, resolve, reject });
      getWorker().postMessage({ type: 'translate', job, model: MODEL.id, revision: MODEL.revision, sentences: list });
    });
  }
  stats.totalMs = Math.round(performance.now() - t0);
  const text = assemble(parts, done);
  try { await idb('readwrite', (s) => s.put({ text, show: true, at: Date.now() }, key(id))); } catch { /* בלי IndexedDB — רק לתצוגה הזו */ }
  return text;
}
