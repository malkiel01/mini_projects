// אפליקציית מתכונים — שכבת החשבונות בצד הדפדפן.
//
// אין כאן ספרייה בכוונה: הדף הזה צריך טופס, fetch והחלפת מסך, וזה הכול.
// כשייכנסו המתכונים, המצב יגדל — ואז נדע מה באמת נדרש, במקום לנחש עכשיו.

const api = async (action, payload) => {
  const res = await fetch(`./api.php?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    // same-origin כדי שעוגיית הסשן תישלח; בלעדיה כל בקשה היא אורח.
    credentials: 'same-origin',
    body: JSON.stringify(payload || {}),
  });

  let data;
  try {
    data = await res.json();
  } catch {
    // שגיאת PHP שנפלה לפני ה-JSON מגיעה לכאן כ-HTML. עדיף לומר את זה
    // מפורש מלהציג "undefined" למשתמש.
    throw new Error('השרת החזיר תשובה שאינה תקינה');
  }
  if (!data.success) throw new Error(data.error || 'שגיאה לא מזוהה');
  return data;
};

const $ = (sel) => document.querySelector(sel);
const guest = $('#guest');
const app = $('#app');
const msg = $('#guest-msg');
const who = $('#who');
const logoutBtn = $('#logout');

function say(text, kind) {
  msg.textContent = text;
  msg.className = 'note' + (kind ? ` note--${kind}` : '');
  msg.hidden = !text;
}

function showPane(name) {
  for (const pane of document.querySelectorAll('.pane')) {
    pane.hidden = pane.dataset.pane !== name;
  }
  for (const tab of document.querySelectorAll('.tab')) {
    tab.classList.toggle('is-on', tab.dataset.pane === name);
  }
  say('');
}

function showUser(user) {
  const signedIn = Boolean(user);
  guest.hidden = signedIn;
  app.hidden = !signedIn;
  who.hidden = !signedIn;
  logoutBtn.hidden = !signedIn;
  if (signedIn) who.textContent = user.display_name || user.username;
}

/** מריץ פעולה ומחזיר את הכפתור למצבו גם כשנכשלה — אחרת הוא נשאר מושבת. */
async function withButton(form, fn) {
  const btn = form.querySelector('button[type="submit"]');
  const label = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'רגע…';
  try {
    await fn();
  } catch (err) {
    say(err.message, 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = label;
  }
}

for (const tab of document.querySelectorAll('.tab')) {
  tab.addEventListener('click', () => showPane(tab.dataset.pane));
}
for (const btn of document.querySelectorAll('[data-goto]')) {
  btn.addEventListener('click', () => showPane(btn.dataset.goto));
}
$('#forgot').addEventListener('click', () => showPane('forgot'));

$('#login-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const f = e.target;
  withButton(f, async () => {
    const { user } = await api('login', {
      username: f.username.value,
      password: f.password.value,
    });
    f.reset();
    showUser(user);
  });
});

$('#register-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const f = e.target;
  withButton(f, async () => {
    const res = await api('register', {
      username: f.username.value,
      display_name: f.display_name.value,
      email: f.email.value,
      password: f.password.value,
    });
    f.reset();
    showPane('login');
    // כשל שליחה אינו כשל הרשמה, ולכן ההודעה שונה אך אינה שגיאה: החשבון
    // קיים, והוא ממתין לאימות.
    say(res.message, res.mail_sent ? 'ok' : 'warn');
  });
});

$('#forgot-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const f = e.target;
  withButton(f, async () => {
    const res = await api('request-reset', { email: f.email.value });
    f.reset();
    showPane('login');
    say(res.message, 'ok');
  });
});

logoutBtn.addEventListener('click', async () => {
  try {
    await api('logout');
  } finally {
    // גם אם הבקשה נכשלה, הדפדפן חוזר למסך האורח. סשן שנשאר פתוח בשרת
    // פחות מזיק ממסך שמראה למשתמש שהוא מחובר כשהוא לא.
    showUser(null);
    showPane('login');
  }
});

// טעינה ראשונה: מי אני. אורח מקבל את מסך הכניסה, ולא שגיאה.
api('me')
  .then(({ user }) => showUser(user))
  .catch(() => {
    showUser(null);
    say('לא הצלחתי להגיע לשרת. יש לרענן את הדף.', 'err');
  });
