#!/usr/bin/env bash
# צילום מסך של האפליקציה ברוחב טלפון אמיתי.
# הרצה: bash recipes-app/tools/shot.sh <קובץ-יעד> [hash]
#   bash recipes-app/tools/shot.sh out.png            # מסך אורח
#   bash recipes-app/tools/shot.sh out.png '#/'       # מחובר: הרשימה
#   bash recipes-app/tools/shot.sh out.png '#/r/1'    # מתכון הבדיקה
#   bash recipes-app/tools/shot.sh out.png '#/edit/1' # העורך
#   bash recipes-app/tools/shot.sh out.png '#/diag'   # אבחון (המנהל)
#
# כשניתן hash, הדף נזרע במתכון בדיקה ונכנסים כמנהל לפני הצילום —
# על מסד זמני, בלי לגעת בנתונים האמיתיים.
#
# למה iframe ולא --window-size: בבנייה של Chromium שמותקנת כאן הדגל
# --window-size קובע את גודל קנבס הצילום אבל **לא** את ה-viewport —
# הדף תמיד נרנדר ב-500px, והצילום רק חותך אותו. זה נראה בדיוק כמו
# גלישה לרוחב, ושלח אותי לחפש באג שלא היה. iframe ברוחב 390 נותן
# למסמך הפנימי viewport אמיתי, וגם מדיה-קוורי מתנהג נכון בתוכו.

set -uo pipefail
cd "$(dirname "$0")/../.."

OUT=${1:-/tmp/recipes-phone.png}
HASH=${2:-}
SCROLL_TO=${3:-}   # בורר CSS. הדף נגלל אליו לפני הצילום — למה שמתחת לקיפול
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
</style></head><body><iframe id="f"></iframe>
<script>
const api = (a, p) => fetch('./api.php?action=' + a, { method: 'POST', credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(p || {}) }).then(r => r.json());
const qs = new URLSearchParams(location.search);
const hash = qs.get('hash') || '';
const scrollTo = qs.get('scroll') || '';
(async () => {
  if (hash) {
    await api('register', { username: 'owner', email: 'o@example.com', password: 'sod12345', display_name: 'מלכיאל' });
    await api('login', { username: 'owner', password: 'sod12345' });
    await api('recipe-save', { title: 'עוגת גבינה של סבתא', visibility: 'public', servings: 8, difficulty: 'medium',
      work_minutes: 25, wait_minutes: 70, tips: 'לא לפתוח את התנור בחצי השעה הראשונה.', tag_ids: [1, 12, 18],
      sections: [
        { name: 'בצק פירורים', ingredients: [
          { free_text: '2 כוסות קמח', amount_min: 300, unit: 'gram', product: 'קמח' },
          { free_text: '100 גרם חמאה', amount_min: 100, unit: 'gram', product: 'חמאה' },
          { free_text: 'קורט מלח', product: 'מלח', optional: true } ],
          steps: [{ text: 'לפורר את החמאה עם הקמח לפירורים.' }, { text: 'ללחוץ לתחתית התבנית ולקרר.' }] },
        { name: 'מלית גבינה', ingredients: [
          { free_text: '750 גרם גבינה לבנה', amount_min: 750, unit: 'gram', product: 'גבינה 5%' },
          { free_text: '3 ביצים', amount_min: 3, unit: 'unit', product: 'ביצים' },
          { free_text: 'כוס סוכר', amount_min: 200, unit: 'gram', product: 'סוכר' } ],
          steps: [{ text: 'להקציף את הגבינה עם הסוכר.' }, { text: 'להוסיף ביצים אחת־אחת.' }, { text: 'לאפות 50 דקות ב-160 מעלות.' }] },
      ] });
  }
  f.src = './index.html' + hash;
  setTimeout(() => {
    const d = f.contentDocument.documentElement, over = [];
    if (scrollTo) f.contentDocument.querySelector(scrollTo)?.scrollIntoView({ block: 'start' });
    // דף ארוך מקבל פס גלילה אנכי (15px ב-headless), וב-RTL הוא יושב בצד
    // שמאל — כך שכל התוכן מוזז ימינה ב-15px ו-clientWidth קטן ב-15. בלי
    // התיקון הזה כל אלמנט ברוחב מלא נראה "גולש", וזה שקר.
    const sb = f.clientWidth - d.clientWidth;
    f.contentDocument.querySelectorAll('body *').forEach(el => {
      const r = el.getBoundingClientRect();
      if (r.right > d.clientWidth + sb + 1 || r.left < -1) over.push(el.tagName + '/' + (el.id || el.className));
    });
    document.title = 'CLIENT=' + d.clientWidth + ' SCROLL=' + d.scrollWidth +
                     ' OVER=' + (over.slice(0, 5).join(',') || 'none');
  }, 1500);
})();
</script></body></html>
HTML

php -S "127.0.0.1:$PORT" -t . -d auto_prepend_file="$TMP/boot.php" -d sendmail_path=/bin/true \
  >"$TMP/s.log" 2>&1 &
SRV=$!
for _ in $(seq 1 40); do
  curl -fsS "http://127.0.0.1:$PORT/recipes-app/api.php?action=me" >/dev/null 2>&1 && break
  sleep 0.25
done

Q=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$HASH")
SC=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$SCROLL_TO")
URL="http://127.0.0.1:$PORT/recipes-app/_shot.html?hash=$Q&scroll=$SC"
# המדידה קודמת לצילום: גלישה לרוחב היא תקלה שצריך לראות כמספר, לא לנחש מתמונה.
"$CH" --headless=new --no-sandbox --disable-gpu --virtual-time-budget=8000 --dump-dom "$URL" \
  2>/dev/null | grep -o '<title>[^<]*</title>'
"$CH" --headless=new --no-sandbox --disable-gpu --hide-scrollbars \
  --window-size=390,844 --force-device-scale-factor=2 --virtual-time-budget=8000 \
  --screenshot="$OUT" "$URL" >/dev/null 2>&1
echo "נשמר: $OUT"
