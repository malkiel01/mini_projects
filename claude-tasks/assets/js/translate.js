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

// שפה חדשה = שורה כאן (+ השפה ב-api.php translate-lang, + זוג ב-tools/models/build.json). המודלים — ההמרה שלנו
// מהמודלים הרשמיים של Helsinki-NLP (12e.7), מהשרת שלנו: models/<id>/<revision>/ (tools/models/convert.sh).
// הגודל המדויק של כל קובץ — מה-checksums.json של אותו build: המכנה של פס ההתקדמות קבוע.
const ENC = 'onnx/encoder_model_quantized.onnx', DEC = 'onnx/decoder_model_merged_quantized.onnx';
export const MODELS_BASE = new URL('../../models/', import.meta.url).href;
export const TRANSLATE_LANGS = {
  he: { name: 'עברית', heName: 'עברית', flag: '🇮🇱', label: 'אנגלית ← עברית', from: 'אנגלית', id: 'en-he',
        revision: '1', sizes: { [ENC]: 53215822, [DEC]: 94243270 } },
  es: { name: 'Español', heName: 'ספרדית', flag: '🇪🇸', label: 'אנגלית ← ספרדית', from: 'אנגלית', id: 'en-es',
        revision: '1', sizes: { [ENC]: 52786767, [DEC]: 93381843 } },
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

/* ── המודלים הקודמים (מ-Hugging Face, עד 12e.7) — מפנים פעם אחת ─────────────
 * ‏‎~260MB‎ שכבר לא בשימוש: הקבצים במטמון של transformers.js, וחתיכות הורדה שלא הסתיימה. */
const OLD_HOST = 'https://huggingface.co/';
let dropped = null;
export function dropOldModels() {
  dropped ??= (async () => {
    try {
      const c = await caches.open(CACHE);
      for (const req of await c.keys()) if (req.url.startsWith(OLD_HOST)) await c.delete(req);
    } catch { /* אין */ }
    try { await idbStore('chunks', 'readwrite', (st) => st.delete(IDBKeyRange.bound(OLD_HOST, `${OLD_HOST}\uffff`))); } catch { /* אין */ }
  })();
  return dropped;
}

let enginePromise = null;
/** ‏{ kind: 'api' | 'model' | null, reason } — נבדק פעם אחת; refresh אחרי הורדה/הסרה. */
export function engine(refresh = false) {
  if (refresh) enginePromise = null;
  enginePromise ??= (async () => {
    await dropOldModels();
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

/** איך אפשר לתרגם לשפה ‎l‎ במכשיר הזה: 'api' (Chrome) · 'model' (הורד) · null. */
export async function availableFor(l) {
  if ('Translator' in self) {
    try {
      const a = await Promise.race([Translator.availability({ sourceLanguage: 'en', targetLanguage: l }),
        new Promise((r) => setTimeout(() => r('timeout'), 4000))]);
      if (['available', 'downloadable', 'downloading'].includes(a)) return 'api';
    } catch { /* ממשיכים */ }
  }
  return (await modelCached(l)) ? 'model' : null;
}

const fileUrl = (m, f) => `${MODELS_BASE}${m.id}/${m.revision}/${f}`;   // כמו env.remotePathTemplate ב-Worker
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

/* ── ⬇️ הורדה שאפשר להמשיך (12e.5) ─────────────────────────────
 * כל קובץ גדול יורד בחתיכות של 4MB (Range), וכל חתיכה נשמרת ב-IndexedDB ברגע שהגיעה. רשת שנפלה או
 * הוחלפה (Wi‑Fi ↔ סלולר) — מנסים שוב לבד, ובלי רשת מחכים לה. יציאה וחזרה — ממשיכים מהחתיכה האחרונה.
 * כשקובץ שלם — מרכיבים אותו ושמים במטמון של transformers.js באותה כתובת, והחתיכות נמחקות. */
const CHUNK = 4 * 1024 * 1024;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const waitOnline = () => (navigator.onLine === false
  ? new Promise((r) => addEventListener('online', r, { once: true })) : Promise.resolve());
const chunkKey = (url, i) => `${url}#${i}`;

/** כמה בתים כבר במכשיר לשפה ‎l‎ — קבצים שלמים וחתיכות. ‏{ have, total, complete } */
export async function downloadedBytes(l = LANG) {
  const m = model(l);
  const total = Object.values(m.sizes).reduce((a, b) => a + b, 0);
  let have = 0, complete = true;
  let c = null; try { c = await caches.open(CACHE); } catch { /* אין */ }
  for (const f of m.files) {
    const url = fileUrl(m, f);
    if (c && await c.match(url)) { have += m.sizes[f]; continue; }
    complete = false;
    try {
      const keys = await idbStore('chunks', 'readonly', (st) => st.getAllKeys(IDBKeyRange.bound(`${url}#`, `${url}#\uffff`)));
      have += Math.min(m.sizes[f], (keys?.length || 0) * CHUNK);
    } catch { /* אין IndexedDB */ }
  }
  return { have, total, complete };
}

/** חתיכה אחת, עם ניסיונות חוזרים. ‏onBytes(n) — תוך כדי, לפס חלק. ‏onRetry(attempt, error) */
async function fetchChunk(url, start, end, onBytes, onRetry) {
  for (let attempt = 1; ; attempt++) {
    await waitOnline();
    let got = 0;
    try {
      const ctl = new AbortController();
      let idle = setTimeout(() => ctl.abort(), 30000);   // 30 ש' בלי בייט — החיבור תקוע
      const res = await fetch(url, { headers: { Range: `bytes=${start}-${end}` }, signal: ctl.signal, cache: 'no-store' });
      if (res.status !== 206) throw new Error(`HTTP ${res.status}`);
      const reader = res.body.getReader();
      const parts = [];
      for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        parts.push(value); got += value.length; onBytes(value.length);
        clearTimeout(idle); idle = setTimeout(() => ctl.abort(), 30000);
      }
      clearTimeout(idle);
      if (got !== end - start + 1) throw new Error('חתיכה חלקית');
      return new Blob(parts);
    } catch (e) {
      onBytes(-got);   // מה שהתחיל לרדת בחתיכה הזו — לא נספר
      onRetry?.(attempt, e);
      if (attempt >= 40) throw new Error('הרשת לא יציבה — מה שירד נשמר; "המשך" ימשיך מאותה נקודה');
      await sleep(Math.min(20000, 1000 * 2 ** Math.min(attempt - 1, 4)));
    }
  }
}

/** ה-checksums.json של ה-build (tools/models/convert.sh): גודל ו-sha256 לכל קובץ. */
async function fetchChecksums(m) {
  const url = `${MODELS_BASE}${m.id}/${m.revision}/checksums.json`;
  for (let attempt = 1; ; attempt++) {
    await waitOnline();
    try {
      const r = await fetch(url, { cache: 'no-store' });
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return await r.json();
    } catch (e) {
      if (attempt >= 6) throw new Error(`לא הצלחתי לקרוא את רשימת הקבצים של המודל (${e.message})`);
      await sleep(1000 * 2 ** attempt);
    }
  }
}
const sha256 = async (blob) => [...new Uint8Array(await crypto.subtle.digest('SHA-256', await blob.arrayBuffer()))]
  .map((b) => b.toString(16).padStart(2, '0')).join('');

/**
 * ⬇️ הורדת המודל של השפה ‎l‎ (הסכמה מפורשת, §5.11.1).
 * onProgress({ loaded, total, etaSec, resumedFrom, retrying }) — ‏resumedFrom: כמה כבר היה במכשיר בהתחלה.
 */
export async function downloadModel(onProgress, l = LANG) {
  const MODEL = model(l);
  const { have, total } = await downloadedBytes(l);
  let loaded = have;
  const t0 = performance.now();
  let fresh = 0;   // מה שירד בהפעלה הזו — לקצב
  const report = (extra = {}) => {
    const rate = fresh / Math.max(1, (performance.now() - t0) / 1000);
    onProgress?.({ loaded, total, resumedFrom: have, etaSec: fresh > 2e6 && rate > 0 ? Math.round((total - loaded) / rate) : null, ...extra });
  };
  report();
  // הקבצים נבדקים מול ה-checksums.json של השרת: גודל לפני ההורדה, sha256 אחרי ההרכבה (12e.7)
  const man = await fetchChecksums(MODEL);
  for (const f of MODEL.files) {
    if (man.files?.[f]?.size !== MODEL.sizes[f]) throw new Error('המודל בשרת התעדכן — רעננו את הדף ונסו שוב');
  }
  const c = await caches.open(CACHE);
  for (const f of MODEL.files) {
    const url = fileUrl(MODEL, f);
    if (await c.match(url)) continue;
    const size = MODEL.sizes[f];
    const n = Math.ceil(size / CHUNK);
    for (let i = 0; i < n; i++) {
      const key = chunkKey(url, i);
      if (await idbStore('chunks', 'readonly', (st) => st.count(key))) continue;
      const start = i * CHUNK, end = Math.min(size, start + CHUNK) - 1;
      const blob = await fetchChunk(url, start, end,
        (b) => { loaded += b; fresh += b; report(); },
        (attempt) => report({ retrying: attempt }));
      await idbStore('chunks', 'readwrite', (st) => st.put(blob, key));
    }
    // הקובץ שלם — מרכיבים, שמים במטמון בכתובת ש-transformers.js מחפש, ומפנים את החתיכות
    const blobs = [];
    for (let i = 0; i < n; i++) blobs.push(await idbStore('chunks', 'readonly', (st) => st.get(chunkKey(url, i))));
    const full = new Blob(blobs, { type: 'application/octet-stream' });
    if (full.size !== size) throw new Error(`הקובץ ${f} לא שלם (${full.size} מתוך ${size}) — נסו שוב`);
    if (await sha256(full) !== man.files[f].sha256) {
      // חתיכה פגומה — לא שומרים; מוחקים את החתיכות של הקובץ, וההורדה הבאה מורידה אותו מחדש
      await idbStore('chunks', 'readwrite', (st) => st.delete(IDBKeyRange.bound(`${url}#`, `${url}#\uffff`)));
      throw new Error(`הקובץ ${f} הגיע פגום (sha256 שונה) — נמחק; לחצו שוב להורדה`);
    }
    await c.put(url, new Response(full, { headers: { 'Content-Type': 'application/octet-stream', 'Content-Length': String(size) } }));
    await idbStore('chunks', 'readwrite', (st) => st.delete(IDBKeyRange.bound(`${url}#`, `${url}#\uffff`)));
  }
  // הקבצים הגדולים במטמון — הטעינה מורידה רק את הקטנים (הגדרות, מילון) ובודקת שהמודל עולה
  await new Promise((resolve, reject) => {
    loadWaiters.push({ resolve, reject, onProgress: () => {} });
    getWorker().postMessage({ type: 'load', model: MODEL.id, revision: MODEL.revision });
  });
  if (l === LANG) engine(true);
}

/** 🗑 הסרה של מודל השפה ‎l‎ — הקבצים שלו בלבד, והתרגומים השמורים לשפה הזו. */
export async function removeModel(l = LANG) {
  const m = model(l);
  worker?.terminate(); worker = null; jobs.clear(); loadWaiters = [];
  try {
    const c = await caches.open(CACHE);
    for (const req of await c.keys()) if (req.url.includes(`/${m.id}/`)) await c.delete(req);
  } catch { /* אין */ }
  try {
    const prefix = `${MODELS_BASE}${m.id}/`;
    await idbStore('chunks', 'readwrite', (st) => st.delete(IDBKeyRange.bound(prefix, `${prefix}\uffff`)));
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
    const r = indexedDB.open('ct-translate', 2);
    // 2: ‏chunks — חתיכות של הורדה שעוד לא הסתיימה (12e.5)
    r.onupgradeneeded = () => {
      for (const st of ['msgs', 'chunks']) if (!r.result.objectStoreNames.contains(st)) r.result.createObjectStore(st);
    };
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
  return dbp;
}
const idb = (mode, fn) => idbStore('msgs', mode, fn);
async function idbStore(store, mode, fn) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(store, mode);
    const req = fn(tx.objectStore(store));
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
