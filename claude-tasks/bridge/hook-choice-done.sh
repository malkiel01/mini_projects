#!/usr/bin/env bash
# PostToolUse (AskUserQuestion) → התשובה שנבחרה בחלון משתקפת ללוח (docs/spec.md §5.10).
#
# רץ רק כשהכלי באמת רץ — כלומר במצב "באפליקציה" (במצב "בלוח" hook-choice.sh דוחה
# אותו, ו-PostToolUse לא נקרא). שולח choice_answer עם reply_to לשאלה, והשרת מסמן
# אותה "נענתה באפליקציה". פעיל רק בצ'אט רשום; כשל רשת — שקט.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
choice="$(link_get pending_choice)"
[ -n "$choice" ] || exit 0
input="$(cat)"
# התשובות: tool_response.answers ({שאלה: תשובה}) כשיש; אחרת התשובה כטקסט
answers="$(printf '%s' "$input" | jq -c '.tool_response.answers // null' 2>/dev/null)"
[ -n "$answers" ] || answers=null
text="$(printf '%s' "$input" | jq -r --arg id "$choice" '
  "תשובה לשאלה #\($id):\n" + (
    if (.tool_response.answers | type) == "object"
    then [.tool_response.answers | to_entries[] | "• \(.key): \(.value | if type == "array" then join(", ") else tostring end)"] | join("\n")
    else (.tool_response | if type == "string" then . else tojson end) end)' 2>/dev/null)"
[ -n "$text" ] || exit 0
body="$(jq -n --arg b "$(clip "$text")" --argjson r "$choice" --argjson a "$answers" \
          '{author_role:"owner", kind:"choice_answer", body:$b, reply_to:$r, answers:$a}')"
board_call channel-post "$body" "$(link_get channel_token)" 5 >/dev/null 2>&1 && link_set pending_choice null
exit 0
