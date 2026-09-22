#!/usr/bin/env python3
"""
הגשר — התוכנה שיושבת ברשת של המצלמות.

התפקיד: להיות הקושחה שאין לנו. המצלמה לא יודעת לדבר עם השרת שלנו;
הגשר יושב לידה, קורא ממנה ב-RTSP ובממשק ה-HTTP של Reolink, ומדבר עם
השרת — תמיד בכיוון החוצה, ב-HTTPS. שום פורט לא נפתח בראוטר.

מה הוא עושה, לכל מצלמה שמשויכת אליו:
  * מקליט תמיד למקטעים מקומיים (ffmpeg, בלי קידוד מחדש), ומעלה לשרת
    רק את המקטעים שהמדיניות אומרת: רציף / לפי לו"ז / לפי תנועה-ואדם
    (עם שניות לפני ואחרי) / ידני (כפתור REC בממשק).
  * מזהה תנועה ואדם דרך ה-API של Reolink (סקירה כל שנייה).
  * משדר חי ב-HLS כשמישהו צופה, ומפסיק כשאף אחד לא.
  * מבצע פקודות מהממשק: סיבוב, זום, עמדות שמורות, צילום.

תלויות: Python 3.9+, ffmpeg (ו-ffprobe). בלי ספריות pip — urllib בלבד,
כדי שההתקנה על רספברי תהיה שתי פקודות.

הרצה:
  bridge.py --server https://.../camera-app/ --pair ABC123   # פעם אחת: צימוד
  bridge.py                                                  # ריצה (systemd)
"""

import argparse
import json
import os
import shutil
import signal
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from datetime import datetime, timezone

VERSION = '1.0.0'
DEFAULT_CONFIG = '/etc/camera-bridge/config.json'
HEARTBEAT_SECONDS = 5
FFMPEG = shutil.which('ffmpeg')
FFPROBE = shutil.which('ffprobe')


def log(*a):
    print(datetime.now().strftime('%H:%M:%S'), *a, flush=True)


def now_iso():
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def iso(ts):
    return datetime.fromtimestamp(ts, timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


# ───────── HTTP אל השרת ─────────

class Server:
    def __init__(self, base, token=None):
        self.base = base.rstrip('/') + '/'
        self.token = token

    def api(self, action, data=None, timeout=20):
        body = json.dumps(data or {}).encode()
        req = urllib.request.Request(self.base + 'api.php?action=' + action, data=body,
                                     headers={'Content-Type': 'application/json'})
        if self.token:
            req.add_header('X-Bridge-Token', self.token)
        with urllib.request.urlopen(req, timeout=timeout) as r:
            j = json.loads(r.read().decode())
        if not j.get('success'):
            raise RuntimeError(j.get('error', 'שגיאה'))
        return j

    def upload(self, kind, fields, files, timeout=300):
        """multipart ידני: files = [(field, filename, bytes_or_path, mime)]."""
        boundary = '----bridge' + uuid.uuid4().hex
        parts = []
        for k, v in fields.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        for field, fname, data, mime in files:
            if isinstance(data, str):
                with open(data, 'rb') as fh:
                    data = fh.read()
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{fname}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + data + b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        body = b''.join(parts)
        req = urllib.request.Request(self.base + 'upload.php', data=body, headers={
            'Content-Type': f'multipart/form-data; boundary={boundary}', 'X-Bridge-Token': self.token or ''})
        req.add_header('Content-Length', str(len(body)))
        with urllib.request.urlopen(req, timeout=timeout) as r:
            j = json.loads(r.read().decode())
        if not j.get('success'):
            raise RuntimeError(j.get('error', 'ההעלאה נכשלה'))
        return j


# ───────── Reolink HTTP API ─────────

# המצלמה ברשת המקומית — לעולם לא דרך proxy, גם אם הסביבה מגדירה אחד.
LAN_OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}))


class Reolink:
    """המינימום שצריך: כניסה, מצב תנועה/AI, PTZ, עמדות, צילום."""

    def __init__(self, cam):
        self.cam = cam
        scheme = 'https' if cam.get('http_port') == 443 else 'http'
        self.base = f"{scheme}://{cam['host']}:{cam['http_port']}/cgi-bin/api.cgi"
        self.token = None
        self.token_at = 0

    def _call(self, cmds, use_token=True, timeout=6):
        q = {'cmd': cmds[0]['cmd']}
        if use_token:
            if not self.token or time.time() - self.token_at > 3000:
                self.login()
            q['token'] = self.token
        url = self.base + '?' + urllib.parse.urlencode(q)
        req = urllib.request.Request(url, data=json.dumps(cmds).encode(), headers={'Content-Type': 'application/json'})
        with LAN_OPENER.open(req, timeout=timeout) as r:
            return json.loads(r.read().decode())

    def login(self):
        res = self._call([{'cmd': 'Login', 'action': 0, 'param': {'User': {'userName': self.cam['username'], 'password': self.cam['password']}}}], use_token=False)
        try:
            self.token = res[0]['value']['Token']['name']
            self.token_at = time.time()
        except (KeyError, IndexError, TypeError):
            raise RuntimeError('Reolink: כניסה נכשלה (סיסמה?)')

    def detection(self):
        """מחזיר set של מה שמזוהה עכשיו: {'motion','person','pet','vehicle'}."""
        found = set()
        res = self._call([{'cmd': 'GetMdState', 'action': 0, 'param': {'channel': 0}},
                          {'cmd': 'GetAiState', 'action': 0, 'param': {'channel': 0}}])
        for r in res:
            v = r.get('value') or {}
            if r.get('cmd') == 'GetMdState' and v.get('state'):
                found.add('motion')
            if r.get('cmd') == 'GetAiState':
                for k, name in (('people', 'person'), ('dog_cat', 'pet'), ('vehicle', 'vehicle')):
                    st = v.get(k)
                    if isinstance(st, dict) and st.get('alarm_state'):
                        found.add(name)
                        found.add('motion')
        return found

    def ptz(self, op, speed=32, pos=None):
        param = {'channel': 0, 'op': op, 'speed': speed}
        if pos is not None:
            param['id'] = pos
        return self._call([{'cmd': 'PtzCtrl', 'action': 0, 'param': param}])

    def preset_set(self, pid, name):
        return self._call([{'cmd': 'SetPtzPreset', 'action': 0, 'param': {'PtzPreset': {'channel': 0, 'enable': 1, 'id': pid, 'name': name}}}])

    def snapshot(self):
        if not self.token or time.time() - self.token_at > 3000:
            self.login()
        url = self.base + '?' + urllib.parse.urlencode({'cmd': 'Snap', 'channel': 0, 'rs': uuid.uuid4().hex[:16], 'token': self.token})
        with LAN_OPENER.open(url, timeout=15) as r:
            data = r.read()
        if not data.startswith(b'\xff\xd8'):
            raise RuntimeError('Reolink: Snap לא החזיר JPEG')
        return data


PTZ_OPS = {'up': 'Up', 'down': 'Down', 'left': 'Left', 'right': 'Right',
           'upleft': 'LeftUp', 'upright': 'RightUp', 'downleft': 'LeftDown', 'downright': 'RightDown'}


# ───────── מדיניות: איזה מקטע עולה ─────────

def in_schedule(windows, ts):
    """האם הרגע ts (מקומי) בתוך אחד מחלונות הלו"ז. חלון שחוצה חצות תקין."""
    lt = time.localtime(ts)
    dow = (lt.tm_wday + 1) % 7   # Python: שני=0 → אצלנו ראשון=0
    minutes = lt.tm_hour * 60 + lt.tm_min
    for w in windows or []:
        try:
            fh, fm = map(int, w['from'].split(':'))
            th, tm = map(int, w['to'].split(':'))
        except (KeyError, ValueError):
            continue
        f, t = fh * 60 + fm, th * 60 + tm
        days = w.get('days') or []
        if f <= t:
            if dow in days and f <= minutes < t:
                return True
        else:   # חוצה חצות: 22:00–06:00
            prev = (dow - 1) % 7
            if (dow in days and minutes >= f) or (prev in days and minutes < t):
                return True
    return False


def decide_upload(cam, t0, t1, events, manual_spans):
    """
    האם המקטע [t0,t1] עולה, ולמה. events: רשימת (ts, kind) של זיהויים;
    manual_spans: רשימת (start, end|None) של הקלטה ידנית.
    ההקלטה הידנית גוברת על כל מצב — REC לחוץ = מקליטים.
    """
    for s, e in manual_spans:
        if s <= t1 and (e is None or e >= t0):
            return 'manual'
    mode = cam.get('record_mode', 'motion')
    if mode == 'continuous':
        return 'continuous'
    if mode == 'schedule':
        # מספיק שרגע אחד במקטע בתוך החלון.
        step = max(30, int((t1 - t0) / 4) or 30)
        ts = t0
        while ts <= t1:
            if in_schedule(cam.get('schedule'), ts):
                return 'schedule'
            ts += step
        return None
    if mode == 'motion':
        pre, post = cam.get('pre_seconds', 10), cam.get('post_seconds', 20)
        best = None
        for ts, kind in events:
            # אירוע ב-ts משפיע על הטווח [ts-pre, ts+post]; חופף למקטע?
            if ts - pre <= t1 and ts + post >= t0:
                if kind != 'motion':
                    return kind
                best = 'motion'
        return best
    return None   # manual: רק כשלחוץ


def parse_segment_name(name):
    """20260922_141530.mp4 (שעון מקומי של הקופסה) → epoch, או None."""
    base = os.path.basename(name)
    if len(base) < 15:
        return None
    try:
        t = time.strptime(base[:15], '%Y%m%d_%H%M%S')
        return time.mktime(t)
    except ValueError:
        return None


# ───────── עובד לכל מצלמה ─────────

class CameraWorker:
    def __init__(self, bridge, cam):
        self.bridge = bridge
        self.cam = cam
        self.key = cam['key']
        self.dir = os.path.join(bridge.work_dir, self.key)
        self.rec_dir = os.path.join(self.dir, 'rec')
        self.live_dir = os.path.join(self.dir, 'live')
        os.makedirs(self.rec_dir, exist_ok=True)
        os.makedirs(self.live_dir, exist_ok=True)
        self.rec_proc = None
        self.rec_started = 0
        self.live_proc = None
        self.live_last_wanted = 0
        self.live_uploaded = set()
        self.reolink = None
        self.events = []          # (ts, kind)
        self.pending_events = []  # לדיווח לשרת ב-heartbeat
        self.active = set()
        self.manual_spans = []
        self.online = False
        self.last_ok = 0
        self.last_error = ''
        self.stop = False
        self.lock = threading.Lock()
        self.threads = [threading.Thread(target=self._loop, daemon=True, name='cam-' + self.key)]
        for t in self.threads:
            t.start()

    # --- תצורה חדשה מהשרת ---
    def update(self, cam):
        with self.lock:
            restart = any(cam.get(k) != self.cam.get(k) for k in ('stream_main', 'record_audio'))
            manual_now = bool(cam.get('manual_recording'))
            manual_was = bool(self.cam.get('manual_recording'))
            if manual_now and not manual_was:
                self.manual_spans.append([time.time(), None])
            if manual_was and not manual_now and self.manual_spans and self.manual_spans[-1][1] is None:
                self.manual_spans[-1][1] = time.time()
            if cam.get('live_wanted'):
                self.live_last_wanted = time.time()
            self.cam = cam
        if restart:
            self._stop_proc('rec')

    def report(self):
        with self.lock:
            ev, self.pending_events = self.pending_events, []
        return {'online': self.online, 'events': ev, 'error': self.last_error, 'live': self.live_proc is not None,
                'recording': self.rec_proc is not None}

    # --- לולאת העבודה ---
    def _loop(self):
        tick = 0
        while not self.stop:
            try:
                self._ensure_recorder()
                self._ensure_live()
                if tick % 1 == 0:
                    self._poll_detection()
                if tick % 3 == 0:
                    self._flush_segments()
                if tick % 1 == 0:
                    self._push_live()
            except Exception as e:   # noqa
                self.last_error = str(e)[:200]
                log(self.key, 'שגיאה:', e)
            tick += 1
            time.sleep(1)

    # --- ffmpeg: הקלטה למקטעים ---
    def _ensure_recorder(self):
        if not FFMPEG or not self.cam.get('stream_main'):
            return
        if self.rec_proc and self.rec_proc.poll() is None:
            # מקטע חדש בזמן סביר = המצלמה חיה.
            newest = self._newest_mtime(self.rec_dir)
            seg = self.bridge.settings.get('segment_seconds', 60)
            fresh_segment = bool(newest) and time.time() - newest < seg * 2 + 15
            just_started = time.time() - self.rec_started < seg + 15
            self.online = fresh_segment or just_started
            return
        if self.rec_proc:
            log(self.key, 'ffmpeg (הקלטה) נפל, קוד', self.rec_proc.returncode)
            self.online = False
            time.sleep(min(30, 3 + 2 * self.bridge.failures.get(self.key, 0)))
            self.bridge.failures[self.key] = self.bridge.failures.get(self.key, 0) + 1
        seg = self.bridge.settings.get('segment_seconds', 60)
        cmd = [FFMPEG, '-hide_banner', '-loglevel', 'error', '-rtsp_transport', 'tcp', '-timeout', '10000000',
               '-i', self.cam['stream_main'], '-c:v', 'copy']
        cmd += (['-c:a', 'aac', '-b:a', '64k'] if self.cam.get('record_audio') else ['-an'])
        cmd += ['-f', 'segment', '-segment_time', str(seg), '-segment_format', 'mp4',
                '-segment_format_options', 'movflags=+faststart', '-reset_timestamps', '1', '-strftime', '1',
                os.path.join(self.rec_dir, '%Y%m%d_%H%M%S.mp4')]
        self.rec_proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.rec_started = time.time()
        log(self.key, 'הקלטה למקטעים החלה')

    def _newest_mtime(self, d):
        try:
            return max((os.path.getmtime(os.path.join(d, f)) for f in os.listdir(d)), default=0)
        except OSError:
            return 0

    def _stop_proc(self, which):
        p = self.rec_proc if which == 'rec' else self.live_proc
        if p and p.poll() is None:
            p.send_signal(signal.SIGINT)
            try:
                p.wait(5)
            except subprocess.TimeoutExpired:
                p.kill()
        if which == 'rec':
            self.rec_proc = None
        else:
            self.live_proc = None

    # --- מקטעים שהסתיימו: להעלות או למחוק ---
    def _flush_segments(self):
        files = sorted(f for f in os.listdir(self.rec_dir) if f.endswith('.mp4'))
        if not files:
            return
        newest = files[-1]   # ffmpeg עדיין כותב אליו
        seg = self.bridge.settings.get('segment_seconds', 60)
        post = self.cam.get('post_seconds', 20)
        now = time.time()
        for f in files:
            path = os.path.join(self.rec_dir, f)
            if f == newest and now - os.path.getmtime(path) < 3:
                continue
            t0 = parse_segment_name(f)
            if t0 is None:
                os.remove(path)
                continue
            dur = self._duration(path) or seg
            t1 = t0 + dur
            # מחכים post שניות אחרי סוף המקטע — אירוע שיגיע עוד רגע עדיין יכול "להציל" אותו.
            if now < t1 + post + 2 and self.cam.get('record_mode') == 'motion':
                continue
            with self.lock:
                trigger = decide_upload(self.cam, t0, t1, self.events, self.manual_spans)
            if trigger and os.path.getsize(path) > 1000:
                try:
                    self._upload_segment(path, t0, dur, trigger)
                except Exception as e:   # noqa
                    self.last_error = 'העלאה: ' + str(e)[:150]
                    log(self.key, 'העלאה נכשלה, ננסה שוב:', e)
                    if now - t0 < 3600:
                        continue   # נשאר לניסיון הבא
            os.remove(path)
        # אירועים ישנים לא צריכים להישאר בזיכרון.
        with self.lock:
            self.events = [(ts, k) for ts, k in self.events if now - ts < 3600]
            self.manual_spans = [s for s in self.manual_spans if s[1] is None or now - s[1] < 3600]

    def _duration(self, path):
        if not FFPROBE:
            return None
        try:
            out = subprocess.run([FFPROBE, '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', path],
                                 capture_output=True, text=True, timeout=15).stdout.strip()
            return float(out) if out else None
        except (subprocess.SubprocessError, ValueError):
            return None

    def _poster(self, path):
        if not FFMPEG:
            return None
        out = path + '.jpg'
        try:
            subprocess.run([FFMPEG, '-hide_banner', '-loglevel', 'error', '-y', '-ss', '1', '-i', path, '-frames:v', '1',
                            '-vf', 'scale=480:-2', '-q:v', '5', out], capture_output=True, timeout=30)
            if os.path.exists(out) and os.path.getsize(out) > 0:
                with open(out, 'rb') as fh:
                    return fh.read()
        except subprocess.SubprocessError:
            pass
        finally:
            if os.path.exists(out):
                os.remove(out)
        return None

    def _upload_segment(self, path, t0, dur, trigger):
        files = [('file', os.path.basename(path), path, 'video/mp4')]
        poster = self._poster(path)
        if poster:
            files.append(('poster', 'poster.jpg', poster, 'image/jpeg'))
        self.bridge.server.upload('recording', {'camera': self.key, 'started_at': iso(t0), 'duration_s': f'{dur:.1f}', 'trigger': trigger}, files)
        log(self.key, f'הועלה מקטע {os.path.basename(path)} ({trigger}, {dur:.0f}ש)')

    # --- זיהוי תנועה/אדם ---
    def _poll_detection(self):
        if self.cam.get('brand') != 'reolink' or not self.cam.get('host') or not self.cam.get('password'):
            return
        if self.reolink is None:
            self.reolink = Reolink(self.cam)
        try:
            found = self.reolink.detection()
            self.last_ok = time.time()
            if not self.rec_proc:
                self.online = True
        except Exception as e:   # noqa
            if time.time() - self.last_ok > 60:
                self.reolink = None   # כניסה מחדש בפעם הבאה
            raise RuntimeError('Reolink API: ' + str(e)[:120])
        now = time.time()
        with self.lock:
            for kind in found:
                if kind not in self.active:
                    self.pending_events.append({'kind': kind, 'state': 'start'})
                    log(self.key, 'זיהוי:', kind)
                self.events.append((now, kind))
            for kind in self.active - found:
                self.pending_events.append({'kind': kind, 'state': 'end'})
            self.active = found

    # --- חי ---
    def _ensure_live(self):
        wanted = time.time() - self.live_last_wanted < 30
        if wanted and FFMPEG and (self.live_proc is None or self.live_proc.poll() is not None):
            src = self.cam.get('stream_sub') or self.cam.get('stream_main')
            if not src:
                return
            for f in os.listdir(self.live_dir):
                os.remove(os.path.join(self.live_dir, f))
            self.live_uploaded = set()
            hls = self.bridge.settings.get('live_segment_seconds', 2)
            cmd = [FFMPEG, '-hide_banner', '-loglevel', 'error', '-rtsp_transport', 'tcp', '-i', src, '-c:v', 'copy', '-an',
                   '-f', 'hls', '-hls_time', str(hls), '-hls_list_size', '6', '-hls_flags', 'delete_segments+independent_segments',
                   '-hls_segment_filename', os.path.join(self.live_dir, 'seg%06d.ts'), os.path.join(self.live_dir, 'live.m3u8')]
            self.live_proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            log(self.key, 'שידור חי החל')
        elif not wanted and self.live_proc is not None:
            self._stop_proc('live')
            log(self.key, 'שידור חי נעצר — אין צופים')

    def _push_live(self):
        if self.live_proc is None:
            return
        pl = os.path.join(self.live_dir, 'live.m3u8')
        if not os.path.exists(pl):
            return
        with open(pl, 'rb') as fh:
            playlist = fh.read()
        names = [l.strip() for l in playlist.decode(errors='ignore').splitlines() if l.strip().endswith('.ts')]
        files = []
        for n in names:
            p = os.path.join(self.live_dir, n)
            if n not in self.live_uploaded and os.path.exists(p):
                files.append(('file[]', n, p, 'video/mp2t'))
        if not files and playlist == getattr(self, '_last_pl', None):
            return
        files.append(('file[]', 'live.m3u8', playlist, 'application/vnd.apple.mpegurl'))
        # השרת מצפה ל-name[] במקביל ל-file[].
        j = self._upload_live({'camera': self.key}, files)
        self._last_pl = playlist
        for f in files:
            self.live_uploaded.add(f[1])
        if j.get('live_wanted'):
            self.live_last_wanted = time.time()

    def _upload_live(self, fields, files):
        srv = self.bridge.server
        boundary = '----bridge' + uuid.uuid4().hex
        parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="kind"\r\n\r\nlive\r\n'.encode()]
        for k, v in fields.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        for field, fname, data, mime in files:
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="name[]"\r\n\r\n{fname}\r\n'.encode())
            if isinstance(data, str):
                with open(data, 'rb') as fh:
                    data = fh.read()
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{fname}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + data + b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        body = b''.join(parts)
        req = urllib.request.Request(srv.base + 'upload.php', data=body, headers={
            'Content-Type': f'multipart/form-data; boundary={boundary}', 'X-Bridge-Token': srv.token or ''})
        with urllib.request.urlopen(req, timeout=20) as r:
            j = json.loads(r.read().decode())
        if not j.get('success'):
            raise RuntimeError(j.get('error', 'live'))
        return j

    # --- פקודות ---
    def command(self, cmd):
        t, p = cmd['type'], cmd.get('payload') or {}
        if t in ('ptz', 'ptz_stop', 'zoom', 'preset_goto', 'preset_set', 'snapshot'):
            if self.cam.get('brand') != 'reolink':
                raise RuntimeError('פקודות שליטה נתמכות כרגע במצלמות Reolink בלבד')
            if self.reolink is None:
                self.reolink = Reolink(self.cam)
        if t == 'ptz':
            op = PTZ_OPS.get(p.get('dir', ''))
            if not op:
                raise RuntimeError('כיוון לא מוכר')
            self.reolink.ptz(op, int(p.get('speed', 32)))
            return {'moved': p['dir']}
        if t == 'ptz_stop':
            self.reolink.ptz('Stop')
            return {'stopped': True}
        if t == 'zoom':
            self.reolink.ptz('ZoomInc' if p.get('dir') == 'in' else 'ZoomDec', 32)
            return {'zoom': p.get('dir')}
        if t == 'preset_goto':
            self.reolink.ptz('ToPos', 32, int(p.get('id', 1)))
            return {'preset': p.get('id')}
        if t == 'preset_set':
            self.reolink.preset_set(int(p.get('id', 1)), f"pos{p.get('id', 1)}")
            return {'saved': p.get('id')}
        if t == 'snapshot':
            data = self.reolink.snapshot()
            j = self.bridge.server.upload('recording', {'camera': self.key, 'started_at': now_iso(), 'trigger': 'manual'},
                                          [('file', 'snap.jpg', data, 'image/jpeg')])
            return {'recording_id': j.get('id')}
        if t == 'probe':
            return self._probe()
        raise RuntimeError('פקודה לא מוכרת: ' + t)

    def _probe(self):
        out = {'ffmpeg': bool(FFMPEG), 'host_reachable': False, 'rtsp': None}
        try:
            with socket.create_connection((self.cam['host'], int(self.cam.get('http_port', 80))), timeout=3):
                out['host_reachable'] = True
        except OSError:
            pass
        if FFPROBE and self.cam.get('stream_main'):
            try:
                r = subprocess.run([FFPROBE, '-v', 'error', '-rtsp_transport', 'tcp', '-show_entries', 'stream=codec_name,width,height',
                                    '-of', 'json', self.cam['stream_main']], capture_output=True, text=True, timeout=20)
                out['rtsp'] = json.loads(r.stdout or '{}').get('streams')
            except (subprocess.SubprocessError, ValueError) as e:
                out['rtsp'] = 'שגיאה: ' + str(e)[:100]
        return out

    def shutdown(self):
        self.stop = True
        self._stop_proc('rec')
        self._stop_proc('live')


# ───────── הגשר ─────────

class Bridge:
    def __init__(self, config, config_path):
        self.config = config
        self.config_path = config_path
        self.server = Server(config['server'], config['token'])
        self.work_dir = config.get('work_dir') or '/var/lib/camera-bridge'
        os.makedirs(self.work_dir, exist_ok=True)
        self.workers = {}
        self.settings = {'segment_seconds': 60, 'live_segment_seconds': 2}
        self.failures = {}
        self.running = True

    def info(self):
        info = {'ffmpeg': bool(FFMPEG), 'python': sys.version.split()[0], 'platform': sys.platform}
        try:
            info['load'] = round(os.getloadavg()[0], 2)
        except (OSError, AttributeError):
            pass
        try:
            st = shutil.disk_usage(self.work_dir)
            info['disk_free_gb'] = round(st.free / 1e9, 1)
        except OSError:
            pass
        return info

    def local_ip(self):
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.connect(('8.8.8.8', 80))
            ip = s.getsockname()[0]
            s.close()
            return ip
        except OSError:
            return ''

    def heartbeat(self):
        report = {'version': VERSION, 'local_ip': self.local_ip(), 'info': self.info(),
                  'cameras': {k: w.report() for k, w in self.workers.items()}}
        res = self.server.api('bridge-heartbeat', report)
        self.settings.update(res.get('settings') or {})
        seen = set()
        for cam in res.get('cameras', []):
            seen.add(cam['key'])
            if cam['key'] in self.workers:
                self.workers[cam['key']].update(cam)
            else:
                log('מצלמה חדשה:', cam['key'], cam.get('name'))
                self.workers[cam['key']] = CameraWorker(self, cam)
        for k in list(self.workers):
            if k not in seen:
                log('מצלמה הוסרה:', k)
                self.workers.pop(k).shutdown()
        for cmd in res.get('commands', []):
            threading.Thread(target=self.run_command, args=(cmd,), daemon=True).start()

    def run_command(self, cmd):
        w = None
        for x in self.workers.values():
            if x.cam.get('id') == cmd.get('camera_id'):
                w = x
        try:
            if w is None:
                raise RuntimeError('המצלמה אינה בגשר הזה')
            result = w.command(cmd)
            ok = True
        except Exception as e:   # noqa
            result, ok = {'error': str(e)[:200]}, False
            log('פקודה נכשלה:', cmd.get('type'), e)
        try:
            self.server.api('bridge-command-done', {'id': cmd['id'], 'ok': ok, 'result': result})
        except Exception as e:   # noqa
            log('דיווח פקודה נכשל:', e)

    def run(self):
        log(f'גשר {VERSION} — שרת {self.server.base} — ffmpeg: {"כן" if FFMPEG else "לא!"}')
        if not FFMPEG:
            log('אזהרה: ffmpeg לא נמצא. אין הקלטה ואין חי; רק פקודות וזיהוי. התקן: sudo apt install ffmpeg')
        backoff = 5
        while self.running:
            try:
                self.heartbeat()
                backoff = 5
            except urllib.error.HTTPError as e:
                if e.code == 401:
                    log('השרת לא מזהה את הגשר (401). יש לצמד מחדש: bridge.py --server ... --pair <קוד>')
                else:
                    log('heartbeat נכשל:', e)
                time.sleep(backoff)
                backoff = min(60, backoff * 2)
                continue
            except Exception as e:   # noqa
                log('heartbeat נכשל:', e)
                time.sleep(backoff)
                backoff = min(60, backoff * 2)
                continue
            time.sleep(HEARTBEAT_SECONDS)

    def shutdown(self, *_):
        self.running = False
        for w in self.workers.values():
            w.shutdown()
        log('הגשר נעצר')
        sys.exit(0)


# ───────── צימוד וכניסה ─────────

def pair(server_url, code, config_path, work_dir):
    srv = Server(server_url)
    tmp = Bridge.__new__(Bridge)
    tmp.work_dir = work_dir or '/var/lib/camera-bridge'
    res = srv.api('bridge-pair', {'code': code.strip().upper(), 'info': {'version': VERSION, 'local_ip': Bridge.local_ip(tmp),
                                                                        'ffmpeg': bool(FFMPEG), 'platform': sys.platform}})
    cfg = {'server': srv.base, 'token': res['token'], 'bridge_id': res['bridge_id'], 'name': res.get('name'),
           'work_dir': tmp.work_dir}
    os.makedirs(os.path.dirname(config_path) or '.', exist_ok=True)
    with open(config_path, 'w') as fh:
        json.dump(cfg, fh, indent=2, ensure_ascii=False)
    os.chmod(config_path, 0o600)
    log(f'צומד כ"{cfg["name"]}" (#{cfg["bridge_id"]}). התצורה נשמרה ב-{config_path}')
    return cfg


def main():
    ap = argparse.ArgumentParser(description='גשר המצלמות')
    ap.add_argument('--config', default=os.environ.get('BRIDGE_CONFIG', DEFAULT_CONFIG))
    ap.add_argument('--server', help='כתובת camera-app בשרת (לצימוד)')
    ap.add_argument('--pair', help='קוד צימוד מהמסך "גשרים" (לצימוד)')
    ap.add_argument('--work-dir', help='תיקיית עבודה למקטעים (ברירת מחדל /var/lib/camera-bridge)')
    ap.add_argument('--once', action='store_true', help='heartbeat אחד ויציאה (לבדיקה)')
    a = ap.parse_args()

    if a.pair:
        if not a.server:
            ap.error('--pair דורש --server')
        pair(a.server, a.pair, a.config, a.work_dir)
        return
    if not os.path.exists(a.config):
        ap.error(f'אין תצורה ב-{a.config}. קודם צימוד: --server <url> --pair <קוד>')
    with open(a.config) as fh:
        cfg = json.load(fh)
    if a.work_dir:
        cfg['work_dir'] = a.work_dir
    b = Bridge(cfg, a.config)
    signal.signal(signal.SIGTERM, b.shutdown)
    signal.signal(signal.SIGINT, b.shutdown)
    if a.once:
        b.heartbeat()
        print(json.dumps({'cameras': list(b.workers), 'settings': b.settings}, ensure_ascii=False))
        b.shutdown()
        return
    b.run()


if __name__ == '__main__':
    main()
