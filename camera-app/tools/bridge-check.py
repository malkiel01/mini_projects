#!/usr/bin/env python3
"""
בדיקת הגשר בלי מצלמה ובלי ffmpeg:
1. המדיניות (איזה מקטע עולה) — טהורה, על נתונים מדומים.
2. צימוד + heartbeat + פקודה מול שרת PHP אמיתי על תיקייה זמנית.
הרצה: python3 camera-app/tools/bridge-check.py
"""
import json, os, subprocess, sys, tempfile, time, urllib.request, shutil, importlib.util

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
spec = importlib.util.spec_from_file_location('bridge', os.path.join(ROOT, 'bridge', 'bridge.py'))
bridge = importlib.util.module_from_spec(spec); spec.loader.exec_module(bridge)

fails = 0
def check(cond, msg):
    global fails
    print(('✓ ' if cond else '✗ ') + msg)
    if not cond: fails += 1

# ── 1. מדיניות ──
cam = {'record_mode': 'motion', 'pre_seconds': 10, 'post_seconds': 20, 'schedule': []}
t0 = 1_000_000
check(bridge.decide_upload(cam, t0, t0 + 60, [], []) is None, 'תנועה: בלי אירועים — לא עולה')
check(bridge.decide_upload(cam, t0, t0 + 60, [(t0 + 30, 'motion')], []) == 'motion', 'תנועה: אירוע בתוך המקטע — עולה')
check(bridge.decide_upload(cam, t0, t0 + 60, [(t0 + 65, 'person')], []) == 'person', 'תנועה: אדם 5 שניות אחרי הסוף — נתפס ב-pre, ומסומן אדם')
check(bridge.decide_upload(cam, t0, t0 + 60, [(t0 - 15, 'motion')], []) == 'motion', 'תנועה: אירוע 15 שניות לפני — נתפס ב-post')
check(bridge.decide_upload(cam, t0, t0 + 60, [(t0 - 25, 'motion')], []) is None, 'תנועה: 25 שניות לפני — מחוץ ל-post, לא עולה')
check(bridge.decide_upload(cam, t0, t0 + 60, [], [[t0 + 10, None]]) == 'manual', 'REC לחוץ גובר על מצב תנועה')
check(bridge.decide_upload(cam, t0, t0 + 60, [], [[t0 - 100, t0 - 50]]) is None, 'REC שנגמר לפני המקטע — לא')
check(bridge.decide_upload({'record_mode': 'continuous'}, t0, t0 + 60, [], []) == 'continuous', 'רציף — תמיד')
check(bridge.decide_upload({'record_mode': 'manual'}, t0, t0 + 60, [(t0, 'person')], []) is None, 'ידני: גם אדם לא מעלה בלי REC')

# לו"ז: חלון 22:00–06:00 בכל הימים; בודקים 23:00 ו-03:00 ו-12:00 בזמן מקומי.
def local_ts(h):
    lt = list(time.localtime(t0)); lt[3], lt[4], lt[5] = h, 0, 0
    return time.mktime(time.struct_time(lt))
sched = {'record_mode': 'schedule', 'schedule': [{'days': [0, 1, 2, 3, 4, 5, 6], 'from': '22:00', 'to': '06:00'}]}
check(bridge.in_schedule(sched['schedule'], local_ts(23)), 'לו"ז חוצה חצות: 23:00 בפנים')
check(bridge.in_schedule(sched['schedule'], local_ts(3)), 'לו"ז חוצה חצות: 03:00 בפנים')
check(not bridge.in_schedule(sched['schedule'], local_ts(12)), 'לו"ז חוצה חצות: 12:00 בחוץ')
check(bridge.decide_upload(sched, local_ts(23), local_ts(23) + 60, [], []) == 'schedule', 'מקטע בתוך הלו"ז עולה')
check(bridge.decide_upload(sched, local_ts(12), local_ts(12) + 60, [], []) is None, 'מקטע מחוץ ללו"ז לא עולה')
check(bridge.parse_segment_name('20260922_141530.mp4') == time.mktime(time.strptime('20260922_141530', '%Y%m%d_%H%M%S')), 'שם מקטע → זמן')
check(bridge.parse_segment_name('junk.mp4') is None, 'שם לא תקין → None')

# ── 2. מול שרת אמיתי ──
tmp = tempfile.mkdtemp()
for d in ('media', 'inbox', 'live'): os.makedirs(os.path.join(tmp, d))
with open(os.path.join(tmp, 'pre.php'), 'w') as fh:
    fh.write(f"<?php define('DB_FILE','{tmp}/db.sqlite'); define('MEDIA_DIR','{tmp}/media'); define('INBOX_DIR','{tmp}/inbox'); define('LIVE_DIR','{tmp}/live'); define('SECRET_FILE','{tmp}/secret.key');")
port = 8793
srv = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-d', f'auto_prepend_file={tmp}/pre.php', '-t', ROOT], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(0.8)
base = f'http://127.0.0.1:{port}/'
try:
    import http.cookiejar
    cj = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    def api(action, data):
        req = urllib.request.Request(base + 'api.php?action=' + action, data=json.dumps(data).encode(), headers={'Content-Type': 'application/json'})
        try:
            with opener.open(req) as r: return json.loads(r.read())
        except urllib.error.HTTPError as e:
            return json.loads(e.read())
    api('setup', {'username': 'admin', 'password': 'secret123'})
    cam_id = api('camera-save', {'name': 'חצר', 'host': '192.168.1.118', 'password': 'pw', 'brand': 'reolink', 'has_ptz': 1})['camera']['id']
    b = api('bridge-create', {'name': 'בית'})
    conf = os.path.join(tmp, 'config.json')
    cfg = bridge.pair(base, b['pair_code'], conf, os.path.join(tmp, 'work'))
    check(len(cfg['token']) == 64 and os.path.exists(conf), 'צימוד שומר תצורה עם טוקן')
    api('camera-save', {'id': cam_id, 'bridge_id': b['id']})
    api('command', {'id': cam_id, 'type': 'ptz', 'payload': {'dir': 'left'}})
    br = bridge.Bridge(cfg, conf)
    br.heartbeat()
    check('cam-1' in br.workers, 'heartbeat יוצר worker למצלמה המשויכת')
    check(br.workers['cam-1'].cam['password'] == 'pw', 'ה-worker קיבל את הסיסמה')
    time.sleep(2.5)   # הפקודה רצה ב-thread; המצלמה לא קיימת → נכשלת ומדווחת
    st = api('command-status', {'id': 1})['command']
    check(st['status'] == 'failed' and 'error' in (st['result'] or {}), 'פקודה למצלמה שאינה ברשת מדווחת כישלון ברור: ' + str((st['result'] or {}).get('error', ''))[:60])
    br.heartbeat()
    lst = api('bridge-list', {})['bridges'][0]
    check(lst['online'] and lst['version'] == bridge.VERSION and 'ffmpeg' in lst['info'], 'השרת רואה את הגשר מחובר, עם גרסה ומידע')
    # מצלמה מוסרת מהגשר → ה-worker נסגר.
    api('camera-save', {'id': cam_id, 'bridge_id': None})
    br.heartbeat()
    check('cam-1' not in br.workers, 'הסרת שיוך סוגרת את ה-worker')
    for w in br.workers.values(): w.shutdown()
finally:
    srv.kill(); shutil.rmtree(tmp, ignore_errors=True)

print('\n' + ('הכול עבר.' if not fails else f'{fails} כשלונות'))
sys.exit(1 if fails else 0)
