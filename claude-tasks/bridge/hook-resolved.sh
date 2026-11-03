#!/usr/bin/env bash
# PostToolUse / PermissionDenied (כל כלי) → סמן מקומי "הוכרע" לבקשת ההרשאה (§5.12.2a, 2).
# מהיר ובלי רשת: רק קובץ. hook-permission.sh רואה אותו ולא מפרסם (אישור אוטומטי), או סוגר את הכרטיס.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
input="$(cat)"
[ -n "$(printf '%s' "$input" | jq -r '.tool_name // empty' 2>/dev/null)" ] || exit 0
dir="$(perm_dir)"
: > "$dir/resolved-$(perm_key "$input")"
# ניקוי: סמנים בני יותר משעה
find "$dir" -type f -mmin +60 -delete 2>/dev/null
exit 0
