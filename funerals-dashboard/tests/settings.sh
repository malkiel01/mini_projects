#!/usr/bin/env bash
# פרוסה 11 — הגדרות בדיקות ותפקידים גמישים. רץ מתוך tests/run.sh (שרת ומסד משלו), או לבד.
# מתחיל ממסד בגרסה 0 עם 22 תיקים (כמו בשרת) ובודק את קריטריוני הקבלה:
#   S1 מיגרציה לא מוחקת · S2 בדיקה חדשה = "חסר" בתיקים קיימים · S3 הסרה לא מוחקת ערכים · S4 כללית + דריסה בארגון
#   S5 סדר · S6 מותנית · S7 דחוף · S8 תפקיד מוגבל באמת מוגבל (בשרת) · S9 שינוי הרשאה חל מיד, המנהל האחרון
#   S10 משימות ו"המשימות שלי" · S11 משתמש בשני ארגונים
#   מיפוי ל-docs/spec/10-acceptance.md §11: S1→S5+S12 · S2→S6+S12 · S3→S4 · S4→S3 · S5→checkForDocKind (חוזה עם 10) · S6→S8+S13
#   S7→S1 · S8→S10+S14 · S9→S7 · S10→S15 · S11→S9
#   S12–S15 — לפי docs/spec/10-acceptance.md: ערך לפי סוג, API/MCP לתיקים שהוקצו, הקצאה גוברת וסגירה, ארכיון
set -euo pipefail
cd "$(dirname "$0")/../.."
APP=funerals-dashboard
D="$APP/data"
tmp="$(mktemp -d)"
pid=0
wipe(){ find "$D" -mindepth 1 ! -name .htaccess ! -name .gitignore -exec rm -rf {} + 2>/dev/null || true; }
cleanup(){ if [ "$pid" != 0 ]; then kill "$pid" >/dev/null 2>&1 || true; fi; wipe; rm -rf "$tmp"; }
trap cleanup EXIT
fail(){ echo "FAIL (11): $*"; tail -5 "$tmp/server.log" 2>/dev/null; [ -f "$tmp/out" ] && { echo "--- תשובה אחרונה:"; head -c 600 "$tmp/out"; echo; }; exit 1; }
ok(){ echo "  ✓ $*"; }
for f in lib/checks.php lib/roles.php lib/settings-actions.php; do php -l "$APP/$f" >/dev/null || fail "php -l $f"; done

wipe
php "$APP/tests/fixture-v0.php" "$D" "fd_$(printf 'y%.0s' {1..64})" >/dev/null
DB="$D/funerals.sqlite"
sql(){ php -r '$p=new PDO("sqlite:".$argv[1]); $r=$p->query($argv[2])->fetchAll(PDO::FETCH_NUM); foreach($r as $row) echo implode("|",$row),"\n";' "$DB" "$1"; }
sql "SELECT id, body FROM funerals ORDER BY id" > "$tmp/cases0"

idf="$tmp/identity.json"
as(){ printf '%s' "$1" > "$idf"; }
as ''
FD_TEST_IDENTITY_FILE="$idf" php -d opcache.jit=disable -S 127.0.0.1:18971 -t "$APP" -d auto_prepend_file="$PWD/$APP/tests/gate-prepend.php" > "$tmp/server.log" 2>&1 &
pid=$!
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18971/index.html >/dev/null && break || sleep .2; done
base="http://127.0.0.1:18971/api.php"
st(){ curl -sS --retry 0 -o "$tmp/out" -w '%{http_code}' "$@"; }
jv(){ php -r '$v=json_decode(file_get_contents($argv[1]),true); $x=eval("return ".$argv[2].";"); echo is_scalar($x)||$x===null ? (is_bool($x)?($x?"true":"false"):(string)$x) : json_encode($x,JSON_UNESCAPED_UNICODE);' "$tmp/out" "$1"; }
declare -A ID CSRF
ident(){ printf '{"sub":"%s","name":"%s","email":"%s","email_verified":true,"platform_admin":%s,"granted":true}' "$1" "$2" "$3" "$4"; }
ID[dev]="$(ident 1 'המפתח' 'dev@example.com' true)"
ID[boss]="$(ident 2 'מנהל א' 'boss@example.com' false)"
ID[photo]="$(ident 3 'צלם' 'photo@example.com' false)"
ID[office]="$(ident 4 'משרד' 'office@example.com' false)"
login(){ as "${ID[$1]}"; code="$(st -b "$tmp/c-$1" -c "$tmp/c-$1" "$base?action=me")"; CSRF[$1]="$(jv '$v["csrf"] ?? ""')"; }
get(){ as "${ID[$1]}"; st -b "$tmp/c-$1" -c "$tmp/c-$1" "$base?action=$2${3:-}"; }
post(){ as "${ID[$1]}"; st -b "$tmp/c-$1" -c "$tmp/c-$1" -H "X-CSRF-Token: ${CSRF[$1]:-}" -H 'Content-Type: application/json' --data-binary "$3" "$base?action=$2"; }

# ===== S1: מיגרציה v0 → v5 לא מוחקת =====
login dev; test "$code" = 200 || fail "S1: me של המפתח ($code)"
test "$(sql 'PRAGMA user_version')" = 5 || fail "S1: user_version"
sql "SELECT id, body FROM funerals ORDER BY id" > "$tmp/cases1"
cmp -s "$tmp/cases0" "$tmp/cases1" || fail "S1: התיקים השתנו במיגרציה"
test "$(sql 'SELECT COUNT(*) FROM check_defs WHERE org_id=0 AND removed=0')" = 9 || fail "S1: 9 בדיקות ברירת מחדל"
test "$(sql "SELECT group_concat(key) FROM (SELECT key FROM check_defs WHERE org_id=0 ORDER BY position)")" = \
  'license_document,license_number,identity_document,identity_source,payment_evidence,hall_service,grave_purchase,partner_identity,national_insurance' || fail "S1: סדר ברירת המחדל = הסדר הקיים"
test "$(sql "SELECT group_concat(base||':'||name) FROM roles WHERE org_id=1 AND builtin=1 ORDER BY id")" = 'admin:מנהל,office:משרד,field:שטח' || fail "S1: שלושה תפקידים מובנים"
test "$(sql 'SELECT COUNT(*) FROM members WHERE role_id IS NULL')" = 0 || fail "S1: role_id לכל חבר"
ls "$D"/backup/*-v0.sqlite >/dev/null 2>&1 || fail "S1: גיבוי לפני המיגרציה"
test "$(jv 'count($v["checks"])')" = 9 || fail "S1: me.checks"
ok "S1 — v0 → v5: 22 תיקים זהים, 9 בדיקות ברירת מחדל בסדר הקיים, 3 תפקידים מובנים, גיבוי"

FID="$(sql "SELECT id FROM funerals ORDER BY id LIMIT 1")"
FID2="$(sql "SELECT id FROM funerals ORDER BY id LIMIT 1 OFFSET 1")"
FID3="$(sql "SELECT id FROM funerals ORDER BY id LIMIT 1 OFFSET 2")"

# משתמשים: מנהל ומשרד בארגון 1 (הזמנה בתפקיד ישן — תאימות), וצלם — בהמשך, בתפקיד מותאם
post dev invite '{"email":"boss@example.com","role":"admin"}' >/dev/null
post dev invite '{"email":"office@example.com","role":"office"}' >/dev/null
login boss; login office
test "$(sql "SELECT r.name FROM members m JOIN roles r ON r.id=m.role_id JOIN users u ON u.id=m.user_id WHERE u.email='office@example.com'")" = 'משרד' || fail "הזמנה בתפקיד ישן → מובנה"

# ===== S2: בדיקה חדשה בארגון → "חסר" בתיקים קיימים, בלי לגעת בתיק =====
code="$(post office check_save '{"name":"טופס חברה קדישא","type":"document"}')"; test "$code" = 403 || fail "S2: משרד מוסיף בדיקה ($code)"
code="$(post boss check_save '{"name":"טופס חברה קדישא","type":"document","doc_kinds":["טופס"]}')"; test "$code" = 200 || fail "S2: מנהל מוסיף בדיקה ($code)"
NEWK="$(jv '$v["saved"]["key"]')"
[[ "$NEWK" =~ ^c_[a-z]{8}$ ]] || fail "S2: מפתח שנוצר ($NEWK)"
test "$(jv '$v["saved"]["scope"]')" = org || fail "S2: scope org"
code="$(get office case_checks "&id=$FID")"; test "$code" = 200 || fail "S2: case_checks ($code)"
test "$(jv 'count($v["checks"])')" = 10 || fail "S2: 10 בדיקות בתיק"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="'"$NEWK"'"))[0]["state"]')" = missing || fail "S2: הבדיקה החדשה — חסר"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="'"$NEWK"'"))[0]["state_name"]')" = 'חסר' || fail "S2: שם המצב"
sql "SELECT id, body FROM funerals ORDER BY id" > "$tmp/cases2"; cmp -s "$tmp/cases0" "$tmp/cases2" || fail "S2: התיקים השתנו"
code="$(get office me)"; test "$(jv 'end($v["checks"])["key"]')" = "$NEWK" || fail "S2: me.checks כולל את החדשה"
# בדיקה שהארגון הוסיף — לא קיימת בארגון אחר
test "$(sql "SELECT COUNT(*) FROM check_defs WHERE key='$NEWK' AND org_id=0")" = 0 || fail "S2: לא נכתב בכללית"
# ממלאים את הבדיקה החדשה בתיק — דרך save (המפתח החדש מתקבל)
code="$(post office save "$(php -r '$p=new PDO("sqlite:".$argv[1]); $b=json_decode($p->query("SELECT body FROM funerals WHERE id=\"".$argv[2]."\"")->fetchColumn(),true); $b["checks"][$argv[3]]=["status"=>"document_present","value"=>"טופס 7"]; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$DB" "$FID" "$NEWK")")"
test "$code" = 200 || fail "S2: שמירת ערך לבדיקה חדשה ($code)"
ok "S2 — בדיקה חדשה (רק מנהל) מופיעה בכל התיק הקיים כ\"חסר\", בלי שינוי בתיקים; ערך נשמר"

# ===== S3: הסרה לא מוחקת ערכים; החזרה מחזירה אותם =====
code="$(post boss check_remove "{\"key\":\"$NEWK\"}")"; test "$code" = 200 || fail "S3: הסרה ($code)"
test "$(sql "SELECT json_extract(body,'\$.checks.$NEWK.value') FROM funerals WHERE id='$FID'")" = 'טופס 7' || fail "S3: הערך נמחק מהתיק"
code="$(get office case_checks "&id=$FID")"
test "$(jv 'count($v["checks"])')" = 9 && test "$(jv '$v["extra"][0]["key"]')" = "$NEWK" && test "$(jv '$v["extra"][0]["value"]')" = 'טופס 7' || fail "S3: אחרי הסרה — 9, והערך ב-extra"
code="$(post boss check_remove '{"key":"hall_service"}')"; test "$code" = 200 || fail "S3: ארגון מסיר בדיקה כללית ($code)"
test "$(sql "SELECT removed FROM check_defs WHERE org_id=0 AND key='hall_service'")" = 0 || fail "S3: הסרה בארגון נגעה בכללית"
code="$(post boss check_save "{\"key\":\"$NEWK\"}")"; test "$code" = 200 || fail "S3: החזרה ($code)"
code="$(get office case_checks "&id=$FID")"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="'"$NEWK"'"))[0]["value"]')" = 'טופס 7' || fail "S3: אחרי החזרה הערך חזר"
test "$(jv 'count(array_filter($v["checks"], fn($c) => $c["key"]==="hall_service"))')" = 0 || fail "S3: hall_service מוסתרת בארגון"
ok "S3 — הסרה רכה: הערך נשאר בתיק (extra), הסרה בארגון לא נוגעת בכללית, החזרה מחזירה את הערך"

# ===== S4: כללית (מפתח בלבד) + דריסה בארגון + חזרה לכללית =====
code="$(post boss check_save '{"scope":"global","key":"identity_source","name":"x"}')"; test "$code" = 403 || fail "S4: מנהל ארגון עורך כללית ($code)"
code="$(post dev check_save '{"scope":"global","key":"identity_source","name":"מקור הזיהוי (עודכן)"}')"; test "$code" = 200 || fail "S4: מפתח עורך כללית ($code)"
code="$(post boss check_save '{"key":"license_number","name":"מס׳ רישיון","type":"value","value_kind":"number"}')"; test "$code" = 200 || fail "S4: דריסה ($code)"
code="$(get boss checks)"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="license_number"))[0]["name"]')" = 'מס׳ רישיון' || fail "S4: הדריסה גוברת"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="license_number"))[0]["overrides"]')" = true || fail "S4: מסומנת כדריסה"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="identity_source"))[0]["name"]')" = 'מקור הזיהוי (עודכן)' || fail "S4: שינוי כללי מגיע לארגון"
test "$(jv '$v["global"]')" = '' || fail "S4: מנהל ארגון לא מקבל את הכללית לעריכה"
code="$(post boss check_reset '{"key":"license_number"}')"; test "$code" = 200 || fail "S4: חזרה לכללית ($code)"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="license_number"))[0]["name"]')" = 'מספר רישיון קבורה' || fail "S4: אחרי חזרה — השם הכללי"
code="$(post boss check_reset "{\"key\":\"$NEWK\"}")"; test "$code" = 400 || fail "S4: חזרה לבדיקה בלי כללית ($code)"
code="$(post boss check_save '{"name":"x","type":"bad"}')"; test "$code" = 400 || fail "S4: סוג לא תקין ($code)"
code="$(post boss check_save '{"key":"BAD-KEY","name":"x"}')"; test "$code" = 400 || fail "S4: מפתח לא תקין ($code)"
code="$(get dev checks)"; test "$(jv 'count($v["global"])')" = 9 || fail "S4: המפתח רואה את הכללית"
ok "S4 — כללית בידי המפתח בלבד, דריסה בארגון גוברת, שינוי כללי מגיע לארגון, חזרה לכללית; קלט שגוי → 400"

# ===== S5: סדר =====
code="$(post boss check_order "{\"keys\":[\"$NEWK\",\"identity_document\"]}")"; test "$code" = 200 || fail "S5: סדר ($code)"
test "$(jv '$v["checks"][0]["key"]."|".$v["checks"][1]["key"]."|".$v["checks"][2]["key"]')" = "$NEWK|identity_document|license_document" || fail "S5: הסדר של הארגון, ואחריו הכללי"
code="$(post boss check_order '{"keys":["nope"]}')"; test "$code" = 400 || fail "S5: מפתח לא מוכר ($code)"
ok "S5 — סדר בארגון, השאר אחריו לפי הכללית"

# ===== S6: מותנית — "זיהוי בן/בת זוג" רק כשיש רכישת קבר נוסף =====
code="$(post boss check_save '{"key":"partner_identity","cond":{"check":"grave_purchase"}}')"; test "$code" = 200 || fail "S6: תנאי ($code)"
code="$(get office case_checks "&id=$FID2")"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="partner_identity"))[0]["required"]')" = false || fail "S6: בלי רכישה — לא נדרשת"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="partner_identity"))[0]["state"]')" = na || fail "S6: בלי רכישה — לא רלוונטי"
post office save "$(php -r '$p=new PDO("sqlite:".$argv[1]); $b=json_decode($p->query("SELECT body FROM funerals WHERE id=\"".$argv[2]."\"")->fetchColumn(),true); $b["checks"]["grave_purchase"]=["status"=>"document_present"]; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$DB" "$FID2")" >/dev/null
code="$(get office case_checks "&id=$FID2")"
test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="partner_identity"))[0]["required"]')" = true || fail "S6: עם רכישה — נדרשת"
code="$(post boss check_save '{"key":"partner_identity","cond":{"check":"partner_identity"}}')"; test "$code" = 400 || fail "S6: תנאי על עצמה ($code)"
ok "S6 — בדיקה מותנית: לא נדרשת עד שהתנאי מתקיים"

# ===== S7: דחוף (ק3) =====
code="$(get office case_checks "&id=$FID3")"
test "$(jv 'implode(",", array_column($v["urgent"], "code"))' | grep -c critical)" -ge 1 || fail "S7: מסמך קריטי חסר → דחוף"
code="$(post boss urgent_save '{"days":3}')"; test "$code" = 200 && test "$(jv '$v["urgent_days"]')" = 3 || fail "S7: שינוי ימים ($code)"
code="$(post office urgent_save '{"days":3}')"; test "$code" = 403 || fail "S7: משרד משנה ימים ($code)"
code="$(post boss urgent_save '{"days":"x"}')"; test "$code" = 400 || fail "S7: ימים לא תקינים ($code)"
code="$(get office case_checks "&id=$FID3")"
test "$(jv 'implode(",", array_column($v["urgent"], "code"))' | grep -c days)" -ge 1 || fail "S7: עברו ימים מהלוויה → דחוף"
code="$(post boss check_save '{"key":"license_document","critical":false}')"; code="$(post boss check_save '{"key":"identity_document","critical":false}')"
code="$(get office case_checks "&id=$FID3")"
test "$(jv 'count(array_filter($v["urgent"], fn($u) => $u["code"]==="critical"))')" = 0 || fail "S7: בלי מסמך קריטי — לא דחוף מסיבה הזו"
post boss check_save '{"key":"license_document","critical":true}' >/dev/null
ok "S7 — דחוף: מסמך קריטי חסר או ימים מהלוויה; ימים וקריטי ניתנים לשינוי (מנהל בלבד)"

# ===== S8: תפקיד מותאם עם הרשאות מוגבלות — מוגבל באמת, בשרת =====
code="$(post office role_save '{"name":"צלם מצבה","perms":["case.view_assigned","case.track"]}')"; test "$code" = 403 || fail "S8: משרד יוצר תפקיד ($code)"
code="$(post boss role_save '{"name":"צלם מצבה","perms":["case.view_assigned","case.track","no.such"]}')"; test "$code" = 400 || fail "S8: הרשאה לא מוכרת ($code)"
code="$(post boss role_save '{"name":"צלם מצבה","perms":["case.view_assigned","case.track"],"task_types":["צילום מצבה","מיקום קבר"]}')"; test "$code" = 200 || fail "S8: תפקיד חדש ($code)"
RID="$(jv '$v["saved"]["id"]')"
test "$(jv '$v["saved"]["task_types"][0]')" = 'צילום מצבה' || fail "S8: סוגי משימות"
code="$(post boss role_save '{"name":"צלם מצבה","perms":[]}')"; test "$code" = 400 || fail "S8: שם כפול ($code)"
code="$(post boss invite "{\"email\":\"photo@example.com\",\"role_id\":$RID}")"; test "$code" = 200 || fail "S8: הזמנה בתפקיד מותאם ($code)"
login photo; test "$code" = 200 || fail "S8: צלם נכנס ($code)"
test "$(jv '$v["role_label"]')" = 'צלם מצבה' && test "$(jv '$v["role_id"]')" = "$RID" || fail "S8: me — שם התפקיד"
test "$(jv '$v["perms"]["case.view"]')" = false && test "$(jv '$v["perms"]["case.track"]')" = false || fail "S8: me.perms — בלי צפייה כללית"
test "$(sql "SELECT m.role FROM members m JOIN users u ON u.id=m.user_id WHERE u.email='photo@example.com'")" = field || fail "S8: members.role (תאימות) = field"
MON="$(sql "SELECT month FROM funerals WHERE id='$FID'")"
code="$(get photo list "&month=$MON")"; test "$code" = 200 && test "$(jv 'count($v["records"])')" = 0 || fail "S8: רשימה ריקה לפני הקצאה ($code)"
code="$(get photo case_checks "&id=$FID")"; test "$code" = 403 || fail "S8: תיק שלא הוקצה ($code)"
code="$(get photo history "&id=$FID")"; test "$code" = 403 || fail "S8: היסטוריה של תיק שלא הוקצה ($code)"
before="$(sql "SELECT body FROM funerals WHERE id='$FID'")"
code="$(post photo save "{\"id\":\"$FID\",\"month\":\"$MON\",\"name\":\"$(sql "SELECT name FROM funerals WHERE id='$FID'")\",\"notes\":\"x\"}")"; test "$code" = 403 || fail "S8: שמירה בתיק שלא הוקצה ($code)"
test "$(sql "SELECT body FROM funerals WHERE id='$FID'")" = "$before" || fail "S8: משהו נשמר אחרי 403"
for a in field_today "field_search&q=%D7%9B%D7%94%D7%9F" export; do code="$(get photo "$a" "&month=$MON")"; test "$code" = 403 || fail "S8: $a בלי צפייה כללית ($code)"; done
code="$(post photo import '{"records":[]}')"; test "$code" = 403 || fail "S8: ייבוא ($code)"
code="$(post photo check_save '{"name":"x"}')"; test "$code" = 403 || fail "S8: הגדרות ($code)"
code="$(post photo role_save '{"name":"x","perms":["case.view"]}')"; test "$code" = 403 || fail "S8: יצירת תפקיד ($code)"
code="$(get photo members)"; test "$code" = 403 || fail "S8: משתמשים ($code)"
# הקצאה → רואה ומעדכן רק את התיק הזה, ורק מה שמותר לו
code="$(post photo task_save "{\"case_id\":\"$FID\",\"title\":\"x\",\"assignee_role_id\":$RID}")"; test "$code" = 403 || fail "S8: צלם מקצה לעצמו ($code)"
code="$(post boss task_save "{\"case_id\":\"$FID\",\"title\":\"צילום מצבה\",\"type\":\"צילום מצבה\",\"assignee_role_id\":$RID}")"; test "$code" = 200 || fail "S8: הקצאה לתפקיד ($code)"
TID="$(jv '$v["task"]["id"]')"
code="$(get photo list "&month=$MON")"; test "$(jv 'implode(",", array_column($v["records"], "id"))')" = "$FID" || fail "S8: רשימה — רק התיק שהוקצה"
code="$(get photo case_checks "&id=$FID")"; test "$code" = 200 || fail "S8: תיק שהוקצה ($code)"
body="$(sql "SELECT body FROM funerals WHERE id='$FID'")"
code="$(post photo save "$(php -r '$b=json_decode($argv[1],true); $b["grave"]="גוש 9"; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$body")")"; test "$code" = 200 || fail "S8: מיקום קבר בתיק שהוקצה ($code)"
body="$(sql "SELECT body FROM funerals WHERE id='$FID'")"
code="$(post photo save "$(php -r '$b=json_decode($argv[1],true); $b["origin"]="אחר"; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$body")")"; test "$code" = 403 || fail "S8: פרטים בלי case.edit ($code)"
code="$(post photo save "$(php -r '$b=json_decode($argv[1],true); $b["checks"]["license_document"]=["status"=>"verified"]; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$body")")"; test "$code" = 403 || fail "S8: אישור בדיקה בלי case.approve ($code)"
code="$(post photo save "$(php -r '$b=json_decode($argv[1],true); $b["checks"]["hall_service"]=["status"=>"not_applicable"]; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$body")")"; test "$code" = 403 || fail "S8: \"לא רלוונטי\" בלי case.approve ($code)"
code="$(post photo save "$(php -r '$b=json_decode($argv[1],true); $b["checks"]["identity_source"]=["status"=>"reported","value"=>"x"]; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$body")")"; test "$code" = 403 || fail "S8: \"עודכן ידנית\" בלי case.approve ($code)"
test "$(sql "SELECT body FROM funerals WHERE id='$FID'")" = "$body" || fail "S8: משהו נשמר אחרי 403"
code="$(get photo case_checks "&id=$FID2")"; test "$code" = 403 || fail "S8: תיק אחר עדיין חסום ($code)"
ok "S8 — תפקיד מותאם (רק שהוקצו + מעקב): בלי הקצאה — כלום; עם הקצאה — רק התיק הזה, בלי פרטים ובלי אישור; שטח/ייצוא/הגדרות → 403"

# ===== S9: שינוי הרשאה חל מיד; המנהל האחרון =====
code="$(post boss role_save "{\"id\":$RID,\"perms\":[\"case.view_assigned\",\"case.track\",\"case.approve\"]}")"; test "$code" = 200 || fail "S9: הוספת אישור ($code)"
code="$(post photo save "$(php -r '$b=json_decode($argv[1],true); $b["checks"]["license_document"]=["status"=>"verified"]; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$body")")"; test "$code" = 200 || fail "S9: אישור אחרי הוספת ההרשאה ($code)"
code="$(post boss role_save "{\"id\":$RID,\"perms\":[\"case.view\",\"case.track\"]}")"
code="$(get photo field_today)"; test "$code" = 200 || fail "S9: צפייה כללית חלה מיד ($code)"
post boss role_save "{\"id\":$RID,\"perms\":[\"case.view_assigned\",\"case.track\"]}" >/dev/null
ADMIN_RID="$(sql "SELECT id FROM roles WHERE org_id=1 AND builtin=1 AND base='admin'")"
OFFICE_RID="$(sql "SELECT id FROM roles WHERE org_id=1 AND builtin=1 AND base='office'")"
code="$(post boss role_save "{\"id\":$ADMIN_RID,\"perms\":[\"case.view\"]}")"; test "$code" = 400 || fail "S9: הורדת ניהול משתמשים מהמנהלים האחרונים ($code)"
BOSS_UID="$(sql "SELECT id FROM users WHERE email='boss@example.com'")"; DEV_UID="$(sql "SELECT id FROM users WHERE email='dev@example.com'")"
code="$(post boss member_role "{\"user_id\":$DEV_UID,\"role_id\":$OFFICE_RID}")"; test "$code" = 200 || fail "S9: מפתח → משרד, נשאר מנהל אחד ($code)"
code="$(post boss member_role "{\"user_id\":$BOSS_UID,\"role_id\":$OFFICE_RID}")"; test "$code" = 400 || fail "S9: המנהל האחרון ($code)"
code="$(post boss role_archive "{\"id\":$RID}")"; test "$code" = 400 || fail "S9: ארכיון לתפקיד עם חברים ($code)"
code="$(post boss role_archive "{\"id\":$ADMIN_RID}")"; test "$code" = 400 || fail "S9: ארכיון למובנה ($code)"
code="$(get boss members)"; test "$(jv 'count($v["role_defs"])')" = 4 || fail "S9: members.role_defs"
test "$(jv 'array_values(array_filter($v["members"], fn($m) => $m["email"]==="photo@example.com"))[0]["role_name"]')" = 'צלם מצבה' || fail "S9: שם התפקיד ברשימת החברים"
ok "S9 — שינוי הרשאה חל מיד (בלי כניסה מחדש), אי אפשר להשאיר ארגון בלי מנהל, ארכיון רק לתפקיד ריק ולא מובנה"

# ===== S10: משימות ו"המשימות שלי" =====
code="$(get photo my_tasks)"; test "$code" = 200 && test "$(jv '$v["items"][0]["kind"]."|".$v["items"][0]["id"]')" = "task|$TID" || fail "S10: המשימה של התפקיד ($code)"
# תפקיד אחראי לבדיקה → כל תיק שבו היא לא הושלמה הוא משימה (מחושבת); לצלם "רק שהוקצו" — רק בתיקים שהוקצו
code="$(post boss check_save "{\"key\":\"$NEWK\",\"role\":\"$RID\"}")"; test "$code" = 200 || fail "S10: תפקיד אחראי ($code)"
code="$(get photo my_tasks)"
test "$(jv 'implode(",", array_unique(array_column(array_filter($v["items"], fn($i) => $i["kind"]==="check"), "case_id")))')" = "$FID" || fail "S10: בדיקות של התפקיד — רק בתיק שהוקצה"
code="$(post boss check_save '{"key":"grave_purchase","role":"'"$OFFICE_RID"'"}')"
code="$(get office my_tasks)"; test "$(jv 'count(array_filter($v["items"], fn($i) => $i["kind"]==="check" && $i["check_key"]==="grave_purchase"))')" -ge 20 || fail "S10: משרד — רכישת קבר בכל התיקים שלא הושלמו"
OFF_UID="$(sql "SELECT id FROM users WHERE email='office@example.com'")"
code="$(post boss task_save "{\"case_id\":\"$FID2\",\"title\":\"לבקש אסמכתה\",\"assignee_user_id\":$OFF_UID,\"due\":\"2026-12-01\"}")"; test "$code" = 200 || fail "S10: משימה לאדם ($code)"
T2="$(jv '$v["task"]["id"]')"
code="$(post boss task_save "{\"case_id\":\"$FID2\",\"title\":\"x\",\"assignee_user_id\":$OFF_UID,\"assignee_role_id\":$RID}")"; test "$code" = 400 || fail "S10: אדם וגם תפקיד ($code)"
code="$(post boss task_save "{\"case_id\":\"fd_nope_nope_nope\",\"title\":\"x\",\"assignee_user_id\":$OFF_UID}")"; test "$code" = 404 || fail "S10: תיק לא קיים ($code)"
code="$(post boss task_save "{\"case_id\":\"$FID2\",\"title\":\"x\",\"assignee_user_id\":99999}")"; test "$code" = 404 || fail "S10: אדם שאינו חבר ($code)"
code="$(post photo task_status "{\"id\":$T2,\"status\":\"done\"}")"; test "$code" = 403 || fail "S10: אחר מסמן משימה לא שלו ($code)"
code="$(post office task_status "{\"id\":$T2,\"status\":\"done\"}")"; test "$code" = 200 && test "$(jv '$v["task"]["status"]')" = done || fail "S10: המוקצה מסמן ($code)"
code="$(post photo task_status "{\"id\":$TID,\"status\":\"done\"}")"; test "$code" = 200 || fail "S10: משימה של התפקיד שלי ($code)"
code="$(get office tasks "&case_id=$FID2")"; test "$(jv 'count($v["tasks"])')" = 1 || fail "S10: משימות התיק"
test "$(sql "SELECT COUNT(*) FROM events WHERE action IN ('task_create','task_status','role_create','check_save')")" -ge 4 || fail "S10: נרשם ב-events"
ok "S10 — משימה לאדם או לתפקיד, \"המשימות שלי\" (מפורשות + בדיקות שהתפקיד אחראי עליהן), סימון רק בידי המוקצה או מנהל, ביומן"

# ===== S11: משתמש בשני ארגונים, תפקיד שונה בכל אחד =====
code="$(post dev org_create '{"name":"חברה קדישא ב"}')"; ORG2="$(jv '$v["created"]')"
test "$(sql "SELECT COUNT(*) FROM roles WHERE org_id=$ORG2 AND builtin=1")" = 3 || fail "S11: תפקידים מובנים לארגון חדש"
code="$(post dev org_switch "{\"org_id\":$ORG2}")"
code="$(post dev invite '{"email":"photo@example.com","role":"office"}')"; test "$code" = 200 || fail "S11: הזמנה לארגון ב ($code)"
login photo
code="$(post photo org_switch "{\"org_id\":$ORG2}")"; test "$code" = 200 || fail "S11: מעבר לארגון ב ($code)"
code="$(get photo me)"; test "$(jv '$v["role_label"]')" = 'משרד' && test "$(jv '$v["perms"]["case.view"]')" = true || fail "S11: בארגון ב — משרד"
test "$(jv 'count($v["checks"])')" = 9 || fail "S11: בארגון ב — הבדיקות הכלליות בלבד"
code="$(post photo org_switch '{"org_id":1}')"; code="$(get photo me)"; test "$(jv '$v["role_label"]')" = 'צלם מצבה' || fail "S11: חזרה לארגון א — צלם"
ok "S11 — משתמש בשני ארגונים: תפקיד והרשאות לפי הארגון הפעיל; הגדרות של ארגון אחד לא עוברות לאחר"

# ===== S12 (10-acceptance S1): ערך לפי סוג, כן/לא =====
code="$(post dev org_switch '{"org_id":1}')"
post boss check_save '{"key":"license_number","type":"value","value_kind":"number"}' >/dev/null
post boss check_save '{"name":"נקבר בחלקה משפחתית","key":"family_plot","type":"yesno"}' >/dev/null
post boss check_save '{"name":"תאריך פטירה","key":"death_date","type":"value","value_kind":"date"}' >/dev/null
setv(){ php -r '$p=new PDO("sqlite:".$argv[1]); $b=json_decode($p->query("SELECT body FROM funerals WHERE id=\"".$argv[2]."\"")->fetchColumn(),true); $b["checks"][$argv[3]]=["status"=>$argv[5]??"document_present","value"=>$argv[4]]; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$DB" "$@"; }
code="$(post office save "$(setv "$FID3" license_number abc)")"; test "$code" = 400 || fail "S12: מספר — טקסט נדחה ($code)"
code="$(post office save "$(setv "$FID3" license_number 12345)")"; test "$code" = 200 || fail "S12: מספר ($code)"
code="$(post office save "$(setv "$FID3" family_plot אולי)")"; test "$code" = 400 || fail "S12: כן/לא — ערך אחר ($code)"
code="$(post office save "$(setv "$FID3" family_plot כן)")"; test "$code" = 200 || fail "S12: כן ($code)"
code="$(post office save "$(setv "$FID3" death_date 2026-02-30)")"; test "$code" = 400 || fail "S12: תאריך לא קיים ($code)"
code="$(post office save "$(setv "$FID3" death_date 2026-10-01)")"; test "$code" = 200 || fail "S12: תאריך ($code)"
# מותנית על כן/לא: רק כש"כן"
post boss check_save '{"key":"partner_identity","cond":{"check":"family_plot"}}' >/dev/null
code="$(get office case_checks "&id=$FID3")"; test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="partner_identity"))[0]["state"]')" = missing || fail "S12: כן → נדרשת, חסר"
post office save "$(setv "$FID3" family_plot לא)" >/dev/null
code="$(get office case_checks "&id=$FID3")"; test "$(jv 'array_values(array_filter($v["checks"], fn($c) => $c["key"]==="partner_identity"))[0]["state"]')" = na || fail "S12: לא → לא נספרת"
ok "S12 — ערך לפי סוג (מספר/תאריך), כן/לא רק שני ערכים, מותנית על \"כן\""

# ===== S13 (S6): API חיצוני ו-MCP — רק התיקים שהוקצו, תיק אחר → 404 =====
login photo
as "${ID[photo]}"; st -b "$tmp/c-photo" -c "$tmp/c-photo" -H "X-CSRF-Token: ${CSRF[photo]}" -H 'Content-Type: application/json' -d '{"name":"בדיקה","days":30}' "http://127.0.0.1:18971/tokens.php?action=create" >/dev/null; TOK="$(jv '$v["token"]')"
test -n "$TOK" || fail "S13: אסימון לצלם"
xg(){ st -H "Authorization: Bearer $TOK" "http://127.0.0.1:18971/ext.php/$1"; }
code="$(xg "cases")"; test "$code" = 200 && test "$(jv 'implode(",", array_column($v["cases"], "id"))')" = "$FID" || fail "S13: cases — רק שלו ($code)"
code="$(xg "case?id=$FID")"; test "$code" = 200 || fail "S13: case שלו ($code)"
code="$(xg "case?id=$FID2")"; test "$code" = 404 || fail "S13: case של אחר → 404 ($code)"
NM="$(sql "SELECT name FROM funerals WHERE id='$FID2'" | cut -c1-6)"
code="$(st -H "Authorization: Bearer $TOK" -G --data-urlencode "q=$NM" "http://127.0.0.1:18971/ext.php/find")"; test "$code" = 200 && test "$(jv 'implode(",", array_column($v["results"], "id"))')" = "$FID" || fail "S13: find — רק התיק שלו ($code)"
code="$(st -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d "{\"id\":\"$FID2\",\"note\":\"x\"}" "http://127.0.0.1:18971/ext.php/case_update")"; test "$code" = 404 || fail "S13: עדכון תיק של אחר ($code)"
code="$(st -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d "{\"id\":\"$FID\",\"note\":\"מהצ'אט\"}" "http://127.0.0.1:18971/ext.php/case_update")"; test "$code" = 200 || fail "S13: עדכון תיק שלו ($code)"
ok "S13 — API חיצוני (וכך MCP, אותו extDo): רק התיקים שהוקצו, תיק אחר → 404, גם בעדכון"

# ===== S14 (S8): הקצאה לאדם גוברת; השלמת הבדיקה סוגרת את המשימה =====
code="$(post boss task_save "{\"case_id\":\"$FID3\",\"check_key\":\"grave_purchase\",\"assignee_user_id\":$BOSS_UID}")"; T3="$(jv '$v["task"]["id"]')"
code="$(get office my_tasks)"; test "$(jv 'count(array_filter($v["items"], fn($i) => $i["kind"]==="check" && $i["case_id"]==="'"$FID3"'" && $i["check_key"]==="grave_purchase"))')" = 0 || fail "S14: הקצאה לאדם גוברת על התפקיד"
code="$(get boss my_tasks)"; test "$(jv 'count(array_filter($v["items"], fn($i) => ($i["id"] ?? 0)=='"$T3"'))')" = 1 || fail "S14: אצל המוקצה"
post office save "$(setv "$FID3" grave_purchase '' verified)" >/dev/null
test "$(sql "SELECT status FROM tasks WHERE id=$T3")" = done || fail "S14: הבדיקה הושלמה → המשימה נסגרה"
ok "S14 — הקצאה לאדם גוברת על תפקיד אחראי; השלמת הבדיקה סוגרת את המשימה"

# ===== S15 (S10): ארכיון =====
MON3="$(sql "SELECT month FROM funerals WHERE id='$FID3'")"
allv="$(php -r '$p=new PDO("sqlite:".$argv[1]); $b=json_decode($p->query("SELECT body FROM funerals WHERE id=\"".$argv[2]."\"")->fetchColumn(),true);
  foreach (json_decode($argv[3],true)["checks"] as $c) $b["checks"][$c["key"]]=["status"=>$c["key"]==="partner_identity"?"unknown":"verified","value"=>$b["checks"][$c["key"]]["value"]??""]; echo json_encode($b,JSON_UNESCAPED_UNICODE);' "$DB" "$FID3" "$(get boss case_checks "&id=$FID3" >/dev/null; cat "$tmp/out")")"
code="$(post boss save "$allv")"; test "$code" = 200 || fail "S15: הכול הושלם ($code)"
code="$(get boss case_checks "&id=$FID3")"; test "$(jv '$v["archived"]')" = true || fail "S15: לארכיון (מותנית שלא חלה לא נחשבת)"
code="$(get boss list "&month=$MON3")"; test "$(jv 'count(array_filter($v["records"], fn($r) => $r["id"]==="'"$FID3"'"))')" = 0 || fail "S15: לא ברשימה הרגילה"
code="$(get boss list "&month=$MON3&archived=1")"; test "$(jv 'count(array_filter($v["records"], fn($r) => $r["id"]==="'"$FID3"'"))')" = 1 || fail "S15: ברשימת הארכיון"
code="$(get boss field_search "&q=$(php -r 'echo rawurlencode(mb_substr($argv[1],0,4));' "$(sql "SELECT name FROM funerals WHERE id='$FID3'")")")"; test "$(jv 'count(array_filter($v["cases"] ?? $v["results"] ?? $v, fn($r) => is_array($r) && ($r["id"]??"")==="'"$FID3"'"))')" = 1 || fail "S15: נמצא בחיפוש"
post boss check_save '{"name":"בדיקה אחרי ארכיון","type":"document"}' >/dev/null
code="$(get boss case_checks "&id=$FID3")"; test "$(jv '$v["archived"]')" = true || fail "S15: בדיקה חדשה לא מוציאה מהארכיון"
code="$(post boss case_restore "{\"id\":\"$FID3\"}")"; test "$code" = 200 || fail "S15: החזרה ($code)"
code="$(get boss list "&month=$MON3")"; test "$(jv 'count(array_filter($v["records"], fn($r) => $r["id"]==="'"$FID3"'"))')" = 1 || fail "S15: אחרי החזרה — ברשימה"
code="$(post boss case_restore "{\"id\":\"$FID3\"}")"; test "$code" = 409 || fail "S15: החזרה פעמיים ($code)"
ok "S15 — ארכיון: הכול הושלם → ארכיון (לא ברשימה, בחיפוש), בדיקה חדשה לא מוציאה, החזרה מחזירה"

sql "SELECT id FROM funerals ORDER BY id" > "$tmp/ids"; test "$(wc -l < "$tmp/ids")" = 22 || fail "בסוף: 22 תיקים"
! grep -E 'PHP (Fatal|Warning|Parse|Deprecated)' "$tmp/server.log" | grep -vq 'in Unknown on line 0' || { grep -E 'PHP (Fatal|Warning|Parse|Deprecated)' "$tmp/server.log" | grep -v 'in Unknown on line 0'; fail "שגיאות PHP בשרת"; }
echo "PASS (11): S1–S15 — מיגרציה לא מוחקת, בדיקה חדשה = חסר, הסרה לא מוחקת, כללית + דריסה, סדר, מותנית, דחוף, תפקיד מוגבל באמת מוגבל, שינוי הרשאה מיד, משימות, שני ארגונים"
