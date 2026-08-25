/**
 * מנוע ההרצה.
 *
 * רץ בתוך GitHub Actions על הריפו של המשתמש, עם checkout מלא: הוא קורא
 * קבצים, כותב, מריץ את הבדיקות של הפרויקט, פותח PR, ומדווח חזרה ללוח.
 *
 * למה כאן ולא בשרת: אחסון PHP משותף לא יכול להחזיק תהליך שמשכפל ריפו,
 * מתקין תלויות ומריץ בדיקות. הריצה של גיטהאב יכולה, והיא כבר משויכת
 * לריפו ולהרשאות שלו.
 *
 * ‏שני כללים שהקובץ נשמע להם:
 *   1. מטלה לעולם לא נעלמת בשקט. כל מסלול — הצלחה, שאלה, קריסה —
 *      נגמר בכתיבה חזרה ללוח.
 *   2. לא דוחפים קוד שלא עבר את הבדיקות. כישלון חוזר לדגם לתיקון,
 *      ואם הוא נמשך — המטלה נחסמת עם הפלט, ולא נסגרת כאילו הצליחה.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';

const env = process.env;
const BOARD    = env.BOARD_URL;
const TOKEN    = env.BOARD_TOKEN;
const TASK_ID  = Number(env.TASK_ID);
const PROVIDER = env.PROVIDER || 'anthropic';
const MODEL    = env.MODEL || '';
const AI_KEY   = env.AI_KEY || '';
const REPO     = env.GITHUB_REPOSITORY || '';
const GH_TOKEN = env.GITHUB_TOKEN || '';
const BASE     = env.BASE_BRANCH || 'main';
const CHECK    = (env.CHECK_COMMAND || '').trim();
const RUN_URL  = `${env.GITHUB_SERVER_URL}/${REPO}/actions/runs/${env.GITHUB_RUN_ID}`;

const MAX_ROUNDS  = 8;
const MAX_REPAIRS = 2;
const MAX_FILE    = 60_000;     // תווים לקובץ שנשלח לדגם
const MAX_TREE    = 800;        // שורות בעץ הקבצים

const sh = (cmd, args, opts = {}) =>
  execFileSync(cmd, args, { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, ...opts });

/* ── הלוח ──────────────────────────────────────────────────────── */

async function board(action, payload = {}) {
  const res = await fetch(`${BOARD}?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Worker-Token': TOKEN,
      'X-Worker-Name': `actions/${PROVIDER}`,
    },
    body: JSON.stringify(payload),
  });
  const data = await res.json().catch(() => ({}));
  if (!data.success) throw new Error(`הלוח דחה את "${action}": ${data.error || res.status}`);
  return data;
}

/* ── הספק ──────────────────────────────────────────────────────── */

const SHAPE = {
  anthropic: {
    url: () => 'https://api.anthropic.com/v1/messages',
    headers: () => ({ 'x-api-key': AI_KEY, 'anthropic-version': '2023-06-01' }),
    body: (sys, msg) => ({ model: MODEL, max_tokens: 8000, system: sys,
                           messages: [{ role: 'user', content: msg }] }),
    text: (d) => (d.content || []).filter((b) => b.type === 'text').map((b) => b.text).join(''),
  },
  openai: {
    url: () => 'https://api.openai.com/v1/chat/completions',
    headers: () => ({ Authorization: `Bearer ${AI_KEY}` }),
    body: (sys, msg) => ({ model: MODEL, max_completion_tokens: 8000,
                           messages: [{ role: 'system', content: sys }, { role: 'user', content: msg }] }),
    text: (d) => d.choices?.[0]?.message?.content || '',
  },
  google: {
    url: () => `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(MODEL)}:generateContent`,
    headers: () => ({ 'x-goog-api-key': AI_KEY }),
    body: (sys, msg) => ({ system_instruction: { parts: [{ text: sys }] },
                           contents: [{ role: 'user', parts: [{ text: msg }] }],
                           generationConfig: { maxOutputTokens: 8000 } }),
    text: (d) => (d.candidates?.[0]?.content?.parts || []).map((p) => p.text || '').join(''),
  },
};

async function ask(system, message) {
  const shape = SHAPE[PROVIDER];
  if (!shape) throw new Error(`ספק לא מוכר: ${PROVIDER}`);

  const res = await fetch(shape.url(), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...shape.headers() },
    body: JSON.stringify(shape.body(system, message)),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`${PROVIDER} החזיר ${res.status}: ${data?.error?.message || ''}`);

  const text = shape.text(data);
  if (!text.trim()) throw new Error(`${PROVIDER} החזיר תשובה ריקה`);
  return text;
}

/** דגמים עוטפים JSON בטקסט ובגדרות קוד. לוקחים את הבלוק. */
function parseJson(text) {
  const start = text.indexOf('{');
  const end   = text.lastIndexOf('}');
  if (start < 0 || end < start) throw new Error('לא נמצא JSON בתשובת הדגם');
  return JSON.parse(text.slice(start, end + 1));
}

/* ── הריפו ─────────────────────────────────────────────────────── */

function repoTree() {
  const files = sh('git', ['ls-files']).split('\n').filter(Boolean);
  const shown = files.slice(0, MAX_TREE);
  return shown.join('\n') + (files.length > shown.length
    ? `\n… ועוד ${files.length - shown.length} קבצים (בקש נתיב ואשלח)` : '');
}

function readFiles(paths) {
  const out = [];
  for (const p of paths.slice(0, 12)) {
    // מונע יציאה מהריפו דרך נתיב יחסי או מוחלט.
    if (p.includes('..') || p.startsWith('/')) { out.push(`### ${p}\n(נתיב לא מורשה)`); continue; }
    if (!existsSync(p)) { out.push(`### ${p}\n(לא קיים)`); continue; }
    const body = readFileSync(p, 'utf8');
    out.push(`### ${p}\n${body.length > MAX_FILE ? body.slice(0, MAX_FILE) + '\n… (נחתך)' : body}`);
  }
  return out.join('\n\n');
}

/* ── בדיקות ────────────────────────────────────────────────────── */

/**
 * מריץ את הבדיקות של הפרויקט. אם לא הוגדרה פקודה — לפחות בדיקת תחביר
 * לקבצים שנגענו בהם, כי לדחוף קוד שאינו נפרס גרוע מלא לדחוף כלום.
 */
function runChecks(changed) {
  const out = [];
  let ok = true;

  const tryRun = (cmd, args) => {
    try { out.push(`$ ${cmd} ${args.join(' ')}\n${sh(cmd, args, { stdio: 'pipe' })}`); return true; }
    catch (e) {
      out.push(`$ ${cmd} ${args.join(' ')}\n${e.stdout || ''}${e.stderr || ''}`.slice(0, 6000));
      return false;
    }
  };

  for (const f of changed.filter((f) => f.endsWith('.php'))) {
    if (!tryRun('php', ['-l', f])) ok = false;
  }
  for (const f of changed.filter((f) => f.endsWith('.js') || f.endsWith('.mjs'))) {
    const tmp = `${f.replace(/[^A-Za-z0-9]/g, '_')}.check.mjs`;
    writeFileSync(tmp, readFileSync(f));
    if (!tryRun('node', ['--check', tmp])) ok = false;
    rmSync(tmp, { force: true });
  }

  if (CHECK) {
    if (!tryRun('bash', ['-lc', CHECK])) ok = false;
  }
  return { ok, output: out.join('\n\n').slice(-8000) };
}

/* ── גיטהאב ────────────────────────────────────────────────────── */

async function gh(method, path, body) {
  const res = await fetch(`https://api.github.com${path}`, {
    method,
    headers: {
      Accept: 'application/vnd.github+json',
      Authorization: `Bearer ${GH_TOKEN}`,
      'User-Agent': 'claude-tasks-agent',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  return { status: res.status, data };
}

async function openPr(branch, title, body) {
  const made = await gh('POST', `/repos/${REPO}/pulls`,
    { title, body, head: branch, base: BASE, draft: true });
  if (made.status === 201) return { url: made.data.html_url, note: '' };

  // ‏422 = כבר קיים PR פתוח לענף הזה; הדחיפה עדכנה אותו.
  const open = await gh('GET', `/repos/${REPO}/pulls?head=${REPO.split('/')[0]}:${branch}&state=open`);
  if (open.data?.[0]?.html_url) return { url: open.data[0].html_url, note: '' };

  /*
   * ‏403 כאן הוא כמעט תמיד ההגדרה "Allow GitHub Actions to create and
   * approve pull requests" שכבויה בריפו. הקוד כבר נדחף, ולכן חבל
   * להיכשל — אבל צריך לומר למה אין PR, אחרת זה נראה כמו באג.
   */
  const why = made.status === 403
    ? 'לגיטהאב אקשנס אין רשות לפתוח PR בריפו הזה (Settings ← Actions ← Workflow permissions)'
    : `גיטהאב החזיר ${made.status}: ${made.data?.message || ''}`;
  return { url: '', note: why };
}

/* ── הלולאה ────────────────────────────────────────────────────── */

const SYSTEM = `אתה מפתח שעובד על ריפו קוד. אתה עונה JSON בלבד, בלי טקסט לפניו או אחריו.

בכל תשובה בחר פעולה אחת:

{"action":"read","paths":["src/a.js"]}  — לקרוא קבצים לפני שמחליטים. עד 12 בכל פעם.
{"action":"skill","name":"שם הסקיל"}   — למשוך הוראות של סקיל מהרשימה שקיבלת.
{"action":"edit","summary":"מה עשית, בעברית","commit_message":"...","files":[{"path":"...","content":"התוכן המלא של הקובץ"}],"delete":["..."]}
{"action":"question","question":"מה בדיוק חסר לך כדי להמשיך"}

כללים:
- ‏content הוא הקובץ השלם אחרי השינוי, לא תיקון חלקי ולא קטע.
- אל תנחש תוכן של קובץ שלא קראת. קרא קודם.
- ‏question רק כשבאמת אי אפשר להתקדם בלי הכרעה של אדם — לא כדי לאשר משהו שאתה כבר בטוח בו.
- אם יש סקיל שמתאים למטלה, משוך אותו לפני שאתה מתחיל. הוא מכיל את הדרך שבה בפרויקט הזה עושים את זה.
- שנה מעט ככל האפשר. אל תרחיב את המטלה מעבר למה שנתבקש.
- כתוב בסגנון הקוד שסביבך: אותם שמות, אותה רמת הערות, אותם ניבים.`;

async function main() {
  const { task, notes, skills = [] } = await board('start', { id: TASK_ID, run_url: RUN_URL });

  /*
   * סקיל שסומן "תמיד" מגיע עם גופו המלא — סימנו אותו ככזה כדי שיחול
   * בלי שהדגם יצטרך לזכור לבקש. השאר מגיעים כמדד, ונמשכים לפי צורך:
   * לדחוף את כולם לכל פרומפט מבזבז הקשר ומטשטש את המטלה עצמה.
   */
  const always  = skills.filter((s) => s.always && s.body);
  const onIndex = skills.filter((s) => !s.always);

  const history = [
    `# המטלה\n${task.title}\n\n${task.body || '(אין פירוט)'}`,
    notes.length
      ? `# השיחה עד כה\n${notes.map((n) => `[${n.author} · ${n.kind}] ${n.body}`).join('\n')}`
      : '',
    always.length
      ? `# הוראות שחלות תמיד\n${always.map((s) => `## ${s.name}\n${s.body}`).join('\n\n')}`
      : '',
    onIndex.length
      ? `# סקילים זמינים (משוך במידת הצורך)\n${onIndex.map((s) => `- ${s.name}: ${s.description || '—'}`).join('\n')}`
      : '',
    `# עץ הקבצים\n${repoTree()}`,
  ].filter(Boolean);

  let repairs = 0;

  for (let round = 1; round <= MAX_ROUNDS; round++) {
    const reply = parseJson(await ask(SYSTEM, history.join('\n\n---\n\n')));

    if (reply.action === 'question') {
      await board('block', { id: TASK_ID, question: reply.question, session_url: RUN_URL });
      console.log('נחסמה בשאלה:', reply.question);
      return;
    }

    if (reply.action === 'skill') {
      try {
        const { skill } = await board('skill', { name: reply.name });
        history.push(`# סקיל: ${skill.name}\n${skill.body}`);
      } catch (e) {
        history.push(`# סקיל: ${reply.name}\n(לא נמצא — ${e.message})`);
      }
      continue;
    }

    if (reply.action === 'read') {
      const paths = Array.isArray(reply.paths) ? reply.paths : [];
      history.push(`# קבצים שביקשת\n${readFiles(paths)}`);
      continue;
    }

    if (reply.action !== 'edit' || !Array.isArray(reply.files)) {
      history.push('# שגיאה\nהתשובה לא הייתה באחד המבנים המותרים. נסה שוב.');
      continue;
    }

    const changed = [];
    for (const f of reply.files) {
      if (!f?.path || typeof f.content !== 'string') continue;
      if (f.path.includes('..') || f.path.startsWith('/')) continue;
      mkdirSync(dirname(f.path), { recursive: true });
      writeFileSync(f.path, f.content);
      changed.push(f.path);
    }
    for (const p of reply.delete || []) {
      if (typeof p === 'string' && !p.includes('..') && !p.startsWith('/') && existsSync(p)) {
        rmSync(p, { force: true });
        changed.push(p);
      }
    }
    if (!changed.length) {
      history.push('# שגיאה\nלא הגיעו קבצים לכתיבה. החזר files עם נתיב ותוכן מלא.');
      continue;
    }

    const checks = runChecks(changed);
    if (!checks.ok) {
      if (++repairs > MAX_REPAIRS) {
        await board('block', {
          id: TASK_ID,
          question: `הבדיקות נכשלו ${repairs} פעמים ולא הצלחתי לייצב. הפלט האחרון:\n\n${checks.output}`,
          session_url: RUN_URL,
        });
        console.log('נחסמה: הבדיקות לא עברו');
        return;
      }
      history.push(`# הבדיקות נכשלו — תקן\n${checks.output}`);
      continue;
    }

    const branch = `agent/task-${TASK_ID}`;
    sh('git', ['config', 'user.name', 'claude-tasks agent']);
    sh('git', ['config', 'user.email', 'agent@users.noreply.github.com']);
    sh('git', ['checkout', '-B', branch]);
    sh('git', ['add', '-A']);
    sh('git', ['commit', '-m', reply.commit_message || `מטלה #${TASK_ID}: ${task.title}`]);
    sh('git', ['push', '-u', 'origin', branch, '--force-with-lease']);

    const pr = await openPr(branch,
      `מטלה #${TASK_ID}: ${task.title}`,
      `${reply.summary || ''}\n\nנפתח אוטומטית מלוח המטלות.\nהרצה: ${RUN_URL}`);

    await board('complete', {
      id: TASK_ID,
      result: [
        reply.summary || 'בוצע',
        `קבצים: ${changed.join(', ')}`,
        `ענף: ${branch}`,
        pr.url ? `PR: ${pr.url}` : `הקוד נדחף אך PR לא נפתח — ${pr.note}`,
      ].join('\n\n'),
      pr_url: pr.url || undefined,
      session_url: RUN_URL,
    });
    console.log('הושלמה:', pr.url || pr.note);
    return;
  }

  await board('block', {
    id: TASK_ID,
    question: `לא הגעתי לתוצאה תוך ${MAX_ROUNDS} סבבים. ייתכן שהמטלה רחבה מדי — אפשר לפצל אותה?`,
    session_url: RUN_URL,
  });
}

main().catch(async (e) => {
  // גם קריסה נגמרת בכתיבה חזרה: מטלה שנעלמת בשקט היא הדבר היחיד
  // שהלוח הזה קיים כדי למנוע.
  console.error(e);
  try {
    await board('block', { id: TASK_ID, question: `ההרצה נכשלה: ${e.message}\n\n${RUN_URL}`, session_url: RUN_URL });
  } catch (inner) {
    console.error('גם הדיווח ללוח נכשל:', inner.message);
  }
  process.exit(1);
});
