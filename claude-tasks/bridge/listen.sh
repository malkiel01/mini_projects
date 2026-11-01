#!/usr/bin/env bash
# הגשר החי — המאזין (docs/spec.md §5.6).
#
# רץ ברקע בצ'אט (run_in_background, timeout ‎7000000 ms). לולאת channel-wait:
# כשמגיעה הודעה — כותב אותה ל-.claude/board-pending.json, מדפיס שורה קצרה ויוצא.
# היציאה היא מה שמעיר את הצ'אט. הצ'אט לא רואה את הפלט (רק סיכום), ולכן הסקיל
# מריץ link.sh pending. בזמן ההמתנה לא נשרף אף טוקן: זה curl, לא המודל.
#
#   listen.sh [seconds]    ברירת מחדל 6900 — קצת פחות משעתיים, מתחת לתקרת פקודת רקע
#
# קוד יציאה: 0 — הודעה או סוף הזמן; 3 — הערוץ נותק/האסימון נפסל (צריך register).
set -u
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[ -f "$BOARD_LINK" ] || { echo "הצ'אט לא רשום — link.sh register"; exit 3; }
TOKEN="$(jq -r .channel_token "$BOARD_LINK")"
END=$(( $(date +%s) + ${1:-6900} ))
WAIT="${CLAUDE_TASKS_WAIT:-20}"
log() { echo "$(date -u +%FT%TZ) $*" >> "$BOARD_LOG"; }
log "start pid=$$ until=$(date -u -d "@$END" +%T)"

fails=0
while [ "$(date +%s)" -lt "$END" ]; do
  out="$(board_call channel-wait "{\"wait\":$WAIT}" "$TOKEN" $(( WAIT + 15 )))"; rc=$?
  if [ $rc = 0 ]; then
    fails=0
    n="$(echo "$out" | jq '.messages | length')"
    if [ "$n" -gt 0 ]; then
      echo "$out" | jq '.messages' > "$BOARD_PENDING"
      log "messages=$n ids=$(echo "$out" | jq -c '[.messages[].id]')"
      echo "📥 $n הודעות מהלוח — הרץ: claude-tasks/bridge/link.sh pending"
      exit 0
    fi
  else
    log "http=$BOARD_HTTP rc=$rc $(echo "$out" | head -c 160 | tr '\n' ' ')"
    if [ $rc = 1 ] && { [ "$BOARD_HTTP" = 401 ] || [ "$BOARD_HTTP" = 403 ]; }; then
      echo "🔌 הערוץ נותק או שהאסימון נפסל (HTTP $BOARD_HTTP) — link.sh register מחדש"
      exit 3
    fi
    # 508 (מגבלת תהליכים באחסון), 5xx, רשת: נסיגה הדרגתית, עד דקה
    fails=$(( fails + 1 ))
    sleep $(( fails * 5 > 60 ? 60 : fails * 5 ))
  fi
  sleep 3
done
log "timeout"
echo "⏱ המאזין הסתיים (זמן) — להפעיל מחדש"
exit 0
