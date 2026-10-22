'use strict';
/*
 * תצוגת חודש — משותפת לעובד (e/) ולכרטיס העובד אצל המנהל. מקבלת את התשובה של monthReport.
 * ימים בלי כלום ובלי תקן (שבת, יום חופש) לא מוצגים; יום עבודה שעבר בלי דיווח — "לא דווח".
 * החיצים ‹ › נכתבים לפי המשמעות (הקודם / הבא) — בעברית הדפדפן הופך אותם לבד.
 */
function monthHtml(r) {
  const TZ = 'Asia/Jerusalem';
  const hm = m => (m < 0 ? '−' : '') + Math.floor(Math.abs(m) / 60) + ':' + String(Math.abs(m) % 60).padStart(2, '0');
  const t = iso => new Date(iso).toLocaleTimeString('he-IL', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
  const [y, mo] = r.month.split('-').map(Number);
  const title = new Date(Date.UTC(y, mo - 1, 15)).toLocaleDateString('he-IL', { month: 'long', year: 'numeric', timeZone: 'UTC' });
  const s = r.summary;
  const rows = r.days.filter(d => d.date <= r.today && (d.pairs.length || d.open || d.missing_out || d.missing_in || d.standard)).reverse();
  const dayName = d => new Date(d + 'T12:00:00Z').toLocaleDateString('he-IL', { weekday: 'short', day: 'numeric', month: 'numeric', timeZone: 'UTC' });
  return `
    <div class="toolbar month-nav">
      <button class="icon-btn" id="prev" type="button" aria-label="החודש הקודם">‹</button>
      <b class="one" style="flex:1;text-align:center">${title}</b>
      <button class="icon-btn" id="next" type="button" aria-label="החודש הבא"${r.month >= r.today.slice(0, 7) ? ' disabled' : ''}>›</button>
    </div>
    <dl class="cells" style="grid-template-columns:repeat(4,1fr)">
      <div><dt>ימים</dt><dd>${s.days}</dd></div>
      <div><dt>שעות</dt><dd>${hm(s.minutes)}</dd></div>
      <div><dt>תקן עד היום</dt><dd>${hm(s.standard)}</dd></div>
      <div><dt>הפרש</dt><dd>${hm(s.diff)}</dd></div>
    </dl>
    ${s.missing ? `<p class="hint">⚠️ ${s.missing} דיווחים חסרים — תיקון יתאפשר בקרוב. בינתיים מעדכנים את המנהל.</p>` : ''}
    <p class="hint">ההפרש הוא מול שעות התקן בלבד, ואינו חישוב שעות נוספות.</p>
    <ul class="list">${rows.length ? rows.map(d => {
      const spans = d.pairs.map(p => t(p.in) + '–' + t(p.out));
      if (d.open) spans.push(t(d.open) + '–…');
      const chips = [d.missing_out ? '<span class="chip bad">חסר יציאה</span>' : '', d.missing_in ? '<span class="chip bad">חסר כניסה</span>' : '',
        d.open ? '<span class="chip ok">בעבודה</span>' : '',
        !spans.length && !d.missing_out && !d.missing_in && d.standard ? '<span class="chip">לא דווח</span>' : ''].join('');
      return `<li class="card"><div class="body"><div class="l1"><span>${dayName(d.date)}</span><span class="t">${d.minutes ? hm(d.minutes) : ''}</span></div>
        <div class="l2" dir="ltr" style="text-align:end">${spans.join(' · ') || '—'}</div></div>${chips}</li>`;
    }).join('') : '<li class="empty">אין ימים להצגה בחודש הזה.</li>'}</ul>`;
}
