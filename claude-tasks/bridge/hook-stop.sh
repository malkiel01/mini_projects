#!/usr/bin/env bash
# Stop → channel-post: התשובה של קלוד משתקפת בערוץ (docs/spec.md §5.6).
#
# הטקסט מגיע ב-last_assistant_message שבקלט ה-hook — בלי לפרסר את ה-transcript.
# אם הצ'אט ענה עכשיו על הודעה מהלוח (link.sh pending רשם pending_reply_to) — נשלח
# כתשובה אליה, והסימון מתנקה. פעיל רק בצ'אט רשום; כשל רשת — שקט, timeout 5.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
input="$(cat)"
text="$(printf '%s' "$input" | jq -r '.last_assistant_message // empty' 2>/dev/null)"
[ -n "$text" ] || exit 0
reply="$(link_get pending_reply_to)"
body="$(jq -n --arg b "$(clip "$text")" --arg r "$reply" \
          '{author_role:"chat", body:$b} + (if $r != "" then {reply_to: ($r|tonumber)} else {} end)')"
if board_call channel-post "$body" "$(link_get channel_token)" 5 >/dev/null 2>&1; then
  [ -n "$reply" ] && link_set pending_reply_to null
fi
exit 0
