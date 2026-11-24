/*
 * פרוסה 02 — הטופס החכם: מצב תיק, "הבא בתור", מקור לבדיקה ושמות לשדות ביומן.
 * פונקציות טהורות בלבד (בלי DOM), כדי שאותו קוד ירוץ בדפדפן (window.FDForm) ובבדיקות (node, module.exports).
 * רשימת הבדיקות, הסטטוסים והקבוצות — של הבעלים; הקובץ רק קורא אותם.
 */
(function (root) {
  'use strict';
  const labels = {license_document:'רישיון קבורה',license_number:'מספר רישיון קבורה',identity_document:'מסמך זיהוי נפטר',identity_source:'מקור הזיהוי',payment_evidence:'אסמכתת תשלום',hall_service:'שירות בית ההספדים',grave_purchase:'רכישת קבר נוסף',partner_identity:'זיהוי בן/בת זוג',national_insurance:'דיווח לביטוח לאומי'};
  const statusLabels = {unknown:'טרם נבדק',document_present:'מסמך קיים',verified:'מאומת',unreadable:'לא קריא',missing:'חסר',reported:'עודכן ידנית',not_applicable:'לא רלוונטי'};
  const viaLabels = {ui:'ממשק', field:'שטח', dropbox:'Dropbox', api:'צ\'אט חיצוני', ai:'AI', import:'ייבוא', system:'מערכת'};
  const fieldLabels = {name:'שם הנפטר/ת', month:'חודש שיוך', date:'תאריך', time:'שעה', origin:'מקום יציאה', team:'צוות',
    grave:'מיקום הקבר', notes:'הערות', folder_url:'תיקיית Dropbox', documents:'מסמכים', payments:'תשלומים', checks:'בדיקות',
    status:'סטטוס', value:'ערך', note:'הערה', source:'מקור', manual_fields:'שדות ידניים', org_id:'ארגון'};
  const actionLabels = {save:'שמירה', import:'ייבוא', api_token:'אסימון API', user_linked:'קישור משתמש', user_created:'משתמש חדש'};
  const KEYS = Object.keys(labels);
  const TOTAL = KEYS.length;
  // סדר הקבוצות במסלול — כמו שהיה: חסר/לא קריא ← לאימות ← טרם נבדק ← הושלם
  const ORDER = ['bad', 'verify', 'open', 'done'];
  const groupOf = s => (s === 'missing' || s === 'unreadable') ? 'bad'
    : (s === 'document_present' || s === 'reported') ? 'verify'
    : (s === 'verified' || s === 'not_applicable') ? 'done' : 'open';
  const statusOf = (x, k) => (((x && x.checks) || {})[k] || {}).status || 'unknown';

  /** הבדיקה הבאה לטיפול: הראשונה בקבוצה הראשונה שאינה ריקה (לפי סדר הבדיקות הקיים). null — אין מה לעשות. */
  function nextUp(x) {
    for (const g of ['bad', 'verify', 'open']) {
      const k = KEYS.find(key => groupOf(statusOf(x, key)) === g);
      if (k) return k;
    }
    return null;
  }

  /**
   * מצב התיק, בלי לשקר: "הושלם" רק כשכל הבדיקות סגורות **ויש לפחות אחת מאומתת**.
   * state: bad (יש חסר) · verify (יש לאימות) · unchecked (אף בדיקה לא מאומתת — "טרם נבדק") · open · done
   */
  function caseState(x) {
    const n = {bad:0, open:0, verify:0, done:0};
    let verified = 0;
    for (const k of KEYS) { const s = statusOf(x, k); n[groupOf(s)]++; if (s === 'verified') verified++; }
    const complete = n.done === TOTAL && verified > 0;
    const state = complete ? 'done' : n.bad ? 'bad' : n.verify ? 'verify' : verified === 0 ? 'unchecked' : 'open';
    const noLinks = ((x && x.documents) || []).filter(d => !d.url).length + (x && x.folder_url ? 0 : 1);
    return {n, verified, complete, state, pending: TOTAL - n.done, noLinks};
  }

  /** האם תיק מופיע בסינון. todo = כל מה שלא הושלם. */
  function matches(filter, st) {
    if (filter === 'all') return true;
    if (filter === 'todo') return !st.complete;
    if (filter === 'done') return st.complete;
    if (filter === 'bad') return st.n.bad > 0;
    if (filter === 'verify') return st.n.verify > 0;
    if (filter === 'unchecked') return st.state === 'unchecked';
    return true;
  }

  /** מי אמר, מאיזה מקור ומתי. בדיקה שנקבעה לפני הפרוסה (בלי via) — "מייבוא ישן". בדיקה ריקה — כלום. */
  function provenance(check) {
    const c = check || {};
    if (c.via) return {legacy:false, by:c.by || '', via:viaLabels[c.via] || c.via, at:c.at || ''};
    if ((c.status || 'unknown') === 'unknown' && !c.value && !c.note) return null;
    return {legacy:true, by:'', via:'מייבוא ישן', at:''};
  }

  /** "checks.license_number.status" → "מספר רישיון קבורה · סטטוס" */
  function fieldLabel(path) {
    return String(path || '').split('.').map(p => labels[p] || fieldLabels[p] || p).join(' · ');
  }
  function valueText(v) {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'string') return statusLabels[v] || v;
    return JSON.stringify(v);
  }

  const api = {labels, statusLabels, viaLabels, actionLabels, KEYS, TOTAL, ORDER, groupOf, nextUp, caseState, matches, provenance, fieldLabel, valueText};
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.FDForm = api;
})(typeof self !== 'undefined' ? self : this);
