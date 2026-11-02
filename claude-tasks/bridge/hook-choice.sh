#!/usr/bin/env bash
# PreToolUse (AskUserQuestion) → שאלת בחירה בלוח (docs/spec.md §5.10, §5.10.1).
#
# פעיל רק בצ'אט רשום (.claude/board-link.json). בלי הקובץ — יוצא 0 והחלון עובד כרגיל.
# מפרסם את השאלות כמו שהן (channel-post kind choice) ושומר pending_choice בקובץ הקישור.
# המצב (channels.questions) נשמר גם בקובץ הקישור (questions, ברירת מחדל board):
#   board — דוחה את הכלי: הצ'אט מדפיס את השאלות ממוספרות ומסיים את התור; התשובה
#           מגיעה מהלוח (choice_answer) או כהקלדה של בעל הלוח בצ'אט.
#   app   — הכלי רץ (החלון באפליקציה); hook-choice-done.sh ישקף את התשובה.
# הלוח לא ענה (429, 503, רשת): fail-safe — במצב board דוחים בכל זאת, והשאלה נשמרת מקומית
# (pending_choice=local); hook-prompt / hook-stop שולחים אותה, ואת התשובה, כשהלוח חוזר.
# החלון נפתח רק כשהמצב השמור הוא app (§5.10.1: לפני כן החלון נפתח בכל פעם שהלוח לא ענה).
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
input="$(cat)"
questions="$(printf '%s' "$input" | jq -c '.tool_input.questions // empty' 2>/dev/null)"
[ -n "$questions" ] || exit 0

deny() {
  jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse", permissionDecision:"deny", permissionDecisionReason:$r}}'
  exit 0
}

body="$(jq -n --argjson q "$questions" '{author_role:"chat", kind:"choice", questions:$q}')"
out_f="$(mktemp)"
board_call channel-post "$body" "$(link_get channel_token)" 8 > "$out_f" 2>/dev/null; rc=$?
out="$(cat "$out_f")"; rm -f "$out_f"
id="$(printf '%s' "$out" | jq -r '.id // empty' 2>/dev/null)"

if [ $rc = 0 ] && [ -n "$id" ]; then
  mode="$(printf '%s' "$out" | jq -r '.mode // "board"')"
  link_set questions "$(jq -Rn --arg m "$mode" '$m')"
  link_set pending_choice "$id"
  [ "$mode" = app ] && exit 0
  deny "The question was sent to the board (message #$id). End your turn now, after printing the same questions in the chat as numbered options (header, question, then 1. label — description …), so the owner can also answer here by typing the number(s) or free text. Do not ask again with the tool. The answer will arrive as a board message (\"תשובה לשאלה #$id\") or as the owner's next prompt — act on it then."
fi

# הלוח לא ענה — לפי המצב השמור
mode="$(link_get questions)"
[ "$mode" = app ] && exit 0
link_set pending_choice '"local"'
link_set pending_choice_questions "$questions"
deny "The board is unreachable right now. Print the question in the chat as numbered options (header, question, then 1. label — description …) and end your turn; the owner will answer here. Do not ask again with the tool."
