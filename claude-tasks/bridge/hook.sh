#!/usr/bin/env bash
# הדיספצ'ר של ה-hooks (docs/spec.md §5.10.1). ‎.claude/settings.json‎ רושם כל אירוע פעם אחת:
#
#   hook.sh prompt | stop | stopfail | choice | choice-done | allow | notify | permission | resolved | gate
#
# מריץ את העותק שב-‎$HOME/.claude-tasks-bridge‎ (link.sh update מרענן אותו מ-origin/main), ואם אין —
# את העותק שבריפו. כך תיקון ב-hooks מגיע לצ'אט רץ בלי שהענף שלו ישתנה.
# תמיד יוצא 0: hook שנכשל לא יעצור את הצ'אט.
event="${1:-}"
case "$event" in
  prompt)      f=hook-prompt.sh ;;
  stop)        f=hook-stop.sh ;;
  stopfail)    f=hook-stopfail.sh ;;    # §15.12.1 — הצ'אט נעצר (מגבלה)
  choice)      f=hook-choice.sh ;;
  choice-done) f=hook-choice-done.sh ;;
  allow)       f=hook-allow.sh ;;
  notify)      f=hook-notify.sh ;;
  permission)  f=hook-permission.sh ;;   # §5.12
  resolved)    f=hook-resolved.sh ;;
  gate)        f=hook-gate.sh ;;         # §5.13
  *) exit 0 ;;
esac
rt="$HOME/.claude-tasks-bridge"
repo="${CLAUDE_PROJECT_DIR:-.}/claude-tasks/bridge"
if [ -f "$rt/$f" ] && [ -f "$rt/lib.sh" ]; then dir="$rt"; else dir="$repo"; fi
[ -f "$dir/$f" ] || exit 0
bash "$dir/$f"
exit 0
