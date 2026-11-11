/*
 * ▶️ התצוגה החיה (פרוסה F4) — הקוד של live.php: המסגרת המבודדת, מצבי המכשיר, המסוף וכלי הבדיקה.
 *
 * הבידוד (00 §8): המסגרת נטענת **מכתובת** (preview/<אסימון>/…), עם sandbox בלי allow-same-origin ובלי
 * allow-top-navigation — קוד העמוד רץ במקור אטום, בלי הסשן. **S5: כל הודעה מהמסגרת היא קלט לא מהימן.** מתקבלים רק
 * ארבעה סוגי דיווח (שורת מסוף, כותרת, "נטען", "נלחץ אלמנט"); כל שדה נחתך ומומר לטקסט או למספר; כל מחרוזת מוצגת
 * ב-textContent בלבד; ואף הודעה אינה מפעילה פעולה — לא שמירה, לא פרסום, לא ניווט של המסך הזה.
 */
'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  const q = new URLSearchParams(location.search);
  const EMBED = q.has('embed');
  const SANDBOX = 'allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-presentation';
  const MAX_LINES = 500, MAX_BURST = 300;
  const LIMITS = { storage: 'אחסון בדפדפן (localStorage, עוגיות)', sw: 'Service Worker', embed: 'נגן מוטמע (יוטיוב, וימאו)' };
  const LEVELS = { log: '', info: 'ℹ️', warn: '⚠️', error: '⛔', debug: '·' };

  /** נתיב יחסי בתוך הפרויקט, או ''. כל נתיב — מהכתובת או מהמסגרת — עובר כאן לפני שנבנית ממנו כתובת. */
  const safePath = p => (typeof p === 'string' && p.length <= 400 && !/[\u0000-\u001f\\]/.test(p) && !p.startsWith('/')
    && p.split('/').every(s => s !== '' && s !== '.' && s !== '..')) ? p : '';
  const str = (v, n) => typeof v === 'string' ? v.slice(0, n) : '';
  const int = v => Number.isInteger(v) && v > 0 && v < 1e7 ? v : 0;

  const S = { tok: null, path: safePath(q.get('path') || ''), frame: null, width: 0, title: '', picking: false,
    lines: [], pending: [], errors: 0, limits: new Set(), ignored: 0, dropped: 0, burst: 0, tab: 'console' };
  let bc = null;
  try { bc = new BroadcastChannel('kol-pf'); } catch { /* דפדפן ישן: בלי רענון אוטומטי ובלי "פתח בקוד" */ }

  function toast(msg, err = false) {
    const t = document.querySelector('.toast');
    t.textContent = msg; t.classList.toggle('err', err); t.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => { t.hidden = true; }, 2600);
  }

  // ── האסימון והכתובת ───────────────────────────────────────
  async function token() {
    let res, data;
    try { res = await fetch('api.php?action=pv_token'); data = await res.json(); } catch { throw new Error(res ? 'תשובה לא תקינה מהשרת' : 'אין חיבור לשרת'); }
    if (!data.ok) throw new Error(data.error || 'שגיאה');
    S.tok = data;
    drawDevices();
    return data;
  }
  const url = () => new URL('preview/' + S.tok.token + '/' + (S.path || S.tok.entry).split('/').map(encodeURIComponent).join('/'), location.href).href;
  const fresh = async () => { if (!S.tok || S.tok.exp - Date.now() / 1000 < 120) await token(); };

  // ── המסגרת ────────────────────────────────────────────────
  /** מסגרת חדשה. גם "עצור ורענן": הסרת המסגרת הישנה עוצרת את הקוד שרץ בה, גם לולאה אינסופית. */
  async function mount() {
    try { await fresh(); } catch (e) { $('lv-msg').hidden = false; $('lv-msg').textContent = e.message; $('lv-msg').className = 'error'; return; }
    S.frame?.remove();
    S.limits.clear(); drawLimits();
    const f = document.createElement('iframe');
    f.setAttribute('sandbox', SANDBOX);                   // בלי allow-same-origin, בלי allow-top-navigation
    f.referrerPolicy = 'no-referrer';
    f.title = 'התצוגה החיה של העמוד';
    f.className = 'lv-iframe';
    f.addEventListener('load', () => { if (S.picking) send({ cmd: 'inspect', on: true }); });
    f.src = url();                                        // מהכתובת — לא srcdoc
    S.frame = f;
    $('lv-frame').prepend(f);
    $('lv-msg').hidden = true;
    drawWhere();
  }
  /** רענון באותה מסגרת: הגשר שומר ומשחזר את מיקום הגלילה. */
  async function reload() {
    if (!S.frame) return mount();
    try { await fresh(); } catch (e) { toast(e.message, true); return; }
    S.limits.clear(); drawLimits();
    S.frame.src = url();
    drawWhere();
  }
  const send = msg => { try { S.frame?.contentWindow?.postMessage(Object.assign({ kol: 'pv' }, msg), '*'); } catch { /* המסגרת הוחלפה */ } };

  function drawWhere() {
    $('lv-title').textContent = S.title || 'תצוגה חיה';
    $('lv-path').textContent = S.path || S.tok?.entry || '';
    if (S.tok) $('lv-tab').href = url();
  }

  // ── הודעות מהמסגרת: קלט לא מהימן (S5) ─────────────────────
  const HANDLERS = {
    console(d) {
      addLine({ level: Object.hasOwn(LEVELS, d.level) ? d.level : 'log', text: str(d.text, 4000), file: safePath(str(d.file, 400)), line: int(d.line),
        limit: Object.hasOwn(LIMITS, d.limit) ? d.limit : '' });
    },
    title(d) { S.title = str(d.title, 300); drawWhere(); },
    ready(d) { const p = safePath(str(d.path, 400)); if (p) { S.path = p; drawWhere(); } },
    pick(d) {
      drawPick({ tag: str(d.tag, 40).replace(/[^a-z0-9-]/gi, ''), id: str(d.id, 200), classes: str(d.classes, 600), file: safePath(str(d.file, 400)), line: int(d.line),
        made: d.made === true, w: int(d.w), h: int(d.h) });
    },
  };
  addEventListener('message', e => {
    if (!S.frame || e.source !== S.frame.contentWindow) return;          // רק מהמסגרת שלנו
    const d = e.data;
    if (e.origin !== 'null' || !d || typeof d !== 'object' || d.kol !== 'pv' || typeof d.t !== 'string' || !Object.hasOwn(HANDLERS, d.t)) { S.ignored++; return; }
    if (++S.burst > MAX_BURST) { S.dropped++; return; }                   // הצפה אינה תוקעת את המסך
    try { HANDLERS[d.t](d); } catch { S.ignored++; }
  });
  setInterval(() => {
    if (S.dropped) { addLine({ level: 'warn', text: `העמוד שלח יותר מדי הודעות — ${S.dropped} הושמטו`, file: '', line: 0, limit: '' }); S.dropped = 0; }
    S.burst = 0;
  }, 1000);

  // ── מסוף ────────────────────────────────────────────────
  function addLine(l) {
    S.pending.push(l);
    if (S.pending.length > MAX_LINES) S.pending.shift();
    if (l.limit && !S.limits.has(l.limit)) { S.limits.add(l.limit); drawLimits(); }
    if (!addLine.raf) addLine.raf = requestAnimationFrame(flush);
  }
  function flush() {
    addLine.raf = 0;
    const list = $('lv-console'), frag = document.createDocumentFragment();
    for (const l of S.pending) {
      S.lines.push(l);
      if (l.level === 'error') S.errors++;
      const li = el('li', 'lv-line ' + l.level);
      li.append(el('span', 'lv-ico', LEVELS[l.level]), el('span', 'lv-text', l.text));      // textContent בלבד
      if (l.file) {
        const b = el('button', 'lv-src', l.file + (l.line ? ':' + l.line : ''));
        b.type = 'button'; b.title = 'פתח בקוד'; b.dir = 'ltr';
        b.addEventListener('click', () => openCode(l.file, l.line));
        li.append(b);
      }
      frag.append(li);
    }
    S.pending = [];
    const stick = list.scrollTop + list.clientHeight >= list.scrollHeight - 30;
    list.append(frag);
    while (list.children.length > MAX_LINES) { list.firstChild.remove(); S.lines.shift(); }
    if (stick) list.scrollTop = list.scrollHeight;
    $('lv-count').textContent = S.errors ? String(S.errors) : '';
  }
  function clearConsole() { S.lines = []; S.pending = []; S.errors = 0; $('lv-console').replaceChildren(); $('lv-count').textContent = ''; }

  function drawLimits() {
    const p = $('lv-limits');
    p.hidden = !S.limits.size;
    if (!S.limits.size) return;
    p.replaceChildren(el('strong', '', 'לא יעבוד בארגז החול: '), [...S.limits].map(k => LIMITS[k]).join(' · ') + '. ',
      el('span', '', 'העמוד מוגש במקור אטום, כדי שלא יוכל לגעת באתר שמארח אותו — וכך יהיה גם אצל הגולש. זה ייפתר כשהעמוד יקבל כתובת משלו (שאלה ש-2). הפרטים במסוף.'));
  }

  function openCode(file, line) {
    if (!bc) { toast('הדפדפן אינו תומך בפתיחה מכאן', true); return; }
    bc.postMessage({ t: 'open', path: file, line });
    toast('נפתח בעורך הקוד: ' + file + (line ? ':' + line : ''));
  }

  // ── כלי הבדיקה ───────────────────────────────────────────
  function setPicking(on) {
    S.picking = on;
    $('lv-pick').setAttribute('aria-pressed', String(on));
    $('lv-pick').classList.toggle('on', on);
    send({ cmd: 'inspect', on });
    if (on) tab('inspect');
  }
  function drawPick(p) {
    const box = $('lv-inspect');
    const sel = '<' + (p.tag || '?') + (p.id ? '#' + p.id : '') + p.classes.split(/\s+/).filter(Boolean).map(c => '.' + c).join('') + '>';
    const rows = [el('code', 'lv-sel', sel)];
    rows.push(el('p', 'hint', `תגית: ${p.tag || '?'} · מחלקות: ${p.classes || '—'} · ${p.w}×${p.h}px`));
    if (p.made || !p.file) rows.push(el('p', 'lv-made', 'האלמנט נוצר בזמן ריצה, על ידי ה-JS של העמוד — הוא אינו כתוב בקובץ.'));
    else {
      const b = el('button', 'btn sm', 'פתח בקוד');
      b.type = 'button';
      b.addEventListener('click', () => openCode(p.file, p.line));
      const where = el('p', 'lv-where-row');
      where.append(el('span', 'lv-file', p.file + (p.line ? ':' + p.line : '')), b);
      where.firstChild.dir = 'ltr';
      rows.push(where);
      if (!p.line) rows.push(el('p', 'hint', 'התגית אינה כתובה במפורש בקובץ (הדפדפן השלים אותה), ולכן אין שורה.'));
    }
    box.replaceChildren(...rows);
    tab('inspect');
  }
  function tab(name) {
    S.tab = name;
    $('lv-t-console').setAttribute('aria-selected', String(name === 'console'));
    $('lv-t-inspect').setAttribute('aria-selected', String(name === 'inspect'));
    $('lv-console').hidden = name !== 'console';
    $('lv-inspect').hidden = name !== 'inspect';
    $('lv-clear').hidden = name !== 'console';
  }

  // ── מצבי מכשיר ורוחב חופשי ────────────────────────────────
  function setWidth(w) {
    S.width = w ? Math.max(240, Math.min(2560, Math.round(w))) : 0;
    $('lv-frame').style.width = S.width ? S.width + 'px' : '100%';
    for (const b of $('lv-seg').children) b.setAttribute('aria-pressed', String(+b.dataset.w === S.width));
    drawSize();
  }
  function drawSize() {
    const r = $('lv-frame').getBoundingClientRect();
    $('lv-size').textContent = Math.round(r.width) + ' × ' + Math.round(r.height);
  }
  function drawDevices() {
    const bps = Array.isArray(S.tok?.breakpoints) ? S.tok.breakpoints : [];
    const modes = [['מלא', 0]];
    for (const b of bps) {
      const w = int(b.preview) || int(b.max);
      if (w) modes.push([str(b.label, 30) || str(b.id, 30) || String(w), w]);
    }
    $('lv-seg').replaceChildren(...modes.map(([label, w]) => {
      const b = el('button', '', w ? `${label} ${w}` : label);
      b.type = 'button'; b.dataset.w = w; b.setAttribute('aria-pressed', String(w === S.width));
      b.addEventListener('click', () => setWidth(w));
      return b;
    }));
  }
  function grip() {
    const g = $('lv-grip'), box = $('lv-frame');
    let x0 = 0, w0 = 0;
    g.addEventListener('pointerdown', e => {
      x0 = e.clientX; w0 = box.getBoundingClientRect().width;
      g.setPointerCapture(e.pointerId); box.classList.add('drag');
      e.preventDefault();
    });
    g.addEventListener('pointermove', e => { if (g.hasPointerCapture(e.pointerId)) setWidth(w0 + 2 * (e.clientX - x0)); });   // המסגרת ממורכזת: צד אחד זז, הרוחב משתנה פי שניים
    const up = e => { if (g.hasPointerCapture(e.pointerId)) g.releasePointerCapture(e.pointerId); box.classList.remove('drag'); };
    g.addEventListener('pointerup', up); g.addEventListener('pointercancel', up);
    g.addEventListener('keydown', e => {
      const d = { ArrowRight: 20, ArrowLeft: -20 }[e.key];
      if (d) { e.preventDefault(); setWidth(box.getBoundingClientRect().width + d); }
    });
    new ResizeObserver(drawSize).observe(box);
  }

  // ── אחרי שמירה בעורך: CSS — בלי טעינה מחדש; כל השאר — טעינה מחדש (הגשר שומר את מיקום הגלילה) ──
  function onSaved(path) {
    if (!S.frame) return;
    if (/\.css$/i.test(path)) send({ cmd: 'css', path });
    else if (path === 'kol.json') token().then(reload, () => {});
    else reload();
  }
  if (bc) bc.addEventListener('message', e => { const d = e.data; if (d && d.t === 'saved') onSaved(safePath(str(d.path, 400))); });

  // ── חיבור ────────────────────────────────────────────────
  $('lv-reload').addEventListener('click', reload);
  // "עצור ורענן": קודם מסירים את המסגרת התקועה, ורק אחרי רגע טוענים חדשה — כדי שהדפדפן יסגור את התהליך התקוע ולא ישבץ בו את החדשה
  $('lv-stop').addEventListener('click', async () => {
    clearConsole();
    S.frame?.remove(); S.frame = null; S.title = ''; drawWhere();
    await new Promise(r => setTimeout(r, 700));
    await mount();
    toast('המסגרת נעצרה ונטענה מחדש');
  });
  $('lv-pick').addEventListener('click', () => setPicking(!S.picking));
  $('lv-clear').addEventListener('click', clearConsole);
  $('lv-t-console').addEventListener('click', () => tab('console'));
  $('lv-t-inspect').addEventListener('click', () => tab('inspect'));
  $('lv-fold').addEventListener('click', () => {
    const folded = $('lv-panel').classList.toggle('folded');
    $('lv-fold').setAttribute('aria-expanded', String(!folded)); $('lv-fold').textContent = folded ? '▴' : '▾';
  });
  $('lv-tab').addEventListener('click', e => { if (!S.tok) e.preventDefault(); });
  if (EMBED) { $('lv-close').hidden = false; $('lv-close').addEventListener('click', () => bc?.postMessage({ t: 'close' })); }
  setInterval(() => { if (S.tok && S.tok.exp - Date.now() / 1000 < 300) token().then(drawWhere, () => {}); }, 60000);   // "פתח בלשונית חדשה" תמיד עם אסימון תקף
  grip();
  tab('console');
  window.Live = { state: S, mount, reload };              // לבדיקות ולמסך המפתח
  mount();
})();
