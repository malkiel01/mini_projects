# Review slice 16 — findings (incremental)

## Engine (sheet_calc.php)
- B1, rounding (half→+∞ incl. negatives), div-zero variants, empty≠0, const parsing, unknown op, self/forward ref: all correct (e1.php). VERIFIED-BY-RUN
- 🔴 DoS: apr_sheet_q does a full Euclid gcd (string bignum, ~24ms at 360 digits) after EVERY op step; a formula may hold 20 args × nesting depth 4.
  e3.php: 65 setup rows + 3 rows of `sub(add(20×mul(r65,1×19)), add(19×…))` (12KB JSON) → eval 47s. 35 such rows/sheet, 30 sheets/case, case-valuation evaluates all.
  API repro v2.php: 60 input rows (12-digit primes) + 5 mul/div rows + ONE 2.6KB row → GET sheet 16.6s, GET case-valuation 15.8s; saving is instant (no eval on save). VERIFIED-BY-RUN
- 🟡 storage: formula of 160k leaves (985KB, under the 1MB body cap) accepted; stored 985KB + audit row 1.36MB per save; view response 5MB. No node-count/length limit (only args≤20, depth≤4).
- ⚪ value "-" accepted as empty; formula text shows rounded operands without ≈; "x + -2,000,000".
- ⚪ sheet-row-order moving an input into a `sum` range silently changes the result (3→7), by design of range.

## Permissions / isolation (v3.php) — VERIFIED-BY-RUN
- other office (bella/boaz no share), no-office: all 40+ actions 404/403, nothing written; cross-case fact/file/sheet/set/supersedes pids → 404.
- staff with edit (tal): only sheet-row-save(input), renovation-add/delete → 200; everything else 403. Appraiser partner (dana): all allowed.
- partner: no see_internal → 404 on case-valuation/sheet; see_internal+all → read only; items-scope with comps → fact refs null, unshared file null, renovation null; writes 404. edit can't be granted in share.
- mutations-05: all killed. mutations-16: 76 killed, 0 survived.

## Migration (m1.php: real v15 code → populate → PR code migrates) — VERIFIED-BY-RUN
- comp-set outputs (summary, rows, weights, shares, subject, conclusion) byte-identical before/after for 4 sets (weighted/mean/median, total/per_sqm).
- alternatives: 1 per started/closed case, +after_works with flag, 0 for draft; draft started later gets exactly 1. fk check 0.

## Per-set membership/weights (v4.php) — VERIFIED-BY-RUN
- two sets, different weights → 1.75M / 1.25M as hand-computed; stale set rev → comp_changed; x-case comp → 404; removed member stays out after deselect/reselect; excluded/deleted comp leaves calc; after_works rules enforced on link / member / alt state change / approach update.
- ⚪ re-adding a removed member silently restores its old weight.

## Other
- 🟡 alt-save is allowed on a DRAFT case; case-start then skips seeding (apr_alts_seed returns if any alt exists) → started case has no "מצב קיים" (and no after_works with the flag). v5.php. valuation.php:172
- ⚪ JSON-number value 123456789012.123456 stored as …12346 (float precision lost before validation); UI sends strings.
- XSS: every interpolation in valuation.js is esc()/constant/server enum/pid (READ-ONLY). No JS arithmetic.

## Hand mutations (mymut.php, mymut2.php on /tmp/rv16-copy)
killed: rev check; row-delete/row-order/alt-conclude/approach-result judge→write; renovation-delete other's item; partner file ref / fact ref / renovation gating (t-valuation; s-shares does not cover them); file_in visibility; active members only; skip after_works set; weight needs active member; member must be selected; rounding floor; sum order; edit-calc rows-above; sheet_in_use; closed case; entered result; flag change; quote supersede; migration weight; migration draft; B5 float; B5 round; C3 merge; C1 budget/quote added; max args.
SURVIVED: max rows per sheet (100); sheets-per-case limit (30); template limit; too_long 3× pre-check; note row referable in formula; pick lists only visible files; new set seeded with non-selected comps (near-equivalent); 16-A4 output-level autofill of `conclusion` from weighted average; alt lookup ignoring case_id (equivalent: case is resolved from the alt pid).
