#!/usr/bin/env bash
# Stop → channel-post: התשובה של קלוד משתקפת בערוץ (docs/spec.md §5.6).
#
# הטקסט מגיע ב-last_assistant_message שבקלט ה-hook — בלי לפרסר את ה-transcript.
# אם הצ'אט ענה עכשיו על הודעה מהלוח (link.sh pending רשם pending_reply_to) — נשלח
# כתשובה אליה, והסימון מתנקה. פעיל רק בצ'אט רשום; כשל רשת — שקט, timeout 5.
# ופעם ב-5 דקות — channel-health ("הצ'אט מתארך", §5.9.3).
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

# "הצ'אט מתארך" (§5.9.3) — לכל היותר פעם ב-5 דקות: גודל ה-transcript, תורות של בעל המערכת,
# ודחיסות (compact_boundary — הסימן החזק שההקשר כבר נגמר פעם). נשמר על הערוץ, לא כהודעה.
tp="$(printf '%s' "$input" | jq -r '.transcript_path // empty' 2>/dev/null)"
last="$(link_get health_at)"; now="$(date +%s)"
if [ -n "$tp" ] && [ -f "$tp" ] && [ $(( now - ${last:-0} )) -ge 300 ]; then
  bytes="$(wc -c < "$tp" | tr -d ' ')"
  turns="$(jq -r 'select(.type == "user" and (.message.content | type) == "string" and (.isMeta | not)) | 1' "$tp" 2>/dev/null | wc -l | tr -d ' ')"
  compactions="$(grep -c '"compact_boundary"' "$tp" 2>/dev/null)"
  [ "${compactions:-0}" -gt 0 ] || compactions="$(grep -c '"isCompactSummary":true' "$tp" 2>/dev/null)"
  h="$(jq -n --argjson b "${bytes:-0}" --argjson t "${turns:-0}" --argjson c "${compactions:-0}" '{bytes:$b, turns:$t, compactions:$c}')"
  board_call channel-health "$h" "$(link_get channel_token)" 5 >/dev/null 2>&1 && link_set health_at "$now"
fi
exit 0
