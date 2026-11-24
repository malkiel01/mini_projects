#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp="$(mktemp -d)"
pid=0
cleanup(){ if [ "$pid" != 0 ]; then kill "$pid" >/dev/null 2>&1 || true; fi; rm -f funerals-dashboard/data/config.php funerals-dashboard/data/funerals.sqlite*; rm -rf "$tmp"; }
trap cleanup EXIT
php -l funerals-dashboard/api.php
php -l funerals-dashboard/setup.php
php -r '$h=file_get_contents("funerals-dashboard/index.html"); if (!preg_match("~<script>\s*(.*?)\s*</script>~s", $h, $m)) exit(1); file_put_contents("/tmp/fd-dashboard-ui-check.js",$m[1]);'
node --check /tmp/fd-dashboard-ui-check.js
php -r '$c=["username"=>"admin","password_hash"=>password_hash("local-test-pass-1234",PASSWORD_DEFAULT),"api_token_sha256"=>hash("sha256","local-test-token"),"dropbox_app_key"=>""]; file_put_contents("funerals-dashboard/data/config.php","<?php\nreturn ".var_export($c,true).";\n");'
php -S 127.0.0.1:18964 -t funerals-dashboard > "$tmp/server.log" 2>&1 &
pid=$!
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18964/index.html >/dev/null && break || sleep .2; done
base="http://127.0.0.1:18964/api.php"
code="$(curl -sS -o "$tmp/unauth" -w '%{http_code}' "$base?action=list&month=2026-10")"
test "$code" = 401 || { echo "קריאה ללא התחברות לא נחסמה"; exit 1; }
login="$(curl -sS -c "$tmp/cookies" -H 'Content-Type: application/json' -d '{"username":"admin","password":"local-test-pass-1234"}' "$base?action=login")"
csrf="$(printf '%s' "$login" | php -r '$v=json_decode(stream_get_contents(STDIN),true); echo $v["csrf"] ?? "";')"
test -n "$csrf" || { echo "כניסה נכשלה"; exit 1; }
code="$(curl -sS -b "$tmp/cookies" -o "$tmp/list.json" -w '%{http_code}' "$base?action=list&month=2026-10")"
test "$code" = 200 || { echo "קריאה מורשית נכשלה"; exit 1; }
curl -sS -H 'Content-Type: application/json' -H 'Authorization: Bearer local-test-token' \
 --data '{"records":[{"month":"2026-10","name":"שם בדיקה בלבד","date":"2026-10-01","checks":{"license_number":{"status":"unreadable","value":"","note":"דורש אימות"}}}]}' \
 "$base?action=import" > "$tmp/import.json"
php -r '$v=json_decode(file_get_contents($argv[1]),true); if (($v["imported"]??0)!==1) exit(1);' "$tmp/import.json"
curl -sS -b "$tmp/cookies" "$base?action=list&month=2026-10" > "$tmp/list.json"
php -r '$v=json_decode(file_get_contents($argv[1]),true); if (count($v["records"]??[])!==1) exit(1); $r=$v["records"][0]; $r["checks"]["license_number"]=["status"=>"reported","value"=>"123","note"=>"עדכון משתמש"]; file_put_contents($argv[2],json_encode($r,JSON_UNESCAPED_UNICODE));' "$tmp/list.json" "$tmp/update.json"
code="$(curl -sS -b "$tmp/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' --data-binary @"$tmp/update.json" -o "$tmp/saved.json" -w '%{http_code}' "$base?action=save")"
test "$code" = 200 || { echo "שמירה ידנית נכשלה"; cat "$tmp/saved.json"; exit 1; }
curl -sS -H 'Content-Type: application/json' -H 'Authorization: Bearer local-test-token' \
 --data '{"records":[{"month":"2026-10","name":"שם בדיקה בלבד","date":"2026-10-01","checks":{"license_number":{"status":"verified","value":"999"}}}]}' \
 "$base?action=import" >/dev/null
curl -sS -b "$tmp/cookies" "$base?action=export&month=2026-10" > "$tmp/export.json"
php -r '$v=json_decode(file_get_contents($argv[1]),true); $r=$v["records"][0]??[]; if(($r["checks"]["license_number"]["value"]??"")!=="123") exit(1);' "$tmp/export.json"
echo "PASS: הרשאות, קליטה, ייצוא, עדכון ידני וייבוא חוזר"
