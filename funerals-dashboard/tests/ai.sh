#!/usr/bin/env bash
# בדיקות פרוסה 06 (AI מובנה): F1–F7 מ-docs/spec/06-ai.md, מול ספק מדומה (tests/ai-mock.php) — בלי רשת.
# רץ מתוך tests/run.sh, או לבד. הכלים — extDo() של 05 (ext-lib.php).
set -euo pipefail
cd "$(dirname "$0")/../.."
APP=funerals-dashboard
D="$APP/data"
tmp="$(mktemp -d)"
pids=()
wipe(){ find "$D" -mindepth 1 ! -name .htaccess -exec rm -rf {} + 2>/dev/null || true; }
cleanup(){ for p in "${pids[@]}"; do kill "$p" >/dev/null 2>&1 || true; done; wipe; rm -rf "$tmp"; }
trap cleanup EXIT
fail(){ echo "FAIL (06): $*"; [ -f "$tmp/out" ] && { echo "--- תשובה אחרונה:"; head -c 800 "$tmp/out"; echo; }; exit 1; }
ok(){ echo "  ✓ $*"; }

for f in ai/lib.php ai/actions.php tests/ai-mock.php tests/ai-fixture.php; do php -l "$APP/$f" >/dev/null || fail "php -l $f"; done
node --check "$APP/assets/js/ai.js" || fail "node --check ai.js"
grep -q 'src="assets/js/ai.js"' "$APP/index.html" || fail "index.html לא טוען את ai.js"
! grep -rqE 'sk-ant-[A-Za-z0-9_-]{20,}' "$APP" --include=*.php --include=*.js --include=*.json --include=*.html || fail "מפתח API בקוד"

# ===== מסד: 22 התיקים, מיגרציה, ונתוני הבדיקה =====
wipe
php "$APP/tests/fixture-v0.php" "$D" "fd_$(printf 'x%.0s' {1..64})" >/dev/null
php -r 'chdir($argv[1]); require "lib.php"; db();' "$APP" >/dev/null
DB="$D/funerals.sqlite"
php "$APP/tests/ai-fixture.php" "$DB"
sql(){ php -r '$p=new PDO("sqlite:".$argv[1]); foreach($p->query($argv[2])->fetchAll(PDO::FETCH_NUM) as $r) echo implode("|",$r),"\n";' "$DB" "$1"; }
CASE="$(sql "SELECT id FROM funerals WHERE org_id=1 ORDER BY id LIMIT 1")"
test -n "$CASE" || fail "אין תיק בארגון 1"

# ===== ספק מדומה + האפליקציה =====
M="$tmp/mock"; mkdir -p "$M"; echo '[]' > "$M/queue.json"; : > "$M/requests.jsonl"
AI_MOCK_DIR="$M" php -d opcache.jit=disable -S 127.0.0.1:18967 "$APP/tests/ai-mock.php" > "$tmp/mock.log" 2>&1 &
pids+=($!)
idf="$tmp/identity.json"
as(){ printf '%s' "$1" > "$idf"; }
as ''
AI_API_BASE=http://127.0.0.1:18967 FD_TEST_IDENTITY_FILE="$idf" php -d opcache.jit=disable -S 127.0.0.1:18966 -t "$APP" -d auto_prepend_file="$PWD/$APP/tests/gate-prepend.php" > "$tmp/server.log" 2>&1 &
pids+=($!)
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18966/index.html >/dev/null && break || sleep .2; done
B=http://127.0.0.1:18966/api.php
st(){ curl -sS -o "$tmp/out" -w '%{http_code}' "$@"; }
j(){ php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){ if(!is_array($v)||!array_key_exists($k,$v)){echo "";exit;} $v=$v[$k];} echo is_scalar($v)?(is_bool($v)?($v?"true":"false"):$v):json_encode($v,JSON_UNESCAPED_UNICODE);' "$tmp/out" "$1"; }
declare -A ID CSRF
ID[admin]='{"sub":"701","name":"מנהלת בדיקה","email":"ai-admin@example.com","email_verified":true,"platform_admin":false,"granted":true}'
ID[dev]='{"sub":"704","name":"המפתח","email":"ai-dev@example.com","email_verified":true,"platform_admin":true,"granted":true}'
ID[field]='{"sub":"702","name":"עובד שטח","email":"ai-field@example.com","email_verified":true,"platform_admin":false,"granted":true}'
login(){ as "${ID[$1]}"; st -b "$tmp/c-$1" -c "$tmp/c-$1" "$B?action=me" >/dev/null; CSRF[$1]="$(j csrf)"; test -n "${CSRF[$1]}" || fail "login $1"; }
get(){ as "${ID[$1]}"; st -b "$tmp/c-$1" -c "$tmp/c-$1" "$B?action=$2${3:+&$3}"; }
post(){ as "${ID[$1]}"; st -b "$tmp/c-$1" -c "$tmp/c-$1" -H "X-CSRF-Token: ${CSRF[$1]}" -H 'Content-Type: application/json' -d "$3" "$B?action=$2"; }
calls(){ wc -l < "$M/requests.jsonl" | tr -d ' '; }
queue(){ printf '%s' "$1" > "$M/queue.json"; }
msg(){ # $1 stop_reason, $2 content (JSON)
  printf '{"status":200,"body":{"id":"msg_%s","type":"message","role":"assistant","model":"claude-opus-5-5","content":%s,"stop_reason":"%s","usage":{"input_tokens":10,"output_tokens":5}}}' "$RANDOM" "$2" "$1"; }
text(){ msg end_turn "[{\"type\":\"text\",\"text\":\"$1\"}]"; }
tool(){ msg tool_use "[{\"type\":\"text\",\"text\":\"בודק\"},{\"type\":\"tool_use\",\"id\":\"toolu_$RANDOM\",\"name\":\"$1\",\"input\":$2}]"; }
lastreq(){ tail -n 1 "$M/requests.jsonl"; }
caseBody(){ sql "SELECT body FROM funerals WHERE id='$1'"; }
events(){ sql "SELECT COUNT(*) FROM events"; }
login admin; login field; login dev

# ===== F2: בלי מפתח → הודעה, בלי קריאה לספק =====
code="$(get admin ai_status)"; test "$code" = 200 && test "$(j has_key)" = false || fail "F2 ai_status ($code)"
code="$(post field ai_chat "{\"text\":\"מה חסר?\",\"case_id\":\"$CASE\"}")"; test "$code" = 200 || fail "F2 chat ($code)"
test "$(j code)" = no_key && test "$(j message)" = 'לא הוגדר מפתח — פנה למנהל' || fail "F2 הודעה"
test "$(calls)" = 0 || fail "F2 קריאה לספק בלי מפתח"
ok "F2 — בלי מפתח: \"לא הוגדר מפתח — פנה למנהל\", אין קריאה לספק"

# ===== F1: המפתח מוצפן, מוצג רק 4 תווים, לא ביומן ולא ב-events =====
KEY="sk-ant-api03-$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')Zq9W"
printf '%s' "$KEY" > "$M/expected_key"
code="$(post field ai_settings_save "{\"key\":\"$KEY\"}")"; test "$code" = 403 || fail "F1 שטח שומר מפתח ($code)"
code="$(get field ai_settings)"; test "$code" = 403 || fail "F1 שטח רואה הגדרות ($code)"
code="$(post admin ai_settings_save "{\"key\":\"$KEY\",\"model\":\"claude-opus-5-5\"}")"; test "$code" = 200 || fail "F1 שמירה ($code)"
test "$(j key_hint)" = Zq9W && test "$(j has_key)" = true || fail "F1 key_hint"
! grep -qF -e "$KEY" "$tmp/out" || fail "F1 המפתח בתשובה"
! grep -qF -e "${KEY:13}" "$tmp/out" || fail "F1 חלק מהמפתח בתשובה"
code="$(get admin ai_settings)"; test "$code" = 200 && test "$(j model)" = claude-opus-5-5 && test "$(j monthly_cap)" = 500 || fail "F1 ai_settings / ברירות מחדל"
! grep -qF -e "$KEY" "$tmp/out" || fail "F1 המפתח ב-ai_settings"
# בדיקת מפתח: תקין / לא תקין / אין יתרה
queue "[$(text pong),{\"status\":401,\"body\":{\"type\":\"error\",\"error\":{\"type\":\"authentication_error\",\"message\":\"invalid x-api-key\"}}},{\"status\":400,\"body\":{\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"message\":\"Your credit balance is too low to access the Anthropic API.\"}}}]"
post admin ai_key_test '{}' >/dev/null; test "$(j result)" = ok || fail "F1 בדיקת מפתח: תקין"
lastreq | grep -q '"key_ok":true' || fail "F1 המפתח שנשלח לספק אינו המפתח שנשמר"
post admin ai_key_test '{}' >/dev/null; test "$(j result)" = invalid || fail "F1 בדיקת מפתח: לא תקין"
post admin ai_key_test '{}' >/dev/null; test "$(j result)" = no_credit || fail "F1 בדיקת מפתח: אין יתרה"
! grep -qF -e "$KEY" "$tmp/out" || fail "F1 המפתח בתשובת בדיקה"
test "$(sql "SELECT COUNT(*) FROM secrets WHERE name='ai_key' AND org_id=1 AND value_enc LIKE 'v1:%'")" = 1 || fail "F1 לא מוצפן ב-secrets"
! grep -raqF -e "$KEY" "$D" || fail "F1 המפתח גלוי בקובץ תחת data/"
! grep -qF -e "$KEY" "$tmp/server.log" || fail "F1 המפתח ביומן השרת"
test "$(sql "SELECT COUNT(*) FROM req_log WHERE error LIKE '%Zq9W%'")" = 0 || fail "F1 ביומן הבקשות"
test "$(sql "SELECT COUNT(*) FROM events WHERE action='ai_settings' AND diff NOT LIKE '%Zq9W%'")" -ge 1 || fail "F1 events (שינוי נרשם, בלי המפתח)"
test "$(calls)" = 3 || fail "F1 מספר קריאות לספק"
ok "F1 — מפתח מוצפן (v1:), מוצג רק …Zq9W, לא במסד בגלוי, לא ביומנים ולא ב-events; בדיקת מפתח: תקין / לא תקין / אין יתרה"

# ===== F7: מעבר תקרה → הודעה, בלי קריאה לספק =====
post admin ai_settings_save '{"monthly_cap":0}' >/dev/null
n="$(calls)"
code="$(post admin ai_chat '{"text":"שלום"}')"; test "$code" = 200 && test "$(j code)" = cap || fail "F7 ($code)"
grep -q 'תקרה' "$tmp/out" || fail "F7 הודעה"
test "$(calls)" = "$n" || fail "F7 קריאה לספק מעל התקרה"
post admin ai_settings_save '{"monthly_cap":2}' >/dev/null
queue "[$(text 'שלום לך'),$(text 'שוב שלום')]"
post admin ai_chat '{"text":"שלום"}' >/dev/null; test "$(j ok)" = true && test "$(j reply)" = 'שלום לך' || fail "F7 שיחה בתוך התקרה"
T="$(j thread)"
post admin ai_chat "{\"text\":\"ושוב\",\"thread\":\"$T\"}" >/dev/null; test "$(j used)" = 2 || fail "F7 מונה"
lastreq | php -r '$r=json_decode(stream_get_contents(STDIN),true); $m=$r["body"]["messages"]; exit(count($m)===3 && $m[1]["role"]==="assistant" ? 0 : 1);' || fail "F7 ההיסטוריה של השיחה לא נשלחה"
n="$(calls)"; post admin ai_chat "{\"text\":\"שלישית\",\"thread\":\"$T\"}" >/dev/null; test "$(j code)" = cap && test "$(calls)" = "$n" || fail "F7 אחרי 2 מתוך 2"
post admin ai_settings_save '{"monthly_cap":500}' >/dev/null
get admin ai_thread "id=$T" >/dev/null; test "$(j thread.messages.1.text)" = 'שלום לך' || fail "שיחה נשמרת וחוזרת"
ok "F7 — תקרה 0 ו-2/2: הודעה, אין קריאה לספק; מונה, היסטוריה נשמרת"

# ===== F3–F6: הכלים = הפעולות של ext.php (05) =====
  # F3: כלי כתיבה → כרטיס, ושום שינוי במסד עד ✓
  before="$(caseBody "$CASE")"; ev0="$(events)"
  queue "[$(tool case_update "{\"id\":\"$CASE\",\"checks\":{\"license_number\":{\"status\":\"verified\",\"value\":\"12345\"}},\"source\":\"רישיון.pdf\"}"),$(text 'הצעתי — אשר בכרטיס')]"
  code="$(post admin ai_chat "{\"text\":\"תמלא מספר רישיון\",\"case_id\":\"$CASE\"}")"; test "$code" = 200 || fail "F3 ($code)"
  test "$(j proposals.0.status)" = pending && test "$(j proposals.0.card.source)" = 'רישיון.pdf' || fail "F3 כרטיס"
  P="$(j proposals.0.id)"
  j proposals.0.card.rows | grep -q '12345' || fail "F3 לפני ← אחרי"
  test "$(caseBody "$CASE")" = "$before" && test "$(events)" = "$ev0" || fail "F3 נכתב למסד לפני ✓"
  lastreq | php -r '$r=json_decode(stream_get_contents(STDIN),true); $m=end($r["body"]["messages"]); $t=json_decode($m["content"][0]["content"],true); exit(($t["status"]??"")==="proposed" && ($t["saved"]??null)===false ? 0 : 1);' || fail "F3 הספק לא קיבל 'הוצע'"
  ok "F3 — כלי כתיבה → כרטיס (לפני ← אחרי, מקור), שום שינוי במסד ובאירועים עד ✓"

  # F4: ✓ על הצעה עם verified → "לאימות", via: ai, שורה ב-events
  code="$(post field ai_decide "{\"id\":$P,\"approve\":true}")"; test "$code" = 404 || fail "F4 משתמש אחר מאשר ($code)"
  code="$(post admin ai_decide "{\"id\":$P,\"approve\":true}")"; test "$code" = 200 && test "$(j proposal.status)" = applied || fail "F4 ✓ ($code)"
  caseBody "$CASE" | php -r '$c=json_decode(stream_get_contents(STDIN),true)["checks"]["license_number"]; exit($c["status"]==="document_present" && $c["via"]==="ai" && $c["value"]==="12345" ? 0 : 1);' || fail "F4 נשמר לא כ'לאימות' / via"
  test "$(events)" = $((ev0 + 1)) || fail "F4 שורה ב-events"
  test "$(sql "SELECT source||'|'||actor FROM events ORDER BY id DESC LIMIT 1")" = "api|מנהלת בדיקה (AI)" || fail "F4 מקור ושם באירוע"
  code="$(post admin ai_decide "{\"id\":$P,\"approve\":true}")"; test "$code" = 409 || fail "F4 ✓ פעמיים ($code)"
  ok "F4 — ✓: verified → document_present (לאימות), via: ai, events עם \"(AI)\"; ✓ שני → 409, משתמש אחר → 404"

  # ✗: נדחה, לא נשמר
  queue "[$(tool case_update "{\"id\":\"$CASE\",\"fields\":{\"grave\":\"חלקה 9\"}}"),$(text 'הצעתי')]"
  post admin ai_chat "{\"text\":\"קבר\",\"case_id\":\"$CASE\"}" >/dev/null; P2="$(j proposals.0.id)"; before="$(caseBody "$CASE")"
  post admin ai_decide "{\"id\":$P2,\"approve\":false}" >/dev/null; test "$(j proposal.status)" = rejected && test "$(caseBody "$CASE")" = "$before" || fail "✗"
  ok "✗ — ההצעה נדחית ושום דבר לא נשמר"

  # F5: שטח → כלי לשינוי תשלום נכשל ב-403, כמו ב-API
  before="$(caseBody "$CASE")"
  queue "[$(tool case_update "{\"id\":\"$CASE\",\"fields\":{\"payments\":[{\"amount\":100,\"purpose\":\"קבר\"}]}}"),$(text 'אין לך הרשאה')]"
  code="$(post field ai_chat "{\"text\":\"תוסיף תשלום\",\"case_id\":\"$CASE\"}")"; test "$code" = 200 || fail "F5 ($code)"
  test "$(j proposals)" = '[]' || fail "F5 נוצרה הצעה"
  lastreq | php -r '$r=json_decode(stream_get_contents(STDIN),true); $m=end($r["body"]["messages"]); $t=$m["content"][0]; exit(($t["is_error"]??false)===true && str_contains($t["content"],"\"status\":403") && str_contains($t["content"],"אין לך הרשאה") ? 0 : 1);' || fail "F5 הכלי לא החזיר 403"
  test "$(caseBody "$CASE")" = "$before" || fail "F5 נשמר"
  # ובדיקה (case.track) — מותרת לשטח, כהצעה
  queue "[$(tool case_update "{\"id\":\"$CASE\",\"checks\":{\"hall_service\":{\"status\":\"missing\"}}}"),$(text 'הצעתי')]"
  post field ai_chat "{\"text\":\"שירות חסר\",\"case_id\":\"$CASE\"}" >/dev/null; test "$(j proposals.0.status)" = pending || fail "F5 בדיקה לשטח"
  ok "F5 — שטח: תשלום → 403 בכלי (שום הצעה, שום שינוי); בדיקה → הצעה"

  # F6: תיק של ארגון אחר → 404 בכלי, ובלי הנתונים שלו
  queue "[$(tool case '{"id":"fd_org2secretcase01"}'),$(text 'לא מצאתי את התיק')]"
  code="$(post admin ai_chat '{"text":"מה בתיק fd_org2secretcase01?"}')"; test "$code" = 200 || fail "F6 ($code)"
  lastreq | php -r '$r=json_decode(stream_get_contents(STDIN),true); $m=end($r["body"]["messages"]); $t=$m["content"][0]; exit(($t["is_error"]??false)===true && str_contains($t["content"],"לא נמצא") ? 0 : 1);' || fail "F6 הכלי לא החזיר 404"
  ! grep -qE 'סודי-ארגון-שני|חלקה-סודית-77' "$M/requests.jsonl" || fail "F6 נתוני ארגון אחר נשלחו לספק"
  ! grep -qE 'סודי-ארגון-שני|חלקה-סודית-77' "$tmp/out" || fail "F6 נתוני ארגון אחר בתשובה"
  # find לא מגלה אותו
  queue "[$(tool find '{"q":"סודי"}'),$(text 'אין')]"
  post admin ai_chat '{"text":"חפש סודי"}' >/dev/null
  ! grep -q 'fd_org2secretcase01' <(tail -n 1 "$M/requests.jsonl" | sed 's/"q":"סודי"//') || fail "F6 find חשף תיק של ארגון אחר"
  # מפתח (platform_admin) — רואה כל הארגונים בממשק, אבל דרך ה-AI רק את הארגון הפעיל
  queue "[$(tool case '{"id":"fd_org2secretcase01"}'),$(text 'לא נמצא')]"
  post dev ai_chat '{"text":"מה בתיק?"}' >/dev/null
  lastreq | php -r '$r=json_decode(stream_get_contents(STDIN),true); $m=end($r["body"]["messages"]); $t=$m["content"][0]; exit(($t["is_error"]??false)===true && str_contains($t["content"],"לא נמצא") ? 0 : 1);' || fail "F6 מפתח הגיע לתיק של ארגון לא פעיל"
  ! grep -qE 'סודי-ארגון-שני|חלקה-סודית-77' "$M/requests.jsonl" || fail "F6 נתוני ארגון אחר נשלחו לספק (מפתח)"
  ok "F6 — תיק של ארגון אחר: 404 בכלי, והנתונים שלו לא בבקשה לספק ולא בתשובה"

  # אישור אוטומטי — מדלג על הכרטיס, לא על הכלל
  post admin ai_settings_save '{"auto_approve":true}' >/dev/null
  queue "[$(tool case_update "{\"id\":\"$CASE\",\"checks\":{\"identity_document\":{\"status\":\"verified\"}}}"),$(text 'נשמר')]"
  post admin ai_chat "{\"text\":\"זיהוי\",\"case_id\":\"$CASE\"}" >/dev/null; test "$(j proposals.0.status)" = applied || fail "אישור אוטומטי"
  caseBody "$CASE" | php -r '$c=json_decode(stream_get_contents(STDIN),true)["checks"]["identity_document"]; exit($c["status"]==="document_present" ? 0 : 1);' || fail "אישור אוטומטי עקף את כלל ה'לאימות'"
  post admin ai_settings_save '{"auto_approve":false}' >/dev/null
  ok "אישור אוטומטי — בלי כרטיס, ועדיין \"לאימות\""

head -n 4 "$M/requests.jsonl" | tail -n 1 | grep -q '"version":"2023-06-01"' || fail "anthropic-version"
! grep -E 'PHP (Fatal|Warning|Parse|Deprecated|Notice)' "$tmp/server.log" | grep -vq 'in Unknown on line 0' || { grep -E 'PHP (Fatal|Warning|Parse|Deprecated|Notice)' "$tmp/server.log" | grep -v 'in Unknown on line 0' | head; fail "שגיאות PHP בשרת"; }
echo "PASS (06): F1–F7 — מפתח מוצפן ו-4 תווים, בלי מפתח בלי קריאה, הצעה עד ✓, ✓ → לאימות via ai, שטח 403, ארגון אחר 404, תקרה"
