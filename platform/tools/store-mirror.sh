#!/usr/bin/env bash
# מעתיק את "תיקיית החנות" של כל אפליקציה (Release <app>-store ב-GitHub) לשרת שלנו:
#   <DEPLOY_PATH>/platform/downloads/<app>/  —  <app>.apk, <app>.aab, store-listing.zip, build.json,
#   assetlinks.json, ו-meta.json: {versionCode, package, builtAt} — ממנו הפלטפורמה יודעת איזו גרסה
#   אחרונה ואיזו חבילה (platform/lib/registry.php). כך כפתור "התקנה" מוריד מהאתר, לא מ-GitHub.
#
# רץ מ-deploy.yml אחרי הפריסה ומ-store-mirror.yml (כל שעה — בניית אנדרואיד מעדכנת את ה-Release בלי
# להפעיל פריסה). לא מוחק כלום בשרת; rsync --checksum מעלה רק קבצים שהשתנו.
#
# סביבה: GH_TOKEN (gh release download), DEST (user@host), DEPLOY_PATH, ומפתח ב-~/.ssh/deploy_key.
set -uo pipefail

W="${RUNNER_TEMP:-/tmp}/store-mirror"
rm -rf "$W"; mkdir -p "$W"
n=0
for f in */app.json; do
    app=$(dirname "$f")
    rel=$(jq -r '.install.release // empty' "$f")
    [ -n "$rel" ] || continue
    d="$W/$app"; mkdir -p "$d"
    if ! gh release download "$rel" --dir "$d" --clobber >/dev/null 2>&1; then
        echo "::notice::$app — אין Release בשם $rel"; rm -rf "$d"; continue
    fi
    ls "$d"/*.apk >/dev/null 2>&1 || { echo "::notice::$app — אין APK ב-$rel"; rm -rf "$d"; continue; }
    ver=$(jq -r '.versionCode // empty' "$d/build.json" 2>/dev/null)
    # builtAt בבניות TWA, built בבניות עם build.json ידני (כרטיסי ברכה ועוד)
    built=$(jq -r '.builtAt // .built // empty' "$d/build.json" 2>/dev/null)
    pkg=$(jq -r '[.[] | .target.package_name // empty][0] // empty' "$d/assetlinks.json" 2>/dev/null)
    # בלי assetlinks.json ב-Release (מגדלור) — שם החבילה מ-build.json; בלעדיו אין "פתיחה" ו"עדכון" בכרטיס
    [ -n "$pkg" ] || pkg=$(jq -r '.package // empty' "$d/build.json" 2>/dev/null)
    jq -n --arg v "$ver" --arg p "$pkg" --arg b "$built" \
        '{versionCode: ($v | if . == "" then null else tonumber end), package: ($p | if . == "" then null else . end), builtAt: ($b | if . == "" then null else . end)}' \
        > "$d/meta.json"
    echo "$app: גרסה ${ver:-?} ${pkg:+($pkg)}"
    n=$((n + 1))
done
[ "$n" -gt 0 ] || { echo "אין תיקיות חנות להעתיק"; exit 0; }

SSH="ssh -i $HOME/.ssh/deploy_key -o StrictHostKeyChecking=no -o ConnectTimeout=30"
DIR="${DEPLOY_PATH%/}/platform/downloads"
# החסימה בשרת משתחררת בדקות (CLAUDE.md, "כשהפריסה נכשלת") — לכן ההמתנות ארוכות
for wait in 0 30 90 180; do
    sleep "$wait"
    if rsync -rtz --checksum -e "$SSH" "$W/" "$DEST:$DIR/"; then
        echo "הועתקו $n תיקיות חנות ל-platform/downloads/"; exit 0
    fi
done
echo "::warning::לא הצלחתי להעתיק את תיקיות החנות לשרת"
exit 1
