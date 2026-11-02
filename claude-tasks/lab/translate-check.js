/*
 * דף מדידה לפני 12e (docs/spec.md §5.11.1): האם הטלפון מתרגם תשובה של צ'אט בתוך 5 שניות.
 *
 * שלב 1 — אנגלית ← עברית בלבד. שני מסלולים, שניהם על המכשיר:
 *  2. Translator API המובנה של Chrome (בלי הורדה מצדנו).
 *  3. Opus-MT דרך transformers.js: הורדה באישור (התקדמות ב-MB ובזמן), ואז טעינה מחדש מהמטמון,
 *     ותרגום של 3 תשובות בנוסח של צ'אט (~150 מילים, עם בלוק קוד שחייב לצאת כפי שהוא).
 * הטקסט לא יוצא מהמכשיר. התוצאות — טבלה, ו-📋 להעתקה לצ'אט.
 */
const $ = (s) => document.querySelector(s);
const TJS = 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.7.6';
const CACHE = 'transformers-cache';   // שם המטמון של transformers.js בדפדפן
// המרה קהילתית, מוצמדת ל-commit — לא תשתנה מתחתינו (12e עצמו — המרה שלנו מהמודל הרשמי)
const MODEL = { id: 'sivelsguy/opus-mt-en-he-onnx', revision: 'fc3befc3af12cd004ae65e9c36ea77858c19157c' };
const FILES = ['onnx/encoder_model_quantized.onnx', 'onnx/decoder_model_merged_quantized.onnx'];

// שלוש תשובות בנוסח של צ'אט: כותרת, רשימה, קוד בשורה ובלוק קוד
const REPLIES = [
`## Deploy finished

I merged the pull request and the deploy finished successfully. The board now shows a pin strip at the top of every conversation, and labels appear as small colored chips next to the time.

- Shared labels are visible to everyone who can see the chat.
- Private labels are visible only to you.
- Deleting a label also clears it from every message.

To check it yourself, run the tests:

\`\`\`
php claude-tasks/tests/labels.php
php claude-tasks/tests/search.php
\`\`\`

Please refresh the board once so the new version loads. If something looks wrong on the phone, send me a screenshot and I will fix it in the next pull request.`,

`I found the cause of the broken layout. Inline code was set to \`nowrap\`, so a long path pushed the whole message wider than the screen, and the start of the heading was cut off.

The fix keeps inline code as one unit but lets it wrap inside its own box. Code blocks and tables now scroll inside the bubble instead of stretching it.

\`\`\`css
.bubble { grid-template-columns: minmax(0, 1fr); }
.bubble > * { min-width: 0; max-width: 100%; }
\`\`\`

I checked the same message at 320 and 390 pixels, in light and dark mode, and there is no horizontal scrolling anymore. Two things are still open on your side: the environment name, and the template for the creative suite.`,

`Search is ready. The magnifier next to the settings button opens a sheet where you can type text, choose labels, show only pinned messages, and pick kinds, authors and dates.

Text alone keeps the whole conversation and highlights every match; the arrows step between them, starting from the newest. Any other filter narrows the list, and the active filter shows as chips you can remove one by one.

\`\`\`
GET api.php?action=channel-export&channel_id=12
\`\`\`

Export writes Markdown with the author, time and number of every message. Your private labels are included only in your own export. The file name stays in English, because some browsers replace a Hebrew name with the word download.`,
];

const report = { page: 'translate-check v2', at: new Date().toISOString(), model: `${MODEL.id}@${MODEL.revision.slice(0, 7)}` };
const rows = [];   // [מה, ערך]
const sec = (ms) => `${(ms / 1000).toFixed(2)} ש'`;
const mb = (b) => `${(b / 1e6).toFixed(1)}MB`;
const words = (t) => t.split(/\s+/).filter(Boolean).length;

function renderTable() {
  $('#results').replaceChildren(...rows.map(([k, v, cls]) => {
    const tr = document.createElement('tr');
    const th = document.createElement('th'); th.scope = 'row'; th.textContent = k;
    const td = document.createElement('td'); td.textContent = v; if (cls) td.className = cls;
    tr.append(th, td);
    return tr;
  }));
  $('#report').textContent = JSON.stringify(report, null, 2);
}
function setRow(k, v, cls) {
  const r = rows.find((x) => x[0] === k);
  if (r) { r[1] = v; r[2] = cls; } else rows.push([k, v, cls]);
  renderTable();
}
function memory() {
  const m = performance.memory;   // Chrome בלבד
  return m ? Math.round(m.usedJSHeapSize / 1e6) : null;
}
const status = (t) => { $('#mtStatus').textContent = t; };

/* ── 1 · המכשיר ───────────────────────────────────────────── */
async function device() {
  let gpu = 'אין';
  try { if (navigator.gpu) gpu = (await navigator.gpu.requestAdapter()) ? 'יש' : 'יש API, אין מתאם'; } catch { gpu = 'שגיאה'; }
  const brands = navigator.userAgentData?.brands?.map((b) => `${b.brand} ${b.version}`).join(', ') || navigator.userAgent;
  Object.assign(report, { ua: navigator.userAgent, mobile: navigator.userAgentData?.mobile ?? null,
    memoryGB: navigator.deviceMemory ?? null, cores: navigator.hardwareConcurrency ?? null, webgpu: gpu });
  setRow('דפדפן', brands);
  setRow('זיכרון המכשיר', navigator.deviceMemory ? `${navigator.deviceMemory}GB (בערך)` : 'לא ידוע');
  setRow('ליבות', String(navigator.hardwareConcurrency ?? 'לא ידוע'));
  setRow('WebGPU', gpu);
  if (gpu !== 'יש') $('#mtDevice').querySelector('[value="webgpu"]').disabled = true;
}

/* ── 2 · Translator API ───────────────────────────────────── */
async function apiCheck() {
  if (!('Translator' in self)) {
    report.translatorApi = 'none';
    setRow('Translator API', 'לא', 'bad');
    $('#apiRun').disabled = true;
    return;
  }
  // availability יכולה להיתקע (נצפה ב-Chromium בלי ממשק) — אחרי 8 ש' נרשם ולא תוקע את הדף
  const timeout = new Promise((r) => setTimeout(() => r('timeout'), 8000));
  let a;
  try { a = await Promise.race([Translator.availability({ sourceLanguage: 'en', targetLanguage: 'he' }), timeout]); }
  catch (e) { a = `error: ${e.message}`; }
  report.translatorApi = a;
  const label = { available: 'כן — מוכן', downloadable: 'כן — Chrome יוריד פעם אחת', downloading: 'כן — בהורדה',
                  unavailable: 'לא (אין עברית)', timeout: 'לא ענה תוך 8 ש\'' };
  setRow('Translator API', label[a] || a, String(a).startsWith('d') || a === 'available' ? 'ok' : 'bad');
}

async function apiRun() {
  $('#apiRun').disabled = true;
  try {
    const t0 = performance.now();
    const tr = await Translator.create({ sourceLanguage: 'en', targetLanguage: 'he', monitor(m) {
      m.addEventListener('downloadprogress', (e) => setRow('Translator · הורדה', `${Math.round(e.loaded * 100)}%`));
    } });
    setRow('Translator · מוכן אחרי', sec(performance.now() - t0));
    report.translatorRun = [];
    for (const [i, text] of REPLIES.entries()) {
      const t = performance.now();
      const out = await translateMarkdown(text, (line) => tr.translate(line));
      const ms = performance.now() - t;
      report.translatorRun.push(Math.round(ms));
      setRow(`Translator · תשובה ${i + 1}`, `${sec(ms)} ${ms <= 5000 ? '✅' : '❌'}`, ms <= 5000 ? 'ok' : 'bad');
      if (i === 0) $('#sample').textContent = out;
    }
    tr.destroy?.();
  } catch (e) {
    report.translatorRun = { error: e.message };
    setRow('Translator · שגיאה', e.message, 'bad');
  }
  $('#apiRun').disabled = false;
}

/* ── תרגום Markdown: רק טקסט; בלוקי קוד וקוד בשורה יוצאים כפי שהם ── */
async function translateMarkdown(md, translateLine) {
  const out = [];
  let inCode = false;
  for (const line of md.split('\n')) {
    if (/^\s*```/.test(line)) { inCode = !inCode; out.push(line); continue; }
    if (inCode || !line.trim()) { out.push(line); continue; }
    const m = line.match(/^(\s*(?:#{1,6}\s+|[-*]\s+|\d+\.\s+|>\s*)?)(.*)$/);
    const [prefix, body] = [m[1], m[2]];
    // קוד בשורה — מחליפים בסמן, מתרגמים, ומחזירים (המודל לא נוגע בו)
    const codes = [];
    const masked = body.replace(/`[^`]+`/g, (c) => { codes.push(c); return `X${codes.length - 1}X`; });
    // משפט אחר משפט: שורה עם שני משפטים — המודל משמיט לפעמים את השני
    const parts = [];
    for (const s of masked.split(/(?<=[.!?])\s+/)) if (s.trim()) parts.push(await translateLine(s));
    let t = parts.join(' ');
    codes.forEach((c, i) => { t = t.includes(`X${i}X`) ? t.replace(`X${i}X`, c) : `${t} ${c}`; });
    out.push(prefix + t);
  }
  return out.join('\n');
}
const codeBlocks = (md) => md.match(/```[\s\S]*?```/g) || [];

/* ── 3 · Opus-MT ──────────────────────────────────────────── */
let tjs = null;
async function lib() {
  if (!tjs) { tjs = await import(TJS); tjs.env.allowLocalModels = false; }
  return tjs;
}
async function loadModel(deviceKind, onBytes) {
  const { pipeline } = await lib();
  return pipeline('translation', MODEL.id, { revision: MODEL.revision, dtype: 'q8', device: deviceKind,
    progress_callback: (p) => { if (p.status === 'progress' && p.total) onBytes(p.file, p.loaded, p.total); } });
}

async function mtRun() {
  const deviceKind = $('#mtDevice').value;
  const prog = $('#mtProg');
  $('#mtRun').disabled = true; $('#mtClear').disabled = true;
  const key = `opus-${deviceKind}`;
  report[key] = {};
  try {
    status('טוען את transformers.js…');
    await lib();
    // א. הורדה (או מהמטמון, אם כבר ירד) — התקדמות אמיתית ב-MB ובזמן
    const seen = {};
    prog.hidden = false; prog.value = 0;
    const t0 = performance.now();
    let first = await loadModel(deviceKind, (file, loaded, total) => {
      seen[file] = { loaded, total };
      const big = Object.entries(seen).filter(([f]) => FILES.includes(f));
      const got = big.reduce((a, [, v]) => a + v.loaded, 0);
      const all = big.reduce((a, [, v]) => a + v.total, 0);
      prog.value = all ? Math.round((got / all) * 100) : 0;
      status(`מוריד: ${mb(got)} מתוך ${mb(all)} · ${sec(performance.now() - t0)}`);
    });
    const dl = performance.now() - t0;
    const bytes = Object.entries(seen).filter(([f]) => FILES.includes(f)).reduce((a, [, v]) => a + v.total, 0);
    const fromNet = Object.keys(seen).length > 0 && dl > 3000;
    report[key].downloadMB = +(bytes / 1e6).toFixed(1);
    report[key].firstLoadMs = Math.round(dl);
    setRow('גודל ההורדה', bytes ? mb(bytes) : 'לא ידוע');
    setRow(fromNet ? 'זמן הורדה' : 'טעינה ראשונה (כבר במטמון)', sec(dl));
    prog.hidden = true;
    await first.dispose?.();
    first = null;

    // ב. טעינה מחדש — עכשיו מהמטמון
    status('טוען שוב, מהמטמון…');
    const t1 = performance.now();
    const translator = await loadModel(deviceKind, () => {});
    const cacheMs = performance.now() - t1;
    report[key].cacheLoadMs = Math.round(cacheMs);
    setRow('טעינה מהמטמון', sec(cacheMs), cacheMs <= 5000 ? 'ok' : 'bad');

    // ג. שלוש תשובות, שורה אחר שורה (אצווה נופלת במודל הזה: "Cannot convert undefined to a BigInt")
    const one = async (line) => (await translator(line, { max_new_tokens: 256 }))[0].translation_text;
    report[key].replies = [];
    for (const [i, text] of REPLIES.entries()) {
      status(`מתרגם תשובה ${i + 1} מתוך ${REPLIES.length}…`);
      const t = performance.now();
      let firstMs = null;
      const out = await translateMarkdown(text, async (l) => { const r = await one(l); firstMs ??= performance.now() - t; return r; });
      const ms = performance.now() - t;
      const codeKept = JSON.stringify(codeBlocks(out)) === JSON.stringify(codeBlocks(text));
      report[key].replies.push({ words: words(text), ms: Math.round(ms), firstSentenceMs: Math.round(firstMs), codeKept });
      setRow(`תשובה ${i + 1} (${words(text)} מילים)`, `${sec(ms)} ${ms <= 5000 ? '✅' : '❌'} · משפט ראשון ${sec(firstMs)}${codeKept ? '' : ' · הקוד השתנה!'}`,
             ms <= 5000 && codeKept ? 'ok' : 'bad');
      if (i === 0) $('#sample').textContent = out;
    }
    const mem = memory();
    report[key].jsHeapMB = mem;
    setRow('זיכרון בשימוש (JS)', mem === null ? 'לא זמין בדפדפן הזה' : `${mem}MB`);
    // מעבר "מהמטמון" — הטעינה מהמטמון + התשובה הארוכה, מה שבעל הלוח ירגיש בפעם הראשונה ביום
    const worst = Math.max(...report[key].replies.map((r) => r.ms));
    report[key].verdict = cacheMs + worst <= 5000 ? 'pass' : worst <= 5000 ? 'pass-after-load' : 'fail';
    setRow('בשורה התחתונה', {
      pass: '✅ טעינה מהמטמון + תשובה — בתוך 5 ש\'',
      'pass-after-load': '🟡 כל תשובה בתוך 5 ש\', אבל הטעינה מהמטמון מוסיפה',
      fail: '❌ תשובה ארוכה מ-5 ש\'',
    }[report[key].verdict], report[key].verdict === 'fail' ? 'bad' : 'ok');
    status('סיום. 📋 העתיקו את התוצאות לצ\'אט.');
    await translator.dispose?.();
  } catch (e) {
    report[key].error = String(e?.message || e);
    setRow('שגיאה', report[key].error, 'bad');
    status('');
  }
  prog.hidden = true;
  $('#mtRun').disabled = false; $('#mtClear').disabled = false;
  renderTable();
}

async function clearCache() {
  try {
    const ok = await caches.delete(CACHE);
    status(ok ? '🗑 המודל נמחק מהמטמון של הדפדפן.' : 'אין מודל במטמון.');
  } catch (e) { status(`לא נמחק: ${e.message}`); }
}

/* ── חיבור ────────────────────────────────────────────────── */
$('#apiRun').addEventListener('click', apiRun);
$('#mtRun').addEventListener('click', () => {
  if (!confirm('להוריד מודל של כ-150MB? (פעם אחת; עדיף ב-Wi-Fi)')) return;
  mtRun();
});
$('#mtClear').addEventListener('click', clearCache);
$('#copy').addEventListener('click', async () => {
  const text = [...rows.map(([k, v]) => `${k}: ${v}`), '', JSON.stringify(report)].join('\n');
  try { await navigator.clipboard.writeText(text); $('#copy').textContent = '✓ הועתק'; }
  catch { getSelection().selectAllChildren($('#report')); }
});
await device();
await apiCheck();
renderTable();
