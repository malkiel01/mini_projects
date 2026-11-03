/*
 * שלב 20 (docs/spec.md §16) — מודל ורמת חשיבה: הגיליון (לשיחה / להודעה הבאה בלבד), השבב בשורת הכתיבה,
 * שורת המטא בבועה של Platform והגיליון שלה, וכרטיס המכסה (ai_card). הממשק לא מכריע — השרת (resolveAi) מכריע.
 */
import { $, el, api, toast } from './core.js';

export const EFFORT_LABEL = { auto: 'אוטומטי', low: 'נמוכה', medium: 'בינונית', high: 'גבוהה', max: 'מרבית' };

/** "claude-opus-5-5" → "Opus 5.5" (כמו modelShortName בשרת). */
export function shortModel(id = '') {
  const m = /^claude-(opus|sonnet|haiku|fable|mythos)-(\d+)(?:-(\d{1,2}))?(?!\d)/.exec(id);
  return m ? `${m[1][0].toUpperCase()}${m[1].slice(1)} ${m[2]}${m[3] ? '.' + m[3] : ''}` : id;
}

export const aiChipText = (ai) => ai?.model ? `${shortModel(ai.model)} · חשיבה ${ai.effort && ai.effort !== 'auto' ? EFFORT_LABEL[ai.effort] : 'אוטומטית'}` : 'מודל';

/**
 * הגיליון. mode: 'channel' (⚙ — נשמר לשיחה) | 'message' (השבב — להודעה הבאה בלבד, עם "השאר לשיחה הזו").
 * onPick(pref|null, {keep}) — pref ‏{provider, model, effort}; null = ברירת המחדל.
 */
export async function openAiSheet({ channelId, mode, current, onPick }) {
  let data;
  try { data = await api('ai-options', { channel_id: channelId, refresh: 1 }); } catch (ex) { toast(ex.message, 'error'); return; }
  const conns = data.connections || [];
  const chan = data.channel || {};
  const first = conns[0];
  let pick = current?.model ? { ...current } : null;   // null = ברירת מחדל
  $('#aiSheetT').textContent = mode === 'message' ? '🧠 להודעה הבאה בלבד' : '🧠 מודל לשיחה הזו';
  $('#aiSheetHint').textContent = mode === 'message'
    ? 'הבחירה חלה על ההודעה הבאה בלבד, ואחר כך השבב חוזר לבחירה של השיחה. העלות היחסית ליד כל מודל.'
    : 'Platform עונה בשיחה הזו במודל שנבחר כאן. מעקב ותקצירים — תמיד בברירת המחדל של החיבור (זול).';
  $('#aiSheetNotice').hidden = !chan.notice;
  $('#aiSheetNotice').textContent = chan.notice || '';
  $('#aiKeep').hidden = mode !== 'message';

  const levelsOf = () => {
    if (!pick) return [first?.effort || 'auto'];
    for (const c of conns) for (const m of c.models) if (c.provider === pick.provider && m.id === pick.model) return m.levels;
    return ['auto'];
  };
  const renderEffort = () => {
    const levels = levelsOf();
    if (pick && !levels.includes(pick.effort)) pick.effort = 'auto';
    $('#aiEffort').replaceChildren(...['auto', 'low', 'medium', 'high', 'max'].map((v) => el('button', {
      type: 'button', role: 'radio', 'aria-checked': String((pick ? pick.effort : first?.effort || 'auto') === v),
      disabled: !pick || !levels.includes(v), text: EFFORT_LABEL[v],
      onclick: () => { pick.effort = v; renderEffort(); },
    })));
    $('#aiEffortHint').textContent = !pick ? 'בברירת המחדל — הרמה של החיבור (הגדרות ← חיבורים).'
      : levels.length === 1 ? 'למודל הזה אין שליטה ברמת החשיבה.' : 'רמה גבוהה יותר — תשובה מעמיקה יותר, ויקרה ואיטית יותר.';
  };
  const row = (label, sub, selected, fn) => el('li', {
    class: `card${selected ? ' is-on' : ''}`, tabindex: '0', role: 'radio', 'aria-checked': String(selected), 'aria-label': label,
    onclick: fn, onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(); } },
  }, [el('span', { class: 'ic', 'aria-hidden': 'true', text: selected ? '●' : '○' }),
      el('div', { class: 'body' }, [el('div', { class: 'l1' }, [el('span', { text: label })]), sub ? el('div', { class: 'l2', text: sub }) : null].filter(Boolean))]);
  const render = () => {
    const items = [row(`ברירת מחדל (${shortModel(first?.model || '') || 'אין חיבור'})`, first ? `${first.label} · חשיבה ${EFFORT_LABEL[first.effort] || 'אוטומטי'}` : '',
      !pick, () => { pick = null; render(); })];
    const seen = new Set();
    for (const c of conns) {
      for (const m of c.models) {
        const k = `${c.provider}:${m.id}`;
        if (seen.has(k)) continue;
        seen.add(k);
        const x = m.cost_x == null ? '' : ` · ×${m.cost_x}`;
        items.push(row(m.label || m.id, `${c.label}${x}`, !!pick && pick.provider === c.provider && pick.model === m.id,
          () => { pick = { provider: c.provider, model: m.id, effort: pick?.effort || 'auto' }; render(); }));
      }
    }
    $('#aiModels').replaceChildren(...items);
    renderEffort();
  };
  render();
  const done = (keep) => { $('#aiSheet').close(); onPick(pick, { keep }); };
  $('#aiApply').onclick = () => done(false);
  $('#aiKeep').onclick = () => done(true);
  $('#aiSheet').showModal();
}

/** שורת המטא בבועה: "☁️ Opus 5.5 · חשיבה גבוהה · $0.04", ו"⚡ להודעה הזו" כשנבחר להודעה. */
export function aiUsedLine(m, override) {
  const u = m.ai_used;
  if (!u?.model) return null;
  const txt = `☁️ ${shortModel(u.model)} · חשיבה ${u.effort && u.effort !== 'auto' ? EFFORT_LABEL[u.effort] : 'אוטומטית'} · $${Number(u.cost_usd || 0).toFixed(2)}${override ? ' · ⚡ להודעה הזו' : ''}${u.fell_back_from ? ' · ↪' : ''}`;
  return el('button', { type: 'button', class: 'b-aiused', text: txt, 'aria-label': `פרטי המודל שענה: ${txt}`, onclick: () => openAiUsed(u, override) });
}

function openAiUsed(u, override) {
  const rows = [
    ['מודל', u.model], ['חיבור', u.label || '—'], ['רמת חשיבה', u.effort && u.effort !== 'auto' ? EFFORT_LABEL[u.effort] : 'אוטומטית'],
    ['טוקנים — קלט', String(u.tokens_in ?? '—')], ['טוקנים — פלט', String(u.tokens_out ?? '—')],
    ['טוקנים — חשיבה', u.thinking_tokens == null ? 'כלולים בפלט' : String(u.thinking_tokens)],
    ['עלות', `$${Number(u.cost_usd || 0).toFixed(4)}`],
    ['נבחר', { message: '⚡ להודעה הזו', channel: 'לשיחה', choice: 'כמו השאלה ששאל', task: 'למטלה' }[u.source] || 'ברירת המחדל של החיבור'],
    u.fell_back_from ? ['מעבר', `עבר מ-${u.fell_back_from}`] : null,
  ].filter(Boolean);
  $('#aiUsedRows').replaceChildren(...rows.map(([k, v]) => el('div', { class: 'kv' }, [el('span', { text: k }), el('b', { dir: 'auto', text: v })])));
  $('#aiUsed').showModal();
}

/** כרטיס המכסה (§16.4): "שלח ב-<מודל הצ'אט>" / "בטל". אף פעם לא הורדת מודל בשקט. */
export function aiCard(m, { canDecide, onDone }) {
  const x = m.meta || {};
  const answer = async (choice, btn) => {
    btn.disabled = true;
    try {
      const r = await api('ai-card-answer', { card_id: m.id, choice });
      toast(choice === 'send' ? `נשלח ב-${x.alt?.name}` : 'בוטל — לא נשלח', 'ok');
      onDone(r);
    } catch (ex) { toast(ex.message, 'error'); btn.disabled = false; }
  };
  const done = m.status !== 'open';
  return el('div', { class: 'lane-card' }, [
    el('b', { text: '💰 ' + m.body }),
    el('p', { class: 'hint', text: 'כדי לא לעבור את המכסה — לא נשלח לספק. בוחרים:' }),
    done ? el('p', { class: 'hint', text: x.decision === 'send' ? `✓ נשלח ב-${x.alt?.name || 'מודל השיחה'}.` : '✕ בוטל — ההודעה לא נשלחה.' })
      : canDecide ? el('div', { class: 'row-btns' }, [
        x.alt ? el('button', { type: 'button', class: 'btn primary', text: `שלח ב-${x.alt.name}`, onclick: (e) => answer('send', e.currentTarget) }) : null,
        el('button', { type: 'button', class: 'btn ghost', text: 'בטל', onclick: (e) => answer('cancel', e.currentTarget) }),
      ].filter(Boolean)) : el('p', { class: 'hint', text: 'ממתין להחלטה של בעל הלוח.' }),
    !done && !x.alt ? el('p', { class: 'hint', text: 'גם במודל של השיחה זה עובר את המכסה — אפשר לבטל, או להגדיל מכסה בהגדרות ← שימוש.' }) : null,
  ]);
}
