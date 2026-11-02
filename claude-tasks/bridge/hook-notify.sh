#!/usr/bin/env bash
# Notification — חלון הרשאה באפליקציה משתקף ללוח (docs/spec.md §5.10.1).
#
# הלוח לא יכול לאשר כלי — זה נשאר באפליקציה. אבל בעל הלוח צריך לדעת למה הצ'אט השתתק: הודעת
# system "⏸ הצ'אט ממתין לאישור באפליקציה: <כלי>" עם קישור לסשן, ו-⏸ בשורת הערוץ (השרת מנקה
# את הסימון כשהצ'אט שולח משהו שוב, ולא שולח שוב על אותו כלי). התראות אחרות (המתנה לקלט) — כלום.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
input="$(cat)"
msg="$(printf '%s' "$input" | jq -r '.message // empty' 2>/dev/null)"
type="$(printf '%s' "$input" | jq -r '.notification_type // empty' 2>/dev/null)"
case "$type:$msg" in
  permission_prompt:*|*"permission"*) ;;
  *) exit 0 ;;
esac
# "Claude needs your permission to use <tool>" → <tool>
tool="$(printf '%s' "$msg" | sed -nE 's/.*permission to use (.+)$/\1/p' | head -c 80)"
[ -n "$tool" ] || tool="$(printf '%s' "$msg" | head -c 80)"
body="$(jq -n --arg t "$tool" '{tool:$t}')"
board_call channel-waiting "$body" "$(link_get channel_token)" 5 >/dev/null 2>&1
exit 0
