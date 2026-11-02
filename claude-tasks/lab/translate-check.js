/*
 * דף מדידה לפני 12e (docs/spec.md §5.11.1): האם הטלפון מתרגם תשובה של צ'אט בתוך 5 שניות.
 *
 * שני מסלולים, שניהם על המכשיר בלבד:
 *  2. Translator API המובנה של Chrome (בלי הורדה מצדנו).
 *  3. Opus-MT דרך transformers.js — הורדה באישור, נשמרת במטמון הדפדפן.
 * הטקסט לא יוצא מהמכשיר. התוצאות מודפסות בסעיף 4, להעתקה לצ'אט.
 */
const $ = (s) => document.querySelector(s);
const TJS = 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.7.6';
const MODELS = {
  'en-he': 'sivelsguy/opus-mt-en-he-onnx',
  'he-en': 'sivelsguy/opus-mt-tc-big-he-en-onnx',
};
// תשובה טיפוסית של צ'אט — כמה שורות, באורך של עדכון רגיל
const SAMPLES = {
  en: [
    'I merged the pull request and the deploy finished successfully.',
    'The search sheet now highlights every match, and the arrows step between them.',
    'Two things are still open: the environment name, and the template for the creative suite.',
    'Please refresh the board once so the new version loads.',
  ],
  he: [
    'מיזגתי את ה-PR והפריסה הסתיימה בהצלחה.',
    'גיליון החיפוש מדגיש עכשיו כל התאמה, והחצים עוברים ביניהן.',
    'נשארו פתוחים שני דברים: שם הסביבה, והתבנית של חבילת היצירה.',
    'רעננו את הלוח פעם אחת כדי שהגרסה החדשה תיטען.',
  ],
};
const SAMPLES2 = {
  en: ['The labels are now shared or private, and each one has its own color.',
       'Long press on a message opens the menu with copy, reply and pin.'],
  he: ['התוויות עכשיו משותפות או פרטיות, ולכל אחת צבע משלה.',
       'נגיעה ארוכה על הודעה פותחת תפריט עם העתקה, תשובה והצמדה.'],
};

const report = { page: 'translate-check v1', at: new Date().toISOString() };
const sec = (ms) => `${(ms / 1000).toFixed(2)} ש'`;
const verdict = (ms) => (ms <= 5000 ? '✅ עומד ביעד' : '❌ מעל 5 ש\'');
function renderReport() { $('#report').textContent = JSON.stringify(report, null, 2); }
function kv(box, rows) {
  box.replaceChildren(...rows.flatMap(([k, v, cls]) => {
    const dt = document.createElement('dt'); dt.textContent = k;
    const dd = document.createElement('dd'); dd.textContent = v; if (cls) dd.className = cls;
    return [dt, dd];
  }));
}
function log(box, line) { box.textContent += (box.textContent ? '\n' : '') + line; }

/* ── 1 · המכשיר ───────────────────────────────────────────── */
async function device() {
  let gpu = 'אין';
  try { if (navigator.gpu) gpu = (await navigator.gpu.requestAdapter()) ? 'יש' : 'יש API, אין מתאם'; } catch { gpu = 'שגיאה'; }
  let storage = '—';
  try { const e = await navigator.storage?.estimate(); if (e) storage = `${Math.round((e.quota - e.usage) / 1e6)}MB פנויים לדף`; } catch { /* לא נתמך */ }
  const ua = navigator.userAgentData?.brands?.map((b) => `${b.brand} ${b.version}`).join(', ') || '';
  Object.assign(report, {
    ua: navigator.userAgent, brands: ua, mobile: navigator.userAgentData?.mobile ?? null,
    memoryGB: navigator.deviceMemory ?? null, cores: navigator.hardwareConcurrency ?? null, webgpu: gpu, storage,
  });
  kv($('#dev'), [
    ['דפדפן', ua || navigator.userAgent],
    ['זיכרון', navigator.deviceMemory ? `${navigator.deviceMemory}GB (בערך)` : 'לא ידוע'],
    ['ליבות', String(navigator.hardwareConcurrency ?? 'לא ידוע')],
    ['WebGPU', gpu],
    ['אחסון', storage],
  ]);
  if (gpu !== 'יש') $('#mtDevice').querySelector('[value="webgpu"]').disabled = true;
}

/* ── 2 · Translator API ───────────────────────────────────── */
async function apiCheck() {
  if (!('Translator' in self)) {
    report.translatorApi = 'אין';
    kv($('#api'), [['זמין', 'לא — הדפדפן הזה אין לו Translator API', 'bad']]);
    $('#apiRun').disabled = true;
    return;
  }
  const res = {};
  for (const [s, t] of [['en', 'he'], ['he', 'en']]) {
    // availability יכולה להיתקע (נצפה ב-Chromium בלי ממשק) — אחרי 8 ש' זה נרשם ולא תוקע את הדף
    const timeout = new Promise((r) => setTimeout(() => r('timeout'), 8000));
    try { res[`${s}-${t}`] = await Promise.race([Translator.availability({ sourceLanguage: s, targetLanguage: t }), timeout]); }
    catch (e) { res[`${s}-${t}`] = `שגיאה: ${e.message}`; }
  }
  report.translatorApi = res;
  const label = { available: 'מוכן', downloadable: 'צריך הורדה (Chrome מוריד)', downloading: 'בהורדה', unavailable: 'לא נתמך',
                  timeout: 'לא ענה תוך 8 ש\'' };
  kv($('#api'), Object.entries(res).map(([k, v]) => [k === 'en-he' ? 'אנגלית ← עברית' : 'עברית ← אנגלית', label[v] || v,
    v === 'available' ? 'ok' : v === 'unavailable' ? 'bad' : '']));
}

async function apiRun() {
  const out = $('#apiOut'); out.textContent = '';
  $('#apiRun').disabled = true;
  report.translatorRun = {};
  for (const [s, t] of [['en', 'he'], ['he', 'en']]) {
    const key = `${s}-${t}`;
    try {
      const t0 = performance.now();
      const tr = await Translator.create({ sourceLanguage: s, targetLanguage: t, monitor(m) {
        m.addEventListener('downloadprogress', (e) => { out.dataset.p = `${key}: הורדה ${Math.round(e.loaded * 100)}%`; });
      } });
      const ready = performance.now() - t0;
      const t1 = performance.now();
      const lines = [];
      for (const line of SAMPLES[s]) lines.push(await tr.translate(line));
      const first = performance.now() - t1;
      const t2 = performance.now();
      for (const line of SAMPLES2[s]) await tr.translate(line);
      const second = performance.now() - t2;
      report.translatorRun[key] = { readyMs: Math.round(ready), replyMs: Math.round(first), nextReplyMs: Math.round(second) };
      log(out, `${key}: מוכן אחרי ${sec(ready)} · תשובה ${sec(first)} ${verdict(first)} · הבאה ${sec(second)}`);
      log(out, lines.join('\n'));
      tr.destroy?.();
    } catch (e) {
      report.translatorRun[key] = { error: e.message };
      log(out, `${key}: ❌ ${e.message}`);
    }
    renderReport();
  }
  $('#apiRun').disabled = false;
}

/* ── 3 · Opus-MT ──────────────────────────────────────────── */
async function mtRun() {
  const dir = $('#mtDir [aria-checked="true"]').dataset.v;
  const deviceKind = $('#mtDevice').value;
  const [s] = dir.split('-');
  const out = $('#mtOut'); out.textContent = '';
  const prog = $('#mtProg'); prog.hidden = false; prog.value = 0;
  $('#mtRun').disabled = true;
  const key = `opus-${dir}-${deviceKind}`;
  try {
    log(out, 'טוען את transformers.js…');
    const { pipeline, env } = await import(TJS);
    env.allowLocalModels = false;
    const sizes = {};
    const t0 = performance.now();
    let downloaded = false;
    const translator = await pipeline('translation', MODELS[dir], {
      dtype: 'q8', device: deviceKind,
      progress_callback: (p) => {
        if (p.status === 'progress' && p.total) {
          sizes[p.file] = p.total;
          if (p.total > 1e6) downloaded = downloaded || p.loaded < p.total;
          const all = Object.values(sizes).reduce((a, b) => a + b, 0);
          prog.value = Math.round((p.loaded / p.total) * 100);
          out.dataset.p = `${p.file} ${Math.round(p.progress)}% · ${Math.round(all / 1e6)}MB`;
        }
      },
    });
    const load = performance.now() - t0;
    log(out, `נטען אחרי ${sec(load)}${downloaded ? ' (כולל הורדה)' : ' (מהמטמון)'}`);
    // שורה אחר שורה: אצווה של כמה שורות נופלת במודלים האלה ("Cannot convert undefined to a BigInt")
    const each = async (lines) => { const r = []; for (const l of lines) r.push((await translator(l, { max_new_tokens: 200 }))[0].translation_text); return r; };
    const t1 = performance.now();
    const r1 = await each(SAMPLES[s]);
    const first = performance.now() - t1;
    const t2 = performance.now();
    await each(SAMPLES2[s]);
    const second = performance.now() - t2;
    log(out, `תשובה ראשונה ${sec(first)} ${verdict(first)} · הבאה ${sec(second)} ${verdict(second)}`);
    log(out, r1.join('\n'));
    report[key] = { model: MODELS[dir], loadMs: Math.round(load), fromCache: !downloaded,
                    replyMs: Math.round(first), nextReplyMs: Math.round(second),
                    mb: Math.round(Object.values(sizes).reduce((a, b) => a + b, 0) / 1e6) };
    log(out, downloaded ? '👉 עכשיו רעננו את הדף ולחצו שוב — כך נמדדת טעינה מהמטמון.' : '');
    translator.dispose?.();
  } catch (e) {
    report[key] = { error: String(e?.message || e) };
    log(out, `❌ ${e?.message || e}`);
  }
  prog.hidden = true;
  $('#mtRun').disabled = false;
  renderReport();
}

/* ── חיבור ────────────────────────────────────────────────── */
$('#mtDir').addEventListener('click', (e) => {
  const b = e.target.closest('button'); if (!b) return;
  $('#mtDir').querySelectorAll('button').forEach((x) => x.setAttribute('aria-checked', String(x === b)));
});
$('#apiRun').addEventListener('click', apiRun);
$('#mtRun').addEventListener('click', () => {
  const dir = $('#mtDir [aria-checked="true"]').dataset.v;
  if (!confirm(`להוריד מודל של כ-${dir === 'en-he' ? 147 : 365}MB? (פעם אחת; עדיף ב-Wi-Fi)`)) return;
  mtRun();
});
$('#copy').addEventListener('click', async () => {
  try { await navigator.clipboard.writeText($('#report').textContent); $('#copy').textContent = '✓ הועתק'; }
  catch { getSelection().selectAllChildren($('#report')); }
});
await device();
await apiCheck();
renderReport();
