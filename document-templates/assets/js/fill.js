// דף המילוי לממלא חיצוני — בלי חשבון. הזהות היא האסימון שבכתובת, אחרי #
// (לא נשלח לשרת בבקשת הדף ולא נרשם ביומני הגישה). כל בקשה נושאת אותו
// בכותרת X-Fill-Token.
(function () {
  'use strict';

  const app = document.getElementById('app');
  const token = (location.hash.match(/t=([0-9a-f]{48})/) || [])[1] || '';

  async function api(action, body) {
    const opts = { headers: { 'X-Fill-Token': token }, credentials: 'omit' };
    if (body instanceof FormData) {
      opts.method = 'POST';
      opts.body = body;
    } else if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    let res, data;
    try {
      res = await fetch('api.php?action=' + action, opts);
      data = await res.json();
    } catch (e) {
      throw new Error('אין תשובה מהשרת');
    }
    if (!res.ok || data.ok === false) throw new Error(data.error || 'שגיאה');
    return data;
  }

  function el(tag, attrs, ...children) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (k === 'class') n.className = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
      else if (v === true) n.setAttribute(k, '');
      else if (v !== false && v != null) n.setAttribute(k, v);
    }
    for (const c of children.flat()) if (c != null) n.append(c);
    return n;
  }
  const notice = (text, kind) => el('p', { class: 'notice ' + (kind || '') }, text);
  const show = (...nodes) => app.replaceChildren(...nodes.filter(n => n != null));

  let guard = null;
  // גיליון (למשל עריכת שורות בטבלה במחשב) והודעה קצרה — כמו באפליקציה
  function sheet(title, body, opts = {}) {
    const dlg = el('dialog', { class: 'sheet', 'aria-label': title });
    dlg.append(el('header', { class: 'sheet-head' },
      el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', title: 'סגירה', onclick: () => dlg.close() }, '✕'),
      el('h2', {}, title), opts.action || null), el('div', { class: 'sheet-body' }, body));
    dlg.addEventListener('close', () => { dlg.remove(); if (opts.onClose) opts.onClose(); });
    document.body.append(dlg);
    dlg.showModal();
    return dlg;
  }
  function toast(message, bad) {
    const t = el('div', { class: 'toast' + (bad ? ' bad' : ''), role: 'status' }, String(message));
    document.body.append(t);
    setTimeout(() => t.remove(), 3500);
  }
  window.DT = {
    api, el, notice, show, sheet, toast,
    setTab: () => {},
    setLeaveGuard: (fn) => { guard = fn; },
    wide: () => document.body.classList.add('wide'),
  };
  window.addEventListener('beforeunload', (e) => {
    if (guard && guard.dirty && guard.dirty()) { e.preventDefault(); e.returnValue = ''; }
  });

  async function otp(info) {
    const msg = el('div');
    const code = el('input', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 6, dir: 'ltr', placeholder: '6 ספרות' });
    const sendBtn = el('button', { type: 'button', onclick: send }, 'שליחת קוד למייל');
    async function send() {
      sendBtn.disabled = true;
      try {
        await api('fill_otp_send', {});
        msg.replaceChildren(notice('נשלח קוד ל-' + info.email + '. הוא בתוקף 10 דקות.', 'ok'));
        code.focus();
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      } finally {
        setTimeout(() => { sendBtn.disabled = false; }, 60000);
      }
    }
    const form = el('form', { class: 'card form' },
      el('h1', {}, info.title),
      el('p', {}, 'לפני הפתיחה — אימות קצר: נשלח קוד לכתובת ', el('strong', { dir: 'ltr' }, info.email), '.'),
      sendBtn,
      el('label', {}, el('span', {}, 'הקוד שקיבלת'), code),
      el('button', { type: 'submit', class: 'primary' }, 'אימות ופתיחה'),
      msg);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await api('fill_otp_verify', { code: code.value });
        start();
      } catch (err) {
        msg.replaceChildren(notice(err.message, 'bad'));
      }
    });
    show(form);
  }

  async function start() {
    if (!token) { show(notice('הקישור חסר או שבור. פתחו אותו שוב מההודעה שקיבלתם.', 'bad')); return; }
    try {
      const r = await api('fill_get');
      if (r.need_otp) return otp(r);
      window.DTDocs.open(null, { fill: true, token });
    } catch (e) {
      show(notice(e.message, 'bad'));
    }
  }

  start();
})();
