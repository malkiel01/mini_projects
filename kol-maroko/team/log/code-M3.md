# יומן — צ'אט הקוד של M3 (המעבר)

- 2026-11-12 08:40 — התחלה על `claude/kol-maroko-m3` מ-31b6761. רשת: `maghreb.co.il/lexicon/` ← 403 `cf-mitigated: challenge` — m3-fidelity.cjs ייבדק רק מקומית; הודעה לבקרה.
- 2026-11-12 09:20 — יחידה 1: `lib/cutover.php` (ההערכה הטהורה, המעקב, הראיות, נקודת שחזור, "מוכן למעבר" ← מטלת DevLoop), מיגרציה 18, 5 פעולות ב-`api.php`, `tests/cases/95-cutover.php` (16). שורה מוספת בטבלת ההרשאות `tests/cases/20-api.php` (הבדיקה הקיימת דורשת כל פעולה בטבלה). `run.php` 124/124.
