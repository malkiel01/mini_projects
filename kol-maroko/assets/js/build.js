/*
 * קול מרוקו — הבנייה לפרסום של פרויקט הקבצים (פרוסה M1; docs/spec-v2/07-publish-and-mount.md §2).
 *
 * לקובץ הזה שני תפקידים, כל אחד בעולם אחר:
 *
 *  1. **בסטודיו** (window.KolBuild): מנהל את הבנייה. השרת מרכיב את החבילה מהמאגר; הדפדפן רק פותח כל עמוד שיש בו
 *     רשימה במסגרת מבודדת (preview/, מצב בנייה), מקבל ממנה את מה שנוצר בתוך כל מכל, ומוסר אותו לשרת.
 *  2. **בתוך המסגרת** (מוגש כ-__kol/bridge.js רק בכתובת של מצב בנייה): מחכה ש-js/kol-data.js **של הפרויקט** יסיים
 *     לצייר, ושולח להורה רק את הצמתים שנוספו בכל מכל — כל אחד מסומן data-kol-pre. שום דבר אחר מהעמוד אינו יוצא.
 *     כאן רץ קוד של בעל העמוד, ולכן ההורה מתייחס לכל מה שמגיע מכאן כקלט זר: השרת בודק ומרכיב.
 */
(function () {
  'use strict';

  var inFrame = false;
  try { inFrame = window.top !== window && /\/preview\/b[0-9a-z]+-p-/.test(location.pathname); } catch (e) { inFrame = true; }
  if (inFrame) { frame(); return; }

  // ── בסטודיו ──────────────────────────────────────────────

  /**
   * בנייה מלאה. opts: { prerender, include_config, exclude }. progress(step) — לפס ההתקדמות.
   * מחזיר את הגרסה הבנויה; כשל — Error עם .build = { msg, file, line, page } ו-.id.
   */
  async function run(opts, progress) {
    progress = progress || function () {};
    progress({ step: 'start' });
    var s = await api('build_start', opts || {});
    var externals = new Set();
    for (var i = 0; i < s.pages.length; i++) {
      var page = s.pages[i];
      progress({ step: 'page', i: i, n: s.pages.length, page: page, id: s.id });
      var r = await renderPage(s, page);
      (Array.isArray(r.externals) ? r.externals : []).forEach(function (u) { if (typeof u === 'string') externals.add(u); });
      if (!r.ok) {
        var err = clean(r.error, page);
        await api('build_fail', { id: s.id, error: err }).catch(function () {});
        throw Object.assign(new Error(err.msg), { build: err, id: s.id });
      }
      try {
        await api('build_page', { id: s.id, path: page, unsupported: !!(r.unsupported || r.nokd),
          containers: Array.isArray(r.containers) ? r.containers.map(function (c) { return c && typeof c.html === 'string' ? { list: String(c.list), html: c.html } : null; }) : [] });
      } catch (e) {
        var bad = { msg: e.message, file: page, line: (e.data && e.data.line) || 0, page: page };
        await api('build_fail', { id: s.id, error: bad }).catch(function () {});
        throw Object.assign(new Error(e.message), { build: bad, id: s.id });
      }
    }
    progress({ step: 'finish', id: s.id });
    var f = await api('build_finish', { id: s.id, externals: Array.from(externals) });
    progress({ step: 'done', id: s.id });
    return f.build;
  }

  function clean(e, page) {
    e = e && typeof e === 'object' ? e : {};
    return { msg: String(e.msg || 'הבנייה נכשלה').slice(0, 500), file: String(e.file || '').slice(0, 255), line: Number(e.line) || 0, page: page };
  }

  /** עמוד אחד במסגרת מבודדת (sandbox בלי allow-same-origin). התשובה — הודעה אחת מהמסגרת הזו בלבד, או זמן. */
  function renderPage(s, page) {
    return new Promise(function (resolve) {
      var f = document.createElement('iframe');
      f.setAttribute('sandbox', s.sandbox);
      f.setAttribute('aria-hidden', 'true');
      f.tabIndex = -1;
      f.referrerPolicy = 'no-referrer';
      f.title = 'בנייה';
      f.className = 'bd-frame';
      var timer = setTimeout(function () { done({ ok: false, error: { msg: 'הבנייה של העמוד לא הסתיימה תוך ' + (s.timeout || 60) + ' שניות', file: page, line: 0 } }); }, (s.timeout || 60) * 1000);
      function onMsg(e) {
        if (e.source !== f.contentWindow || !e.data || e.data.kolBuild !== 1) return;
        done(e.data);
      }
      function done(r) { clearTimeout(timer); window.removeEventListener('message', onMsg); f.remove(); resolve(r); }
      window.addEventListener('message', onMsg);
      f.src = s.base + s.token + '/' + page.split('/').map(encodeURIComponent).join('/');
      document.body.appendChild(f);
    });
  }

  window.KolBuild = { run: run };

  // ── בתוך המסגרת ──────────────────────────────────────────

  function frame() {
    var root = location.pathname.replace(/^(.*?\/preview\/[^/]+\/).*$/, '$1');
    var page = decodeURIComponent(location.pathname.slice(root.length));
    var errors = [], sent = false, started = Date.now(), loadedAt = 0;
    var initial = new Map();

    function rel(u) {
      try {
        var x = new URL(u, location.href);
        if (x.origin === location.origin && x.pathname.indexOf(root) === 0) return decodeURIComponent(x.pathname.slice(root.length));
        return x.href;
      } catch (e) { return String(u || ''); }
    }
    window.addEventListener('error', function (e) {
      if (e.target && e.target !== window) return;                    // משאב שלא נטען — הסריקה בשרת מדווחת על הפניות שבורות
      errors.push({ msg: e.message || String(e.error), file: rel(e.filename || ''), line: e.lineno || 0 });
    }, true);
    window.addEventListener('unhandledrejection', function (e) {
      errors.push({ msg: 'Promise: ' + String((e.reason && e.reason.message) || e.reason), file: '', line: 0 });
    });
    // לפני ש-kol-data.js מתחיל (הסקריפט הזה ראשון ב-<head>, ולכן גם המאזין שלו ראשון): מה שכבר היה בכל מכל
    document.addEventListener('DOMContentLoaded', function () {
      all().forEach(function (c) { initial.set(c, new Set(Array.prototype.slice.call(c.children))); });
    });
    window.addEventListener('load', function () { loadedAt = Date.now(); });

    function all() { return Array.prototype.slice.call(document.querySelectorAll('[data-kol-list]')); }
    function hasTemplate(c) { for (var i = 0; i < c.children.length; i++) if (c.children[i].tagName === 'TEMPLATE') return true; return false; }
    function skip(c) { return !hasTemplate(c) || c.getAttribute('data-kol-prerender') === 'none'; }
    function externals() {
      try {
        return performance.getEntriesByType('resource').map(function (e) { return e.name; })
          .filter(function (u) { return /^https?:/i.test(u) && rel(u) === u; }).slice(0, 500);
      } catch (e) { return []; }
    }
    function done(r) {
      if (sent) return;
      sent = true;
      r.kolBuild = 1;
      r.page = page;
      r.externals = externals();
      parent.postMessage(r, '*');
    }
    function later() { setTimeout(tick, 100); }

    function tick() {
      if (errors.length) return done({ ok: false, error: errors[0] });
      if (!loadedAt) return later();
      var kd = window.KolData;
      if (!kd) return Date.now() - loadedAt > 1500 ? done({ ok: true, nokd: true }) : later();
      if (kd.prerender !== true) return done({ ok: true, unsupported: true, version: String(kd.version || '') });
      var lists = all();
      var waiting = lists.filter(function (c) { return !skip(c) && !c.hasAttribute('data-kol-ready'); });
      if (waiting.length) {
        if (Date.now() - started > 55000) return done({ ok: false, error: { msg: 'הרשימה "' + waiting[0].getAttribute('data-kol-list') + '" לא סיימה להיבנות', file: page, line: 0 } });
        return later();
      }
      // עוד רגע אחד — שגיאה שנזרקה מיד אחרי הציור האחרון נתפסת
      if (!tick.settled) { tick.settled = true; return setTimeout(tick, 150); }
      done({ ok: true, containers: lists.map(function (c) {
        if (skip(c)) return null;
        var was = initial.get(c) || new Set();
        var nodes = Array.prototype.slice.call(c.children).filter(function (n) { return n.tagName !== 'TEMPLATE' && !was.has(n); });
        var lim = c.getAttribute('data-kol-prerender');
        if (lim && /^\d+$/.test(lim)) nodes = nodes.slice(0, Number(lim));
        return { list: c.getAttribute('data-kol-list'), html: nodes.map(function (n) {
          var x = n.cloneNode(true);
          x.setAttribute('data-kol-pre', '');
          return x.outerHTML;
        }).join('\n') };
      }) });
    }
    later();
  }
})();
