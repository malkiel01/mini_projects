#!/usr/bin/env bash
# PreToolUse (AskUserQuestion) → שאלת בחירה בלוח (docs/spec.md §5.10).
#
# פעיל רק בצ'אט רשום (.claude/board-link.json). בלי הקובץ — יוצא 0 והחלון עובד כרגיל.
# מפרסם את השאלות כמו שהן (channel-post kind choice) ושומר pending_choice בקובץ הקישור.
# לפי מה שהלוח מחזיר (channels.questions):
#   board — דוחה את הכלי: הצ'אט מדפיס את השאלות ממוספרות ומסיים את התור; התשובה
#           מגיעה מהלוח (choice_answer) או כהקלדה של בעל הלוח בצ'אט.
#   app   — הכלי רץ (החלון באפליקציה); hook-choice-done.sh ישקף את התשובה.
# הלוח לא ענה — החלון רץ כרגיל (עדיף שאלה באפליקציה משאלה שנעלמה).
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
input="$(cat)"
questions="$(printf '%s' "$input" | jq -c '.tool_input.questions // empty' 2>/dev/null)"
[ -n "$questions" ] || exit 0

body="$(jq -n --argjson q "$questions" '{author_role:"chat", kind:"choice", questions:$q}')"
out="$(board_call channel-post "$body" "$(link_get channel_token)" 8 2>/dev/null)" || exit 0
id="$(printf '%s' "$out" | jq -r '.id // empty')"
mode="$(printf '%s' "$out" | jq -r '.mode // "board"')"
[ -n "$id" ] || exit 0
link_set pending_choice "$id"
[ "$mode" = app ] && exit 0

reason="The question was sent to the board (message #$id). End your turn now, after printing the same questions in the chat as numbered options (header, question, then 1. label — description …), so the owner can also answer here by typing the number(s) or free text. Do not ask again with the tool. The answer will arrive as a board message (\"תשובה לשאלה #$id\") or as the owner's next prompt — act on it then."
jq -n --arg r "$reason" '{hookSpecificOutput:{hookEventName:"PreToolUse", permissionDecision:"deny", permissionDecisionReason:$r}}'
exit 0
