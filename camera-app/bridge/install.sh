#!/usr/bin/env bash
# התקנת הגשר על Raspberry Pi OS / Debian / Ubuntu.
#
#   curl -fsSL https://<שרת>/mini_projects/camera-app/bridge/install.sh | sudo bash -s -- <כתובת camera-app> <קוד צימוד>
#
# מה זה עושה: מתקין ffmpeg ו-python3, מוריד את bridge.py ל-/opt/camera-bridge,
# מצמיד לשרת עם הקוד, ורושם שירות systemd שעולה אחרי חשמל ומתאושש מנפילה.
# הרצה חוזרת בטוחה: מעדכנת את הסקריפט ומצמידה מחדש אם ניתן קוד.
set -euo pipefail

SERVER=${1:-}
CODE=${2:-}
if [ -z "$SERVER" ]; then
  echo "שימוש: install.sh <כתובת camera-app, למשל https://example.com/mini_projects/camera-app/> [קוד צימוד]" >&2
  exit 1
fi
if [ "$(id -u)" -ne 0 ]; then echo "יש להריץ כ-root (sudo)" >&2; exit 1; fi

SERVER="${SERVER%/}/"
DIR=/opt/camera-bridge
CONF=/etc/camera-bridge/config.json
WORK=/var/lib/camera-bridge

echo "▸ חבילות (ffmpeg, python3)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ffmpeg python3 curl >/dev/null

echo "▸ הורדת bridge.py מ-$SERVER"
mkdir -p "$DIR" "$WORK" /etc/camera-bridge
curl -fsSL "${SERVER}bridge/bridge.py" -o "$DIR/bridge.py"
chmod +x "$DIR/bridge.py"

if [ -n "$CODE" ]; then
  echo "▸ צימוד עם הקוד $CODE"
  python3 "$DIR/bridge.py" --config "$CONF" --server "$SERVER" --pair "$CODE" --work-dir "$WORK"
elif [ ! -f "$CONF" ]; then
  echo "אין תצורה ולא ניתן קוד צימוד. צור קוד במסך 'גשרים' והרץ שוב עם הקוד." >&2
  exit 1
fi

echo "▸ שירות systemd"
cat > /etc/systemd/system/camera-bridge.service <<UNIT
[Unit]
Description=Camera bridge (mini_projects/camera-app)
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/bin/python3 $DIR/bridge.py --config $CONF
Restart=always
RestartSec=5
Nice=5
Environment=PYTHONUNBUFFERED=1

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now camera-bridge.service
systemctl restart camera-bridge.service
sleep 2
systemctl --no-pager --lines=5 status camera-bridge.service || true
echo
echo "✓ הגשר רץ. תוך דקה הוא יופיע במסך 'גשרים' כמחובר."
echo "  יומן:  journalctl -u camera-bridge -f"
echo "  עדכון: הרצה חוזרת של הפקודה הזו (בלי קוד)"
