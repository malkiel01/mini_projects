/*
 * Web Worker לתרגום במכשיר (docs/spec.md §5.11.1) — המודל רץ כאן, כדי שהמסך לא ייתקע.
 *
 * הודעות פנימה:  { type:'load', model, revision }   ← טעינה (או הורדה), עם התקדמות
 *                { type:'translate', job, sentences }  ← משפט אחר משפט
 * הודעות החוצה:  progress {file, loaded, total} · ready · sentence {job, i, text} · done {job} · error {job?, error}
 * העבודות רצות בתור, אחת אחרי השנייה (מודל אחד בזיכרון).
 */
import { pipeline, env } from '../vendor/transformers-3.7.6.min.js';

env.allowLocalModels = false;
// ה-WASM של onnxruntime — מ-jsDelivr באותה גרסה מוצמדת (‎~21MB‎, נשמר במטמון הדפדפן; לא בריפו)
env.backends.onnx.wasm.wasmPaths = 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.7.6/dist/';

let ready = null;
let queue = Promise.resolve();

function load(model, revision) {
  ready ??= pipeline('translation', model, {
    revision, dtype: 'q8', device: 'wasm',
    progress_callback: (p) => {
      if (p.status === 'progress' && p.total) postMessage({ type: 'progress', file: p.file, loaded: p.loaded, total: p.total });
    },
  }).catch((e) => { ready = null; throw e; });
  return ready;
}

onmessage = ({ data }) => {
  queue = queue.then(async () => {
    try {
      const tr = await load(data.model, data.revision);
      if (data.type === 'load') { postMessage({ type: 'ready' }); return; }
      if (data.type !== 'translate') return;
      // שורה אחר שורה: אצווה נופלת במודל הזה ("Cannot convert undefined to a BigInt")
      for (const [i, s] of data.sentences.entries()) {
        const out = await tr(s, { max_new_tokens: 256 });
        postMessage({ type: 'sentence', job: data.job, i, text: out[0].translation_text });
      }
      postMessage({ type: 'done', job: data.job });
    } catch (e) {
      postMessage({ type: 'error', job: data.job, error: String(e?.message || e) });
    }
  });
};
