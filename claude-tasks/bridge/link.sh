#!/usr/bin/env bash
# הגשר החי — הקישור של הצ'אט לערוץ בלוח (docs/spec.md §5.5–5.6).
#
#   link.sh register --project <id> [--name <שם>] [--role <תפקיד>] [--replaces <ערוץ>]
#                    [--launch <launch_id>] [--replaces-dispatcher]   (--launch: פתיחה מ-➕ בלוח, §19; --replaces-dispatcher: החלפת צ'אט המנהל, §21)
#                    רישום (או רישום מחדש עם אותו session_id). אסימון ההרשמה: $CLAUDE_TASKS_TOKEN —
#                    של השורש, אחד לכל הסביבה. מספר הפרויקט מגיע רק מהפרומפט (§5.9.1);
#                    CLAUDE_TASKS_PROJECT לא נקרא יותר. --replaces — צ'אט המשך (§5.9.4)
#   link.sh handoff <קובץ>  תקציר העברה לצ'אט ההמשך (אחרי handoff_request מהלוח)
#   link.sh status   הערוץ, ומה ממתין. אין קובץ קישור (הקונטיינר הוחלף) והסשן כבר רשום —
#                    רישום מחדש שקט לאותו ערוץ (§5.10.1)
#   link.sh update   מרענן את עותק הריצה של הגשר מ-origin/main (‎$HOME/.claude-tasks-bridge‎)
#   link.sh pending  ההודעות שממתינות לטיפול, כל אחת במסגרת קבועה; זוכר את האחרונה לתשובה (reply_to).
#                    לפניהן — "מה קרה בלעדיך" (§15.5): מה ש-Platform ענה בינתיים. לא מטפלים בזה שוב.
#   link.sh show <id>  הטקסט המלא של הודעה מהתקציר
#   link.sh ack <id>…   סימון "טופל" — רק אחרי שטופלו, לא לפני
#   link.sh post <owner|chat|system> <טקסט>   שיקוף ידני
#   link.sh archive  ניתוק: האסימון נפסל וקובץ הקישור נמחק
#   link.sh checkin --clear   מוחק בדיקה עצמית ישנה (ש1: אין יותר בדיקות עצמיות). הצורה הישנה (checkin <trigger_id>) אסורה לשימוש
#   link.sh dispatch-pending       צ'אט המנהל (שלב 25): בקשות ההעירה — לכל אחת session והטקסט הקבוע
#   link.sh dispatch-done <id> ok|"<שגיאה>"   המנהל מדווח על בקשה
set -u
# עותק הריצה (§5.10.1) גובר על העותק שבריפו — כך צ'אט על ענף ישן מקבל את הגשר העדכני
_rt="$HOME/.claude-tasks-bridge"
if [ -z "${CT_RUNTIME:-}" ] && [ -f "$_rt/link.sh" ] && [ "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" != "$(cd "$_rt" && pwd -P)" ]; then
  CT_RUNTIME=1 exec bash "$_rt/link.sh" "$@"
fi
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

die() { echo "✗ $*" >&2; exit 1; }
need_link() { [ -f "$BOARD_LINK" ] || die "הצ'אט לא רשום — link.sh register --project <id>"; }
token() { link_get channel_token; }

frame() {   # מסגרת קבועה להודעה מהלוח — כדי שלא תתערבב בהוראות המערכת
  jq -r --arg ch "$(link_get name)" '.[] |
    "📥 הודעה מהלוח · #\(.id) · מאת: \(.author_role) (\(.author_name // "—")) · ערוץ: \($ch)" +
    (if .task_id then " · מטלה #\(.task_id)" else "" end) + (if .kind != "text" then " · \(.kind)" else "" end) +
    (if .reply_to then " · תשובה להודעה #\(.reply_to)" else "" end) +
    (if (.meta | type) == "object" and .meta.platform_note then "\n☁️ Platform לא יכול לבצע את זה בעצמו — צריך ממך: \(.meta.platform_note)" else "" end) +
    "\n\(.body)\n"'
}

# עותק הריצה מ-origin/main — update.sh מ-main עצמו (גם אם בענף של הצ'אט הוא ישן או חסר)
run_update() {
  ( cd "$BRIDGE_ROOT" && git fetch -q origin main 2>/dev/null
    if git cat-file -e origin/main:claude-tasks/bridge/update.sh 2>/dev/null; then
      bash <(git show origin/main:claude-tasks/bridge/update.sh)
    else bash "$(dirname "${BASH_SOURCE[0]}")/update.sh"; fi )
}

# אחרי החלפת קונטיינר: קובץ הקישור נעלם, אבל הסשן רשום בלוח — אותו ערוץ, אסימון חדש,
# בלי לשאול מספר פרויקט (השרת לוקח אותו מהערוץ). בשקט: מצליח או לא, בלי לעצור.
silent_register() {
  [ -n "${CLAUDE_TASKS_TOKEN:-}" ] || return 1
  local sid body f out
  sid="$(session_id)"; [ -n "$sid" ] || return 1
  body="$(jq -n --arg s "$sid" --arg u "$(session_url)" --arg v "$BRIDGE_VERSION" '{session_id:$s, session_url:$u, bridge_version:$v}')"
  f="$(mktemp)"; board_call channel-register "$body" "$CLAUDE_TASKS_TOKEN" 10 > "$f" || { rm -f "$f"; return 1; }
  out="$(cat "$f")"; rm -f "$f"
  mkdir -p "$(dirname "$BOARD_LINK")"; umask 077
  jq -n --argjson r "$out" --arg url "$(board_url)" --arg cookie "$(board_cookie)" --arg s "$sid" \
        '{url:$url, cookie:$cookie, channel_id:$r.channel_id, channel_token:$r.channel_token,
          project_id:$r.project_id, name:$r.name, session_id:$s, registered_at:(now|floor), pending_reply_to:null,
          notify_session:($r.notify_session // null)}' > "$BOARD_LINK"
  chmod 600 "$BOARD_LINK"
  echo "✓ נרשם מחדש אחרי החלפת קונטיינר: ערוץ #$(link_get channel_id) · $(link_get name)"
  run_update >/dev/null 2>&1 || true
}

cmd="${1:-status}"; shift || true
case "$cmd" in
  register)
    project=""; name=""; role=""; replaces=""; launch=""; repdisp=""
    while [ $# -gt 0 ]; do
      case "$1" in
        --project)  project="$2"; shift 2 ;;
        --name)     name="$2"; shift 2 ;;
        --role)     role="$2"; shift 2 ;;
        --replaces) replaces="$2"; shift 2 ;;
        --launch)   launch="$2"; shift 2 ;;   # שלב 23: צ'אט שנפתח מהלוח — הרשומה קובעת פרויקט, שם והחלפה
        --replaces-dispatcher) repdisp=1; shift ;;   # שלב 25: מנהל חדש במקום הקודם (אחרי "🔁 החלפת מנהל" בלוח)
        *) die "פרמטר לא מוכר: $1" ;;
      esac
    done
    # preflight (§5.9.2): כל כשל — שורה אחת בעברית שאומרת בדיוק מה לעשות
    if [ -n "${CLAUDE_TASKS_PROJECT:-}" ]; then
      echo "⚠ CLAUDE_TASKS_PROJECT=$CLAUDE_TASKS_PROJECT מוגדר בסביבה ולא נקרא — מספר הפרויקט מגיע רק מהפרומפט. כדאי למחוק את השורה מהגדרות הסביבה." >&2
    fi
    if [ -z "${CLAUDE_TASKS_TOKEN:-}" ]; then
      env_name="$(board_call chat-env '{}' "" 5 2>/dev/null | jq -r '.environment // empty' 2>/dev/null)"
      die "הצ'אט נפתח בסביבה בלי האסימון. פתחו צ'אט חדש בסביבה${env_name:+ \"$env_name\"} (הסביבה שבה שמור CLAUDE_TASKS_TOKEN)."
    fi
    [[ "$project" =~ ^[0-9]+$ ]] || die "חסר מספר פרויקט (--project). הפרומפט מהלוח (⚙ ← 🆕 צ'אט חדש) כבר כולל אותו — העתיקו אותו משם."
    [ -z "$replaces" ] || [[ "$replaces" =~ ^[0-9]+$ ]] || die "--replaces: מספר הערוץ שמוחלף"
    [ -z "$launch" ] || [[ "$launch" =~ ^L-[0-9a-f]{8,64}$ ]] || die "--launch: ה-launch_id מרשומת הפתיחה (L-…)"
    sid="$(session_id)"; [ -n "$sid" ] || die "אין מזהה סשן (CLAUDE_CODE_REMOTE_SESSION_ID)"
    [ -n "$name" ] || name="צ'אט ${sid: -6}"
    body="$(jq -n --argjson p "$project" --arg n "$name" --arg r "$role" --arg s "$sid" --arg u "$(session_url)" --arg x "$replaces" \
             --arg v "$BRIDGE_VERSION" \
             --arg l "$launch" \
             '{project_id:$p, name:$n, role:$r, session_id:$s, session_url:$u, bridge_version:$v} + (if $x != "" then {replaces: ($x|tonumber)} else {} end)
              + (if $l != "" then {launch: $l} else {} end)')"
    [ -n "$repdisp" ] && body="$(echo "$body" | jq '. + {replaces_dispatcher: true}')"
    mkdir -p "$(dirname "$BOARD_LINK")"
    # בלי $( ) — כדי ש-BOARD_HTTP (שנקבע בתוך board_call) לא ילך לאיבוד בתת-מעטפת
    tmp_out="$(mktemp)"; board_call channel-register "$body" "$CLAUDE_TASKS_TOKEN" > "$tmp_out"; rc=$?
    out="$(cat "$tmp_out")"; rm -f "$tmp_out"
    if [ $rc != 0 ]; then
      err="$(echo "$out" | jq -r '.error // empty' 2>/dev/null)"
      case "$BOARD_HTTP" in
        401) env_name="$(board_call chat-env '{}' "" 5 2>/dev/null | jq -r '.environment // empty' 2>/dev/null)"
             die "האסימון שבסביבה${env_name:+ \"$env_name\"} לא מוכר ללוח (אולי הוחלף). העתיקו מחדש את האסימון של השורש: ⚙ של השורש ← אסימון לצ'אטים, ושימו אותו ב-CLAUDE_TASKS_TOKEN." ;;
        *)   die "${err:-הרישום נכשל (HTTP $BOARD_HTTP)}" ;;
      esac
    fi
    umask 077
    jq -n --argjson r "$out" --arg url "$(board_url)" --arg cookie "$(board_cookie)" --arg n "$name" \
          --argjson p "$project" --arg s "$sid" \
          '{url:$url, cookie:$cookie, channel_id:$r.channel_id, channel_token:$r.channel_token,
            project_id:$p, name:$n, session_id:$s, registered_at:(now|floor), pending_reply_to:null,
            notify_session:($r.notify_session // null), project_folder:($r.project_folder // "")}' > "$BOARD_LINK"
    chmod 600 "$BOARD_LINK"
    echo "✓ רשום: ערוץ #$(link_get channel_id) · $name · פרויקט #$project $(echo "$out" | jq -r '(.project_path // "") + (if .renewed then " (רישום חוזר — אסימון חדש)" else "" end)')"
    [ -n "$replaces" ] && echo "✓ מחליף את ערוץ #$replaces — המטלות שנשאלו בו עברו לכאן"
    nt="$(echo "$out" | jq -r '.notify_session // empty')"
    [ -n "$nt" ] && echo "→ הודעת ההצטרפות: create_trigger ל-$nt"
    run_update >/dev/null 2>&1 || true   # עותק הריצה של ה-hooks (§5.10.1)
    ;;

  update)
    run_update
    old_check_hint ""   # ש1: בדיקה שעתית ישנה (מהקישור המקומי) — לבקש למחוק; status בודק גם מול הלוח
    ;;

  status)
    [ -f "$BOARD_LINK" ] || silent_register || die "הצ'אט לא רשום — link.sh register --project <id>"
    out="$(board_call channel-inbox "$(report_json)" "$(token)")" || die "הלוח לא ענה (HTTP $BOARD_HTTP) — אולי הערוץ נותק; link.sh register מחדש"
    echo "ערוץ #$(link_get channel_id) · $(link_get name) · $(board_url)"
    echo "ממתינות: $(echo "$out" | jq '.messages | length')"
    listeners_hint "$(echo "$out" | jq -r '.listeners // empty')"
    old_check_hint "$(echo "$out" | jq -r '.old_check // empty')"
    ;;

  pending)
    need_link
    # מקור האמת הוא השרת (inbox לא מסמן); הקובץ של המאזין — גיבוי כשהרשת נופלת
    if out="$(board_call channel-inbox "$(report_json)" "$(token)")"; then msgs="$(echo "$out" | jq '.messages')"
    elif [ -f "$BOARD_PENDING" ]; then msgs="$(cat "$BOARD_PENDING")"
    else die "הלוח לא ענה (HTTP $BOARD_HTTP)"; fi
    # §15.5: התקציר קודם — מה שקרה במסלול אחר בזמן שהצ'אט לא ענה
    dg="$(printf '%s' "${out:-}" | jq -r '.digest // empty' 2>/dev/null)"
    [ -n "$dg" ] && printf '%s\n\n' "$dg"
    n="$(echo "$msgs" | jq 'length')"
    if [ "$n" = 0 ]; then echo "אין הודעות ממתינות."; rm -f "$BOARD_PENDING"; exit 0; fi
    echo "$msgs" | frame
    # התשובה הבאה של הצ'אט תירשם כתשובה להודעה האחרונה
    link_set pending_reply_to "$(echo "$msgs" | jq '.[-1].id')"
    # שאלת בחירה שנענתה בלוח — כבר לא ממתינה להקלדה בצ'אט (§5.10)
    pc="$(link_get pending_choice)"
    if [ -n "$pc" ] && echo "$msgs" | jq -e --argjson c "$pc" 'any(.[]; .kind == "choice_answer" and .reply_to == $c)' >/dev/null; then
      link_set pending_choice null
    fi
    echo "— אחרי הטיפול: link.sh ack $(echo "$msgs" | jq -r '[.[].id] | join(" ")')"
    ;;

  ack)
    need_link
    [ $# -gt 0 ] || die "שימוש: link.sh ack <id>…"
    ids="$(printf '%s\n' "$@" | jq -R 'tonumber' | jq -s '{ids: .}')"
    out="$(board_call channel-ack "$ids" "$(token)")" || die "ack נכשל (HTTP $BOARD_HTTP)"
    echo "✓ סומנו: $(echo "$out" | jq '.acked')"
    ah="$(echo "$out" | jq -r '(.already_handled // []) | map(tostring) | join(" ")')"
    [ -n "$ah" ] && echo "ℹ️ כבר טופלו במסלול אחר (Platform) — לא לטפל שוב: $ah"
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

  handoff)
    need_link
    f="${1:-}"; [ -n "$f" ] && [ -f "$f" ] || die "שימוש: link.sh handoff <קובץ התקציר>"
    body="$(jq -n --arg b "$(clip "$(cat "$f")")" '{author_role:"chat", kind:"handoff", body:$b}')"
    board_call channel-post "$body" "$(token)" >/dev/null && echo "✓ התקציר נשלח ללוח — בעל המערכת יפתח ממנו את צ'אט ההמשך" \
      || die "שליחת התקציר נכשלה (HTTP $BOARD_HTTP)"
    ;;

  show)
    need_link
    [ -n "${1:-}" ] || die "שימוש: link.sh show <id>"
    out="$(board_call channel-show "$(jq -n --argjson i "$1" '{id:$i}')" "$(token)")" || die "לא נמצא (HTTP $BOARD_HTTP)"
    echo "$out" | jq -r '.message | "#\(.id) · \(.author_role) · \(.kind)" + (if .reply_to then " · על #\(.reply_to)" else "" end) + "\n\(.body)"'
    ;;

  # ‏checkin --clear — מחקת את הבדיקה השעתית הישנה (ש1, audit-2026-11); ‏checkin <trigger_id> [דקות] — מעקב שבעל הלוח ביקש
  checkin)
    need_link
    if [ "${1:-}" = "--clear" ]; then
      link_set check_trigger_id '""'; link_set next_check_at 0
      board_call channel-checkin '{"check_trigger_id":"","next_check_at":0,"listen_state":"never"}' "$(token)" >/dev/null || die "הלוח לא אישר (HTTP $BOARD_HTTP)"
      echo "✓ הבדיקה השעתית נמחקה מהלוח"; exit 0
    fi
    tid="${1:-}"; mins="${2:-60}"
    [[ "$tid" =~ ^trig_[A-Za-z0-9]{8,64}$ ]] || die "שימוש: link.sh checkin <trigger_id> [דקות] — ה-trigger_id שקיבלת מ-create_trigger"
    [[ "$mins" =~ ^[0-9]{1,3}$ ]] || die "דקות: מספר"
    link_set check_trigger_id "$(jq -Rn --arg t "$tid" '$t')"
    link_set next_check_at "$(( $(date +%s) + mins * 60 ))"
    out="$(board_call channel-checkin "$(report_json)" "$(token)")" || die "הלוח לא אישר (HTTP $BOARD_HTTP)"
    echo "✓ בדיקה עצמית רשומה בלוח: $tid · הבאה בעוד $mins דק'"
    listeners_hint "$(echo "$out" | jq -r '.listeners // empty')"
    ;;

  # שלב 25 (§21.4): צ'אט המנהל — מה להעיר. היעד נקבע בלוח; המנהל לא בוחר יעד ולא משנה את הטקסט
  dispatch-pending)
    need_link
    out="$(board_call dispatch-pending '{}' "$(token)")" || die "הלוח לא ענה (HTTP $BOARD_HTTP)"
    if [ "$(echo "$out" | jq -r '.retired')" = true ]; then
      echo "🛰 retired — מנהל אחר החליף אותך. עצור את המאזין וסיים את התור."; exit 0
    fi
    n="$(echo "$out" | jq '.requests | length')"
    [ "$n" = 0 ] && { echo "אין בקשות העירה."; exit 0; }
    echo "🛰 $n בקשות העירה. לכל אחת: create_trigger עם persistent_session_id = ה-session, run_once_at בעוד דקה, והטקסט הזה בדיוק:"
    echo "$out" | jq -r '.text'
    echo "---"
    echo "$out" | jq -r '.requests[] | "#\(.id) · \(.session_id) · \(.channel)"'
    echo "---"
    echo "אחרי כל אחת: link.sh dispatch-done <id> ok   (או: link.sh dispatch-done <id> \"<השגיאה>\")"
    ;;

  dispatch-done)
    need_link
    [[ "${1:-}" =~ ^[0-9]+$ ]] || die "שימוש: link.sh dispatch-done <id> ok|\"<שגיאה>\""
    res="${2:-ok}"
    if [ "$res" = ok ]; then b="$(jq -n --argjson i "$1" '{id:$i, ok:true}')"
    else b="$(jq -n --argjson i "$1" --arg e "$res" '{id:$i, ok:false, error:$e}')"; fi
    board_call dispatch-done "$b" "$(token)" >/dev/null || die "הלוח לא אישר (HTTP $BOARD_HTTP)"
    echo "✓ #$1 · $res"
    ;;

  archive)
    need_link
    board_call channel-archive '{}' "$(token)" >/dev/null || echo "הלוח לא אישר (HTTP $BOARD_HTTP) — מוחק את הקישור בכל זאת" >&2
    ct="$(link_get check_trigger_id)"
    rm -f "$BOARD_LINK" "$BOARD_PENDING"
    echo "✓ נותק"
    [ -n "$ct" ] && echo "⚠ מחק עכשיו את הבדיקה השעתית: delete_trigger $ct (אחרת היא תמשיך להעיר את הסשן)"
    ;;

  *) die "פקודה לא מוכרת: $cmd (register | status | update | pending | ack | show | post | handoff | checkin | archive)" ;;
esac
