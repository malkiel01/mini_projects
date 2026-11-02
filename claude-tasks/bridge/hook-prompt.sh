#!/usr/bin/env bash
# UserPromptSubmit → channel-post: מה שנכתב בצ'אט משתקף בערוץ (docs/spec.md §5.6).
#
# פעיל רק בצ'אט רשום (.claude/board-link.json). בלי הקובץ — יוצא מיד, בלי כלום.
# לא משקף:
#   "<…"           — <task-notification> (מאזין שהעיר, send_later, אירועי רקע) ועטיפות מערכת אחרות
#   "📥 הודעה מהלוח" — הודעה שכבר הגיעה מהלוח (אחרת היא הייתה מופיעה פעמיים)
# הודעה בין צ'אטים ("📨") — כ-system, לא כ-owner. כשל רשת — שקט, timeout 5.
# כשיש שאלת בחירה פתוחה (pending_choice) — ההודעה נשלחת כתשובה אליה (reply_to).
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
input="$(cat)"
prompt="$(printf '%s' "$input" | jq -r '.prompt // empty' 2>/dev/null)"
[ -n "$prompt" ] || exit 0
case "$prompt" in
  "<"*|"📥 הודעה מהלוח"*|"This session's worker process was restarted"*) exit 0 ;;
  "📨"*) role=system ;;
  *) role=owner ;;
esac
# שאלת בחירה פתוחה (hook-choice.sh) — מה שבעל הלוח מקליד עכשיו הוא התשובה לה (§5.10)
choice=""; [ "$role" = owner ] && choice="$(link_get pending_choice)"
body="$(jq -n --arg r "$role" --arg b "$(clip "$prompt")" --arg c "$choice" \
          '{author_role:$r, body:$b} + (if $c != "" then {reply_to: ($c|tonumber)} else {} end)')"
if board_call channel-post "$body" "$(link_get channel_token)" 5 >/dev/null 2>&1; then
  [ -n "$choice" ] && link_set pending_choice null
fi
exit 0
