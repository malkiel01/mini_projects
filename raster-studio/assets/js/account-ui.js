// 👤 תפריט המשתמש ו-📁 "המסמכים שלי" — ui-core (account.js, files-window.js), אותו דבר בספרייה ובעורך.
// ההגדרות האישיות נשמרות במסד שלנו (api.php → lib/ui-core/account). "המסמכים שלי": הספרייה בשרת (מתאם קטן) וענן החבילה
// (מודול הכוננים). הקטע של עיצוב רסטר בהגדרות: מנוע הציור — של המכשיר (localStorage), כי הוא תלוי בכרטיס הגרפי.

import { accountMenu, accountServer } from './ui-core/account.js';
import { openDocuments, suiteSource } from './ui-core/files-window.js';
import { h } from './ui-core/dom.js';
import { toast } from './ui-core/toast.js';
import { idbSet } from './idb.js';

export const ENGINE_KEY = 'raster-studio:engine';
/** מנוע הציור שנבחר במכשיר הזה: 'auto' (WebGPU כשיש) או 'webgl2' */
export function enginePref() {
  try { return localStorage.getItem(ENGINE_KEY) === 'webgl2' ? 'webgl2' : 'auto'; } catch { return 'auto'; }
}

/**
 * bar — הסרגל העליון; api(action, data) — הקריאה לשרת; me — { name, email, role }; drives — מודול הכוננים (רשות);
 * engine — שם המנוע שרץ עכשיו (בעורך), לתצוגה.
 */
export async function mountAccount(bar, { api, me, drives = null, engine = '' }) {
  const library = {
    id: 'app', title: 'באפליקציה', icon: '🎨',
    async list() {
      const { docs } = await api('docs_list');
      // בלי ".mbe" בשם — בעברית הסיומת הלטינית מתהפכת לצד השני
      return { items: docs.map(d => ({ id: d.id, name: d.title, kind: 'file', icon: '🖼️', updated: d.updated_at })) };
    },
    rename: (id, name) => api('doc_rename', { id, title: name }),
    remove: id => api('doc_delete', { id }),
  };
  const sources = [library, drives && suiteSource(drives)].filter(Boolean);
  // מסמך מהספרייה — העורך פותח אותו מהשרת; קובץ מהענן — כמו קובץ מהמכשיר (דרך IndexedDB, כמו פתיחה מאפליקציה אחרת)
  const onOpen = async (item, src) => {
    if (src.id === 'app') { location.href = 'editor.html?doc=' + item.id; return; }
    try {
      const blob = await src.read(item.id);
      await idbSet('pending-open', new File([blob], item.name, { type: blob.type }));
      location.href = 'editor.html?open=pending';
    } catch (e) { toast(e?.message || 'הקובץ לא נפתח', true); }
  };

  let server = null;
  try { server = await accountServer((action, body) => api(action, body)); } catch { /* בלי שרת — ההגדרות במכשיר */ }
  return accountMenu(bar, {
    user: () => me && { name: me.name, email: me.email, admin: me.role === 'admin' },
    server,
    docs: () => openDocuments(sources, { onOpen }),
    sections: [{
      id: 'raster-studio', title: 'עיצוב רסטר',
      render: body => {
        const sel = h('select', { name: 'engine' },
          h('option', { value: 'auto' }, 'אוטומטי — WebGPU כשיש, אחרת WebGL2'),
          h('option', { value: 'webgl2' }, 'WebGL2 תמיד'));
        sel.value = enginePref();
        sel.addEventListener('change', () => {
          try { localStorage.setItem(ENGINE_KEY, sel.value); } catch { /* פרטי / חסום */ }
          toast('ייכנס לתוקף בפתיחה הבאה של העורך');
        });
        body.append(h('label.field', h('span', 'מנוע הציור (במכשיר הזה)'), sel),
          engine ? h('p.hint', `עכשיו: ${engine}`) : null);
      },
    }],
    help: () => window.open('https://github.com/malkiel01/mini_projects/tree/main/raster-studio#readme', '_blank', 'noopener'),
  });
}
