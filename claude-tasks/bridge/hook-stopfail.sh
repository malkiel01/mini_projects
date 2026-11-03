#!/usr/bin/env bash
# StopFailure → channel-limit (docs/spec.md §15.12.1, שלב 16). התור נעצר בשגיאה של ה-API.
#
# שלב 0 (2026-11-03): הקלט של ה-hook הוא {hook_event_name:"StopFailure", error, error_details?, last_assistant_message?},
# ו-error אחד מ: rate_limit, billing_error, overloaded, authentication_failed, server_error, … — בלי שעת איפוס במבנה.
# רק מגבלה / חיוב / התחברות פותחים "הצ'אט לא עונה" מיד (השרת מסנן); עומס ותקלה רגעית — השתיקה תתפוס אם זה נמשך.
# פעיל רק בצ'אט רשום; כשל רשת — שקט, timeout 5.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh" 2>/dev/null || exit 0
[ -f "$BOARD_LINK" ] || exit 0
input="$(cat)"
err="$(printf '%s' "$input" | jq -r '.error // empty' 2>/dev/null)"
[ -n "$err" ] || exit 0
det="$(printf '%s' "$input" | jq -r '(.error_details // "") | tostring' 2>/dev/null | head -c 1500)"
body="$(jq -n --arg e "$err" --arg d "$det" '{error:$e, details:$d}')"
board_call channel-limit "$body" "$(link_get channel_token)" 5 >/dev/null 2>&1
exit 0
