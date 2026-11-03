#!/usr/bin/env bash
# PermissionRequest → בקשת אישור בלוח (docs/spec.md §5.12, שלב 13).
#
# פעיל רק בצ'אט רשום (.claude/board-link.json). מודל המרוץ (§5.12.2a): החלון באפליקציה פתוח כל הזמן,
# ומי שעונה ראשון קובע — החלטה מאוחרת של ה-hook נזרקת. לכן:
#   1. מחכים 3 ש'. אם הכלי כבר רץ או נדחה (סמן resolved מ-PostToolUse / PermissionDenied) — אישור
#      אוטומטי, לא שאלה לבעל הלוח: לא מפרסמים כלום ויוצאים בלי החלטה.
#   2. מפרסמים בקשה (channel-post kind permission). ‏mode app / paused / הלוח לא עונה — בלי החלטה.
#   3. כל 10 ש', עד 10 דקות: הסמן הופיע → permission-close resolved; נענתה בלוח → allow / deny.
#   4. 10 דקות בלי תשובה → permission-close timeout ("⌛ עבר לאפליקציה"), ובלי החלטה.
# "תמיד בצ'אט הזה" — הכלל שהשרת בנה (Bash(<קידומת>:*) / שם כלי MCP), ב-destination session בלבד.
# יוצא תמיד 0. בלי פלט = בלי החלטה = Claude Code ממשיך כרגיל.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
input="$(cat)"
tool="$(printf '%s' "$input" | jq -r '.tool_name // empty' 2>/dev/null)"
[ -n "$tool" ] || exit 0
key="$(perm_key "$input")"
dir="$(perm_dir)"
token="$(link_get channel_token)"
[ -n "$token" ] || exit 0

WAIT_FIRST="${CT_PERM_FIRST:-3}"; POLL="${CT_PERM_POLL:-10}"; LIMIT="${CT_PERM_LIMIT:-600}"

sleep "$WAIT_FIRST"
[ -f "$dir/resolved-$key" ] && exit 0

body="$(printf '%s' "$input" | jq -c --arg k "$key" '{kind:"permission", author_role:"chat", tool_name, tool_input, key:$k}')"
f="$(mktemp)"; board_call channel-post "$body" "$token" 8 > "$f" 2>/dev/null
id="$(jq -r '.id // 0' "$f" 2>/dev/null)"; rm -f "$f"
[ -n "$id" ] && [ "$id" != 0 ] || exit 0
printf '%s' "$id" > "$dir/open-$key"

close() {   # close <how>
  board_call permission-close "$(jq -n --argjson i "$id" --arg h "$1" '{message_id:$i, how:$h}')" "$token" 5 >/dev/null 2>&1
  rm -f "$dir/open-$key"
}

start=$(date +%s)
while [ $(( $(date +%s) - start )) -lt "$LIMIT" ]; do
  sleep "$POLL"
  if [ -f "$dir/resolved-$key" ]; then close resolved; exit 0; fi
  f="$(mktemp)"; board_call permission-status "$(jq -n --argjson i "$id" '{message_id:$i}')" "$token" 8 > "$f" 2>/dev/null; rc=$?
  st="$(cat "$f")"; rm -f "$f"
  # ⏸ הלוח הושהה באמצע — לאפליקציה
  if [ "$BOARD_HTTP" = 503 ]; then close timeout; exit 0; fi
  [ $rc = 0 ] || continue          # 429 / רשת — מנסים שוב בסבב הבא
  case "$(printf '%s' "$st" | jq -r '.status // empty')" in
    answered)
      rm -f "$dir/open-$key"
      case "$(printf '%s' "$st" | jq -r '.decision // empty')" in
        once)   jq -n '{hookSpecificOutput:{hookEventName:"PermissionRequest", decision:{behavior:"allow"}}}' ;;
        always) printf '%s' "$st" | jq -c '{hookSpecificOutput:{hookEventName:"PermissionRequest", decision:{behavior:"allow",
                  updatedPermissions:[{type:"addRules", rules:[.rule], behavior:"allow", destination:"session"}]}}}' ;;
        deny)   printf '%s' "$st" | jq -c '{hookSpecificOutput:{hookEventName:"PermissionRequest", decision:{behavior:"deny",
                  message:("בעל הלוח דחה את הבקשה בלוח" + (if (.reason // "") != "" then ". הסיבה: " + .reason else "" end)
                           + ". אל תנסה שוב באותה דרך; אם צריך — שאל את בעל הלוח.")}}}' ;;
      esac
      exit 0 ;;
    resolved|moved_to_app) rm -f "$dir/open-$key"; exit 0 ;;
  esac
done
close timeout
exit 0
