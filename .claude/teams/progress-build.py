#!/usr/bin/env python3
"""בונה את דף ההתקדמות של כל הצוותים: .claude/teams/progress.html

מריצים מתוך שורש הריפו, אחרי `git fetch origin main '+refs/heads/claude/*-control:refs/remotes/origin/claude/*-control'`:
    python3 .claude/teams/progress-build.py

מקורות (קריאה בלבד, מגיט):
- .claude/teams/STATE.md ב-main — רשימת האפליקציות.
- לכל אפליקציה: team/board.md, team/STATE.md, team/owner-tasks.md, docs/open-questions.md —
  מהענף הטרי מבין main ו-origin/claude/<app>-control (לפי הקומיט האחרון שנגע ב-<app>/team/).
- .claude/teams/progress-overrides.json — מה שאי אפשר לקרוא מגיט: האם השעון דולק (פעיל / מושהה),
  "בבנייה עכשיו", דף התקדמות של הצוות, ופריטים "מחכה לך" נוספים. מנהל הצוותים מעדכן אותו בכל סבב.

הדף לא ממציא: מה שלא נמצא — מוצג כ"אין נתון".
"""
import datetime as dt
import html
import json
import os
import re
import subprocess
import sys

ROOT = subprocess.run(['git', 'rev-parse', '--show-toplevel'], capture_output=True, text=True).stdout.strip() or '.'
OUT = os.path.join(ROOT, '.claude/teams/progress.html')
OVR = os.path.join(ROOT, '.claude/teams/progress-overrides.json')


def git(*args):
    r = subprocess.run(['git', '-C', ROOT, *args], capture_output=True, text=True)
    return r.stdout if r.returncode == 0 else ''


def show(ref, path):
    return git('show', f'{ref}:{path}')


def ref_exists(ref):
    return subprocess.run(['git', '-C', ROOT, 'rev-parse', '-q', '--verify', ref], capture_output=True).returncode == 0


def last_ts(ref, path):
    out = git('log', '-1', '--format=%ct', ref, '--', path).strip()
    return int(out) if out else 0


def table_rows(md):
    """כל שורות הטבלאות בקובץ: [(header_cells, row_cells)]."""
    rows, header = [], None
    for line in md.splitlines():
        s = line.strip()
        if not s.startswith('|'):
            header = None
            continue
        cells = [c.strip() for c in s.strip('|').split('|')]
        if all(re.fullmatch(r':?-{2,}:?', c) for c in cells if c):
            continue
        if header is None:
            header = cells
            continue
        rows.append((header, cells))
    return rows


def plain(s):
    s = re.sub(r'\*\*|`', '', s)
    s = re.sub(r'\[([^\]]+)\]\([^)]+\)', r'\1', s)
    return re.sub(r'\s+', ' ', s).strip()


def classify(text):
    t = text
    if re.search(r'✖|נמחק', t):
        return 'removed'
    if re.search(r'⏳|🔨|🔍|בקוד|בביקורת|בעבודה', t) and not re.search(r'ב-main|באתר|נפרס|מוזג', t):
        return 'run'
    if re.search(r'ב-main|באתר|נפרס|מוזג|בשרת', t):
        return 'ok'
    if re.search(r'⏸|⬜|ממתין ל"', t):
        return 'wait'
    if '✅' in t:
        return 'ok'
    return 'wait'


def parse_board(md):
    slices = []
    for header, cells in table_rows(md):
        h = [plain(x) for x in header]
        if not h or h[0] not in ('#',):
            continue
        sid = plain(cells[0]) if cells else ''
        if not sid or sid in ('—', '-'):
            continue
        title_i = h.index('פרוסה') if 'פרוסה' in h else 1
        skip = {0, title_i}
        for name in ('PR', 'תלויה ב-', 'אצל מי'):
            if name in h:
                skip.add(h.index(name))
        status_txt = ' '.join(cells[i] for i in range(len(cells)) if i not in skip)
        title = plain(cells[title_i]) if title_i < len(cells) else ''
        state = classify(status_txt)
        if state == 'removed':
            continue
        spec_done = ('✅' in status_txt) or state == 'ok'
        slices.append({'id': sid, 'title': title[:140], 'state': state, 'spec': spec_done,
                       'status': plain(status_txt)[:160]})
    return slices


CLOSED = re.compile(r'✅|בוצע|נסגר|הושלם|סגורה|הוחלט|לא רלוונטי|בוטל|נענה|נענתה')


def parse_owner_tasks(md):
    items = []
    for header, cells in table_rows(md):
        h = [plain(x) for x in header]
        if 'מטלה' not in h:
            continue
        ti = h.index('מטלה')
        si = h.index('מצב') if 'מצב' in h else None
        status = cells[si] if si is not None and si < len(cells) else ''
        if CLOSED.search(status):
            continue
        num = plain(cells[0]) if cells else ''
        txt = plain(cells[ti]) if ti < len(cells) else ''
        if txt:
            items.append({'kind': 'מטלה', 'id': num, 'text': txt[:220], 'status': plain(status)[:80]})
    return items


def parse_questions(md):
    items = []
    # טבלה עם עמודת "מצב": פתוחה כשיש ⏳ ואין ✅
    for header, cells in table_rows(md):
        h = [plain(x) for x in header]
        if 'מצב' not in h:
            continue
        si = h.index('מצב')
        st = cells[si] if si < len(cells) else ''
        if '⏳' in st and '✅' not in st:
            q = plain(cells[1]) if len(cells) > 1 else ''
            items.append({'kind': 'שאלה', 'id': plain(cells[0]), 'text': q[:220], 'status': 'ממתינה'})
    # כותרות "## ש1." / "### ש1." — פתוחה כשהגוף לא מסומן נענה / הוכרע
    blocks = re.split(r'(?m)^(#{2,3}\s+ש[-\s]?\d+[^\n]*)\n', md)
    for i in range(1, len(blocks) - 1, 2):
        head, body = blocks[i], blocks[i + 1]
        body = re.split(r'(?m)^#{1,3}\s', body)[0]
        if re.search(r'✅|נענה|הוכרע|נענתה', body):
            continue
        m = re.match(r'#{2,3}\s+(ש[-\s]?\d+)\.?\s*(.*)', head)
        if m:
            dm = re.search(r'ברירת (?:ה)?מחדל:?\*?\*?\s*([^\n]{0,120})', body)
            items.append({'kind': 'שאלה', 'id': m.group(1), 'text': plain(m.group(2))[:220],
                          'status': ('ברירת מחדל: ' + plain(dm.group(1))) if dm else 'ממתינה'})
    return items


def team_list():
    md = show('origin/main', '.claude/teams/STATE.md')
    apps = []
    for header, cells in table_rows(md):
        if not cells or not cells[0].startswith('`'):
            continue
        m = re.match(r'`([a-z0-9-]+)`\s*(.*)', cells[0])
        if not m:
            continue
        apps.append({'id': m.group(1), 'name': plain(m.group(2)) or m.group(1),
                     'state_line': plain(cells[1]) if len(cells) > 1 else ''})
    return apps


def best_ref(app):
    main_ts = last_ts('origin/main', f'{app}/team')
    ref, ts = 'origin/main', main_ts
    ctl = f'origin/claude/{app}-control'
    if ref_exists(ctl):
        c = last_ts(ctl, f'{app}/team')
        if c > ts:
            ref, ts = ctl, c
    return ref, ts


def fmt_time(ts):
    if not ts:
        return 'אין נתון'
    d = dt.datetime.fromtimestamp(ts, dt.timezone.utc)
    return d.strftime('%d.%m.%Y, %H:%M UTC')


def main():
    ovr = {}
    if os.path.exists(OVR):
        with open(OVR, encoding='utf-8') as f:
            ovr = json.load(f)
    apps_ovr = ovr.get('apps', {})
    data = []
    for a in team_list():
        app = a['id']
        o = apps_ovr.get(app, {})
        ref, ts = best_ref(app)
        board = show(ref, f'{app}/team/board.md')
        slices = o.get('slices') or parse_board(board)
        tasks = parse_owner_tasks(show(ref, f'{app}/team/owner-tasks.md'))
        qs = [] if o.get('questions') == 'off' else parse_questions(show(ref, f'{app}/docs/open-questions.md'))
        waiting = [*o.get('waiting', []), *tasks]
        counted = [s for s in slices if s.get('count', True)]
        done = sum(1 for s in counted if s['state'] == 'ok')
        building = [s for s in counted if s['state'] == 'run']
        data.append({
            'id': app, 'name': o.get('name') or a['name'],
            'status': o.get('status', 'paused'), 'status_note': o.get('status_note', ''),
            'now': o.get('now') or (' · '.join(f"{s['id']} {s['title']}" for s in building[:2]) if building else ''),
            'updated': fmt_time(ts), 'source': ref.replace('origin/', ''),
            'board_note': o.get('board_note', ''), 'progress_url': o.get('progress_url', ''),
            'slices': slices, 'done': done, 'total': len(counted), 'waiting': waiting, 'questions': qs,
            'questions_note': o.get('questions_note', ''),
        })
    order = {'active': 0, 'paused': 1}
    data.sort(key=lambda d: (order.get(d['status'], 2), d['name']))
    now = dt.datetime.now(dt.timezone.utc).strftime('%d.%m.%Y, %H:%M UTC')
    with open(OUT, 'w', encoding='utf-8') as f:
        f.write(render(data, now, ovr.get('general_waiting', [])))
    print(f'{OUT}: {len(data)} צוותים')


def esc(s):
    return html.escape(str(s), quote=True)


STEP_LABELS = [('spec', 'אפיון'), ('code', 'קוד'), ('review', 'ביקורת'), ('live', 'בשרת')]


def steps_for(s):
    st = s['state']
    if st == 'ok':
        marks = ['ok', 'ok', 'ok', 'ok']
    elif st == 'run':
        marks = ['ok' if s.get('spec') else 'run', 'run' if s.get('spec') else '', '', '']
    else:
        marks = ['ok' if s.get('spec') else '', '', '', '']
    out = []
    for (_, label), m in zip(STEP_LABELS, marks):
        out.append(f'<span class="step {m}">{label}{" ✓" if m == "ok" else ""}</span>')
    return ''.join(out)


PILL = {'ok': ('ok', 'בשרת'), 'run': ('run', 'בבנייה'), 'wait': ('wait', 'ממתין')}


def app_desc(app):
    try:
        return json.loads(show('origin/main', f'{app}/app.json') or '{}').get('description', '')
    except ValueError:
        return ''


def task_html(w):
    head = ' · '.join(x for x in (w.get('kind', ''), w.get('id', '')) if x)
    st = w.get('status') or ''
    sub = f'<span class="foot">{esc(st)}</span>' if st else ''
    text = w['text']
    cut = re.split(r'(?<=[.;:])\s', text, maxsplit=1)
    short = cut[0] if len(cut[0]) >= 30 or len(cut) == 1 else text[:120]
    short = short if len(short) <= 120 else short[:118].rstrip() + '…'
    short = short.rstrip(' :;')
    more = f'<details><summary>פירוט</summary><p>{esc(text)}</p></details>' if short != text.rstrip(' :;') else ''
    return (f'<div class="task"><div><b>{esc(head)}</b><span class="txt">{esc(short)}</span>{more}{sub}</div>'
            f'<span class="pill run">פתוחה</span></div>')


def q_html(w):
    st = w.get('status') or ''
    sub = f'<span>{esc(st)}</span>' if st else ''
    return f'<div class="q"><span class="id">{esc(w.get("id", ""))}</span><div><b>{esc(w["text"])}</b>{sub}</div></div>'


def slice_html(s):
    st = s.get('status') or ''
    p = f'<p>{esc(st)}</p>' if st else ''
    return (f'<li class="slice"><div class="num">{esc(s["id"])}</div><div><h3>{esc(s["title"])}</h3>{p}'
            f'<div class="steps">{steps_for(s)}</div></div>'
            f'<span class="pill {PILL[s["state"]][0]}">{PILL[s["state"]][1]}</span></li>')


def render(data, now, general):
    opts, panels = [], []
    for i, d in enumerate(data):
        total = d['total']
        active = d['status'] == 'active'
        st_txt = 'פעיל' if active else 'מושהה'
        extra = f' · מחכה לך {len(d["waiting"])}' if d['waiting'] else ''
        opts.append(f'<option value="{esc(d["id"])}">{esc(d["name"])} · {st_txt}{extra}</option>')
        segs = ''.join(f'<span class="{ {"ok": "ok", "run": "run"}.get(s["state"], "") }"></span>'
                       for s in d['slices'] if s.get('count', True))
        slices = ''.join(slice_html(s) for s in d['slices'])
        waits = ''.join(task_html(w) for w in d['waiting'])
        qs = ''.join(q_html(w) for w in d['questions'])
        desc = app_desc(d['id'])
        status_line = st_txt + (' · ' + d['status_note'] if d['status_note'] else '')
        now_lbl = 'בבנייה עכשיו' if active else 'נעצר ב'
        parts = [f'<article class="app" data-app="{esc(d["id"])}"{"" if i == 0 else " hidden"}>',
                 '<header class="hero">',
                 f'<div class="eyebrow"><span class="pill {"ok" if active else "wait"}">{esc(status_line)}</span></div>',
                 f'<h1>{esc(d["name"])}</h1>']
        if desc:
            parts.append(f'<p class="lead">{esc(desc)}</p>')
        parts.append(f'<div class="meter" aria-label="התקדמות הבנייה"><div class="bar" style="--n:{max(total, 1)}">{segs}</div>'
                     f'<div class="meter-row"><span>בשרת: <b>{d["done"]} מתוך {total}</b></span>'
                     f'<span>{now_lbl}: <b>{esc(d["now"]) or "—"}</b></span></div></div>')
        if d['progress_url']:
            parts.append(f'<a class="ext" href="{esc(d["progress_url"])}" target="_blank" rel="noopener">הדף המפורט של הצוות ↗</a>')
        parts.append('</header>')
        parts.append('<section><h2>מחכה לך</h2>' +
                     (f'<div class="panel">{waits}</div>' if waits else '<p class="empty">אין כרגע שום דבר שמחכה לך כאן.</p>') +
                     '</section>')
        if qs:
            parts.append('<section><h2>שאלות פתוחות</h2><div class="panel">'
                         '<div class="foot">עד שתענה, העבודה ממשיכה לפי ברירת המחדל.</div>' + qs + '</div></section>')
        if d['questions_note']:
            parts.append(f'<p class="foot">{esc(d["questions_note"])}</p>')
        parts.append('<section><h2>הפרוסות</h2>' +
                     (f'<ol class="slices">{slices}</ol>' if slices else '<p class="empty">אין לוח פרוסות עדיין.</p>') +
                     (f'<p class="foot">{esc(d["board_note"])}</p>' if d['board_note'] else '') + '</section>')
        parts.append(f'<p class="foot">עודכן {esc(d["updated"])} · מקור: <code>{esc(d["source"])}</code></p></article>')
        panels.append('\n'.join(parts))
    gen = ''.join(task_html(w) for w in general)
    return (TEMPLATE.replace('{{NOW}}', esc(now)).replace('{{OPTIONS}}', ''.join(opts))
            .replace('{{PANELS}}', '\n'.join(panels))
            .replace('{{GENERAL}}', f'<section><h2>כללי</h2><div class="panel">{gen}</div></section>' if gen else ''))


TEMPLATE = r'''<title>מצב כל הצוותים</title>
<meta name="claude:viewport-fit-sides" content="cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Frank+Ruhl+Libre:wght@500;700&family=Heebo:wght@400;500;700&display=swap">
<style>
/* פריסה: בורר פרויקט קבוע למעלה; מתחתיו דשבורד של פרויקט אחד — כותרת ומד, מחכה לך, שאלות, פרוסות */
:root{
  --bg:#f5f6f4; --surface:#ffffff; --ink:#1d2523; --muted:#5f6b67; --line:#dde2df;
  --accent:#2f5d55; --accent-soft:#e3ece9;
  --ok:#2e7d4f; --ok-soft:#e2f2e8; --run:#b26a00; --run-soft:#fbefd9; --wait:#7a8580; --wait-soft:#eceeed;
  --f-display:"Frank Ruhl Libre","David",Georgia,serif;
  --f-body:"Heebo","Segoe UI",Arial,sans-serif;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:#141917; --surface:#1c2320; --ink:#e7ece9; --muted:#9aa7a2; --line:#2c3632;
  --accent:#8cc2b6; --accent-soft:#22332f;
  --ok:#72c993; --ok-soft:#1c3326; --run:#f0b25a; --run-soft:#3a2c14; --wait:#8d9893; --wait-soft:#252c29; color-scheme:dark}}
:root[data-theme="dark"]{
  --bg:#141917; --surface:#1c2320; --ink:#e7ece9; --muted:#9aa7a2; --line:#2c3632;
  --accent:#8cc2b6; --accent-soft:#22332f;
  --ok:#72c993; --ok-soft:#1c3326; --run:#f0b25a; --run-soft:#3a2c14; --wait:#8d9893; --wait-soft:#252c29; color-scheme:dark}
body{background:var(--bg);color:var(--ink);font-family:var(--f-body);font-size:15px;line-height:1.55}
.top{position:sticky;top:0;z-index:2;background:var(--bg);border-bottom:1px solid var(--line)}
.top-in{max-width:760px;margin:0 auto;padding:10px max(16px,env(safe-area-inset-right,0px)) 10px max(16px,env(safe-area-inset-left,0px));display:flex;flex-direction:column;gap:4px}
.top label{font-size:12px;color:var(--muted)}
.top select{font:inherit;font-size:16px;font-weight:500;color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:10px;min-height:46px;padding:0 12px;width:100%}
.top select:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
.wrap{max-width:760px;margin:0 auto;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px));padding-block:22px 48px;display:flex;flex-direction:column;gap:28px}
.app{display:flex;flex-direction:column;gap:28px}
.app[hidden]{display:none}
.task details{font-size:13.5px;color:var(--muted)}
.task summary{cursor:pointer;color:var(--accent);font-size:13px;width:max-content}
.task details p{margin:4px 0 0;overflow-wrap:anywhere}
h1,h2{font-family:var(--f-display);text-wrap:balance;margin:0}
h1{font-size:clamp(26px,6vw,36px);line-height:1.15}
h2{font-size:21px}
.eyebrow{font-size:12px;color:var(--muted)}
.lead{color:var(--muted);margin:0;max-width:60ch;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.hero{display:flex;flex-direction:column;gap:12px}
.meter{display:flex;flex-direction:column;gap:8px}
.bar{display:grid;grid-template-columns:repeat(var(--n),1fr);gap:4px}
.bar span{height:10px;border-radius:3px;background:var(--wait-soft)}
.bar .ok{background:var(--ok)} .bar .run{background:var(--run)}
.meter-row{display:flex;justify-content:space-between;gap:6px 12px;flex-wrap:wrap;font-size:13px;color:var(--muted)}
.meter-row b{color:var(--ink);font-variant-numeric:tabular-nums}
section{display:flex;flex-direction:column;gap:12px}
.slices{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;border-top:1px solid var(--line)}
.slice{display:grid;grid-template-columns:44px 1fr auto;gap:12px;align-items:start;padding:14px 0;border-bottom:1px solid var(--line)}
.slice>div{min-width:0}
.num{font-family:var(--f-display);font-size:22px;color:var(--muted);font-variant-numeric:tabular-nums;line-height:1.2;overflow-wrap:anywhere}
.slice h3{margin:0;font-size:16px;font-weight:700}
.slice p{margin:2px 0 0;color:var(--muted);font-size:13.5px;overflow-wrap:anywhere}
.steps{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap}
.step{font-size:12px;padding:2px 8px;border-radius:999px;background:var(--wait-soft);color:var(--wait)}
.step.ok{background:var(--ok-soft);color:var(--ok)} .step.run{background:var(--run-soft);color:var(--run)}
.pill{font-size:12px;font-weight:500;padding:3px 10px;border-radius:999px;white-space:nowrap;display:inline-block}
.pill.ok{background:var(--ok-soft);color:var(--ok)} .pill.run{background:var(--run-soft);color:var(--run)} .pill.wait{background:var(--wait-soft);color:var(--wait)}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:4px 16px;display:flex;flex-direction:column}
.panel>.foot{padding-top:12px}
.task{display:flex;justify-content:space-between;gap:8px 12px;align-items:start;padding:12px 0;border-top:1px solid var(--line)}
.task:first-child{border-top:0}
.task>div{min-width:0;flex:1 1 220px;display:flex;flex-direction:column;gap:2px}
.task .txt{overflow-wrap:anywhere}
.q{display:grid;grid-template-columns:40px 1fr;gap:10px;padding:12px 0;border-top:1px solid var(--line)}
.q .id{font-weight:700;color:var(--accent);font-variant-numeric:tabular-nums}
.q b{display:block;font-weight:500;overflow-wrap:anywhere}
.q span{color:var(--muted);font-size:13.5px}
.q>div{min-width:0}
.ext{color:var(--accent);font-weight:500;align-self:start}
.empty{margin:0;color:var(--muted)}
code{font-size:12px;background:var(--wait-soft);padding:0 5px;border-radius:4px}
.foot{font-size:12px;color:var(--muted);margin:0;overflow-wrap:anywhere}
@media (max-width:420px){.slice{grid-template-columns:34px 1fr}.slice .pill{grid-column:2;justify-self:start}}
</style>

<div dir="rtl" lang="he">
  <div class="top"><div class="top-in">
    <label for="pick">פרויקט</label>
    <select id="pick">{{OPTIONS}}</select>
  </div></div>
  <main class="wrap">
    {{PANELS}}
    {{GENERAL}}
    <p class="foot">עודכן {{NOW}} · מתעדכן בכל סבב של מנהל הצוותים. "בשרת" = מוזג ל-main ונפרס.</p>
  </main>
</div>
<script>
(function(){
  var pick=document.getElementById('pick'), apps=document.querySelectorAll('.app');
  function show(id){
    var found=false;
    apps.forEach(function(a){var on=a.dataset.app===id; a.hidden=!on; if(on) found=true;});
    if(!found && apps[0]){apps[0].hidden=false; id=apps[0].dataset.app;}
    pick.value=id;
    try{localStorage.setItem('teams-pick',id);}catch(e){}
  }
  var saved=null; try{saved=localStorage.getItem('teams-pick');}catch(e){}
  if(location.hash.length>1) saved=decodeURIComponent(location.hash.slice(1));
  if(saved) show(saved);
  pick.addEventListener('change',function(){show(pick.value); window.scrollTo(0,0);});
})();
</script>
'''

if __name__ == '__main__':
    sys.exit(main())
