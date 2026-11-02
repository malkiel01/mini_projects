// סיום ריצה של מנוע Claude Code (שלב 10, claude-tasks/docs/spec.md §11).
//
// 1. העלות: total_cost_usd מהדוח של Claude Code ← report-usage (develop, במכסה של מי שהריץ).
// 2. מטלה שנשארה in_progress (Claude נעצר בלי complete / block) ← block עם הסבר
//    וקישור לריצה — כדי שלא תיתקע בשקט עד שההחזקה תפוג.
//
// הקובץ מנוהל אוטומטית. שינוי ידני יידרס בהתקנה הבאה.

import { readFileSync } from 'node:fs';
import { env, argv, exit } from 'node:process';

const BOARD = env.BOARD_URL;
const TOKEN = env.BOARD_TOKEN;
const TASK_ID = Number(env.TASK_ID);
const RUN_URL = env.RUN_URL || '';

// הגנת הבוטים של האחסון — כמו ב-run.mjs: עוגייה תמיד, ועוגייה חדשה מתוך 409 פעם אחת
let COOKIE = env.BOARD_COOKIE || 'humans_21909=1';

async function board(action, payload = {}, retried = false) {
  const res = await fetch(`${BOARD}?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Worker-Token': TOKEN, 'X-Worker-Name': 'claude-code',
               'X-Worker-Route': 'platform', Cookie: COOKIE },
    body: JSON.stringify(payload),
  });
  if (res.status === 409 && !retried) {
    const m = (await res.text().catch(() => '')).match(/document\.cookie\s*=\s*["']([^"';]+)/);
    if (m && m[1] !== COOKIE) { COOKIE = m[1]; return board(action, payload, true); }
  }
  const data = await res.json().catch(() => ({}));
  if (!data.success) throw new Error(`הלוח דחה את "${action}": ${data.error || res.status}`);
  return data;
}

let report = null;
try { report = JSON.parse(readFileSync(argv[2] || 'claude-result.json', 'utf8')); } catch { /* הריצה קרסה לפני דוח */ }

try {
  if (report && typeof report.total_cost_usd === 'number') {
    const u = report.usage || {};
    await board('report-usage', {
      id: TASK_ID, provider: 'anthropic', model: env.MODEL || 'claude-code',
      cost_usd: report.total_cost_usd,
      tokens_in: (u.input_tokens || 0) + (u.cache_creation_input_tokens || 0) + (u.cache_read_input_tokens || 0),
      tokens_out: u.output_tokens || 0,
    });
    console.log(`העלות נרשמה: $${report.total_cost_usd.toFixed(4)}`);
  }

  const { task } = await board('task', { id: TASK_ID });
  if (task.status === 'in_progress') {
    const why = !report ? 'Claude Code לא החזיר דוח (קרס או נעצר)'
      : report.is_error ? `Claude Code נעצר בשגיאה (${report.subtype || 'error'})`
      : 'Claude Code סיים בלי לסגור את המטלה';
    const tail = report && report.result ? `\n\nמה שנכתב בסוף:\n${String(report.result).slice(0, 3000)}` : '';
    await board('block', { id: TASK_ID, question: `${why}.${tail}\n\n${RUN_URL}`, session_url: RUN_URL });
    console.log('המטלה נשארה פתוחה — נחסמה עם הסבר');
  }
} catch (e) {
  console.error(e.message);
  exit(1);
}
