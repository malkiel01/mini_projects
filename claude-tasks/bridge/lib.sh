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

# גרסת הגשר — לעדכן יחד עם BRIDGE_VERSION_CURRENT ב-lib/bridgever.php (§5.10.1)
BRIDGE_VERSION="2026-11-03.5"
# עותק הריצה: hook.sh ו-link.sh מריצים ממנו כשקיים. link.sh update מרענן אותו מ-origin/main
# בלי לגעת בעץ העבודה של הצ'אט (update.sh)
BRIDGE_RUNTIME="$HOME/.claude-tasks-bridge"
BRIDGE_ROOT="${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel 2>/dev/null || git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel 2>/dev/null)}"
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
# ‏BOARD_HTTP מחזיק את קוד ה-HTTP האחרון (למאזין: 429, 508 וכו'), ו-BOARD_RETRY_AFTER — את
# הכותרת Retry-After בשניות (0 = לא נשלחה).
BOARD_HTTP=0
BOARD_RETRY_AFTER=0
board_call() {
  local action="$1" body="$2" token="$3" max="${4:-15}" cookie out code try hdr
  cookie="$(board_cookie)"
  hdr="$(mktemp)"
  for try in 1 2; do
    out="$(curl -sS -m "$max" -D "$hdr" -w $'\n%{http_code}' -X POST \
            -H 'Content-Type: application/json' -H "X-Worker-Token: $token" \
            -H "X-Worker-Name: ${BOARD_WORKER_NAME:-chat}" -H "X-Worker-Route: ${BOARD_ROUTE:-chat}" -b "$cookie" \
            --data-binary "$body" "$(board_url)?action=$action" 2>/dev/null)" || { BOARD_HTTP=0; BOARD_RETRY_AFTER=0; rm -f "$hdr"; return 2; }
    code="${out##*$'\n'}"; out="${out%$'\n'*}"; BOARD_HTTP="$code"
    BOARD_RETRY_AFTER="$(tr -d '\r' < "$hdr" | awk 'tolower($1)=="retry-after:" && $2 ~ /^[0-9]+$/ {v=$2} END {print v+0}')"
    if [ "$code" = 409 ] && [ "$try" = 1 ]; then
      local fresh
      fresh="$(printf '%s' "$out" | grep -oE 'document\.cookie *= *"[^";]+' | head -1 | sed -E 's/.*"//')"
      if [ -n "$fresh" ] && [ "$fresh" != "$cookie" ]; then
        cookie="$fresh"; link_set cookie "$(jq -Rn --arg c "$fresh" '$c')"; continue
      fi
    fi
    break
  done
  rm -f "$hdr"
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

# שאלת בחירה שנשמרה מקומית כשהלוח לא ענה (hook-choice.sh, §5.10.1) — נשלחת כשהוא חוזר,
# ואחריה התשובה שבעל הלוח הקליד בינתיים (pending_choice_answer), כתשובה אליה.
# מחזיר 0 כשאין מה לשלוח או שנשלח; 1 — הלוח עדיין לא עונה (נשאר לפעם הבאה).
flush_local_choice() {
  [ "$(link_get pending_choice)" = local ] || return 0
  local q body f id ans
  q="$(jq -c '.pending_choice_questions // empty' "$BOARD_LINK" 2>/dev/null)"
  [ -n "$q" ] || { link_set pending_choice null; return 0; }
  body="$(jq -n --argjson q "$q" '{author_role:"chat", kind:"choice", questions:$q}')"
  f="$(mktemp)"; board_call channel-post "$body" "$(link_get channel_token)" 5 > "$f" 2>/dev/null
  id="$(jq -r '.id // empty' "$f" 2>/dev/null)"; rm -f "$f"
  [ -n "$id" ] || return 1
  link_set pending_choice "$id"; link_set pending_choice_questions null
  ans="$(link_get pending_choice_answer)"
  [ -n "$ans" ] || return 0
  body="$(jq -n --arg b "$(clip "$ans")" --argjson r "$id" '{author_role:"owner", body:$b, reply_to:$r}')"
  if board_call channel-post "$body" "$(link_get channel_token)" 5 >/dev/null 2>&1; then
    link_set pending_choice null; link_set pending_choice_answer null
  fi
  return 0
}

# 🔐 בקשות הרשאה (§5.12): סמנים מקומיים — מחוץ לריפו, כדי שלא ילכלכו את git status
# ‏open-<key> = מזהה הבקשה בלוח; resolved-<key> = הכלי כבר רץ / נדחה (PostToolUse, PermissionDenied)
perm_dir() {
  local d="${TMPDIR:-/tmp}/claude-tasks-perm-$(printf '%s' "$BOARD_LINK" | sha256sum | cut -c1-12)"
  mkdir -p "$d" 2>/dev/null; printf '%s' "$d"
}
# perm_key <hook-input-json> — אותו מפתח ב-PermissionRequest וב-PostToolUse (אין tool_use_id בראשון)
perm_key() {
  printf '%s' "$1" | jq -cS '[.tool_name, .tool_input]' 2>/dev/null | sha256sum | cut -c1-32
}
