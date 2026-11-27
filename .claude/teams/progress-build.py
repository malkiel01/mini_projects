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


def render(data, now, general):
    active = sum(1 for d in data if d['status'] == 'active')
    paused = sum(1 for d in data if d['status'] != 'active')
    all_wait = [(d['name'], w) for d in data for w in d['waiting']] + [('כללי', w) for w in general]
    qcount = sum(len(d['questions']) for d in data)
    cards = []
    for d in data:
        total = d['total']
        pct = round(100 * d['done'] / total) if total else 0
        segs = ''.join(f'<span class="{ {"ok": "ok", "run": "run"}.get(s["state"], "") }"></span>'
                       for s in d['slices'] if s.get('count', True))
        st_cls = 'ok' if d['status'] == 'active' else 'wait'
        st_txt = 'פעיל' if d['status'] == 'active' else 'מושהה'
        if d['status_note']:
            st_txt += ' · ' + d['status_note']
        slices_html = ''.join(
            f'<li class="slice"><div class="num">{esc(s["id"])}</div><div><h4>{esc(s["title"])}</h4>'
            f'<p>{esc(s.get("status", ""))}</p><div class="steps">{steps_for(s)}</div></div>'
            f'<span class="pill {PILL[s["state"]][0]}">{PILL[s["state"]][1]}</span></li>'
            for s in d['slices'])
        wait_html = ''.join(
            f'<li><span class="kind">{esc(w["kind"])} {esc(w.get("id", ""))}</span> {esc(w["text"])}'
            f'{(" <em>" + esc(w["status"]) + "</em>") if w.get("status") else ""}</li>' for w in d['waiting'])
        q_html = ''.join(
            f'<li><span class="kind">{esc(w.get("id", ""))}</span> {esc(w["text"])}'
            f'{(" <em>" + esc(w["status"]) + "</em>") if w.get("status") else ""}</li>' for w in d['questions'])
        link = (f'<a class="ext" href="{esc(d["progress_url"])}" target="_blank" rel="noopener">דף ההתקדמות של הצוות ↗</a>'
                if d['progress_url'] else '')
        note = f'<p class="foot">{esc(d["board_note"])}</p>' if d['board_note'] else ''
        cards.append(f'''
<details class="card" id="app-{esc(d['id'])}">
  <summary>
    <div class="card-head"><h3>{esc(d['name'])}</h3><span class="pill {st_cls}">{esc(st_txt)}</span></div>
    <div class="bar" style="--n:{max(total, 1)}" aria-hidden="true">{segs}</div>
    <div class="meter-row"><span>בשרת: <b>{d['done']} מתוך {total}</b>{f' ({pct}%)' if total else ''}</span>
      <span>{'בבנייה' if d['status'] == 'active' else 'נעצר ב'}: <b>{esc(d['now']) or '—'}</b></span>
      <span>מחכה לך: <b>{len(d['waiting'])}</b>{f" · שאלות: <b>{len(d['questions'])}</b>" if d['questions'] else ''}</span></div>
    <div class="foot">עודכן {esc(d['updated'])} · מקור: <code>{esc(d['source'])}</code></div>
  </summary>
  <div class="detail">
    {link}{note}
    <h4 class="sub">הפרוסות</h4>
    {f'<ol class="slices">{slices_html}</ol>' if slices_html else '<p class="foot">אין לוח פרוסות עדיין.</p>'}
    <h4 class="sub">מחכה לך כאן</h4>
    {f'<ul class="waits">{wait_html}</ul>' if wait_html else '<p class="foot">אין כרגע.</p>'}
    {f'<h4 class="sub">שאלות פתוחות</h4><p class="foot">העבודה ממשיכה לפי ברירת המחדל של כל שאלה עד שתענה.</p><ul class="waits">{q_html}</ul>' if q_html else ''}
    {f'<p class="foot">{esc(d["questions_note"])}</p>' if d['questions_note'] else ''}
  </div>
</details>''')
    wait_list = ''.join(
        f'<li><span class="app">{esc(app)}</span><span class="kind">{esc(w["kind"])} {esc(w.get("id", ""))}</span>'
        f'<span class="txt">{esc(w["text"] if len(w["text"]) <= 110 else w["text"][:108].rstrip() + "…")}</span></li>' for app, w in all_wait)
    return TEMPLATE.replace('{{NOW}}', esc(now)).replace('{{ACTIVE}}', str(active)) \
        .replace('{{PAUSED}}', str(paused)).replace('{{WAITCOUNT}}', str(len(all_wait))).replace('{{QCOUNT}}', str(qcount)) \
        .replace('{{WAITLIST}}', wait_list or '<li><span class="txt">אין כרגע.</span></li>') \
        .replace('{{CARDS}}', ''.join(cards))


TEMPLATE = r'''<title>מצב כל הצוותים</title>
<meta name="claude:viewport-fit-sides" content="cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Frank+Ruhl+Libre:wght@500;700&family=Heebo:wght@400;500;700&display=swap">
<style>
/* פריסה: עמודה אחת צרה — סיכום למעלה, "מחכה לך" מכל הצוותים, ואז כרטיס מתקפל לכל אפליקציה */
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
.wrap{max-width:760px;margin:0 auto;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px));padding-block:28px 48px;display:flex;flex-direction:column;gap:28px}
h1,h2,h3{font-family:var(--f-display);text-wrap:balance;margin:0}
h1{font-size:clamp(26px,6vw,36px);line-height:1.15}
h2{font-size:21px} h3{font-size:18px}
h4{margin:0}
.eyebrow{font-size:12px;letter-spacing:.06em;color:var(--muted)}
.lead{color:var(--muted);margin:6px 0 0;max-width:60ch}
.hero{display:flex;flex-direction:column;gap:14px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:2px}
.stat b{font-family:var(--f-display);font-size:28px;font-variant-numeric:tabular-nums;line-height:1.1}
.stat span{font-size:13px;color:var(--muted)}
.stat.ok b{color:var(--ok)} .stat.run b{color:var(--run)}
section{display:flex;flex-direction:column;gap:12px}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:6px 16px}
.allwait{list-style:none;margin:0;padding:0}
.allwait li{display:grid;grid-template-columns:auto 1fr;gap:2px 10px;padding:10px 0;border-top:1px solid var(--line);min-width:0}
.allwait li:first-child{border-top:0}
.allwait .app{font-size:12px;font-weight:500;padding:2px 8px;border-radius:999px;background:var(--accent-soft);color:var(--accent);align-self:start;white-space:nowrap}
.allwait .kind{font-size:12px;color:var(--muted);align-self:center}
.allwait .txt{grid-column:1/-1;min-width:0;overflow-wrap:anywhere}
.cards{display:flex;flex-direction:column;gap:12px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:10px}
.card summary{list-style:none;cursor:pointer;padding:14px 16px;display:flex;flex-direction:column;gap:8px}
.card summary::-webkit-details-marker{display:none}
.card summary:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:10px}
.card[open] summary{border-bottom:1px solid var(--line)}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.card-head h3::after{content:"‹";display:inline-block;margin-inline-start:8px;color:var(--muted);transition:transform .15s}
.card[open] .card-head h3::after{transform:rotate(-90deg)}
.bar{display:grid;grid-template-columns:repeat(var(--n),1fr);gap:3px}
.bar span{height:9px;border-radius:3px;background:var(--wait-soft)}
.bar .ok{background:var(--ok)} .bar .run{background:var(--run)}
.meter-row{display:flex;justify-content:space-between;gap:6px 14px;flex-wrap:wrap;font-size:13px;color:var(--muted)}
.meter-row b{color:var(--ink);font-variant-numeric:tabular-nums;font-weight:500}
.detail{padding:12px 16px 16px;display:flex;flex-direction:column;gap:10px}
.sub{font-size:15px;font-family:var(--f-display)}
.slices{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;border-top:1px solid var(--line)}
.slice{display:grid;grid-template-columns:52px 1fr auto;gap:10px;align-items:start;padding:12px 0;border-bottom:1px solid var(--line)}
.num{font-family:var(--f-display);font-size:18px;color:var(--muted);font-variant-numeric:tabular-nums;line-height:1.2;overflow-wrap:anywhere}
.slice h4{font-size:15px;font-weight:700}
.slice p{margin:2px 0 0;color:var(--muted);font-size:13px;min-width:0;overflow-wrap:anywhere}
.slice>div{min-width:0}
.steps{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}
.step{font-size:12px;padding:2px 8px;border-radius:999px;background:var(--wait-soft);color:var(--wait)}
.step.ok{background:var(--ok-soft);color:var(--ok)} .step.run{background:var(--run-soft);color:var(--run)}
.pill{font-size:12px;font-weight:500;padding:3px 10px;border-radius:999px;white-space:nowrap}
.pill.ok{background:var(--ok-soft);color:var(--ok)} .pill.run{background:var(--run-soft);color:var(--run)} .pill.wait{background:var(--wait-soft);color:var(--wait)}
.waits{margin:0;padding-inline-start:18px;display:flex;flex-direction:column;gap:6px;font-size:14px}
.waits .kind{font-weight:500;color:var(--accent)} .waits em{color:var(--muted);font-style:normal;font-size:13px}
.ext{color:var(--accent);font-weight:500;align-self:start}
code{font-size:12px;background:var(--wait-soft);padding:0 5px;border-radius:4px}
.foot{font-size:12px;color:var(--muted);margin:0}
@media (max-width:420px){.slice{grid-template-columns:40px 1fr}.slice .pill{grid-column:2;justify-self:start}.stat b{font-size:24px}}
@media (prefers-reduced-motion:reduce){.card-head h3::after{transition:none}}
</style>

<div class="wrap" dir="rtl" lang="he">
  <header class="hero">
    <div class="eyebrow">מצב הפיתוח · עודכן {{NOW}}</div>
    <h1>כל הצוותים</h1>
    <p class="lead">כל האפליקציות שעובדות בשיטת הצוות, במקום אחד. לחיצה על כרטיס פותחת את הפרוסות ואת מה שמחכה לך באותה אפליקציה.</p>
    <div class="stats">
      <div class="stat ok"><b>{{ACTIVE}}</b><span>צוותים פעילים</span></div>
      <div class="stat"><b>{{PAUSED}}</b><span>מושהים</span></div>
      <div class="stat run"><b>{{WAITCOUNT}}</b><span>מחכים לך</span></div>
    </div>
  </header>

  <section>
    <h2>מחכה לך</h2>
    <p class="foot">מטלות והחלטות מכל הצוותים. בנוסף יש {{QCOUNT}} שאלות פתוחות, בפירוט של כל אפליקציה — עליהן העבודה ממשיכה לפי ברירת המחדל.</p>
    <div class="panel"><ul class="allwait">{{WAITLIST}}</ul></div>
  </section>

  <section>
    <h2>האפליקציות</h2>
    <div class="cards">{{CARDS}}</div>
    <p class="foot">הפרוסות נקראות מ-team/board.md של כל צוות, מהענף שעודכן אחרון (main או ענף הבקרה). "בשרת" = מוזג ל-main ונפרס.</p>
  </section>
</div>
'''

if __name__ == '__main__':
    sys.exit(main())
