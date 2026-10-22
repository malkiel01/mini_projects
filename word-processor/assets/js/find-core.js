/**
 * הליבה של החיפוש (בלי TipTap — משותפת ל-find.js ול-find-worker.js): ההתאמות בטקסט של פסקה אחת.
 * ביקורת 04, R04: אחרי התאמה ריקה מתקדמים בנקודת קוד שלמה (לא באמצע זוג surrogate — אחרת (?=😀) על "😀abc"
 * חוזר לאותה התאמה לעד), ויש תקציב: עד MAX_MATCHES התאמות ועד TIME_BUDGET מ"ש לכל החיפוש — מעבר לזה החיפוש נעצר.
 */
export const MAX_MATCHES = 10000;
export const TIME_BUDGET = 400;
export const SLOW = 'החיפוש נעצר: הביטוי איטי מדי או מחזיר יותר מדי התאמות';

const WORD = /[\p{L}\p{N}\p{M}_]/u;

/**
 * כל ההתאמות של re (עם הדגל g) ב-text: [{ a, b, groups, text }]. budget — { deadline, left } משותף לכל הפסקאות;
 * כשנגמר — budget.stopped = true והתוצאה עד כה.
 */
export function matchText(text, re, wholeWord, budget) {
    const out = [];
    re.lastIndex = 0;
    let m;
    while ((m = re.exec(text))) {
        if (--budget.left < 0 || Date.now() > budget.deadline) { budget.stopped = true; break; }
        if (!m[0].length) {
            // התאמה ריקה: קדימה בנקודת קוד שלמה (תו מחוץ ל-BMP — שתי יחידות)
            re.lastIndex = m.index + ((text.codePointAt(m.index) ?? 0) > 0xffff ? 2 : 1);
            if (re.lastIndex > text.length) break;
            continue;
        }
        const a = m.index, b = a + m[0].length;
        if (wholeWord && ((a > 0 && WORD.test(text[a - 1])) || (b < text.length && WORD.test(text[b])))) continue;
        out.push({ a, b, groups: m.slice(1), text: m[0] });
    }
    return out;
}

export const newBudget = () => ({ deadline: Date.now() + TIME_BUDGET, left: MAX_MATCHES, stopped: false });
