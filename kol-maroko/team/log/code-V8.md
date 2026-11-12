# יומן קוד — V8 (מחלקות משותפות, מצבים, כלל היעד המלא, "מאפיינים נוספים", תכונות)

- 2026-11-12 · ענף `claude/kol-maroko-v8` מ-main (a5c0c79). קראתי PROTOCOL, handoff (V3–V7), ה-slice ו-03 §4.2/§4.4/§8.2.
- 2026-11-12 · יחידה 1 (טהור): `details.js` — ייחודיות לפי Selectors 4 (‎:is/:not/:has — החזק ברשימה, ‎:where — אפס,
  ‎:nth-child(of S), ‎:before הישן), המפל עם `@layer` (סדר לפי הופעה ראשונה, מקונן, הפוך ב-!important), `@scope` (קרבה, גבול
  `to`), `@supports` נבדק, ומצב (`data-kol-st`) — `rankOf` אחד ל-details ול-style. `style.js` — `stateSel`, `checkState`,
  `checkSelector`, `planState` (כלל מצב מיד אחרי היעד; כלל משותף `.btn:hover, .btn:focus` נערך במקומו), `Styler.st/stOwn`,
  מצב × מכשיר דרך `planDevice`, `transition` בקיצורים, `removeDeclAt`. `identity.js` (חדש) — בדיקות קלט (מחלקה, id, תכונה:
  on* / javascript: מוסווה / srcdoc נדחים), שינוי שם ב-HTML/CSS/<style>, מועמדים ב-JS, הצהרות משותפות, שם מוצע.
  `tests/editor/identity.mjs` — 29.
