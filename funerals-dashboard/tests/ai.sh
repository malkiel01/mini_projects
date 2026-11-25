#!/usr/bin/env bash
# בדיקות פרוסה 06 (AI מובנה): F1–F7 מ-docs/spec/06-ai.md, מול ספק מדומה (tests/ai-mock.php) — בלי רשת.
# רץ מתוך tests/run.sh, או לבד. הכלים — extDo() של 05 (ext-lib.php).
set -euo pipefail
cd "$(dirname "$0")/../.."
APP=funerals-dashboard
D="$APP/data"
tmp="$(mktemp -d)"
pids=()
wipe(){ find "$D" -mindepth 1 ! -name .htaccess ! -name .gitignore -exec rm -rf {} + 2>/dev/null || true; }
cleanup(){ for p in "${pids[@]}"; do kill "$p" >/dev/null 2>&1 || true; done; wipe; rm -rf "$tmp"; }
trap cleanup EXIT
fail(){ echo "FAIL (06): $*"; [ -f "$tmp/out" ] && { echo "--- תשובה אחרונה:"; head -c 800 "$tmp/out"; echo; }; exit 1; }
ok(){ echo "  ✓ $*"; }

for f in ai/lib.php ai/actions.php tests/ai-mock.php tests/ai-fixture.php tests/ai-dbx-fixture.php; do php -l "$APP/$f" >/dev/null || fail "php -l $f"; done
node --check "$APP/assets/js/ai.js" || fail "node --check ai.js"
grep -q 'src="assets/js/ai.js"' "$APP/index.html" || fail "index.html לא טוען את ai.js"
! grep -rqE 'sk-ant-[A-Za-z0-9_-]{20,}' "$APP" --include=*.php --include=*.js --include=*.json --include=*.html || fail "מפתח API בקוד"

# ===== מסד: 22 התיקים, מיגרציה, ונתוני הבדיקה =====
wipe
php "$APP/tests/fixture-v0.php" "$D" "fd_$(printf 'x%.0s' {1..64})" >/dev/null
php -r 'chdir($argv[1]); require "lib.php"; db();' "$APP" >/dev/null
DB="$D/funerals.sqlite"
php "$APP/tests/ai-fixture.php" "$DB"
test "$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("PRAGMA user_version")->fetchColumn();' "$DB")" = 4 || fail "v4: user_version"
# v4 על מסד שכבר יש בו טבלאות AI בצורה הישנה (ALTER עצל, לפני v4): העמודות נוספות, הנתונים נשארים
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("ALTER TABLE ai_proposals DROP COLUMN base; ALTER TABLE ai_usage DROP COLUMN tokens;
  INSERT INTO ai_proposals(thread_id,org_id,user_id,tool,input,card,created_at) VALUES (\"t_old\",1,1,\"case_update\",\"{}\",\"{}\",\"2026-11-01\");
  PRAGMA user_version=3");' "$DB"
php -r 'chdir($argv[1]); require "lib.php"; db();' "$APP" >/dev/null
php -r '$p=new PDO("sqlite:".$argv[1]); $c=fn($t)=>array_column($p->query("PRAGMA table_info($t)")->fetchAll(),"name");
  exit($p->query("PRAGMA user_version")->fetchColumn()==4 && in_array("base",$c("ai_proposals")) && in_array("tokens",$c("ai_usage"))
    && $p->query("SELECT COUNT(*) FROM ai_proposals WHERE thread_id=\"t_old\"")->fetchColumn()==1 ? 0 : 1);' "$DB" || fail "v4 על טבלאות AI ישנות"
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("DELETE FROM ai_proposals WHERE thread_id=\"t_old\"");' "$DB"   # מסד בדיקה בלבד
sql(){ php -r '$p=new PDO("sqlite:".$argv[1]); foreach($p->query($argv[2])->fetchAll(PDO::FETCH_NUM) as $r) echo implode("|",$r),"\n";' "$DB" "$1"; }
CASE="$(sql "SELECT id FROM funerals WHERE org_id=1 ORDER BY id LIMIT 1")"
test -n "$CASE" || fail "אין תיק בארגון 1"
# תיק שבו "רישיון קבורה" עוד לא נבדק — לקובץ ב-Dropbox (06 §2.2.2, §2.3)
DCASE="$(php -r '$p=new PDO("sqlite:".$argv[1]); foreach($p->query("SELECT id, body FROM funerals WHERE org_id=1 ORDER BY id DESC") as $r){ $s=json_decode($r["body"],true)["checks"]["license_document"]["status"]??"unknown"; if(in_array($s,["unknown","missing"],true)){echo $r["id"];break;} }' "$DB")"
test -n "$DCASE" || fail "אין תיק עם רישיון שלא נבדק"
DBXS="$tmp/dbx-state.json"
FID="$(php "$APP/tests/ai-dbx-fixture.php" "$APP" "$DCASE" "$DBXS")"

# ===== ספק מדומה + האפליקציה =====
M="$tmp/mock"; mkdir -p "$M"; echo '[]' > "$M/queue.json"; : > "$M/requests.jsonl"
AI_MOCK_DIR="$M" php -d opcache.jit=disable -S 127.0.0.1:18967 "$APP/tests/ai-mock.php" > "$tmp/mock.log" 2>&1 &
pids+=($!)
DBX_MOCK_STATE="$DBXS" php -d opcache.jit=disable -S 127.0.0.1:18968 "$APP/tests/dropbox-mock.php" > "$tmp/dbx.log" 2>&1 &
pids+=($!)
idf="$tmp/identity.json"
as(){ printf '%s' "$1" > "$idf"; }
as ''
AI_API_BASE=http://127.0.0.1:18967 DBX_API_BASE=http://127.0.0.1:18968 FD_TEST_IDENTITY_FILE="$idf" php -d opcache.jit=disable -S 127.0.0.1:18966 -t "$APP" -d auto_prepend_file="$PWD/$APP/tests/gate-prepend.php" > "$tmp/server.log" 2>&1 &
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

# ===== §2.2.2: הקובץ עצמו לספק (file_view, מול Dropbox מדומה) =====
queue "[$(tool file_view "{\"file\":$FID}"),$(text 'קראתי את הרישיון')]"
code="$(post admin ai_chat "{\"text\":\"תקרא את הרישיון\",\"case_id\":\"$DCASE\"}")"; test "$code" = 200 && test "$(j reply)" != '' || fail "file_view ($code)"
lastreq | php -r '$r=json_decode(stream_get_contents(STDIN),true); $m=end($r["body"]["messages"]); $t=$m["content"][0]; $d=$t["content"][1]??[];
  exit(empty($t["is_error"]) && ($d["type"]??"")==="document" && $d["source"]["media_type"]==="application/pdf" && str_starts_with(base64_decode($d["source"]["data"]),"%PDF") ? 0 : 1);' || fail "file_view: ה-PDF לא נשלח לספק כ-document"
lastreq | grep -q '"name":"file_view"' || fail "file_view לא בהגדרות הכלים"
queue "[$(tool file_view '{"file":999999}'),$(text 'לא נמצא')]"
post admin ai_chat "{\"text\":\"קובץ אחר\",\"case_id\":\"$DCASE\"}" >/dev/null
lastreq | php -r '$r=json_decode(stream_get_contents(STDIN),true); $m=end($r["body"]["messages"]); $t=$m["content"][0]; exit(($t["is_error"]??false)===true ? 0 : 1);' || fail "file_view: קובץ לא קיים — לא שגיאה"
ok "§2.2.2 — file_view: ה-PDF עצמו נשלח לספק (document); קובץ שלא קיים → שגיאה בכלי"

# ===== §2.3: קובץ חדש בלי סוג → הצעה לסוג ולבדיקה; ✓ → סוג + "לאימות" =====
n="$(calls)"
queue "[$(text '{\"kind\":\"license_document\",\"reason\":\"כתוב רישיון קבורה\"}')]"
code="$(post field ai_file_kinds "{\"case_id\":\"$DCASE\"}")"; test "$code" = 200 || fail "ai_file_kinds ($code)"
test "$(j "suggestions.$FID.label")" = 'רישיון קבורה' && test "$(j "suggestions.$FID.status")" = pending || fail "§2.3 הצעה"
lastreq | grep -q '"json_schema"' || fail "§2.3 בלי structured output"
test "$(calls)" = $((n + 1)) || fail "§2.3 מספר קריאות"
post field ai_file_kinds "{\"case_id\":\"$DCASE\"}" >/dev/null; test "$(calls)" = $((n + 1)) || fail "§2.3 קרא לספק שוב על אותו קובץ"
before="$(caseBody "$DCASE")"
test "$(sql "SELECT kind FROM files WHERE id=$FID")" = '' || fail "§2.3 שויך לפני ✓"
code="$(post field ai_file_kind_decide "{\"file\":$FID,\"approve\":true}")"; test "$code" = 200 && test "$(j status)" = applied || fail "§2.3 ✓ ($code)"
test "$(sql "SELECT kind FROM files WHERE id=$FID")" = 'רישיון קבורה' || fail "§2.3 files.kind"
caseBody "$DCASE" | php -r '$c=json_decode(stream_get_contents(STDIN),true)["checks"]["license_document"]; exit($c["status"]==="document_present" && $c["via"]==="ai" ? 0 : 1);' || fail "§2.3 הבדיקה לא סומנה לאימות"
code="$(post field ai_file_kind_decide "{\"file\":$FID,\"approve\":true}")"; test "$code" = 409 || fail "§2.3 ✓ פעמיים ($code)"
ok "§2.3 — קובץ חדש בלי סוג: הצעה (structured output, פעם אחת לקובץ); ✓ → files.kind + הבדיקה \"לאימות\" via ai"

# ===== ביקורת 06 סבב 1 =====
setcase(){ # $1 id, $2 קוד PHP שמשנה את $c (התיק) — "אדם" שעורך את התיק בינתיים
  php -r '$p=new PDO("sqlite:".$argv[1]); $st=$p->prepare("SELECT body FROM funerals WHERE id=?"); $st->execute([$argv[2]]); $c=json_decode($st->fetchColumn(),true);
    eval($argv[3]); $p->prepare("UPDATE funerals SET body=? WHERE id=?")->execute([json_encode($c,JSON_UNESCAPED_UNICODE),$argv[2]]);' "$DB" "$1" "$2"; }
# R1: הצעה למיקום קבר, אדם משנה אותו בינתיים, ✓ → 409 stale, ומה שהאדם כתב נשאר
queue "[$(tool case_update "{\"id\":\"$CASE\",\"fields\":{\"grave\":\"חלקה מהעוזר\"}}"),$(text 'הצעתי')]"
post admin ai_chat "{\"text\":\"קבר\",\"case_id\":\"$CASE\"}" >/dev/null; P="$(j proposals.0.id)"
setcase "$CASE" '$c["grave"]="חלקה של אדם";'
code="$(post admin ai_decide "{\"id\":$P,\"approve\":true}")"; test "$code" = 409 && test "$(j code)" = stale && test "$(j proposal.status)" = stale || fail "R1 ($code)"
caseBody "$CASE" | grep -q 'חלקה של אדם' || fail "R1 העריכה של האדם נדרסה"
# R2: הצעת תשלומים, המשרד מוסיף 777 בינתיים, ✓ → 409, ה-777 נשאר
queue "[$(tool case_update "{\"id\":\"$CASE\",\"fields\":{\"payments\":[{\"amount\":100,\"purpose\":\"קבר\"}]}}"),$(text 'הצעתי')]"
post admin ai_chat "{\"text\":\"תשלום\",\"case_id\":\"$CASE\"}" >/dev/null; P="$(j proposals.0.id)"
setcase "$CASE" '$c["payments"][]=["amount"=>777,"purpose"=>"משרד","reference"=>"","source"=>""];'
code="$(post admin ai_decide "{\"id\":$P,\"approve\":true}")"; test "$code" = 409 || fail "R2 ($code)"
caseBody "$CASE" | grep -q '777' || fail "R2 התשלום של המשרד נמחק"
# והצעה שהשדות שלה לא השתנו — עדיין נשמרת (שינוי בשדה אחר לא חוסם)
queue "[$(tool case_update "{\"id\":\"$CASE\",\"fields\":{\"team\":\"צוות ב\"}}"),$(text 'הצעתי')]"
post admin ai_chat "{\"text\":\"צוות\",\"case_id\":\"$CASE\"}" >/dev/null; P="$(j proposals.0.id)"
setcase "$CASE" '$c["origin"]="מקום אחר";'
code="$(post admin ai_decide "{\"id\":$P,\"approve\":true}")"; test "$code" = 200 && test "$(j proposal.status)" = applied || fail "R1/R2 שינוי בשדה אחר חסם ($code)"
ok "R1, R2 — ✓ על הצעה ששדותיה השתנו → 409 stale, העריכה של האדם נשארת; שינוי בשדה אחר לא חוסם"

# R3: יותר מ-8 סבבי כלים — כל tool_use נסגר ב-tool_result, והשיחה ממשיכה
q="["; for i in $(seq 1 10); do q="$q$(tool case "{\"id\":\"$CASE\"}"),"; done; queue "${q%,}]"
post admin ai_chat "{\"text\":\"לולאה\",\"case_id\":\"$CASE\"}" >/dev/null; T="$(j thread)"; grep -q 'יותר מדי צעדים' "$tmp/out" || fail "R3 הודעת עצירה"
sql "SELECT messages FROM ai_threads WHERE id='$T'" | php -r '$m=json_decode(stream_get_contents(STDIN),true); $open=[];
  foreach($m as $x){ if(!is_array($x["content"])) continue; foreach($x["content"] as $b){ if(($b["type"]??"")==="tool_use") $open[$b["id"]]=1; if(($b["type"]??"")==="tool_result") unset($open[$b["tool_use_id"]]); } }
  exit($open===[] && end($m)["role"]==="user" ? 0 : 1);' || fail "R3 נשאר tool_use בלי tool_result"
queue "[$(text 'ממשיכים')]"
post admin ai_chat "{\"text\":\"הלאה\",\"thread\":\"$T\"}" >/dev/null; test "$(j reply)" = 'ממשיכים' || fail "R3 השיחה לא ממשיכה"
ok "R3 — עצירה אחרי 8 סבבים: כל tool_use נסגר (is_error), והשיחה ממשיכה"

# 🟡3: הקובץ לא נשמר בשיחה; הבקשה הבאה שולחת סימון במקומו, עם drop_block
queue "[$(tool file_view "{\"file\":$FID}"),$(text 'קראתי')]"
post admin ai_chat "{\"text\":\"תקרא\",\"case_id\":\"$DCASE\"}" >/dev/null; T="$(j thread)"
test "$(sql "SELECT COUNT(*) FROM ai_threads WHERE messages LIKE '%JVBERi%' OR messages LIKE '%\"document\"%' OR messages LIKE '%base64%'")" = 0 || fail "🟡3 base64 נשמר ב-ai_threads"
queue "[$(text 'זוכר')]"
post admin ai_chat "{\"text\":\"ומה עכשיו\",\"thread\":\"$T\"}" >/dev/null
lastreq | php -r '$r=json_decode(stream_get_contents(STDIN),true); $j=json_encode($r["body"]["messages"],JSON_UNESCAPED_UNICODE);
  exit(!str_contains($j,"JVBERi") && str_contains($j,"קובץ נקרא בסבב קודם") && ($r["body"]["thinking"]["block_binding"]["prefix_mismatch_behavior"]??"")==="drop_block"
    && str_contains($r["beta"],"thinking-binding-controls-2026-08-01") && ($r["body"]["cache_control"]["type"]??"")==="ephemeral" ? 0 : 1);' || fail "🟡3 הבקשה הבאה: סימון במקום הקובץ, drop_block, cache_control"
ok "🟡3 — אין base64 ב-ai_threads; בבקשה הבאה סימון במקום הקובץ, drop_block ו-cache_control"

# ⚪7: באישור אוטומטי — כתיבה בסבב שבו נקרא קובץ נשארת כרטיס
post admin ai_settings_save '{"auto_approve":true}' >/dev/null
queue "[$(tool file_view "{\"file\":$FID}"),$(tool case_update "{\"id\":\"$DCASE\",\"fields\":{\"team\":\"מהקובץ\"}}"),$(text 'הצעתי')]"
post admin ai_chat "{\"text\":\"תמלא מהקובץ\",\"case_id\":\"$DCASE\"}" >/dev/null
test "$(j proposals.0.status)" = pending && test "$(j proposals.0.card.from_file)" = true || fail "⚪7 כתיבה אחרי קריאת קובץ נשמרה בלי כרטיס"
post admin ai_settings_save '{"auto_approve":false}' >/dev/null
ok "⚪7 — סבב שבו נקרא קובץ: כתיבה תמיד ככרטיס (גם באישור אוטומטי), עם אזהרה"

# 🟡4: תקרת טוקנים — מעבר → הודעה, בלי קריאה לספק
test "$(sql "SELECT tokens FROM ai_usage WHERE org_id=1")" -gt 0 || fail "🟡4 הטוקנים לא נצברים"
post admin ai_settings_save '{"token_cap":1}' >/dev/null; n="$(calls)"
code="$(post admin ai_chat '{"text":"שלום"}')"; test "$(j code)" = cap && test "$(calls)" = "$n" || fail "🟡4 תקרת טוקנים ($code)"
post admin ai_settings_save '{"token_cap":10000000}' >/dev/null
ok "🟡4 — טוקנים נצברים לחודש ולארגון; מעבר לתקרה → הודעה, בלי קריאה לספק"

# ⚪8: הסרת מפתח
post admin ai_key_remove '{}' >/dev/null; test "$(j has_key)" = false && test "$(sql "SELECT COUNT(*) FROM secrets WHERE name='ai_key'")" = 0 || fail "⚪8 הסרת מפתח"
ok "⚪8 — הסרת מפתח (רק מנהל), ונרשם ב-events"

# ===== פרוסה 10: מפתח ברירת מחדל של הבעלים (א11), וקריאת תוכן בזיהוי וקישור — מול הספק המדומה =====
code="$(post admin ai_owner_key_save '{"key":"sk-test-owner-key-0123456789"}')"; test "$code" = 403 || fail "א11: מנהל ארגון שומר מפתח בעלים ($code)"
code="$(post dev ai_owner_key_save '{"key":"sk-test-owner-key-0123456789"}')"; test "$code" = 200 || fail "א11: שמירת מפתח בעלים ($code)"
get admin ai_status >/dev/null; test "$(j has_key)" = true && test "$(j key_source)" = owner || fail "א11: ארגון בלי מפתח — לא עובר למפתח הבעלים"
! grep -q 'sk-test-owner-key' "$tmp/out" || fail "א11: המפתח בתשובה"
code="$(get field link_questions)"; test "$code" = 200 && test "$(j can_answer)" = false || fail "10: link_questions לשטח ($code)"
code="$(post field link_run '{}')"; test "$code" = 403 || fail "10: link_run לשטח ($code)"
code="$(post admin link_rule_save '{"kind":"month","pattern":"חשון","value":"2026-11","layer":"shared"}')"; test "$code" = 403 || fail "10: מנהל ארגון כותב כלל משותף ($code)"
code="$(post admin link_rule_save '{"kind":"month","pattern":"חשון","value":"2026-11"}')"; test "$code" = 200 || fail "10: כלל בארגון ($code)"
get admin link_rules >/dev/null; test "$(j rules.0.layer)" = org && grep -q '11/2026' "$tmp/out" || fail "10: link_rules"
# מספר הרישיון של התיק — "טרם נבדק" (מסד הבדיקה בלבד), כדי שהבינה תמלא אותו; בדיקה שמולאה — לא נדרסת (tests/link.test.php, F3)
php -r '$p=new PDO("sqlite:".$argv[1]); $b=json_decode($p->query("SELECT body FROM funerals WHERE id=".$p->quote($argv[2]))->fetchColumn(),true);
  $b["checks"]["license_number"]=["status"=>"unknown","value"=>"","source"=>"","note"=>""]; $p->prepare("UPDATE funerals SET body=? WHERE id=?")->execute([json_encode($b,JSON_UNESCAPED_UNICODE),$argv[2]]);' "$DB" "$DCASE"
queue "[$(msg end_turn '[{"type":"text","text":"{\"kind\":\"license_document\",\"confidence\":\"high\",\"name\":\"\",\"tz\":\"\",\"death_date\":\"\",\"license_number\":\"L-7788\"}"}]')]"
n="$(calls)"
code="$(post admin link_run '{}')"; test "$code" = 200 || fail "10: link_run ($code)"
test "$(calls)" = "$((n + 1))" || fail "10: הקובץ לא נשלח לספק"
lastreq | php -r '$r=json_decode(stream_get_contents(STDIN),true); exit(($r["body"]["output_config"]["format"]["type"]??"")==="json_schema" && ($r["body"]["messages"][0]["content"][0]["type"]??"")==="document" ? 0 : 1);' || fail "10: בקשה לספק — PDF + structured output"
caseBody "$DCASE" | php -r '$c=json_decode(stream_get_contents(STDIN),true); $l=$c["checks"]["license_number"]??[]; exit(($l["value"]??"")==="L-7788" && ($l["via"]??"")==="ai" && ($l["status"]??"")!=="verified" ? 0 : 1);' || fail "10: מספר הרישיון לא נשלף לתיק"
ok "10 — מפתח הבעלים כברירת מחדל (רק מפתח שומר; לא בתשובה); link_* בהרשאות; קריאת PDF ב-structured output → מספר רישיון בתיק via ai"

head -n 4 "$M/requests.jsonl" | tail -n 1 | grep -q '"version":"2023-06-01"' || fail "anthropic-version"
! grep -E 'PHP (Fatal|Warning|Parse|Deprecated|Notice)' "$tmp/server.log" | grep -vq 'in Unknown on line 0' || { grep -E 'PHP (Fatal|Warning|Parse|Deprecated|Notice)' "$tmp/server.log" | grep -v 'in Unknown on line 0' | head; fail "שגיאות PHP בשרת"; }
echo "PASS (06): F1–F7 — מפתח מוצפן ו-4 תווים, בלי מפתח בלי קריאה, הצעה עד ✓, ✓ → לאימות via ai, שטח 403, ארגון אחר 404, תקרה; §2.2.2 קובץ לספק; §2.3 הצעת סוג; ביקורת: R1–R3, בלי base64, ⚪7, תקרת טוקנים"
