#!/usr/bin/env bash
# הגשר החי — צד הצ'אט: עזרים משותפים (docs/spec.md §5.6).
#
# כל פנייה ללוח מכאן עוברת דרך board_call, ששולח את עוגיית הגנת הבוטים של
# האחסון: בקשה בלי דפדפן מקבלת 409 ודף שמציב עוגייה (humans_NNNN=1). אם השם
# השתנה — מחלצים את החדשה מהדף, מנסים פעם אחת נוספת, ושומרים בקובץ הקישור.
#
# קבצים (מחוץ לגיט — .gitignore):
#   .claude/board-link.json     הקישור לערוץ: url, עוגייה, channel_id, channel_token, …
#   .claude/board-pending.json  הודעות שהמאזין קיבל ועוד לא טופלו
#   .claude/board-listen.log    יומן המאזין (חסימות, 508, שגיאות רשת)

BRIDGE_ROOT="${CLAUDE_PROJECT_DIR:-$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel 2>/dev/null)}"
BOARD_LINK="$BRIDGE_ROOT/.claude/board-link.json"
BOARD_PENDING="$BRIDGE_ROOT/.claude/board-pending.json"
BOARD_LOG="$BRIDGE_ROOT/.claude/board-listen.log"
BOARD_DEFAULT_URL="https://mbe-plus.com/mini_projects/claude-tasks/api.php"

link_get() {   # link_get <key>  → הערך מקובץ הקישור, או ריק
  [ -f "$BOARD_LINK" ] && jq -r --arg k "$1" '.[$k] // empty' "$BOARD_LINK" 2>/dev/null
}

link_set() {   # link_set <key> <json-value>
  [ -f "$BOARD_LINK" ] || return 0
  local tmp; tmp="$(mktemp)"
  jq --arg k "$1" --argjson v "$2" '.[$k] = $v' "$BOARD_LINK" > "$tmp" && cat "$tmp" > "$BOARD_LINK"
  rm -f "$tmp"
}

board_url()    { local u; u="$(link_get url)"; echo "${u:-${CLAUDE_TASKS_URL:-$BOARD_DEFAULT_URL}}"; }
board_cookie() { local c; c="$(link_get cookie)"; echo "${c:-${CLAUDE_TASKS_COOKIE:-humans_21909=1}}"; }

# board_call <action> <json-body> <token> [max-seconds]
# מדפיס את גוף התשובה. מחזיר 0 כש-success=true; 1 — הלוח דחה; 2 — רשת/חסימה.
# ‏BOARD_HTTP מחזיק את קוד ה-HTTP האחרון (למאזין: 508 וכו').
BOARD_HTTP=0
board_call() {
  local action="$1" body="$2" token="$3" max="${4:-15}" cookie out code try
  cookie="$(board_cookie)"
  for try in 1 2; do
    out="$(curl -sS -m "$max" -w $'\n%{http_code}' -X POST \
            -H 'Content-Type: application/json' -H "X-Worker-Token: $token" \
            -H "X-Worker-Name: ${BOARD_WORKER_NAME:-chat}" -b "$cookie" \
            --data-binary "$body" "$(board_url)?action=$action" 2>/dev/null)" || { BOARD_HTTP=0; return 2; }
    code="${out##*$'\n'}"; out="${out%$'\n'*}"; BOARD_HTTP="$code"
    if [ "$code" = 409 ] && [ "$try" = 1 ]; then
      local fresh
      fresh="$(printf '%s' "$out" | grep -oE 'document\.cookie *= *"[^";]+' | head -1 | sed -E 's/.*"//')"
      if [ -n "$fresh" ] && [ "$fresh" != "$cookie" ]; then
        cookie="$fresh"; link_set cookie "$(jq -Rn --arg c "$fresh" '$c')"; continue
      fi
    fi
    break
  done
  printf '%s' "$out"
  [ "$(printf '%s' "$out" | jq -r '.success // false' 2>/dev/null)" = true ] && return 0
  case "$code" in 2??|4??) return 1 ;; *) return 2 ;; esac
}

# מזהה הסשן וכתובתו — מהסביבה של Claude Code בענן
session_id() {
  local s="${CLAUDE_CODE_REMOTE_SESSION_ID:-$CLAUDE_CODE_SESSION_ID}"
  echo "${s/#cse_/session_}"
}
session_url() {
  local s; s="$(session_id)"
  case "$s" in session_*) echo "https://claude.ai/code/$s" ;; *) echo "" ;; esac
}

# גוף הודעה: עד 20,000 תווים
clip() { local t="$1"; [ "${#t}" -gt 20000 ] && t="${t:0:20000}…(truncated)"; printf '%s' "$t"; }
