# יומן צ'אט הקוד — פרוסה A2 (הבאת ההקלטות)

ענף `claude/kol-maroko-a2`. החוזה: `docs/spec-v2/slices/A2-audio-fetch.md` (+ `05` §3, §4, §7).

- 2026-11-12 · התחלה מ-`main`. הודעה מוקדמת לבקרה על נתיבי הנתונים: `data/files/` (קיים, מוחרג), `data/files/tmp/`,
  טבלה `recordings` ב-`data/kol.sqlite` — אין `--exclude` חדש. הכרעה: הטבלה ב-`CREATE TABLE IF NOT EXISTS` ולא שלב 14
  ב-`db.php` (חמישה צ'אטי קוד במקביל — מניעת התנגשות במספר מיגרציה).
- 2026-11-12 · יחידה 1 (95f9901): `lib/recordings.php` — מודל, סריקה, הבאה, גבולות; ייבוא ZIP לפי מזהה; `tests/cases/85-recordings.php`
  + `tests/fake-audio.php`. 86/86.
- 2026-11-12 · יחידה 2 (cf9c5b3): `assets/js/library-fetch.js`, חיבור ב-`library.js`, `tests/browser/a2.cjs` (32/32).
