#!/usr/bin/env bash
# מריץ קבוצת בדיקות PHP כרמה אחת (README, "בדיקות"), וכותב שורה לסיכום: ‏run-level.sh <רמה> <קבצים...>
# ‏SUMMARY — קובץ הסיכום (ב-CI: ‏$RUNNER_TEMP/summary). יוצא בכישלון אם בדיקה אחת נכשלה.
label=$1; shift
FAIL=0; TOTAL=0
for t in "$@"; do
  echo "::group::$t"
  out=$(php "$t" 2>&1); code=$?
  echo "$out"; echo "::endgroup::"
  n=$(grep -oE "[0-9]+ עברו|עברו: [0-9]+" <<<"$out" | grep -oE "[0-9]+" | tail -1)
  TOTAL=$((TOTAL + ${n:-0}))
  [ $code = 0 ] || { echo "::error file=guarded-browser/$t::נכשל"; FAIL=1; }
done
[ -n "${SUMMARY:-}" ] && echo "| $label | $([ $FAIL = 0 ] && echo ✅ || echo ❌) | $TOTAL בדיקות ב-$# קבצים |" >> "$SUMMARY"
echo "$label: $TOTAL בדיקות ב-$# קבצים — $([ $FAIL = 0 ] && echo עבר || echo נכשל)"
exit $FAIL
