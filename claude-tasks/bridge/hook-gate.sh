#!/usr/bin/env bash
# PreToolUse → שער המדיניות של הלוח (docs/spec.md §5.13, שלב 14). רץ על כל כלי, בכל סוג שיחה:
#
#   💬 צ'אט (default / acceptEdits / plan)   — יש אדם מול המסך. ask → החלון באפליקציה (ושלב 13 מעלה אותו ללוח).
#   🔁 Routine (auto / bypassPermissions / dontAsk) — אין אדם. ask → כרטיס בלוח ומחכים עד 10 דק'; אין תשובה → נדחה.
#   ☁️ Platform (CT_GATE_KIND=platform, ב-Actions) — ask → ask-approval על המטלה, ונדחה "נשלח לאישור".
#
# 1. סיווג מקומי (php classify.php — אותו קוד שהשרת מריץ). לא ממופה → בלי החלטה, יוצאים מיד.
# 2. לא רשום: ללא השגחה → נדחה (אין מדיניות לבדוק); אינטראקטיבי → בלי החלטה.
# 3. פסק דין allow / deny שמור 5 דקות לכל סוג. אחרת — gate-check בלוח (השרת מסווג שוב, ומכריע).
# 4. הלוח לא עונה / ⏸: ללא השגחה → נדחה (fail-closed); אינטראקטיבי → בלי החלטה (כמו שלב 13).
# יוצא תמיד 0. בלי פלט = בלי החלטה = Claude Code ממשיך כרגיל.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
input="$(cat)"
tool="$(printf '%s' "$input" | jq -r '.tool_name // empty' 2>/dev/null)"
[ -n "$tool" ] || exit 0
mode="$(printf '%s' "$input" | jq -r '.permission_mode // empty' 2>/dev/null)"
kind="${CT_GATE_KIND:-}"
case "$mode" in auto|bypassPermissions|dontAsk) unattended=1 ;; *) unattended=0 ;; esac
[ "$kind" = platform ] && unattended=1

emit() {   # emit <allow|deny|ask> <reason>
  jq -cn --arg d "$1" --arg r "$2" '{hookSpecificOutput:{hookEventName:"PreToolUse", permissionDecision:$d, permissionDecisionReason:$r}}'
  exit 0
}
deny_unattended() { [ "$unattended" = 1 ] && emit deny "🔐 השער של הלוח חסם: $1. אין כאן אדם שיאשר. אל תנסה דרך עוקפת — דווח בלוח מה נחסם ולמה."; exit 0; }

branch="$(git -C "$BRIDGE_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null)"
ctx="$(printf '%s' "$input" | jq -c --arg p "$(link_get project_folder)" --arg r "$BRIDGE_ROOT" --arg b "$branch" --arg s "$(session_id)" \
        '{tool_name, tool_input, project_path:$p, root:$r, cwd:(.cwd // ""), branch:$b, session_id:$s}' 2>/dev/null)"
[ -n "$ctx" ] || exit 0

# 1. סיווג
if command -v php >/dev/null 2>&1 && [ -f "$here/classify.php" ]; then
  type="$(printf '%s' "$ctx" | php "$here/classify.php" 2>/dev/null | jq -r '.type // empty' 2>/dev/null)"
else
  # אין php — רק ללא השגחה, ורק הגס: פקודות שעלולות להרוס
  [ "$unattended" = 1 ] || exit 0
  cmd="$(printf '%s' "$input" | jq -r '.tool_input.command // empty')"
  printf '%s' "$cmd" | grep -Eq '(^|[;&|[:space:]])(rm|git[[:space:]]+push|gh[[:space:]]+pr[[:space:]]+merge)([[:space:]]|$)|curl[^|]*\|' || exit 0
  type="unknown"
fi
[ -n "$type" ] || exit 0

# 2. רישום
if [ "$kind" = platform ]; then
  token="${BOARD_TOKEN:-}"; export BOARD_ROUTE=platform BOARD_WORKER_NAME=claude-code
  [ -n "$token" ] && [ -n "${TASK_ID:-}" ] || deny_unattended "$type (אין אסימון לוח)"
else
  token="$(link_get channel_token)"
  if [ -z "$token" ] || [ ! -f "$BOARD_LINK" ]; then deny_unattended "$type (הצ'אט לא רשום בלוח)"; fi
fi

# 3. מטמון — 5 דקות לסוג
dir="$(perm_dir)"
cache="$dir/gate-$type"
if [ -f "$cache" ]; then
  read -r at verdict < "$cache" 2>/dev/null
  if [ -n "$at" ] && [ $(( $(date +%s) - at )) -lt "${CT_GATE_CACHE:-300}" ]; then
    case "$verdict" in
      allow) emit allow "🔐 מותר לפי מדיניות הלוח" ;;
      deny)  emit deny "$(tail -n +2 "$cache")" ;;
    esac
  fi
fi

key="$(perm_key "$input")"
body="$(printf '%s' "$ctx" | jq -c --arg m "$mode" --arg k "$key" --arg t "${TASK_ID:-}" \
         '. + {permission_mode:$m, key:$k} + (if $t != "" then {task_id:($t|tonumber)} else {} end)')"
f="$(mktemp)"; board_call gate-check "$body" "$token" 8 > "$f" 2>/dev/null; rc=$?
res="$(cat "$f")"; rm -f "$f"
# 4. הלוח לא זמין (רשת, ⏸ = 503, 429)
[ $rc = 0 ] || deny_unattended "$type (הלוח לא זמין — HTTP $BOARD_HTTP)"

verdict="$(printf '%s' "$res" | jq -r '.verdict // empty')"
reason="$(printf '%s' "$res" | jq -r '.reason // empty')"
label="$(printf '%s' "$res" | jq -r '.label // empty')"
remember() { [ "$(printf '%s' "$res" | jq -r '.cache // false')" = true ] && printf '%s %s\n%s' "$(date +%s)" "$1" "$reason" > "$cache"; }
case "$verdict" in
  none|'') exit 0 ;;
  allow) remember allow; emit allow "🔐 מותר לפי מדיניות הלוח: $label" ;;
  deny)  remember deny;  emit deny "$reason" ;;
esac

# ask
[ "$unattended" = 1 ] || emit ask "${reason:-🔐 לפי מדיניות הלוח — דורש אישור}"
id="$(printf '%s' "$res" | jq -r '.message_id // 0')"
[ "$id" != 0 ] || deny_unattended "$label"

POLL="${CT_PERM_POLL:-10}"; LIMIT="${CT_PERM_LIMIT:-600}"
start=$(date +%s)
while [ $(( $(date +%s) - start )) -lt "$LIMIT" ]; do
  sleep "$POLL"
  f="$(mktemp)"; board_call permission-status "$(jq -n --argjson i "$id" '{message_id:$i}')" "$token" 8 > "$f" 2>/dev/null; rc=$?
  st="$(cat "$f")"; rm -f "$f"
  [ "$BOARD_HTTP" = 503 ] && break
  [ $rc = 0 ] || continue
  case "$(printf '%s' "$st" | jq -r '.status // empty')" in
    answered)
      case "$(printf '%s' "$st" | jq -r '.decision // empty')" in
        once|always) emit allow "🔐 בעל הלוח אישר בלוח: $label" ;;
        *) emit deny "$(printf '%s' "$st" | jq -r --arg l "$label" '"🔐 בעל הלוח דחה בלוח: " + $l + (if (.reason // "") != "" then ". הסיבה: " + .reason else "" end) + ". אל תנסה שוב באותה דרך."')" ;;
      esac ;;
    resolved|moved_to_app) break ;;
  esac
done
board_call permission-close "$(jq -n --argjson i "$id" '{message_id:$i, how:"timeout"}')" "$token" 5 >/dev/null 2>&1
emit deny "🔐 לא אושר בזמן: $label — אף אחד לא ענה בלוח תוך $(( (LIMIT + 59) / 60 )) דק'. הבקשה נשארת בלוח; דווח בסוף ההרצה מה נחסם."
