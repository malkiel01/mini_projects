// דשבורד חבילת היצירה — העתקת פרומפטים, וזכירת קבוצות פתוחות / סגורות.
(() => {
  const live = document.getElementById('live');
  const say = msg => { if (live) { live.textContent = ''; setTimeout(() => { live.textContent = msg; }, 30); } };

  document.addEventListener('click', async e => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const text = btn.closest('.prompt').querySelector('pre').textContent;
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      const ta = Object.assign(document.createElement('textarea'), { value: text });
      ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.append(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    btn.textContent = '✓ הועתק'; btn.classList.add('ok');
    say('הפרומפט הועתק. הדבק אותו בצ\'אט.');
    setTimeout(() => { btn.textContent = '📋 העתקה'; btn.classList.remove('ok'); }, 2000);
  });

  const KEY = 'cs-dash-groups';
  let state = {};
  try { state = JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch {}
  document.querySelectorAll('details.group[data-id]').forEach(d => {
    if (d.dataset.id in state) d.open = state[d.dataset.id];
    d.addEventListener('toggle', () => {
      state[d.dataset.id] = d.open;
      try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch {}
    });
  });
})();
