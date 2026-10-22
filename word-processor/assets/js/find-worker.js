/**
 * בדיקה מקדימה של ביטוי רגולרי (ביקורת 04, R04): ריצה על הטקסט של כל הפסקאות ב-Worker, שאפשר לעצור מבחוץ.
 * ביטוי עם backtracking יקר נתקע בתוך exec אחד — ושם תקציב הזמן של matchText לא עוזר; כאן העורך פשוט מחכה
 * לתשובה עד זמן קצוב, ואם אין — עוצר את ה-Worker ולא מריץ את החיפוש בדף.
 * הודעה: { source, flags, wholeWord, texts: [] } → { ok, count, stopped } או { error }.
 */
import { matchText, newBudget } from './find-core.js';

self.onmessage = e => {
    const { source, flags, wholeWord, texts } = e.data;
    let re;
    try { re = new RegExp(source, flags); } catch { self.postMessage({ error: 'ביטוי רגולרי לא תקין' }); return; }
    const budget = newBudget();
    let count = 0;
    for (const t of texts) {
        count += matchText(t, re, wholeWord, budget).length;
        if (budget.stopped) break;
    }
    self.postMessage({ ok: true, count, stopped: budget.stopped });
};
