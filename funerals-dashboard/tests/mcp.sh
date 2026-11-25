#!/usr/bin/env bash
# בדיקות פרוסה 09 (MCP + OAuth): K1–K15 מ-docs/spec/09-mcp.md. רץ מתוך tests/run.sh, או לבד.
# K16–K18 — ידניים, אחרי פריסה (MCP Inspector, ChatGPT, Claude).
set -euo pipefail
cd "$(dirname "$0")/../.."
D=funerals-dashboard
tmp="$(mktemp -d)"
pid=0
wipe(){ find "$D/data" -mindepth 1 ! -name .htaccess ! -name .gitignore -exec rm -rf {} + 2>/dev/null || true; }
cleanup(){ if [ "$pid" != 0 ]; then kill "$pid" >/dev/null 2>&1 || true; fi; wipe; rm -rf "$tmp"; }
trap cleanup EXIT
fail(){ echo "FAIL (09): $*"; [ -f "$tmp/out" ] && head -c 800 "$tmp/out" && echo; exit 1; }
for f in mcp.php mcp-lib.php oauth/metadata.php oauth/register.php oauth/authorize.php oauth/token.php oauth/revoke.php tests/mcp-router.php tokens.php; do
  php -l "$D/$f" >/dev/null || fail "php -l $f"; done

# K15: app.json — הכתובות הציבוריות, ו-authorize לא
php -r '$a=json_decode(file_get_contents($argv[1]),true)["public"]??[];
  foreach(["mcp","mcp.php",".well-known/oauth-protected-resource",".well-known/oauth-authorization-server",".well-known/openid-configuration","oauth/metadata.php","oauth/register.php","oauth/token.php","oauth/revoke.php"] as $p) if(!in_array($p,$a,true)) { fwrite(STDERR,"חסר $p\n"); exit(1); }
  foreach($a as $p) if (str_contains($p,"authorize")) exit(2);' $D/app.json || fail "K15 public"
php platform/check-apps.php >/dev/null || fail "K15 check-apps"

wipe
db=$D/data/funerals.sqlite
fx(){ php $D/tests/ext-fixture.php "$db" "$@"; }
sql(){ fx sql "$1"; }
id="$tmp/identity.json"
as(){ printf '%s' "$1" > "$id"; }
as ''
# מסמכי CIMD מדומים ו-DNS מדומה — רק בשרת הפיתוח (oauthTestDir)
cimd="$tmp/cimd"; mkdir -p "$cimd"; : > "$cimd/fetch.log"
printf '%s' '{"client.example.com":["93.184.216.34"],"intranet.example.com":["192.168.1.10"],"mapped.example.com":["::ffff:10.0.0.1"],"cgnat.example.com":["100.64.1.1"],"nat64.example.com":["64:ff9b::a00:1"],"mixed.example.com":["93.184.216.34","10.1.1.1"]}' > "$cimd/hosts.json"
CIMD_ID='https://client.example.com/oauth/client.json'
php -r 'file_put_contents($argv[1]."/".sha1($argv[2]).".json", json_encode(["client_id"=>$argv[2],"client_name"=>"Claude","redirect_uris"=>["https://claude.ai/api/mcp/auth_callback"],"token_endpoint_auth_method"=>"none"]));' "$cimd" "$CIMD_ID"

FD_TEST_IDENTITY_FILE="$id" FD_TEST_CIMD_DIR="$cimd" php -d opcache.jit=disable -S 127.0.0.1:18966 -t $D \
  -d auto_prepend_file="$PWD/$D/tests/gate-prepend.php" "$D/tests/mcp-router.php" > "$tmp/server.log" 2>&1 &
pid=$!
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18966/oauth/metadata.php?doc=server >/dev/null && break || sleep .2; done
H=http://127.0.0.1:18966
st(){ curl -sS -o "$tmp/out" -D "$tmp/hdr" -w '%{http_code}' "$@"; }
j(){ php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){ if(!is_array($v)||!array_key_exists($k,$v)){echo "";exit;} $v=$v[$k];} echo is_scalar($v)?(is_bool($v)?($v?"true":"false"):$v):json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' "$tmp/out" "$1"; }
rpc(){ # $1 גוף JSON, שאר הארגומנטים ל-curl
  local b="$1"; shift; st -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$b" "$@" "$H/mcp"; }
call(){ # $1 אסימון, $2 כלי, $3 ארגומנטים
  rpc "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":${3:-{\}}}}" -H "Authorization: Bearer $1"; }
ADMIN='{"sub":"7","name":"בודק","email":"tester@example.com","platform_admin":false,"granted":true}'
FIELD='{"sub":"9","name":"עובד שטח","email":"field@example.com","platform_admin":false,"granted":true}'
NOBODY='{"sub":"11","name":"בלי ארגון","email":"nobody@example.com","platform_admin":false,"granted":true}'

# הבקשה הראשונה מריצה את המיגרציה של 01
code="$(rpc '{"jsonrpc":"2.0","id":1,"method":"ping"}')"; test "$code" = 200 || fail "ping ($code)"
fx setup

# ── K1: initialize בלי אסימון ──
code="$(rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}')"
test "$code" = 200 || fail "K1 initialize ($code)"
test "$(j result.protocolVersion)" = 2025-06-18 || fail "K1 גרסה שהלקוח ביקש"
test -n "$(j result.capabilities.tools)" || fail "K1 capabilities.tools"
php -r '$v=json_decode(file_get_contents($argv[1]),true); $i=$v["result"]["instructions"]??""; exit($i!=="" && mb_strlen($i)<=512 && str_contains($i,"find") ? 0 : 1);' "$tmp/out" || fail "K1 instructions ≤ 512"
rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"1999-01-01"}}' >/dev/null
test "$(j result.protocolVersion)" = 2025-11-25 || fail "K1 גרסה לא נתמכת → החדשה"
code="$(rpc '{"jsonrpc":"2.0","method":"notifications/initialized"}')"; test "$code" = 202 && test ! -s "$tmp/out" || fail "notification → 202 בלי גוף ($code)"
code="$(st "$H/mcp")"; test "$code" = 405 || fail "GET → 405 ($code)"
code="$(st "$H/mcp.php")"; test "$code" = 405 || fail "GET mcp.php → 405 ($code)"
rpc '{"jsonrpc":"2.0","id":3,"method":"resources/list"}' >/dev/null; test "$(j error.code)" = -32601 || fail "שיטה לא מוכרת → -32601"
code="$(rpc 'not json')"; test "$code" = 400 && test "$(j error.code)" = -32700 || fail "parse error ($code)"
rpc '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","method":"notifications/x"},{"jsonrpc":"2.0","id":2,"method":"tools/list"}]' >/dev/null
php -r '$v=json_decode(file_get_contents($argv[1]),true); exit(count($v)===2 && $v[0]["id"]===1 && isset($v[1]["result"]["tools"]) ? 0 : 1);' "$tmp/out" || fail "batch"
big="$(php -r 'echo json_encode(array_map(fn($i)=>["jsonrpc"=>"2.0","id"=>$i,"method"=>"ping"], range(1,21)));')"
code="$(rpc "$big")"; test "$code" = 400 || fail "batch מעל 20 ($code)"

# ── K2: tools/list ──
rpc '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' >/dev/null
php -r 'chdir($argv[2]); require "lib.php"; require "ext-lib.php";
  $want=array_values(array_diff(array_keys(extActions()),["prompt"])); $acts=extActions();
  $t=json_decode(file_get_contents($argv[1]),true)["result"]["tools"]; $names=array_column($t,"name");
  if ($names!==$want) { fwrite(STDERR,"names\n"); exit(1); }
  foreach($t as $x){ if (preg_match("/delete|remove/i",$x["name"])) exit(2);
    $ro=$x["annotations"]["readOnlyHint"]??null; if ($ro!==($acts[$x["name"]]["method"]==="GET")) exit(3);
    if (($x["inputSchema"]["type"]??"")!=="object") exit(4);
    if (!$ro && (($x["annotations"]["destructiveHint"]??null)!==false || ($x["annotations"]["openWorldHint"]??null)!==false)) exit(5); }
  $f=$t[array_search("find",$names)]; if (($f["inputSchema"]["required"]??[])!==["q"]) exit(6);
  $u=$t[array_search("case_update",$names)]; if (!isset($u["inputSchema"]["properties"]["checks"])) exit(7);' "$tmp/out" "$D" || fail "K2 tools/list ($?)"

# ── K3: tools/call בלי אסימון → 401 + resource_metadata ──
code="$(call '' whoami)"; test "$code" = 401 || fail "K3 בלי אסימון ($code)"
prm="$(grep -i '^WWW-Authenticate:' "$tmp/hdr" | sed -n 's/.*resource_metadata="\([^"]*\)".*/\1/p')"
test "$prm" = "https://mbe-plus.com/mini_projects/funerals-dashboard/.well-known/oauth-protected-resource" || fail "K3 resource_metadata ($prm)"
code="$(st "$H/.well-known/oauth-protected-resource")"; test "$code" = 200 || fail "K3 PRM ($code)"
test "$(j resource)" = "https://mbe-plus.com/mini_projects/funerals-dashboard/mcp" && test "$(j authorization_servers.0)" = "https://mbe-plus.com/mini_projects/funerals-dashboard" \
  && test "$(j scopes_supported.0)" = cases || fail "K3 PRM תוכן"
for p in .well-known/oauth-authorization-server .well-known/openid-configuration .well-known/oauth-authorization-server/mini_projects/funerals-dashboard; do
  code="$(st "$H/$p")"; test "$code" = 200 || fail "מטא-נתונים $p ($code)"
  test "$(j issuer)" = "https://mbe-plus.com/mini_projects/funerals-dashboard" && test "$(j code_challenge_methods_supported)" = '["S256"]' \
    && test "$(j token_endpoint_auth_methods_supported)" = '["none"]' && test "$(j client_id_metadata_document_supported)" = true \
    && test "$(j grant_types_supported)" = '["authorization_code","refresh_token"]' || fail "RFC 8414 תוכן ($p)"
done
grep -qi '^Access-Control-Allow-Origin: \*' "$tmp/hdr" || fail "CORS במטא-נתונים"
code="$(call 'fdo_000000000000000000000000000000000000000000000000' whoami)"; test "$code" = 401 && grep -qi 'error="invalid_token"' "$tmp/hdr" || fail "K3 אסימון שגוי ($code)"

# ── DCR ──
RED='https://chatgpt.com/connector_platform_oauth_redirect'
reg(){ st -X POST -H 'Content-Type: application/json' -d "$1" "$H/oauth/register.php"; }
code="$(reg "{\"client_name\":\"ChatGPT\",\"redirect_uris\":[\"$RED\"],\"grant_types\":[\"authorization_code\",\"refresh_token\"],\"response_types\":[\"code\"],\"token_endpoint_auth_method\":\"none\"}")"
test "$code" = 201 || fail "DCR ($code)"
CID="$(j client_id)"; [[ "$CID" =~ ^fdc_[a-f0-9]{32}$ ]] || fail "DCR client_id"
code="$(reg '{"redirect_uris":["http://evil.example/cb"]}')"; test "$code" = 400 || fail "DCR http ($code)"
code="$(reg '{"redirect_uris":["https://x.example/cb"],"token_endpoint_auth_method":"client_secret_basic"}')"; test "$code" = 400 || fail "DCR סוד ($code)"
code="$(reg '{"client_name":"Inspector","redirect_uris":["http://localhost:6274/oauth/callback"]}')"; test "$code" = 201 || fail "DCR loopback (Inspector) ($code)"
for u in 'http://evil\\@localhost/cb' 'http://evil@localhost/cb' 'http://127.0.0.1.evil.com/cb' 'http://localhost.evil.com/cb' 'http://[::2]/cb' 'https://x.example/cb#frag'; do
  code="$(reg "{\"redirect_uris\":[\"$u\"]}")"; test "$code" = 400 || fail "DCR עקיפת loopback: $u ($code)"
done
code="$(reg '{"client_name":"<script>alert(1)</script>\u202eטקסט\u200b","redirect_uris":["https://x.example/cb"]}')"; test "$code" = 201 || fail "DCR שם עם תגית ($code)"
XID="$(j client_id)"; test "$(j client_name)" = '<script>alert(1)</script>טקסט' || fail "client_name — תווי bidi/zero-width לא הוסרו ($(j client_name))"

# ── OAuth: authorize ──
pkce(){ php -r '$v=rtrim(strtr(base64_encode(random_bytes(40)),"+/","-_"),"="); echo $v," ",rtrim(strtr(base64_encode(hash("sha256",$v,true)),"+/","-_"),"=");'; }
enc(){ php -r 'echo rawurlencode($argv[1]);' "$1"; }
authq(){ # $1 client, $2 redirect, $3 challenge, [$4 method] → query
  echo "response_type=code&client_id=$(enc "$1")&redirect_uri=$(enc "$2")&code_challenge=$3&code_challenge_method=${4:-S256}&state=st_123&resource=$(enc https://mbe-plus.com/mini_projects/funerals-dashboard/mcp)&scope=cases"; }
consent(){ # $1 זהות, $2 client, $3 redirect, $4 challenge, [$5 org] → מדפיס את הקוד; התשובה ב-out
  as "$1"; local jar="$tmp/jar.$RANDOM" q c loc
  q="$(authq "$2" "$3" "$4")"
  c="$(st -c "$jar" -b "$jar" "$H/oauth/authorize.php?$q")"; test "$c" = 200 || { echo "consent GET $c" >&2; return 1; }
  local csrf; csrf="$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/out")"
  local org="${5:-$(sed -n 's/.*name="org" value="\([0-9]*\)".*/\1/p' "$tmp/out" | head -1)}"
  c="$(st -c "$jar" -b "$jar" -X POST --data "$q&csrf=$csrf&org=$org&decision=approve" "$H/oauth/authorize.php")"
  test "$c" = 302 || { echo "consent POST $c" >&2; return 1; }
  loc="$(grep -i '^Location:' "$tmp/hdr" | tr -d '\r' | sed 's/^[Ll]ocation: //')"
  [[ "$loc" == "$3?"* ]] || { echo "redirect $loc" >&2; return 1; }
  [[ "$loc" == *"state=st_123"* && "$loc" == *"iss="* ]] || { echo "state/iss $loc" >&2; return 1; }
  printf '%s' "$loc" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p'; }
token(){ st -X POST --data "$1" "$H/oauth/token.php"; }

read V C <<<"$(pkce)"
as "$ADMIN"
code="$(st "$H/oauth/authorize.php?$(authq "$CID" "$RED" "$C")")"; test "$code" = 200 || fail "מסך ההסכמה ($code)"
grep -q 'ChatGPT' "$tmp/out" && grep -q 'chatgpt.com' "$tmp/out" && grep -q 'ארגון בדיקה\|חברה קדישא' "$tmp/out" && grep -q 'אין מחיקה' "$tmp/out" || fail "מסך ההסכמה — תוכן"
grep -qi "^X-Frame-Options: DENY" "$tmp/hdr" || fail "מסך ההסכמה בלי הגנת מסגרת"
as ''; code="$(st "$H/oauth/authorize.php?$(authq "$CID" "$RED" "$C")")"; test "$code" = 401 || fail "authorize בלי זהות ($code)"
as "$NOBODY"; code="$(st "$H/oauth/authorize.php?$(authq "$CID" "$RED" "$C")")"; test "$code" = 403 && grep -q 'אין לך ארגון באפליקציה' "$tmp/out" || fail "בלי ארגון ($code)"

# ── K6: redirect_uri לא רשום / בלי PKCE / plain → דף שגיאה בלי הפניה ──
as "$ADMIN"
code="$(st "$H/oauth/authorize.php?$(authq "$CID" "https://evil.example/cb" "$C")")"; test "$code" = 400 && ! grep -qi '^Location:' "$tmp/hdr" || fail "K6 redirect זר ($code)"
code="$(st "$H/oauth/authorize.php?$(authq "$CID" "$RED/x" "$C")")"; test "$code" = 400 || fail "K6 redirect לא מדויק ($code)"
code="$(st "$H/oauth/authorize.php?response_type=code&client_id=$CID&redirect_uri=$(enc "$RED")&state=s")"; test "$code" = 400 && ! grep -qi '^Location:' "$tmp/hdr" || fail "K6 בלי code_challenge ($code)"
code="$(st "$H/oauth/authorize.php?$(authq "$CID" "$RED" "$C" plain)")"; test "$code" = 400 || fail "K6 plain ($code)"
code="$(st "$H/oauth/authorize.php?$(authq fdc_00000000000000000000000000000000 "$RED" "$C")")"; test "$code" = 400 || fail "לקוח לא מוכר ($code)"
code="$(st "$H/oauth/authorize.php?$(authq "$XID" https://x.example/cb "$C")")"; test "$code" = 200 || fail "XSS: מסך ($code)"
grep -q '&lt;script&gt;alert(1)&lt;/script&gt;' "$tmp/out" && ! grep -q '<script>alert' "$tmp/out" || fail "XSS: שם הלקוח לא מוברח"
q="$(authq "$CID" "$RED" "$C")"; st -c "$tmp/jx" -b "$tmp/jx" "$H/oauth/authorize.php?$q" >/dev/null
code="$(st -c "$tmp/jx" -b "$tmp/jx" -X POST --data "$q&csrf=wrong&org=1&decision=approve" "$H/oauth/authorize.php")"; test "$code" = 400 || fail "CSRF שגוי ($code)"
csrf="$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/out")"
st -c "$tmp/jx" -b "$tmp/jx" "$H/oauth/authorize.php?$q" >/dev/null; csrf="$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/out")"
code="$(st -c "$tmp/jx" -b "$tmp/jx" -X POST --data "$q&csrf=$csrf&org=1&decision=deny" "$H/oauth/authorize.php")"
test "$code" = 302 && grep -qi "^Location: $RED?error=access_denied&state=st_123" "$tmp/hdr" || fail "ביטול → access_denied ($code)"

# ── K4: זרימה מלאה (DCR + PKCE S256) → whoami ──
read V C <<<"$(pkce)"
K="$(consent "$ADMIN" "$CID" "$RED" "$C")" || fail "K4 consent"
test "$(sql "SELECT COUNT(*) n FROM events WHERE action='oauth_grant'" | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["n"];')" = 1 || fail "אירוע oauth_grant"
code="$(token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED")&resource=$(enc https://mbe-plus.com/mini_projects/funerals-dashboard/mcp)")"
test "$code" = 200 || fail "K4 token ($code)"
AT="$(j access_token)"; RT="$(j refresh_token)"
[[ "$AT" =~ ^fdo_[a-f0-9]{48}$ && "$RT" =~ ^fdr_[a-f0-9]{48}$ ]] && test "$(j expires_in)" = 3600 && test "$(j token_type)" = Bearer || fail "K4 פורמט אסימונים"
grep -qi '^Cache-Control: no-store' "$tmp/hdr" || fail "token בלי no-store"
code="$(call "$AT" whoami)"; test "$code" = 200 || fail "K4 whoami ($code)"
test "$(j result.isError)" = false && test "$(j result.structuredContent.user.name)" = 'בודק' && test "$(j result.structuredContent.org.id)" = 1 \
  && test "$(j result.structuredContent.role)" = admin || fail "K4 whoami תוכן"
php -r '$v=json_decode(file_get_contents($argv[1]),true)["result"]; exit(json_decode($v["content"][0]["text"],true)==$v["structuredContent"]?0:1);' "$tmp/out" || fail "content ≠ structuredContent"
call "$AT" find '{"q":"כהן"}' >/dev/null; test "$(j result.structuredContent.count)" = 2 || fail "find"
call "$AT" nope >/dev/null; test "$(j error.code)" = -32602 || fail "כלי לא מוכר → -32602"
call "$AT" prompt >/dev/null; test "$(j error.code)" = -32602 || fail "prompt אינו כלי"

# ── K5: invalid_grant ──
code="$(token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED")")"
test "$code" = 400 && test "$(j error)" = invalid_grant || fail "K5 קוד בשימוש שני ($code)"
code="$(call "$AT" whoami)"; test "$code" = 401 || fail "K5 קוד חוזר → ה-grant בוטל ($code)"
read V C <<<"$(pkce)"; K="$(consent "$ADMIN" "$CID" "$RED" "$C")" || fail "consent"
code="$(token "grant_type=authorization_code&code=$K&code_verifier=${V}x&client_id=$CID&redirect_uri=$(enc "$RED")")"; test "$(j error)" = invalid_grant || fail "K5 verifier שגוי ($code)"
read V C <<<"$(pkce)"; K="$(consent "$ADMIN" "$CID" "$RED" "$C")" || fail "consent"
code="$(token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED/other")")"; test "$(j error)" = invalid_grant || fail "K5 redirect שונה ($code)"
read V C <<<"$(pkce)"; K="$(consent "$ADMIN" "$CID" "$RED" "$C")" || fail "consent"
sql "UPDATE oauth_codes SET expires_at=$(( $(date +%s) - 1 )) WHERE used_at IS NULL" >/dev/null
code="$(token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED")")"; test "$(j error)" = invalid_grant || fail "K5 קוד פג ($code)"
code="$(token "grant_type=password&username=a")"; test "$(j error)" = unsupported_grant_type || fail "grant לא נתמך"

# זרימה טרייה לשאר הבדיקות
read V C <<<"$(pkce)"; K="$(consent "$ADMIN" "$CID" "$RED" "$C")" || fail "consent"
token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED")" >/dev/null
AT="$(j access_token)"; RT="$(j refresh_token)"

# ── K8: case_update עם verified → "לאימות"; events: via api, actor "<שם> (ChatGPT)" ──
code="$(call "$AT" case_update '{"id":"fd_case_org1_cohen","checks":{"license_number":{"status":"verified","value":"12345"}},"note":"הערה בדיקה"}')"
test "$code" = 200 && test "$(j result.isError)" = false || fail "K8 case_update ($code)"
test "$(j result.structuredContent.saved.checks.license_number.status)" = document_present || fail "K8 נשמר לאימות"
php -r '$b=json_decode($argv[1],true); $c=$b["checks"]["license_number"]; exit($c["status"]==="document_present" && $c["value"]==="12345" && $c["via"]==="api" && str_contains($b["notes"],"בודק (ChatGPT): הערה בדיקה") ? 0 : 1);' "$(fx body fd_case_org1_cohen)" || fail "K8 התיק"
sql "SELECT source, actor FROM events WHERE case_id='fd_case_org1_cohen' ORDER BY id DESC LIMIT 1" > "$tmp/out"
test "$(j 0.source)" = api && test "$(j 0.actor)" = 'בודק (ChatGPT)' || fail "K8 events"

# ── K9: עובד שטח → תשלום 403; תיק של ארגון אחר → 404 ──
read V C <<<"$(pkce)"; K="$(consent "$FIELD" "$CID" "$RED" "$C")" || fail "consent שטח"
token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED")" >/dev/null; FT="$(j access_token)"; FR="$(j refresh_token)"
call "$FT" case_update '{"id":"fd_case_org1_cohen","fields":{"payments":[{"amount":5}]}}' >/dev/null
test "$(j result.isError)" = true && test "$(j result.structuredContent.status)" = 403 && j result.structuredContent.error | grep -q 'אין לך הרשאה' || fail "K9 תשלום"
call "$AT" case '{"id":"fd_case_org2_levi"}' >/dev/null
test "$(j result.isError)" = true && test "$(j result.structuredContent.status)" = 404 || fail "K9 ארגון אחר"
call "$AT" case_update '{"id":"fd_case_org2_levi","note":"x"}' >/dev/null
test "$(j result.isError)" = true && test "$(j result.structuredContent.status)" = 404 || fail "K9 עדכון בארגון אחר"
call "$AT" case_update '{"id":"fd_case_org1_cohen","bogus":1}' >/dev/null; test "$(j result.isError)" = true || fail "עדכון ריק → isError"
# אחרי שגיאה באמצע כתיבה המסד לא נעול (הטרנזקציה בוטלה)
call "$AT" find '{"q":"כהן"}' >/dev/null; test "$(j result.isError)" = false || fail "אחרי שגיאה"

# ── K10: רענון מחליף; שימוש חוזר בישן → ה-grant מבוטל ──
code="$(token "grant_type=refresh_token&refresh_token=$RT&client_id=$CID")"; test "$code" = 200 || fail "K10 refresh ($code)"
AT2="$(j access_token)"; RT2="$(j refresh_token)"; test "$RT2" != "$RT" || fail "K10 לא הוחלף"
code="$(call "$AT2" whoami)"; test "$code" = 200 || fail "K10 אסימון חדש ($code)"
code="$(token "grant_type=refresh_token&refresh_token=$RT2&client_id=fdc_00000000000000000000000000000000")"; test "$(j error)" = invalid_grant || fail "refresh של לקוח אחר"
code="$(token "grant_type=refresh_token&refresh_token=$RT&client_id=$CID")"; test "$code" = 400 && test "$(j error)" = invalid_grant || fail "K10 שימוש חוזר ($code)"
code="$(call "$AT2" whoami)"; test "$code" = 401 || fail "K10 ה-grant לא בוטל ($code)"
code="$(token "grant_type=refresh_token&refresh_token=$RT2&client_id=$CID")"; test "$(j error)" = invalid_grant || fail "K10 רענון חדש אחרי ביטול"

# ── K11: השבתה → 401 מיד; "ניתוק" במסך 🤖 → 401 מיד ──
code="$(call "$FT" whoami)"; test "$code" = 200 || fail "K11 לפני השבתה ($code)"
fx deactivate 9
code="$(call "$FT" whoami)"; test "$code" = 401 || fail "K11 משתמש שהושבת ($code)"
code="$(token "grant_type=refresh_token&refresh_token=$FR&client_id=$CID")"; test "$(j error)" = invalid_grant || fail "רענון אחרי השבתה ($code)"
sql "UPDATE users SET active=1 WHERE sub='9'" >/dev/null
code="$(token "grant_type=refresh_token&refresh_token=$FR&client_id=$CID")"; test "$(j error)" = invalid_grant || fail "הפעלה מחדש החייתה רענון ישן ($code)"
code="$(call "$FT" whoami)"; test "$code" = 401 || fail "הפעלה מחדש החייתה אסימון גישה ($code)"
# השבתה דרך api.php (member_active / user_active) — revokeOauthGrantsOf
read V C <<<"$(pkce)"; K="$(consent "$FIELD" "$CID" "$RED" "$C")" || fail "consent שטח 2"
token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED")" >/dev/null; FR2="$(j refresh_token)"
php -r 'chdir($argv[1]); require "lib.php"; $p=db(); revokeOauthGrantsOf($p, 9, 2, 7); if ((int)$p->query("SELECT COUNT(*) FROM oauth_grants WHERE user_id=9 AND revoked_at IS NULL")->fetchColumn()!==1) exit(1);
  revokeOauthGrantsOf($p, 9, 1, 7); if ((int)$p->query("SELECT COUNT(*) FROM oauth_grants WHERE user_id=9 AND revoked_at IS NULL")->fetchColumn()!==0) exit(2);' "$D" || fail "revokeOauthGrantsOf"
code="$(token "grant_type=refresh_token&refresh_token=$FR2&client_id=$CID")"; test "$(j error)" = invalid_grant || fail "השבתה בארגון לא ביטלה את החיבור ($code)"
read V C <<<"$(pkce)"; K="$(consent "$ADMIN" "$CID" "$RED" "$C")" || fail "consent"
token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED")" >/dev/null; AT3="$(j access_token)"; RT3="$(j refresh_token)"
call "$AT3" whoami >/dev/null
as "$ADMIN"; st -c "$tmp/ct" -b "$tmp/ct" "$H/tokens.php?action=me" >/dev/null
test "$(j mcp_url)" = "https://mbe-plus.com/mini_projects/funerals-dashboard/mcp" || fail "מסך 🤖: mcp_url"
gid="$(php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach($v["connections"] as $g) if($g["client_name"]==="ChatGPT" && $g["last_used_at"]) { echo $g["id"]; exit; }' "$tmp/out")"
[ -n "$gid" ] || fail "מסך 🤖: חיבורים פעילים"
! grep -q 'fdo_\|fdr_\|client_id' "$tmp/out" || fail "מסך 🤖 חושף אסימון או client_id"
csrf="$(j csrf)"
code="$(st -b "$tmp/ct" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d "{\"id\":$gid}" "$H/tokens.php?action=disconnect")"; test "$code" = 200 || fail "ניתוק ($code)"
code="$(call "$AT3" whoami)"; test "$code" = 401 || fail "K11 אחרי ניתוק ($code)"
# עובד שטח לא מנתק חיבור של אחר
read V C <<<"$(pkce)"; K="$(consent "$ADMIN" "$CID" "$RED" "$C")" || fail "consent"
token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED")" >/dev/null; AT4="$(j access_token)"; RT4="$(j refresh_token)"
g4="$(sql "SELECT MAX(id) id FROM oauth_grants" | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["id"];')"
as "$FIELD"; st -c "$tmp/cf" -b "$tmp/cf" "$H/tokens.php?action=me" >/dev/null; csrf="$(j csrf)"
code="$(st -b "$tmp/cf" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d "{\"id\":$g4}" "$H/tokens.php?action=disconnect")"; test "$code" = 404 || fail "שטח מנתק חיבור של אחר ($code)"
code="$(call "$AT4" whoami)"; test "$code" = 200 || fail "החיבור נותק בלי הרשאה ($code)"
# revoke (RFC 7009): רענון → כל החיבור
code="$(st -X POST --data "token=$RT4&client_id=$CID" "$H/oauth/revoke.php")"; test "$code" = 200 || fail "revoke ($code)"
code="$(call "$AT4" whoami)"; test "$code" = 401 || fail "revoke לא ביטל ($code)"
code="$(st -X POST --data "token=nothing" "$H/oauth/revoke.php")"; test "$code" = 200 || fail "revoke אסימון לא מוכר ($code)"

# ── K12: fdx_ עובד ב-mcp.php; fdo_ לא ב-ext.php ──
as "$ADMIN"; st -c "$tmp/ca" -b "$tmp/ca" "$H/tokens.php?action=me" >/dev/null; csrf="$(j csrf)"
st -b "$tmp/ca" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d '{"name":"Inspector","days":30}' "$H/tokens.php?action=create" >/dev/null; X="$(j token)"
code="$(call "$X" whoami)"; test "$code" = 200 && test "$(j result.structuredContent.role)" = admin || fail "K12 fdx_ ב-MCP ($code)"
call "$X" case_update '{"id":"fd_case_org1_cohen2","note":"דרך fdx"}' >/dev/null
sql "SELECT actor FROM events WHERE case_id='fd_case_org1_cohen2' ORDER BY id DESC LIMIT 1" > "$tmp/out"; test "$(j 0.actor)" = "בודק (צ'אט)" || fail "K12 fdx_ — שם ביומן"
read V C <<<"$(pkce)"; K="$(consent "$ADMIN" "$CID" "$RED" "$C")" || fail "consent"
token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$CID&redirect_uri=$(enc "$RED")" >/dev/null; AT5="$(j access_token)"; RT5="$(j refresh_token)"
code="$(st -H "Authorization: Bearer $AT5" "$H/ext.php/whoami")"; test "$code" = 401 || fail "K12 fdo_ ב-ext.php ($code)"

# ── K13: Origin זר → 403; מוכר → עובר ──
code="$(rpc '{"jsonrpc":"2.0","id":1,"method":"ping"}' -H 'Origin: https://evil.example')"; test "$code" = 403 || fail "K13 Origin זר ($code)"
code="$(rpc '{"jsonrpc":"2.0","id":1,"method":"ping"}' -H 'Origin: https://chatgpt.com')"; test "$code" = 200 || fail "K13 chatgpt.com ($code)"
code="$(rpc '{"jsonrpc":"2.0","id":1,"method":"ping"}' -H 'Origin: https://claude.ai')"; test "$code" = 200 || fail "K13 claude.ai ($code)"

# ── K7: CIMD ──
: > "$cimd/fetch.log"
as "$ADMIN"
read V C <<<"$(pkce)"
code="$(st "$H/oauth/authorize.php?$(authq "$CIMD_ID" https://claude.ai/api/mcp/auth_callback "$C")")"; test "$code" = 200 && grep -q '<bdi>Claude</bdi>' "$tmp/out" || fail "K7 CIMD תקין ($code)"
test "$(wc -l < "$cimd/fetch.log")" = 1 || fail "K7 CIMD לא הורד"
code="$(st "$H/oauth/authorize.php?$(authq "$CIMD_ID" https://claude.ai/api/mcp/auth_callback "$C")")"; test "$code" = 200 && test "$(wc -l < "$cimd/fetch.log")" = 1 || fail "K7 מטמון"
code="$(st "$H/oauth/authorize.php?$(authq "$CIMD_ID" https://evil.example/cb "$C")")"; test "$code" = 400 || fail "K7 redirect שלא במסמך ($code)"
K="$(consent "$ADMIN" "$CIMD_ID" https://claude.ai/api/mcp/auth_callback "$C")" || fail "K7 consent"
token "grant_type=authorization_code&code=$K&code_verifier=$V&client_id=$(enc "$CIMD_ID")&redirect_uri=$(enc https://claude.ai/api/mcp/auth_callback)" >/dev/null; CT="$(j access_token)"
call "$CT" whoami >/dev/null; test "$(j result.structuredContent.user.name)" = 'בודק' || fail "K7 CIMD → whoami"
: > "$cimd/fetch.log"
for bad in 'https://[::ffff:127.0.0.1]/c.json' 'https://[::ffff:7f00:1]/c.json' 'https://100.64.0.1/c.json' 'https://mapped.example.com/c.json' 'https://cgnat.example.com/c.json' 'https://nat64.example.com/c.json' 'https://mixed.example.com/c.json' 'http://client.example.com/c.json' 'https://127.0.0.1/c.json' 'https://10.0.0.5/c.json' 'https://localhost/c.json' 'https://intranet.example.com/c.json' 'https://[::1]/c.json' 'https://client.example.com:8443/c.json' 'https://client.example.com/'; do
  code="$(st "$H/oauth/authorize.php?$(authq "$bad" https://claude.ai/api/mcp/auth_callback "$C")")"; test "$code" = 400 || fail "K7 נדחה: $bad ($code)"
done
test ! -s "$cimd/fetch.log" || fail "K7 בקשה יוצאת לכתובת שנדחתה: $(cat "$cimd/fetch.log")"
CIMD2='https://client.example.com/wrong.json'
php -r 'file_put_contents($argv[1]."/".sha1($argv[2]).".json", json_encode(["client_id"=>"https://other.example/x","client_name"=>"X","redirect_uris"=>["https://claude.ai/cb"]]));' "$cimd" "$CIMD2"
code="$(st "$H/oauth/authorize.php?$(authq "$CIMD2" https://claude.ai/cb "$C")")"; test "$code" = 400 || fail "K7 client_id לא תואם ($code)"

# ── 60 לדקה לחיבור ──
sql "UPDATE oauth_grants SET rl_min=$(( $(date +%s) / 60 )), rl_n=60 WHERE revoked_at IS NULL" >/dev/null
call "$AT5" whoami >/dev/null; test "$(j result.isError)" = true && j result.structuredContent.error | grep -q 'יותר מדי בקשות' || fail "הגבלת קצב"

# אסימון גישה שפג → 401
sql "UPDATE oauth_tokens SET expires_at=$(( $(date +%s) - 1 )) WHERE kind='access'" >/dev/null
code="$(call "$AT5" whoami)"; test "$code" = 401 || fail "אסימון שפג ($code)"

# ── K14: שום אסימון ביומן הבקשות, ב-events או בתשובת שגיאה ──
sql "SELECT action FROM req_log WHERE action LIKE 'mcp:tools/call:%' LIMIT 1" > "$tmp/out"; test "$(j 0.action)" = 'mcp:tools/call:whoami' || fail "req_log mcp:<method>:<tool>"
sql "SELECT * FROM req_log" > "$tmp/log1"; sql "SELECT * FROM events" > "$tmp/log2"
cat "$tmp/server.log" >> "$tmp/log1"
for t in "$AT" "$RT" "$AT2" "$RT2" "$FT" "$AT3" "$AT4" "$RT4" "$AT5" "$RT5" "$X" "$CT" "$K"; do
  ! grep -qF "$t" "$tmp/log1" "$tmp/log2" || fail "K14 אסימון ביומן"
done
! grep -q 'fd[oxrk]_[a-f0-9]\{20\}' "$tmp/log1" "$tmp/log2" || fail "K14 אסימון ביומן (תבנית)"
code="$(token "grant_type=refresh_token&refresh_token=$RT&client_id=$CID")"; ! grep -qF "$RT" "$tmp/out" || fail "K14 אסימון בתשובת שגיאה"
code="$(call "$AT" whoami)"; ! grep -qF "$AT" "$tmp/out" || fail "K14 אסימון בתשובת 401"
sql "SELECT hash FROM oauth_tokens" | grep -qF "$AT5" && fail "K14 אסימון גולמי במסד"
sql "SELECT hash FROM oauth_codes" | grep -qF "$K" && fail "K14 קוד גולמי במסד"
# 429 ב-DCR: עד 20 רישומים לשעה לכתובת
n=0; for i in $(seq 1 25); do code="$(reg '{"redirect_uris":["https://x.example/cb"]}')"; [ "$code" = 429 ] && break; n=$((n+1)); done
test "$code" = 429 && test "$n" -le 20 && grep -qi '^Retry-After:' "$tmp/hdr" || fail "429 ב-DCR ($code אחרי $n)"
! grep -E 'PHP (Fatal|Warning|Notice|Deprecated|Parse)' "$tmp/server.log" || fail "שגיאות PHP בשרת"
echo "09 MCP + OAuth: K1–K15 עברו"
