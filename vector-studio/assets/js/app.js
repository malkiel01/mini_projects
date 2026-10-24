// וקטורי — דף הפתיחה. בשלד: מי נכנס, ומקום לספרייה.

const $ = id => document.getElementById(id);

async function api(action) {
  const res = await fetch('api.php?action=' + encodeURIComponent(action), { credentials: 'same-origin' });
  const data = await res.json().catch(() => ({ success: false, error: 'תשובה לא תקינה מהשרת' }));
  if (!data.success) throw Object.assign(new Error(data.error || 'שגיאה'), { status: res.status });
  return data;
}

function initials(name) {
  return name.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('');
}

async function start() {
  try {
    const { user } = await api('me');
    const me = $('me');
    me.textContent = initials(user.name);
    me.title = user.name;
    me.setAttribute('aria-label', 'מחובר: ' + user.name);
    me.hidden = false;
    $('home').hidden = false;
  } catch (e) {
    $('denied-msg').textContent = e.message;
    $('denied').hidden = false;
  } finally {
    $('loading').hidden = true;
  }
}

start();
