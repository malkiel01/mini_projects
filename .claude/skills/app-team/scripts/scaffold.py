#!/usr/bin/env python3
"""שלד לצוות של אפליקציה ב-mini_projects (הסקיל app-team).

    python3 .claude/skills/app-team/scripts/scaffold.py <app-id> "<שם בעברית>" [--category daily] [--existing]

אפליקציה חדשה: התיקייה, app.json (שער + restricted), דף "בפיתוח", data/ חסומה, team/ ו-docs/.
--existing: רק team/ ו-docs/ (owner-brief, open-questions) — לא נוגע בקוד ובנתונים.
בשני המקרים: שורת --exclude ב-deploy.yml ל-team/ (ולאפליקציה חדשה גם ל-data/).
קובץ שכבר קיים — לא נדרס.
"""
import argparse
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SKILL = Path(__file__).resolve().parents[1]
DEPLOY = ROOT / '.github/workflows/deploy.yml'
ANCHOR = "--exclude='/devloop/'"

created, skipped = [], []


def put(rel: str, text: str) -> None:
    p = ROOT / rel
    if p.exists():
        skipped.append(rel)
        return
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(text, encoding='utf-8')
    created.append(rel)


def deploy_exclude(line: str) -> None:
    s = DEPLOY.read_text(encoding='utf-8')
    if line.strip().rstrip('\\').strip() in s:
        skipped.append(f'deploy.yml: {line.strip()}')
        return
    rows = s.split('\n')
    i = next((k for k, r in enumerate(rows) if ANCHOR in r), None)
    if i is None:
        sys.exit(f'לא נמצאה שורת העוגן {ANCHOR} ב-deploy.yml — להוסיף ידנית: {line}')
    indent = re.match(r'\s*', rows[i]).group(0)
    rows.insert(i + 1, f'{indent}{line} \\')
    DEPLOY.write_text('\n'.join(rows), encoding='utf-8')
    created.append(f'deploy.yml: {line}')


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument('app')
    ap.add_argument('name')
    ap.add_argument('--category', default='daily')
    ap.add_argument('--existing', action='store_true')
    a = ap.parse_args()

    if not re.fullmatch(r'[a-z0-9]+(-[a-z0-9]+)*', a.app):
        sys.exit('app-id: אנגלית, kebab-case')
    cats = [c['id'] for c in json.loads((ROOT / 'platform/categories.json').read_text(encoding='utf-8'))]
    if a.category not in cats:
        sys.exit(f'category: אחת מ-{cats}')
    app, name = a.app, a.name
    if a.existing and not (ROOT / app / 'app.json').exists():
        sys.exit(f'--existing: אין {app}/app.json')
    if not a.existing and (ROOT / app).exists():
        sys.exit(f'{app}/ כבר קיימת — אפליקציה קיימת? --existing')

    if not a.existing:
        put(f'{app}/app.json', json.dumps({
            'id': app, 'name': name, 'icon': '🧰',
            'description': 'בפיתוח.', 'category': a.category, 'entry': 'index.html',
            'version': '0.0', 'gate': True, 'restricted': True,
        }, ensure_ascii=False, indent=2) + '\n')
        put(f'{app}/index.html', f'''<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{name}</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<main><h1>{name}</h1><p>האפליקציה בפיתוח.</p></main>
<script src="assets/js/app.js"></script>
</body>
</html>
''')
        put(f'{app}/assets/css/app.css', 'body { margin: 0; font-family: system-ui, sans-serif; }\n'
                                         'main { max-width: 34rem; margin: 15vh auto; padding: 0 16px; text-align: center; }\n')
        put(f'{app}/assets/js/app.js', '// שלד — הקוד נבנה בצ\'אט הקוד, לפי team/PROTOCOL.md\n')
        put(f'{app}/README.md', f'# {name}\n\n**מצב: בפיתוח — שלד.**\n\n'
                                '| מה | איפה |\n| --- | --- |\n'
                                '| הדרישה במילים של הבעלים | `docs/owner-brief.md` |\n'
                                '| האפיון | `docs/spec/` |\n| כללי הצוות | `team/PROTOCOL.md` |\n'
                                '| נתוני ריצה בשרת | `data/` — מוחרגת מהפריסה וחסומה מהדפדפן |\n\n'
                                '## מה טרם הוכרע\n\nהשאלות לבעלים — `docs/open-questions.md`.\n')
        put(f'{app}/data/.htaccess', '# המסד — לעולם לא מהדפדפן.\n<IfModule mod_authz_core.c>\n    Require all denied\n'
                                     '</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n')
        deploy_exclude(f"--include='/{app}/data/.htaccess' --exclude='/{app}/data/*'")

    put(f'{app}/docs/owner-brief.md', f'''# {name} — מה הבעלים צריך

המסמך המחייב של הצוות. מי שמשנה אותו: רק הבקרה, ורק לפי מה שהבעלים אמר.

## 1. במילים של הבעלים

> <ציטוט מדויק>

## 2. מה נגזר מזה

<הדרישות, כל אחת שאפשר לבדוק>

## 3. המבחן

<שאלה אחת שכל פרוסה וכל PR חייבים לעבור>
''')
    put(f'{app}/docs/open-questions.md', '# שאלות פתוחות לבעלים\n\n'
                                        'של צ\'אט האפיון. כל שאלה עם דוגמה, המלצה וברירת מחדל. מי ששואל — צ\'אט הבקרה.\n')

    proto = (SKILL / 'references/protocol.md').read_text(encoding='utf-8')
    put(f'{app}/team/PROTOCOL.md', proto.replace('{{APP}}', app).replace('{{NAME}}', name))
    put(f'{app}/team/STATE.md', f'# מצב — הזיכרון הנייד של הבקרה\n\n- **איפה עומדים:** '
                                f'{"צוות חדש לאפליקציה קיימת; הפרוסה הראשונה של הקוד — סקר (dev/audit.md)" if a.existing else "שלד בלבד"}.\n'
                                '- **מה פתוח:** —\n- **ממתין לבעלים:** —\n- **הצעד הבא:** sessions.json, הודעה לצוות, שעון.\n')
    put(f'{app}/team/board.md', '# הלוח\n\n| # | פרוסה | מצב | אצל מי | PR |\n| --- | --- | --- | --- | --- |\n'
                                f'| 00 | {"סקר מול הכללים (dev/audit.md)" if a.existing else "שלד וכללי הצוות"} | ⏳ | {"קוד" if a.existing else "—"} | |\n'
                                '| 01 | השאלות לבעלים והאפיון | ⏳ | אפיון | |\n')
    put(f'{app}/team/decisions.md', '# הכרעות\n\n| תאריך | הכרעה | מי | למה |\n| --- | --- | --- | --- |\n')
    put(f'{app}/team/owner-tasks.md', '# מטלות לבעלים\n\nשל צ\'אט המטלות. רק דברים לבדוק או לעשות.\n\n'
                                      '| # | מטלה | מי ביקש | מצב | תוצאה |\n| --- | --- | --- | --- | --- |\n')
    put(f'{app}/team/sessions.json', json.dumps({'note': 'מתעדכן בידי צ\'אט הבקרה', 'spec': None, 'code': None,
                                                 'control': None, 'tasks': None}, ensure_ascii=False, indent=2) + '\n')
    for r in ('spec', 'code', 'control', 'tasks'):
        put(f'{app}/team/log/{r}.md', f'# יומן — {r}\n\n')
    put(f'{app}/team/handoff/code.md', '# מה צ\'אט הקוד הבא צריך לדעת\n\n')
    put(f'{app}/team/reviews/.gitkeep', '')
    deploy_exclude(f"--exclude='/{app}/team/'")

    print('נוצר:\n  ' + '\n  '.join(created or ['—']))
    if skipped:
        print('קיים — לא נגעתי:\n  ' + '\n  '.join(skipped))
    print('\nהבא: למלא את docs/owner-brief.md, להריץ php platform/check-apps.php, PR ומיזוג.')


if __name__ == '__main__':
    main()
