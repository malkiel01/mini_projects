/**
 * ערכת נושא: "לפי המכשיר" (ברירת מחדל), בהיר או כהה — נבחר בהגדרות המשתמש ונשמר במכשיר הזה.
 * נטען ב-<head>, לפני הציור — כך שאין הבהוב של הערכה הלא נכונה.
 */
(function () {
  const KEY = 'dt-theme';
  const get = () => { try { return localStorage.getItem(KEY) || 'auto'; } catch (e) { return 'auto'; } };
  const dark = (t) => t === 'dark' || (t !== 'light' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
  function apply(t) {
    const root = document.documentElement;
    if (t === 'light' || t === 'dark') root.dataset.theme = t; else delete root.dataset.theme;
    // צבע שורת הסטטוס בטלפון — כמו הפס העליון
    const meta = document.querySelector('meta[name=theme-color]');
    if (meta) meta.content = dark(t) ? '#34539c' : '#2d4a8a';
  }
  apply(get());
  if (window.matchMedia) matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => apply(get()));
  window.DTTheme = {
    get,
    set(t) { try { localStorage.setItem(KEY, t); } catch (e) { /* אחסון חסום — לפחות לעכשיו */ } apply(t); },
  };
})();
