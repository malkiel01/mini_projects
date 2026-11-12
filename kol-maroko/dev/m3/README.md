# דוחות הנאמנות (M3)

כאן נשמרים הדוחות של `tests/browser/m3-fidelity.cjs` — `fidelity-<תאריך>.json`, `.html` ותיקיית הצילומים לידם.
הם נוצרים בבדיקה (במחשב של מי שמריץ, או ב-workflow), **לא בשרת**, ולכן אין בשבילם `--exclude`.

    node kol-maroko/tests/browser/m3-fidelity.cjs <העמוד-החי> <כתובת-הבדיקה>
    node kol-maroko/tests/browser/m3-fidelity.cjs --self-test        # בלי האתר

השורה בסוף: `עבר N/N`. את הדוח מצרפים כראיה בשורה "דוח הנאמנות עבר" במסך 🔁 מעבר.
