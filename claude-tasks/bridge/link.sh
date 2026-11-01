#!/usr/bin/env bash
# הגשר החי — הקישור של הצ'אט לערוץ בלוח (docs/spec.md §5.5–5.6).
#
#   link.sh register --project <id> [--name <שם>] [--role <תפקיד>]
#                    רישום (או רישום מחדש עם אותו session_id). אסימון ההרשמה: $CLAUDE_TASKS_TOKEN
#   link.sh status   הערוץ, ומה ממתין
#   link.sh pending  ההודעות שממתינות לטיפול, כל אחת במסגרת קבועה; זוכר את האחרונה לתשובה (reply_to)
#   link.sh ack <id>…   סימון "טופל" — רק אחרי שטופלו, לא לפני
#   link.sh post <owner|chat|system> <טקסט>   שיקוף ידני
#   link.sh archive  ניתוק: האסימון נפסל וקובץ הקישור נמחק
set -u
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

die() { echo "✗ $*" >&2; exit 1; }
need_link() { [ -f "$BOARD_LINK" ] || die "הצ'אט לא רשום — link.sh register --project <id>"; }
token() { link_get channel_token; }

frame() {   # מסגרת קבועה להודעה מהלוח — כדי שלא תתערבב בהוראות המערכת
  jq -r --arg ch "$(link_get name)" '.[] |
    "📥 הודעה מהלוח · #\(.id) · מאת: \(.author_role) (\(.author_name // "—")) · ערוץ: \($ch)" +
    (if .task_id then " · מטלה #\(.task_id)" else "" end) + (if .kind != "text" then " · \(.kind)" else "" end) +
    "\n\(.body)\n"'
}

cmd="${1:-status}"; shift || true
case "$cmd" in
  register)
    project="${CLAUDE_TASKS_PROJECT:-}"; name=""; role=""
    while [ $# -gt 0 ]; do
      case "$1" in
        --project) project="$2"; shift 2 ;;
        --name)    name="$2"; shift 2 ;;
        --role)    role="$2"; shift 2 ;;
        *) die "פרמטר לא מוכר: $1" ;;
      esac
    done
    [ -n "${CLAUDE_TASKS_TOKEN:-}" ] || die "חסר CLAUDE_TASKS_TOKEN (אסימון ההרשמה) בהגדרות הסביבה"
    [ -n "$project" ] || die "חסר --project <id>"
    sid="$(session_id)"; [ -n "$sid" ] || die "אין מזהה סשן (CLAUDE_CODE_REMOTE_SESSION_ID)"
    [ -n "$name" ] || name="צ'אט ${sid: -6}"
    body="$(jq -n --argjson p "$project" --arg n "$name" --arg r "$role" --arg s "$sid" --arg u "$(session_url)" \
             '{project_id:$p, name:$n, role:$r, session_id:$s, session_url:$u}')"
    mkdir -p "$(dirname "$BOARD_LINK")"
    out="$(board_call channel-register "$body" "$CLAUDE_TASKS_TOKEN")" || die "הרישום נכשל (HTTP $BOARD_HTTP): $(echo "$out" | jq -r '.error // .' 2>/dev/null)"
    umask 077
    jq -n --argjson r "$out" --arg url "$(board_url)" --arg cookie "$(board_cookie)" --arg n "$name" \
          --argjson p "$project" --arg s "$sid" \
          '{url:$url, cookie:$cookie, channel_id:$r.channel_id, channel_token:$r.channel_token,
            project_id:$p, name:$n, session_id:$s, registered_at:(now|floor), pending_reply_to:null}' > "$BOARD_LINK"
    chmod 600 "$BOARD_LINK"
    echo "✓ רשום: ערוץ #$(link_get channel_id) · $name · פרויקט $project$(echo "$out" | jq -r 'if .renewed then " (רישום חוזר — אסימון חדש)" else "" end')"
    ;;

  status)
    need_link
    out="$(board_call channel-inbox '{}' "$(token)")" || die "הלוח לא ענה (HTTP $BOARD_HTTP) — אולי הערוץ נותק; link.sh register מחדש"
    echo "ערוץ #$(link_get channel_id) · $(link_get name) · $(board_url)"
    echo "ממתינות: $(echo "$out" | jq '.messages | length')"
    ;;

  pending)
    need_link
    # מקור האמת הוא השרת (inbox לא מסמן); הקובץ של המאזין — גיבוי כשהרשת נופלת
    if out="$(board_call channel-inbox '{}' "$(token)")"; then msgs="$(echo "$out" | jq '.messages')"
    elif [ -f "$BOARD_PENDING" ]; then msgs="$(cat "$BOARD_PENDING")"
    else die "הלוח לא ענה (HTTP $BOARD_HTTP)"; fi
    n="$(echo "$msgs" | jq 'length')"
    if [ "$n" = 0 ]; then echo "אין הודעות ממתינות."; rm -f "$BOARD_PENDING"; exit 0; fi
    echo "$msgs" | frame
    # התשובה הבאה של הצ'אט תירשם כתשובה להודעה האחרונה
    link_set pending_reply_to "$(echo "$msgs" | jq '.[-1].id')"
    echo "— אחרי הטיפול: link.sh ack $(echo "$msgs" | jq -r '[.[].id] | join(" ")')"
    ;;

  ack)
    need_link
    [ $# -gt 0 ] || die "שימוש: link.sh ack <id>…"
    ids="$(printf '%s\n' "$@" | jq -R 'tonumber' | jq -s '{ids: .}')"
    out="$(board_call channel-ack "$ids" "$(token)")" || die "ack נכשל (HTTP $BOARD_HTTP)"
    echo "✓ סומנו: $(echo "$out" | jq '.acked')"
    if [ -f "$BOARD_PENDING" ]; then
      jq --argjson ids "$(echo "$ids" | jq '.ids')" '[.[] | select(.id as $i | $ids | index($i) | not)]' "$BOARD_PENDING" > "$BOARD_PENDING.tmp" \
        && mv "$BOARD_PENDING.tmp" "$BOARD_PENDING"
      [ "$(jq length "$BOARD_PENDING")" = 0 ] && rm -f "$BOARD_PENDING"
    fi
    ;;

  post)
    need_link
    role="${1:-chat}"; shift || true
    body="$(jq -n --arg r "$role" --arg b "$(clip "$*")" '{author_role:$r, body:$b}')"
    board_call channel-post "$body" "$(token)" >/dev/null && echo "✓ נשלח" || die "השליחה נכשלה (HTTP $BOARD_HTTP)"
    ;;

  archive)
    need_link
    board_call channel-archive '{}' "$(token)" >/dev/null || echo "הלוח לא אישר (HTTP $BOARD_HTTP) — מוחק את הקישור בכל זאת" >&2
    rm -f "$BOARD_LINK" "$BOARD_PENDING"
    echo "✓ נותק"
    ;;

  *) die "פקודה לא מוכרת: $cmd (register | status | pending | ack | post | archive)" ;;
esac
