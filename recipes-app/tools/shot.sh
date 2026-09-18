#!/usr/bin/env bash
# צילום מסך של האפליקציה ברוחב טלפון אמיתי.
# הרצה: bash recipes-app/tools/shot.sh [שם-קובץ-יעד]
#
# למה iframe ולא --window-size: בבנייה של Chromium שמותקנת כאן הדגל
# --window-size קובע את גודל קנבס הצילום אבל **לא** את ה-viewport —
# הדף תמיד נרנדר ב-500px, והצילום רק חותך אותו. זה נראה בדיוק כמו
# גלישה לרוחב, ושלח אותי לחפש באג שלא היה. iframe ברוחב 390 נותן
# למסמך הפנימי viewport אמיתי, וגם מדיה-קוורי מתנהג נכון בתוכו.

set -uo pipefail
cd "$(dirname "$0")/../.."

OUT=${1:-/tmp/recipes-phone.png}
PORT=${PORT:-8798}
CH=${CH:-/opt/pw-browsers/chromium-1194/chrome-linux/chrome}
TMP=$(mktemp -d)
trap 'kill ${SRV:-0} 2>/dev/null; rm -f recipes-app/_shot.html; rm -rf "$TMP"' EXIT

cat > "$TMP/boot.php" <<PHPBOOT
<?php
define('DB_FILE', '$TMP/t.sqlite');
define('MEDIA_DIR', '$TMP/media');
PHPBOOT

cat > recipes-app/_shot.html <<'HTML'
<!DOCTYPE html><html><head><meta charset="utf-8"><style>
html,body{margin:0;background:#000}
iframe{border:0;width:390px;height:844px;display:block}
</style></head><body><iframe id="f" src="./index.html"></iframe>
<script>
window.addEventListener('load', () => setTimeout(() => {
  const d = f.contentDocument.documentElement, over = [];
  f.contentDocument.querySelectorAll('body *').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.right > d.clientWidth + 1 || r.left < -1) over.push(el.tagName + '/' + (el.id || el.className));
  });
  document.title = 'CLIENT=' + d.clientWidth + ' SCROLL=' + d.scrollWidth +
                   ' OVER=' + (over.slice(0,5).join(',') || 'none');
}, 500));
</script></body></html>
HTML

php -S "127.0.0.1:$PORT" -t . -d auto_prepend_file="$TMP/boot.php" -d sendmail_path=/bin/true \
  >"$TMP/s.log" 2>&1 &
SRV=$!
for _ in $(seq 1 40); do
  curl -fsS "http://127.0.0.1:$PORT/recipes-app/api.php?action=me" >/dev/null 2>&1 && break
  sleep 0.25
done

URL="http://127.0.0.1:$PORT/recipes-app/_shot.html"
# המדידה קודמת לצילום: גלישה לרוחב היא תקלה שצריך לראות כמספר, לא לנחש מתמונה.
"$CH" --headless=new --no-sandbox --disable-gpu --virtual-time-budget=5000 --dump-dom "$URL" \
  2>/dev/null | grep -o '<title>[^<]*</title>'
"$CH" --headless=new --no-sandbox --disable-gpu --hide-scrollbars \
  --window-size=390,844 --force-device-scale-factor=2 --virtual-time-budget=5000 \
  --screenshot="$OUT" "$URL" >/dev/null 2>&1

echo "נשמר: $OUT"
