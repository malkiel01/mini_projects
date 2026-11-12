# יומן — צ'אט הקוד של F2b

- 2026-11-12 · התחלה. ענף `claude/kol-maroko-f2b` מ-main (c5a0aec). החוזה: F2 §3, הקריטריונים [b].
- 2026-11-12 · יחידה 1 — השרת: `lib/project_ops.php` (קובץ חדש; `project.php` רק `require` ושלושה שינויים מוספים: מגבלות `zip`/`unzip`,
  `hid` בגרסאות, `update_refs` ב-`pf_rename`). פעולות: `pf_copy`, `pf_version`, `pf_version_restore`, `pf_search`, `pf_replace`, `pf_refs`,
  `pf_zip`, `pf_zip_import` (גוף גולמי). בדיקות: `tests/cases/62-project-f2b.php` (11). אין נתיב חדש תחת `data/` — ZIP זמני בתיקייה הזמנית של המערכת.
