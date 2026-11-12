# יומן קוד — M7 (משיכה במקום דחיפה)

- 2026-11-12 — התחלה. ענף `claude/kol-maroko-m7` מ-main (7edad0c). מיגרציה 24 (`site_outbox`; ב-main המקסימום 23).
- 2026-11-12 — יחידה 1: `lib/outbox.php` (התור, החלפה, תוקף, הדופק, האסימון, שורת החיבור), `pull.php` (ציבורית ב-app.json),
  `site_call` בוחר ערוץ (push / pull; ברירת מחדל pull רק ל-maghreb.co.il), `build_queue` — "באתר" רק אחרי `applied`.
  פעולות חדשות ב-ACTION_ROLES: outbox, outbox_resend, site_wake (מפרסם); pull_line, pub_channel_set (בעל האתר). run.php 326 ✓.
