// בדיקת עשן להמרה: כל מודל ב-dist/ נטען ב-transformers.js 3.7.6 (כמו בדפדפן, q8) ומתרגם משפט.
//   node smoke.mjs <workdir>/dist        (בתיקייה שבה @huggingface/transformers@3.7.6 מותקן)
// נכשל אם התרגום ריק, זהה למקור, לא בכתב של שפת היעד, או איטי מ-25ms לטוקן (ה-lm_head לא קופל).
import { pipeline, env } from '@huggingface/transformers';
import fs from 'node:fs';
import path from 'node:path';

const dist = path.resolve(process.argv[2]);
const build = JSON.parse(fs.readFileSync(new URL('./build.json', import.meta.url), 'utf8')).build;
env.allowRemoteModels = false;
env.localModelPath = dist + '/';
const S = 'The deploy finished successfully, and the board shows the new version.';
const SCRIPT = { he: /[֐-׿]/, es: /[a-záéíóúñ]/i };
let bad = 0;
for (const pair of fs.readdirSync(dist)) {
  const tr = await pipeline('translation', `${pair}/${build}`, { dtype: 'q8', local_files_only: true });
  await tr('Warm up now.');
  const enc = tr.tokenizer(S);
  const t0 = performance.now();
  const ids = await tr.model.generate({ ...enc, max_new_tokens: 128 });
  const ms = performance.now() - t0, tok = ids.dims[1];
  const out = tr.tokenizer.decode(ids[0], { skip_special_tokens: true });
  const lang = pair.split('-')[1];
  const ok = out && out !== S && SCRIPT[lang]?.test(out) && ms / tok < 25;
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${pair}: ${out}  (${tok} טוקנים, ${Math.round(ms / tok)}ms לטוקן)`);
  if (!ok) bad++;
  await tr.dispose?.();
}
process.exit(bad ? 1 : 0);
