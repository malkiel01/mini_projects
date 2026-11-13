/* רכיב מוכן: חלונית מהצד (kit 1.0.0) — משפר בלבד. החלון הוא <dialog data-kol-dialog="שם"> (02 §8.2): כל אלמנט עם
   data-kol-open="שם" בעמוד פותח אותו, data-kol-close סוגר; Esc ולחיצה על הרקע — סוגרים. "מתי נפתח" — תכונות על החלון:
   data-kol-trigger: click (רק בלחיצה) · delay (אחרי data-kol-delay שניות) · scroll (בגלילה ל-data-kol-scroll%) · exit (העכבר יוצא למעלה);
   data-kol-once: visitor (פעם אחת לגולש — נזכר בדפדפן שלו) · session (פעם בביקור) · always. */
(() => {
  const KEY = 'kol-dialog-seen:';
  const store = (dlg, kind) => { try { return kind === 'visitor' ? localStorage : kind === 'session' ? sessionStorage : null; } catch { return null; } };
  const seen = dlg => { const s = store(dlg, dlg.getAttribute('data-kol-once')); try { return !!(s && s.getItem(KEY + dlg.getAttribute('data-kol-dialog'))); } catch { return false; } };
  const mark = dlg => { const s = store(dlg, dlg.getAttribute('data-kol-once')); try { if (s) s.setItem(KEY + dlg.getAttribute('data-kol-dialog'), '1'); } catch { /* */ } };
  let back = null;
  const open = dlg => { if (!dlg || dlg.open) return; back = document.activeElement; dlg.hidden = false; if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); };
  const close = dlg => { if (!dlg || !dlg.open) return; if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); };
  const init = dlg => {
    if (dlg.kolDlg) return;
    dlg.kolDlg = true;
    dlg.addEventListener('close', () => { mark(dlg); if (back && back.focus) back.focus(); });
    dlg.addEventListener('click', e => { if (e.target === dlg) close(dlg); });
    const t = dlg.getAttribute('data-kol-trigger') || 'click';
    if (t === 'click' || seen(dlg)) return;
    if (t === 'delay') setTimeout(() => { if (!seen(dlg)) open(dlg); }, Math.max(0, parseFloat(dlg.getAttribute('data-kol-delay') || '5') || 0) * 1000);
    if (t === 'scroll') { const at = Math.min(100, Math.max(0, parseFloat(dlg.getAttribute('data-kol-scroll') || '50') || 0)); const on = () => { const h = document.documentElement.scrollHeight - innerHeight; if (h <= 0 || scrollY / h * 100 >= at) { removeEventListener('scroll', on); if (!seen(dlg)) open(dlg); } }; addEventListener('scroll', on, { passive: true }); }
    if (t === 'exit') { const on = e => { if (e.clientY <= 0 && !e.relatedTarget) { document.removeEventListener('mouseout', on); if (!seen(dlg)) open(dlg); } }; document.addEventListener('mouseout', on); }
  };
  document.addEventListener('click', e => {
    const o = e.target.closest('[data-kol-open]');
    if (o) { const d = document.querySelector('dialog[data-kol-dialog="' + CSS.escape(o.getAttribute('data-kol-open')) + '"].kol-offcanvas__dlg');
      if (d) { e.preventDefault(); open(d); } return; }
    const c = e.target.closest('[data-kol-close]'), d = c && c.closest('dialog.kol-offcanvas__dlg');
    if (d) { e.preventDefault(); close(d); }
  });
  const all = () => document.querySelectorAll('dialog.kol-offcanvas__dlg').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
