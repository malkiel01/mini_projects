#!/usr/bin/env bash
# PreToolUse — אישור אוטומטי לכלי הגשר, לפי סיומת (docs/spec.md §5.10.1).
#
# בסשן בענן אותו שרת MCP מופיע לפעמים בשם מחולל (mcp__<uuid>__create_trigger), ורשימת ההיתרים
# ב-settings.json לא תופסת אותו — הצ'אט נעצר על חלון "Allow?" באפליקציה. כאן: בצ'אט רשום בלבד,
# ורק ששת הכלים שבעל הלוח כבר התיר בשם — allow. שם שאינו ברשימה — שורת system ללוח, פעם אחת
# לכל שם, כדי שהכינוי החדש ייראה.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
input="$(cat)"
tool="$(printf '%s' "$input" | jq -r '.tool_name // empty' 2>/dev/null)"
case "$tool" in
  *__create_trigger|*__send_later|*__get_session|*__list_sessions|*__get_trigger|*__list_triggers) ;;
  *) exit 0 ;;
esac

settings="$BRIDGE_ROOT/.claude/settings.json"
if ! jq -e --arg t "$tool" '.permissions.allow // [] | index($t)' "$settings" >/dev/null 2>&1; then
  if ! jq -e --arg t "$tool" '.allow_seen // [] | index($t)' "$BOARD_LINK" >/dev/null 2>&1; then
    body="$(jq -n --arg b "🔓 אושר אוטומטית כלי בשם שאינו ברשימת ההיתרים: $tool" '{author_role:"system", body:$b}')"
    board_call channel-post "$body" "$(link_get channel_token)" 5 >/dev/null 2>&1
    link_set allow_seen "$(jq -c --arg t "$tool" '(.allow_seen // []) + [$t]' "$BOARD_LINK")"
  fi
fi
jq -n '{hookSpecificOutput:{hookEventName:"PreToolUse", permissionDecision:"allow",
        permissionDecisionReason:"Bridge tool, already allowed by the board owner (claude-tasks §5.10.1)"}}'
exit 0
