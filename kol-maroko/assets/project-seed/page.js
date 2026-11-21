/*
 * page.js — ההתנהגות של העמוד עצמו (מה שאינו קשירת נתונים; זו ב-kol-data.js).
 *
 * כרגע יש כאן דבר אחד: כפתורים שפותחים וסוגרים קטע בעמוד (המבוא, "קרא עוד").
 * כפתור כזה מסומן במחלקה kadisha-lex-gate, והתכונה data-panel שלו היא ה-id של הקטע שהוא פותח.
 * אם לכפתור יש data-more-text ו-data-less-text — הכיתוב שלו מתחלף ביניהם.
 */
(function () {
  'use strict';

  function buttonsOf(panelId) {
    return Array.prototype.slice.call(document.querySelectorAll('.kadisha-lex-gate[data-panel="' + panelId + '"]'));
  }

  function setOpen(panel, open) {
    panel.hidden = !open;
    buttonsOf(panel.id).forEach(function (button) {
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      var more = button.getAttribute('data-more-text'), less = button.getAttribute('data-less-text');
      if (more && less) {
        // הכיתוב הוא צומת הטקסט הראשון של הכפתור; החץ שאחריו (אם יש) נשאר במקומו
        var text = button.firstChild && button.firstChild.nodeType === 3 ? button.firstChild : button.insertBefore(document.createTextNode(''), button.firstChild);
        text.nodeValue = (open ? less : more) + ' ';
      }
    });
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('.kadisha-lex-gate[data-panel]') : null;
    if (!button) return;
    var panel = document.getElementById(button.getAttribute('data-panel'));
    if (!panel) return;
    var open = panel.hidden;
    // כפתור בפס העליון פותח קטע אחד בכל פעם: שאר הקטעים נסגרים
    if (open && button.classList.contains('kadisha-lex-hero-btn')) {
      Array.prototype.slice.call(document.querySelectorAll('.kadisha-lex-section-panel')).forEach(function (other) {
        if (other !== panel && !other.hidden) setOpen(other, false);
      });
    }
    setOpen(panel, open);
    if (open && panel.scrollIntoView && button.classList.contains('kadisha-lex-hero-btn')) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
})();
