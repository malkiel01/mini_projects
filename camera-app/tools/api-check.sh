#!/usr/bin/env bash
# בדיקה מקצה לקצה על שרת PHP אמיתי, עם תיקיית נתונים זמנית.
# עובר את המסלול: התקנה → כניסה → מצלמה → קובץ "מה-FTP" נקלט → גלריה →
# צימוד גשר → heartbeat → פקודה → העלאת הקלטה מהגשר → מקטע חי → Range.
set -euo pipefail
cd "$(dirname "$0")/.."
TMP=$(mktemp -d)
PORT=${PORT:-8765}
trap 'kill $PID 2>/dev/null || true; rm -rf "$TMP"' EXIT

# הספריות קוראות DB_FILE/MEDIA_DIR/INBOX_DIR/LIVE_DIR/SECRET_FILE אם הוגדרו לפני
# הטעינה. prepend מגדיר אותן לתיקייה הזמנית.
cat > "$TMP/prepend.php" <<PHP
<?php
define('DB_FILE',     '$TMP/db.sqlite');
define('MEDIA_DIR',   '$TMP/media');
define('INBOX_DIR',   '$TMP/inbox');
define('LIVE_DIR',    '$TMP/live');
define('SECRET_FILE', '$TMP/secret.key');
PHP
mkdir -p "$TMP/media" "$TMP/inbox" "$TMP/live"
php -S 127.0.0.1:$PORT -d auto_prepend_file="$TMP/prepend.php" -d upload_max_filesize=64M -d post_max_size=64M -t . >"$TMP/server.log" 2>&1 &
PID=$!
for i in $(seq 1 30); do curl -s "http://127.0.0.1:$PORT/api.php?action=me" >/dev/null && break; sleep 0.2; done

J="$TMP/cookies"
api() { curl -s -b "$J" -c "$J" -H 'Content-Type: application/json' -d "$2" "http://127.0.0.1:$PORT/api.php?action=$1"; }
need() { # need <json> <jq-expr> <desc>
  if ! echo "$1" | python3 -c "import sys,json; d=json.load(sys.stdin); assert ($2), d" 2>/dev/null; then
    echo "✗ $3"; echo "   ← $1"; exit 1; fi; echo "✓ $3"; }

R=$(api me '{}');                                       need "$R" "d['needs_setup']==True" "לפני התקנה: needs_setup"
R=$(api login '{"username":"x","password":"y"}');      need "$R" "d['success']==False" "כניסה לפני התקנה נכשלת"
R=$(api setup '{"username":"admin","password":"secret123","display_name":"מנהל"}'); need "$R" "d['user']['role']=='admin'" "התקנה יוצרת מנהל ומחברת"
R=$(api setup '{"username":"b","password":"secret123"}'); need "$R" "d['success']==False" "התקנה שנייה נדחית"
R=$(api me '{}');                                       need "$R" "d['user']['username']=='admin'" "me אחרי כניסה"

R=$(api folder-save '{"name":"בית"}');                  need "$R" "d['id']==1" "תיקיית מצלמות"
R=$(api camera-save '{"name":"חצר","folder_id":1,"brand":"reolink","host":"192.168.1.118","password":"pw123","has_ptz":1,"record_mode":"motion"}')
need "$R" "d['camera']['key']=='cam-1' and d['camera']['has_password']==1 and 'password_enc' not in d['camera']" "מצלמה נוצרת, מפתח אוטומטי, סיסמה לא חוזרת"
CAM=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['camera']['id'])")
R=$(api camera-save "{\"id\":$CAM,\"name\":\"חצר אחורית\",\"host\":\"192.168.1.118\",\"has_ptz\":1}"); need "$R" "d['camera']['name']=='חצר אחורית' and d['camera']['has_password']==1" "עדכון בלי סיסמה שומר את הישנה"
R=$(api record "{\"id\":$CAM,\"on\":1}");               need "$R" "d['success']==False and d.get('error','').find('גשר')>=0" "REC בלי גשר: הודעה ברורה"

# קובץ שהמצלמה "העלתה" ב-FTP — שם בסגנון Reolink, ותאריך מהשם.
mkdir -p "$TMP/inbox/cam-1/2026-09-20"
python3 - "$TMP/inbox/cam-1/2026-09-20/Camera1_00_20260920143000_MD.jpg" <<'PY'
import sys, struct, zlib
# JPEG זעיר תקין (1x1) — finfo צריך להכיר בו
import base64
data=base64.b64decode("/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==")
open(sys.argv[1],'wb').write(data)
PY
touch -d '2 minutes ago' "$TMP/inbox/cam-1/2026-09-20/Camera1_00_20260920143000_MD.jpg"
R=$(api maintenance '{}');                              need "$R" "d['result']['inbox']['ingested']==1" "סריקת FTP קולטת קובץ שהתיישב"
R=$(api recordings "{\"camera_id\":$CAM}");            need "$R" "len(d['recordings'])==1 and d['recordings'][0]['trigger_kind']=='motion' and d['recordings'][0]['started_at'].startswith('2026-09-20T1')" "בקטלוג: טריגר תנועה, זמן מהשם"
REC=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['recordings'][0]['id'])")
[ -z "$(ls -A "$TMP/inbox/cam-1" 2>/dev/null)" ] && echo "✓ תיבת ה-FTP התרוקנה (תת-תיקיית התאריך נמחקה)" || { echo "✗ נשאר משהו בתיבה"; ls -R "$TMP/inbox"; exit 1; }

R=$(api topic-save '{"name":"שיפוץ"}');                need "$R" "d['id']==1" "תיקיית נושא"
R=$(api recording-update "{\"id\":$REC,\"title\":\"חתול\",\"tags\":\"לילה, חתול\",\"topics\":[1],\"starred\":1}")
need "$R" "d['recording']['title']=='חתול' and d['recording']['tags']==['לילה','חתול'] and d['recording']['topics']==[1] and d['recording']['starred']==1" "עריכת הקלטה: כותרת, תגיות, נושא, כוכב"
R=$(api recordings '{"q":"חת"}');                       need "$R" "len(d['recordings'])==1" "חיפוש חופשי"
R=$(api recordings '{"topic_id":1}');                   need "$R" "len(d['recordings'])==1" "סינון לפי נושא"
R=$(api bookmark-add "{\"recording_id\":$REC,\"at\":3.5,\"note\":\"כאן\"}"); need "$R" "d['id']==1" "סימנייה"
R=$(api recording "{\"id\":$REC}");                     need "$R" "d['bookmarks'][0]['at_seconds']==3.5" "ההקלטה חוזרת עם הסימניות"

# מדיה: מוגשת, עם Range, ורק למחובר.
code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/media.php?r=$REC")
[ "$code" = "401" ] && echo "✓ מדיה בלי סשן: 401" || { echo "✗ מדיה בלי סשן החזירה $code"; exit 1; }
code=$(curl -s -b "$J" -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/media.php?r=$REC")
[ "$code" = "200" ] && echo "✓ מדיה עם סשן: 200" || { echo "✗ מדיה עם סשן החזירה $code"; exit 1; }
code=$(curl -s -b "$J" -H 'Range: bytes=0-9' -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/media.php?r=$REC")
[ "$code" = "206" ] && echo "✓ Range: 206" || { echo "✗ Range החזיר $code"; exit 1; }

# צילום מסך מהנגן (multipart, בסשן).
R=$(curl -s -b "$J" -F kind=screenshot -F recording_id=$REC -F at=2 -F "file=@$TMP/media/$(cd $TMP/media && find . -name '*_s_*.jpg' | head -1 | sed 's|^\./||');type=image/jpeg" "http://127.0.0.1:$PORT/upload.php")
need "$R" "d['recording']['kind']=='snapshot' and d['recording']['parent_id']==$REC and d['recording']['source']=='player'" "צילום מסך מהנגן נרשם עם parent"

# גשר: יצירה → צימוד בקוד → heartbeat מחזיר תצורה עם סיסמה → פקודה → סיום.
R=$(api bridge-create '{"name":"בית"}');                need "$R" "len(d['pair_code'])==6" "יצירת גשר עם קוד"
CODE=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['pair_code'])")
BID=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")
R=$(curl -s -H 'Content-Type: application/json' -d "{\"code\":\"$CODE\",\"info\":{\"version\":\"0.1\",\"local_ip\":\"192.168.1.50\"}}" "http://127.0.0.1:$PORT/api.php?action=bridge-pair")
need "$R" "len(d['token'])==64" "צימוד מחזיר טוקן"
TOK=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")
R=$(curl -s -H 'Content-Type: application/json' -d "{\"code\":\"$CODE\"}" "http://127.0.0.1:$PORT/api.php?action=bridge-pair"); need "$R" "d['success']==False" "הקוד נשרף אחרי שימוש"
R=$(api camera-save "{\"id\":$CAM,\"name\":\"חצר אחורית\",\"host\":\"192.168.1.118\",\"has_ptz\":1,\"bridge_id\":$BID}"); need "$R" "d['camera']['bridge_id']==$BID" "שיוך מצלמה לגשר"
R=$(api command "{\"id\":$CAM,\"type\":\"ptz\",\"payload\":{\"dir\":\"left\"}}"); need "$R" "d['command_id']==1" "פקודת PTZ נכנסת לתור"
R=$(api record "{\"id\":$CAM,\"on\":1}");               need "$R" "d['camera']['manual_recording']==1" "REC עם גשר"
HB=$(curl -s -H "X-Bridge-Token: $TOK" -H 'Content-Type: application/json' -d "{\"version\":\"0.1\",\"cameras\":{\"cam-1\":{\"online\":true}}}" "http://127.0.0.1:$PORT/api.php?action=bridge-heartbeat")
need "$HB" "d['cameras'][0]['password']=='pw123' and d['cameras'][0]['stream_main'].startswith('rtsp://admin:pw123@192.168.1.118:554/h264Preview_01_main') and d['cameras'][0]['manual_recording']==1 and d['commands'][0]['type']=='ptz'" "heartbeat: תצורה עם סיסמה, RTSP, REC ופקודה"
R=$(curl -s -H 'Content-Type: application/json' -d '{}' "http://127.0.0.1:$PORT/api.php?action=bridge-heartbeat"); need "$R" "d['success']==False" "heartbeat בלי טוקן נדחה"
R=$(curl -s -H "X-Bridge-Token: $TOK" -H 'Content-Type: application/json' -d '{"id":1,"ok":true,"result":{"moved":"left"}}' "http://127.0.0.1:$PORT/api.php?action=bridge-command-done"); need "$R" "d['success']" "הגשר מדווח סיום"
R=$(api command-status '{"id":1}');                      need "$R" "d['command']['status']=='done' and d['command']['result']['moved']=='left'" "הדפדפן רואה את התוצאה"
R=$(api camera "{\"id\":$CAM}");                        need "$R" "d['camera']['status']=='online'" "המצלמה online לפי הגשר"

# העלאת צילום מהגשר (multipart עם טוקן).
cp "$TMP/media/cam-1/2026/09/20/"*_s_*.jpg "$TMP/snap.jpg" 2>/dev/null || cp $(find "$TMP/media" -name '*.jpg' | head -1) "$TMP/snap.jpg"
R=$(curl -s -H "X-Bridge-Token: $TOK" -F kind=recording -F camera=cam-1 -F trigger=person -F started_at=2026-09-21T10:00:00Z -F "file=@$TMP/snap.jpg;type=image/jpeg" "http://127.0.0.1:$PORT/upload.php")
need "$R" "d['success'] and d['id']>0" "הגשר מעלה צילום"
R=$(api recordings '{"trigger":"person"}');            need "$R" "len(d['recordings'])==1 and d['recordings'][0]['source']=='bridge'" "הצילום בקטלוג עם טריגר אדם"

# חי: הדפדפן מבקש → live_wanted; הגשר מעלה פלייליסט → available.
R=$(api live "{\"id\":$CAM}");                          need "$R" "d['live']['available']==False and d['live']['bridge_online']==True" "לפני מקטעים: לא זמין, גשר מחובר"
printf '#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:2\n#EXTINF:2.0,\nseg1.ts\n' > "$TMP/live.m3u8"
head -c 1000 /dev/urandom > "$TMP/seg1.ts"
R=$(curl -s -H "X-Bridge-Token: $TOK" -F kind=live -F camera=cam-1 -F 'name[]=live.m3u8' -F 'name[]=seg1.ts' -F "file[]=@$TMP/live.m3u8" -F "file[]=@$TMP/seg1.ts" "http://127.0.0.1:$PORT/upload.php")
need "$R" "d['success'] and d['live_wanted']==True" "העלאת מקטעי חי; הגשר יודע שמישהו צופה"
R=$(api live "{\"id\":$CAM}");                          need "$R" "d['live']['available']==True" "אחרי מקטעים: זמין"
code=$(curl -s -b "$J" -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/media.php?live=cam-1&f=seg1.ts")
[ "$code" = "200" ] && echo "✓ מקטע חי מוגש" || { echo "✗ מקטע חי החזיר $code"; exit 1; }
code=$(curl -s -b "$J" -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/media.php?live=cam-1&f=../../secret.key")
[ "$code" = "400" ] && echo "✓ נתיב זדוני בחי נחסם" || { echo "✗ נתיב זדוני החזיר $code"; exit 1; }

# משתמשים והרשאות.
R=$(api user-create '{"username":"viewer1","password":"secret123","role":"viewer"}'); need "$R" "d['id']==2" "יצירת צופה"
J2="$TMP/cookies2"
R=$(curl -s -c "$J2" -H 'Content-Type: application/json' -d '{"username":"viewer1","password":"secret123"}' "http://127.0.0.1:$PORT/api.php?action=login"); need "$R" "d['user']['role']=='viewer'" "כניסת צופה"
R=$(curl -s -b "$J2" -H 'Content-Type: application/json' -d "{\"id\":$CAM,\"on\":0}" "http://127.0.0.1:$PORT/api.php?action=record"); need "$R" "d['success']==False" "צופה לא יכול להקליט"
R=$(curl -s -b "$J2" -H 'Content-Type: application/json' -d '{}' "http://127.0.0.1:$PORT/api.php?action=overview"); need "$R" "len(d['cameras'])==1 and d['bridges']==[]" "צופה רואה מצלמות, לא גשרים"

# מחיקה: נעולה נחסמת; מחיקת מצלמה מוחקת קבצים.
R=$(api recording-update "{\"id\":$REC,\"locked\":1}"); need "$R" "d['recording']['locked']==1" "נעילה"
R=$(api recording-delete "{\"id\":$REC}");             need "$R" "d['success']==False" "נעולה לא נמחקת"
R=$(api events '{}');                                   need "$R" "len(d['events'])>5" "יומן אירועים מתמלא"
R=$(api camera-delete "{\"id\":$CAM}");                need "$R" "d['success']" "מחיקת מצלמה"
[ "$(find "$TMP/media" -type f | wc -l)" = "0" ] && echo "✓ הקבצים נמחקו עם המצלמה" || { echo "✗ נשארו קבצים"; find "$TMP/media" -type f; exit 1; }

grep -i "warning\|fatal\|error" "$TMP/server.log" | grep -v "Accepted\|Closing\|\[200\]\|\[206\]\|\[401\]\|\[400\]\|\[404\]\|\[403\]\|\[409\]" && { echo "✗ אזהרות ב-server.log"; exit 1; } || echo "✓ בלי אזהרות PHP"
echo "הכול עבר."
