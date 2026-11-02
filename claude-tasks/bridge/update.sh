#!/usr/bin/env bash
# עותק הריצה של הגשר (docs/spec.md §5.10.1) — מרענן את ‎$HOME/.claude-tasks-bridge‎ מ-origin/main.
#
# בלי לגעת בעץ העבודה של הצ'אט: git show לכל קובץ ב-claude-tasks/bridge, לתיקייה מחוץ לריפו.
# כך שינוי ב-hooks שמוזג ל-main מגיע לכל צ'אט רץ בתוך מחזור גיבוי אחד — בלי PR בענף שלו
# ובלי קבצים "שונו" ב-git status. עומד לבד (לא טוען את lib.sh), כדי שאפשר יהיה להריץ אותו
# ישר מ-main גם בצ'אט ישן:   bash <(git show origin/main:claude-tasks/bridge/update.sh)
#
# קוד יציאה 0 — עודכן (או כבר עדכני); 1 — אין גישה ל-origin/main (העותק הקודם נשאר).
set -u
RT="$HOME/.claude-tasks-bridge"
ROOT="${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel 2>/dev/null)}"
[ -n "$ROOT" ] || { echo "✗ לא בתוך הריפו"; exit 1; }
cd "$ROOT" || exit 1

git fetch -q origin main 2>/dev/null || echo "⚠ git fetch נכשל — משתמש ב-origin/main שכבר כאן" >&2
rev="$(git rev-parse --short origin/main 2>/dev/null)" || { echo "✗ אין origin/main"; exit 1; }
files="$(git ls-tree --name-only origin/main claude-tasks/bridge/ 2>/dev/null)"
[ -n "$files" ] || { echo "✗ אין claude-tasks/bridge ב-origin/main"; exit 1; }

# בונים בתיקייה זמנית ומחליפים בבת אחת — hook שרץ באמצע לא יראה חצי עותק
tmp="$(mktemp -d "${RT}.new.XXXXXX")" || exit 1
for f in $files; do
  git show "origin/main:$f" > "$tmp/$(basename "$f")" || { rm -rf "$tmp"; echo "✗ git show $f"; exit 1; }
done
chmod 700 "$tmp"; chmod 755 "$tmp"/*.sh 2>/dev/null
echo "$rev" > "$tmp/REVISION"
rm -rf "$RT.old"; [ -d "$RT" ] && mv "$RT" "$RT.old"
mv "$tmp" "$RT" && rm -rf "$RT.old"
ver="$(sed -n 's/^BRIDGE_VERSION="\(.*\)"/\1/p' "$RT/lib.sh" 2>/dev/null)"
echo "✓ הגשר עודכן: גרסה ${ver:-?} (main @ $rev) ← $RT"
exit 0
