/*
 * שני מסלולים — docs/spec.md §15. שלב 15: Platform נעצר (§15.3), וההגדרה של המשתמש (§15.11).
 *
 * הפס האדום במסך הראשי כשהמפסק פתוח: הסיבה, מה קורה עכשיו (עובר לצ'אט / ממתין), איפה מתקנים (הגדרות ←
 * חיבורים), ו"נסה שוב" — קריאת בדיקה אחת. בהגדרות ← 🔀 מסלולים: מה לעשות כש-Platform נעצר.
 */
import { $, el, api, toast } from './core.js';

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
  box.className = 'quota-warn quota-warn--over platform-stop';
  const retry = el('button', { type: 'button', class: 'btn sm', text: '↻ נסה שוב', onclick: async () => {
    retry.disabled = true;
    try {
      const { platform } = await api('platform-retry');
      toast(platform.open ? `עדיין לא עובד — ${platform.error || STATE_TEXT[platform.state] || ''}` : '☁️ Platform חזר', platform.open ? 'error' : 'ok');
    } catch (ex) { toast(ex.message, 'error'); }
    retry.disabled = false;
    onChange?.();
  } });
  box.replaceChildren(
    el('span', { class: 'ps-line', text: `⛔ ☁️ Platform נעצר — ${STATE_TEXT[p.state] || p.state}.` }),
    el('span', { class: 'ps-line ps-sub', text: p.handover
      ? 'ההודעות עוברות לצ\'אטים — שום דבר לא נתקע.'
      : 'לפי ההגדרה שלך ההודעות ממתינות עד שיחזור.' }),
    el('span', { class: 'ps-line ps-sub', text: whenText(p) }),
    el('span', { class: 'ps-acts' }, [
      el('button', { type: 'button', class: 'btn sm ghost', text: '🔌 הגדרות ← חיבורים', onclick: () => openConnections?.() }),
      retry,
    ]),
  );
  return true;
}

/** הגדרות ← 🔀 מסלולים. */
export async function renderLanesPanel(box) {
  if (!box) return;
  let prefs;
  try { ({ lane_prefs: prefs } = await api('lane-prefs')); } catch (ex) { box.replaceChildren(el('p', { class: 'hint', text: ex.message })); return; }
  const set = async (v) => {
    try { ({ lane_prefs: prefs } = await api('lane-prefs', { prefs: { platform_stop: v } })); toast('נשמר', 'ok'); } catch (ex) { toast(ex.message, 'error'); }
    draw();
  };
  const draw = () => box.replaceChildren(
    el('p', { class: 'hint', text: 'מה קורה בערוצים שאתה משלם עליהם, כשמסלול אחד נעצר. ההגדרה שלך — לא של הלוח.' }),
    el('div', { class: 'lane-pref' }, [
      el('b', { text: 'כש-☁️ Platform נעצר (קרדיט נגמר, מפתח נדחה, עומס)' }),
      el('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'כש-Platform נעצר' }, [
        ['handover', '✅ להעביר לצ\'אט אוטומטית'],
        ['wait', '❌ לא — ההודעות ממתינות'],
      ].map(([v, t]) => el('button', { type: 'button', role: 'radio', 'aria-checked': String(prefs.platform_stop === v),
        onclick: () => set(v) }, [t]))),
      el('small', { class: 'hint', text: prefs.platform_stop === 'handover'
        ? 'הצ\'אט מקבל את ההודעות וממשיך מאותה נקודה, ושורה אחת אומרת לו ש-Platform נעצר. כשהוא חוזר — הערוץ חוזר ל-☁️.'
        : 'ההודעות נשארות ל-Platform, ושום דבר לא נענה עד שהוא חוזר.' }),
    ]),
    el('p', { class: 'hint', text: 'עוד הגדרות (צ\'אט שלא עונה, חזרה, זמן שקט) — בשלבים הבאים.' }),
  );
  draw();
}
