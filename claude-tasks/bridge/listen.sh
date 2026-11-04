#!/usr/bin/env bash
# הגשר החי — המאזין (docs/spec.md §5.6, והכללים שאחרי ה-429 ב-2026-11-02).
#
# רץ ברקע בצ'אט (run_in_background, timeout ‎7000000 ms). בודק את הלוח בבקשה קצרה —
# בלי long-poll: כל 30±10 שניות כשאין כלום, וכל 10 שניות בשתי הדקות שאחרי הודעה.
# כשמגיעה הודעה — כותב אותה ל-.claude/board-pending.json, מדפיס שורה קצרה ויוצא.
# היציאה היא מה שמעיר את הצ'אט. בזמן ההמתנה לא נשרף אף טוקן: זה curl, לא המודל.
#
# כל סימן לעומס (429, 503, 508, 5xx, רשת או timeout) — המאזין **יוצא** ולא מנסה שוב.
# הוא רושם בקובץ הקישור עד מתי לא לחזור (backoff_until): שעה, ואז 2, 4, עד 6 שעות כל
# עוד יש שגיאות, או Retry-After אם השרת שלח יותר. הפעלה לפני הזמן יוצאת מיד, בלי בקשה.
# באחסון משותף, מאזינים שממשיכים לדפוק מפילים את כל הדומיין — לכל האפליקציות.
#
#   listen.sh [seconds]    ברירת מחדל 6900 — קצת פחות משעתיים, מתחת לתקרת פקודת רקע
#
# קוד יציאה: 0 — הודעה או סוף הזמן; 3 — הערוץ נותק/האסימון נפסל (צריך register);
#            4 — נסיגה: עומס או "המאזינים מושהים" (לא להפעיל לפני backoff_until).
#            5 — המתג בלוח לא "🎧 פעילים", או 5 מקומות תפוסים (שלב 24א): לא תקלה — הבדיקה העצמית בודקת את הלוח.
set -u
# עותק הריצה (§5.10.1) גובר על העותק שבריפו — כך צ'אט על ענף ישן מקבל את הגשר העדכני
_rt="$HOME/.claude-tasks-bridge"
if [ -z "${CT_RUNTIME:-}" ] && [ -f "$_rt/listen.sh" ] && [ "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" != "$(cd "$_rt" && pwd -P)" ]; then
  CT_RUNTIME=1 exec bash "$_rt/listen.sh" "$@"
fi
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[ -f "$BOARD_LINK" ] || { echo "הצ'אט לא רשום — link.sh register"; exit 3; }
TOKEN="$(jq -r .channel_token "$BOARD_LINK")"
END=$(( $(date +%s) + ${1:-6900} ))
IDLE="${CLAUDE_TASKS_POLL:-30}"     # שניות בין בדיקות כשאין כלום (±10)
BUSY=10                             # אחרי הודעה, למשך BUSY_FOR שניות
BUSY_FOR=120
BACKOFF_FIRST=3600
BACKOFF_MAX=21600
log() { echo "$(date -u +%FT%TZ) $*" >> "$BOARD_LOG"; }
hhmm() { date -d "@$1" +%H:%M 2>/dev/null || date -r "$1" +%H:%M; }

# בתוך נסיגה — לא שולחים אף בקשה
until_ts="$(link_get backoff_until)"; until_ts="${until_ts:-0}"
if [ "$until_ts" -gt "$(date +%s)" ]; then
  echo "⏸ המאזין בנסיגה עד $(hhmm "$until_ts") ($(link_get backoff_reason)) — לא להפעיל לפני כן"
  exit 4
fi

log "start pid=$$ until=$(date -u -d "@$END" +%T 2>/dev/null)"
listen_state running

back_off() {   # back_off <reason>
  local level delay now after
  now=$(date +%s)
  level=$(( $(link_get backoff_level || true) + 1 ))
  delay=$(( BACKOFF_FIRST << (level - 1) ))
  [ "$delay" -gt "$BACKOFF_MAX" ] && delay=$BACKOFF_MAX
  after="${BOARD_RETRY_AFTER:-0}"
  [ "$after" -gt "$delay" ] && delay=$after
  link_set backoff_level "$level"
  link_set backoff_until "$(( now + delay ))"
  link_set backoff_reason "$(jq -Rn --arg r "$1" '$r')"
  listen_state "backoff:$(( now + delay )):$1"
  log "backoff level=$level delay=${delay}s reason=$1"
  echo "🛑 הלוח לא זמין ($1) — המאזין יצא, ולא יחזור לפני $(hhmm $(( now + delay ))). לא להפעיל ידנית לפני כן."
  exit 4
}

# הפלט לקובץ, לא ‎$( )‎ — בתת-מעטפת BOARD_HTTP ו-BOARD_RETRY_AFTER הולכים לאיבוד, ו-429 נראה כ"רשת"
OUT="$(mktemp)"; trap 'rm -f "$OUT"' EXIT
while [ "$(date +%s)" -lt "$END" ]; do
  board_call channel-wait '{"wait":0}' "$TOKEN" 20 > "$OUT"; rc=$?; out="$(cat "$OUT")"
  if [ $rc = 0 ]; then
    [ -n "$(link_get backoff_level)" ] && link_set backoff_level 0
    n="$(echo "$out" | jq '.messages | length')"
    if [ "$n" -gt 0 ]; then
      echo "$out" | jq '.messages' > "$BOARD_PENDING"
      link_set last_message_at "$(date +%s)"
      log "messages=$n ids=$(echo "$out" | jq -c '[.messages[].id]')"
      echo "📥 $n הודעות מהלוח — הרץ: claude-tasks/bridge/link.sh pending"
      exit 0
    fi
  else
    log "http=$BOARD_HTTP rc=$rc retry_after=$BOARD_RETRY_AFTER $(echo "$out" | head -c 160 | tr '\n' ' ')"
    if [ $rc = 1 ] && { [ "$BOARD_HTTP" = 401 ] || [ "$BOARD_HTTP" = 403 ]; }; then
      echo "🔌 הערוץ נותק או שהאסימון נפסל (HTTP $BOARD_HTTP) — link.sh register מחדש"
      exit 3
    fi
    case "$BOARD_HTTP" in
      503) code="$(echo "$out" | jq -r '.code // empty' 2>/dev/null)"
           # שלב 24א: המתג לא "🎧" / כל המקומות תפוסים — לא תקלה: יוצאים לבדיקה העצמית, בלי נסיגה
           if [ "$code" = listeners_off ]; then listen_state off_policy; log "listeners_off"
             echo "🕐 הלוח במצב \"בדיקה שעתית\" — המאזין יצא (לא תקלה). אל תפעיל אותו שוב; הבדיקה העצמית שלך תבדוק את הלוח"; exit 5
           elif [ "$code" = listener_slots_full ]; then listen_state slots_full; log "slots_full"
             echo "🎧 כל המקומות למאזינים תפוסים — המאזין יצא (לא תקלה). הבדיקה העצמית שלך תבדוק את הלוח"; exit 5
           elif [ "$code" = bridge_paused ]; then listen_state paused; back_off "המאזינים מושהים בלוח"
           else back_off "HTTP 503"; fi ;;
      429|508|5??) back_off "HTTP $BOARD_HTTP" ;;
      0) back_off "רשת או timeout" ;;
      *) [ $rc = 2 ] && back_off "HTTP $BOARD_HTTP" ;;   # 4xx אחר — הבקשה עצמה; ממשיכים בקצב הרגיל
    esac
  fi
  # הקצב: 10 ש' בשתי הדקות שאחרי הודעה, אחרת 30±10
  last="$(link_get last_message_at)"; last="${last:-0}"
  if [ $(( $(date +%s) - last )) -lt $BUSY_FOR ]; then
    sleep $BUSY
  else
    sleep $(( IDLE - 10 + RANDOM % 21 ))
  fi
done
log "timeout"
listen_state ended
echo "⏱ המאזין הסתיים (זמן) — להפעיל מחדש"
exit 0
