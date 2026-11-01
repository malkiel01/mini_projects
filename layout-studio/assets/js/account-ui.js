// 👤 תפריט המשתמש ו-📁 "המסמכים שלי" — ui-core (account.js, files-window.js), אותו דבר בספרייה ובעורך.
// ההגדרות האישיות נשמרות במסד שלנו (api.php → lib/ui-core/account), וה"מסמכים שלי" הם הספרייה בשרת, דרך מתאם קטן.

import { accountMenu, accountServer } from './ui-core/account.js';
import { openDocuments } from './ui-core/files-window.js';

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
  let server = null;
  try { server = await accountServer((action, body) => api(action, body)); } catch { /* בלי שרת — ההגדרות במכשיר */ }
  return accountMenu(bar, {
    user: () => me && { name: me.name, email: me.email, admin: me.role === 'admin' },
    server,
    docs: () => openDocuments([library], { onOpen: item => { location.href = 'editor.html?id=' + item.id; } }),
    shortcuts,
    help: () => window.open('https://github.com/malkiel01/mini_projects/tree/main/layout-studio#readme', '_blank', 'noopener'),
  });
}
