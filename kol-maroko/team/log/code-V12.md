# יומן קוד — V12 (רכיבים)

- 2026-11-12 · ענף `claude/kol-maroko-v12` מ-main (32130ae). קראתי CLAUDE.md, PROTOCOL, owner-brief, ה-slice, 02 §7, 03 §10,
  handoff (V3–V10), reviews/V10, יומן V11.
- `send_later` נדחה בסשן הזה ("lineage depth 8") — אין תזכורת עצמית; עובדים ברצף.
- יחידה 1: `component-state.js` (טהור: סכמה, בורר, קריאה / כתיבה לכל יעד, ציור, מצב, diff) + `tests/editor/component-state.mjs` — 56/56
  (20 מקרי מצב, כל יעד עם ביטול בייט בבייט, עדכון לתבנית חדשה, JSON שגוי עם שורה, הזרקה).
