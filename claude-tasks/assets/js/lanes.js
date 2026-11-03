/*
 * שני מסלולים — docs/spec.md §15. שלב 15: Platform נעצר (§15.3), וההגדרות של המשתמש (§15.11 + בעל הלוח 2026-11-03).
 *
 * הפס במסך הראשי כשהמפסק פתוח: הסיבה, ולפי ההגדרה — "עוברים לצ'אט" / "ממתינות" / שאלה עם שני כפתורים
 * ("💬 לעבור לצ'אט" · "⏳ להמתין"). תמיד: "🔌 הגדרות ← חיבורים" ו"↻ נסה שוב".
 * בהגדרות ← 🔀 מסלולים: השיטה המועדפת, מה קורה כשהשיטה נעצרת, והאם לחזור לשיטה המועדפת גם באמצע שיח.
 */
import { el, api, toast } from './core.js';

const STATE_TEXT = {
  credit_exhausted: 'נגמר הקרדיט אצל ספק הבינה',
  key_rejected: 'המפתח של ספק הבינה נדחה',
  rate_limited: 'ספק הבינה עמוס',
  down: 'ספק הבינה לא זמין',
};

function whenText(p) {
  if (p.manual) return 'לא ינסה שוב לבד — מתקנים חיבור, או לוחצים "נסה שוב".';
  if (p.retry_at) {
    const t = new Date(p.retry_at * 1000).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
    return `ינסה שוב לבד ב-${t}.`;
  }
  return '';
}

/** הפס האדום. מחזיר true אם הוצג (ואז אזהרת המכסה מוותרת על מקומה). */
export function renderPlatformStrip(box, s, { onChange, openConnections } = {}) {
  const p = s?.platform;
  if (!box || !p?.open) return false;
  box.hidden = false;
  box.className = `quota-warn quota-warn--over platform-stop${p.ask_pending ? ' platform-stop--ask' : ''}`;
  const busy = (b, fn) => async () => {
    b.disabled = true;
    try { await fn(); } catch (ex) { toast(ex.message, 'error'); }
    b.disabled = false;
    onChange?.();
  };
  const retry = el('button', { type: 'button', class: 'btn sm', text: '↻ נסה שוב' });
  retry.onclick = busy(retry, async () => {
    const { platform } = await api('platform-retry');
    toast(platform.open ? `עדיין לא עובד — ${platform.error || STATE_TEXT[platform.state] || ''}` : '☁️ Platform חזר', platform.open ? 'error' : 'ok');
  });
  const decide = (decision, text, cls) => {
    const b = el('button', { type: 'button', class: `btn sm ${cls}`, text });
    b.onclick = busy(b, async () => {
      await api('lane-decide', { decision });
      toast(decision === 'handover' ? 'עוברים לצ\'אט עד ש-Platform יחזור' : 'ההודעות ממתינות ל-Platform', 'ok');
    });
    return b;
  };
  const what = p.ask_pending
    ? 'מה לעשות עם ההודעות? עד שתחליט — הן ממתינות.'
    : p.handover ? 'ההודעות עוברות לצ\'אטים — שום דבר לא נתקע.' : 'לפי ההגדרה שלך ההודעות ממתינות עד שיחזור.';
  box.replaceChildren(
    el('span', { class: 'ps-line', text: `⛔ ☁️ Platform נעצר — ${STATE_TEXT[p.state] || p.state}.` }),
    el('span', { class: 'ps-line ps-sub', text: what }),
    p.ask_pending ? el('span', { class: 'ps-acts ps-ask' }, [decide('handover', '💬 לעבור לצ\'אט', 'primary'), decide('wait', '⏳ להמתין', 'ghost')]) : null,
    el('span', { class: 'ps-line ps-sub', text: whenText(p) }),
    el('span', { class: 'ps-acts' }, [
      el('button', { type: 'button', class: 'btn sm ghost', text: '🔌 הגדרות ← חיבורים', onclick: () => openConnections?.() }),
      retry,
    ]),
  );
  return true;
}

const GROUPS = [
  { key: 'preferred', title: 'השיטה המועדפת', hint: 'שיח חדש בלוח נפתח בה.',
    opts: [['chat', '💬 צ\'אט'], ['platform', '☁️ Platform'], ['', 'לפי הפרויקט']] },
  { key: 'on_stop', title: 'כשהשיטה שהשיח עובד בה נעצרת', hint: 'למשל: נגמר הקרדיט ב-Platform, או נגמרו הטוקנים בצ\'אט. אותה הגדרה לשני הכיוונים.',
    opts: [['auto', '🔁 לעבור אוטומטית לשנייה'], ['ask', '❓ לשאול אותי'], ['wait', '⏳ להמתין']] },
  { key: 'return_preferred', title: 'לעבור לשיטה המועדפת גם באמצע שיח, כשהיא זמינה', hint: 'כן — שיח שעובד בשיטה השנייה עובר לבד למועדפת ברגע שאפשר.',
    opts: [['yes', 'כן'], ['no', 'לא']] },
];

const SUMMARY = {
  auto: 'נעצרת — עוברים מיד לשיטה השנייה, והצ\'אט מקבל שורה אחת על כך.',
  ask: 'נעצרת — שאלה בלוח עם "לעבור" / "להמתין". עד שעונים, ההודעות ממתינות.',
  wait: 'נעצרת — ההודעות ממתינות עד שהיא חוזרת.',
};

/** הגדרות ← 🔀 מסלולים. */
export async function renderLanesPanel(box) {
  if (!box) return;
  let prefs;
  try { ({ lane_prefs: prefs } = await api('lane-prefs')); } catch (ex) { box.replaceChildren(el('p', { class: 'hint', text: ex.message })); return; }
  const set = async (key, v) => {
    try { ({ lane_prefs: prefs } = await api('lane-prefs', { prefs: { [key]: v } })); toast('נשמר', 'ok'); } catch (ex) { toast(ex.message, 'error'); }
    draw();
  };
  const draw = () => box.replaceChildren(
    el('p', { class: 'hint', text: 'איך מתנהלים השיחים בלוח בערוצים שאתה משלם עליהם — ב-💬 צ\'אט (טוקנים של המנוי) או ב-☁️ Platform (קרדיט). ההגדרות שלך, לא של הלוח.' }),
    ...GROUPS.map((g) => el('div', { class: 'lane-pref' }, [
      el('b', { text: g.title }),
      el('div', { class: 'seg', role: 'radiogroup', 'aria-label': g.title }, g.opts.map(([v, t]) => el('button', {
        type: 'button', role: 'radio', 'aria-checked': String(prefs[g.key] === v), onclick: () => set(g.key, v) }, [t]))),
      el('small', { class: 'hint', text: g.key === 'on_stop' ? SUMMARY[prefs.on_stop] : g.hint }),
    ])),
    el('p', { class: 'hint', text: 'צ\'אט שנגמרו לו הטוקנים — זיהוי אוטומטי בשלב הבא. עד אז ההגדרה חלה כש-☁️ Platform נעצר.' }),
  );
  draw();
}
