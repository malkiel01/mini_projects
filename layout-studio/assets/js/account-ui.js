// 👤 תפריט המשתמש ו-📁 "המסמכים שלי" — ui-core (account.js, files-window.js), אותו דבר בספרייה ובעורך.
// ההגדרות האישיות נשמרות במסד שלנו (api.php → lib/ui-core/account), וה"מסמכים שלי" הם הספרייה בשרת, דרך מתאם קטן,
// ולצידה ☁️ ענן החבילה (מודול הכוננים — assets/js/drives, עותק מסונכרן): ‎.mbe מהענן נכנס לספרייה ונפתח בעורך.

import { accountMenu, accountServer } from './ui-core/account.js';
import { openDocuments, suiteSource } from './ui-core/files-window.js';
import { toast } from './ui-core/toast.js';
import { createDrives } from './drives/drives.js';

/** ‎.mbe (File) → מסמך חדש בספרייה (השרת בודק אותו בליבה). מחזיר את המזהה; זורק Error עם קוד השרת. */
export async function importMbe(file) {
  let res;
  try {
    res = await fetch('api.php?action=doc_import&name=' + encodeURIComponent(file.name), { method: 'POST', credentials: 'same-origin',
      headers: { 'X-Layout-Studio': '1', 'Content-Type': 'application/vnd.mbe+zip' }, body: file });
  } catch { throw new Error('network'); }
  const out = await res.json().catch(() => ({ ok: false, error: res.status === 413 ? 'too_big' : 'server' }));
  if (!out.ok) throw new Error(out.error || 'server');
  return out.doc.id;
}

/**
 * bar — הסרגל העליון; api(action, data) — הקריאה לשרת (זורקת Error עם קוד); me — { name, email, role };
 * shortcuts — רשימת הקיצורים (ui-core/keys.js), אם יש.
 */
export async function mountAccount(bar, { api, me, shortcuts = null }) {
  const library = {
    id: 'app', title: 'באפליקציה', icon: '📖',
    async list() {
      const { docs } = await api('docs_list');
      // בלי ".mbe" בשם — בעברית הסיומת הלטינית מתהפכת לצד השני; הסמל אומר שזה מסמך עימוד
      return { items: docs.map(d => ({ id: d.id, name: d.title, kind: 'file', icon: '📖', updated: d.updated_at })) };
    },
    rename: (id, name) => api('doc_rename', { id, title: name }),
    remove: id => api('doc_delete', { id }),
  };
  // ☁️ ענן החבילה: קובץ ‎.mbe משם — עולה לספרייה (כמו 📂 מהמכשיר) ונפתח בעורך
  let drives = null;
  try { drives = createDrives({ endpoint: 'api.php' }); } catch { /* בלי המודול — רק הספרייה */ }
  const sources = [library, drives && suiteSource(drives)].filter(Boolean);
  const onOpen = async (item, src) => {
    if (src.id === 'app') { location.href = 'editor.html?id=' + item.id; return; }
    if (!/\.mbe$/i.test(item.name)) { toast('בעימוד נפתחים קובצי ‎.mbe (תמונה — דרך "🖼 תמונה" בחלון העמוד)', true); return; }
    try {
      toast('פותח מהענן…');
      const blob = await src.read(item.id);
      location.href = 'editor.html?id=' + await importMbe(new File([blob], item.name, { type: 'application/vnd.mbe+zip' }));
    } catch (e) {
      const m = e?.message || '';
      toast(m.startsWith('mbe_kind') ? 'זה לא קובץ עימוד' : m.startsWith('mbe_invalid') ? 'הקובץ פגום' : 'הקובץ לא נפתח' + (m ? ': ' + m : ''), true);
    }
  };

  let server = null;
  try { server = await accountServer((action, body) => api(action, body)); } catch { /* בלי שרת — ההגדרות במכשיר */ }
  return accountMenu(bar, {
    user: () => me && { name: me.name, email: me.email, admin: me.role === 'admin' },
    server,
    docs: () => openDocuments(sources, { onOpen }),
    shortcuts,
    help: () => window.open('https://github.com/malkiel01/mini_projects/tree/main/layout-studio#readme', '_blank', 'noopener'),
  });
}
