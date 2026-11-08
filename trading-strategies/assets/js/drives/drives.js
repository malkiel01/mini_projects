/**
 * מודול הכוננים — צד הדפדפן של האפליקציה. עותק מסונכרן: לא לערוך כאן. המקור: mbe-cloud/drives/src/js/.
 *
 *   import { createDrives } from './drives/drives.js';
 *   const drives = createDrives({ endpoint: 'api.php' });     // ה-api.php של האפליקציה (שם רץ Drives.php)
 *
 *   await drives.status()          → { suite: { connected, name, cloud_id, scope, layers: { files: true, libs: false, … } } }
 *        layers — רק השכבות שהאפליקציה הצהירה (apps.json), ולכל אחת: דלוקה או לא. מתג לכל שכבה (spec-0-vision.md 7.3).
 *   await drives.connect()         — מעבר למסך האישור בענן, לכל השכבות שהוצהרו; חוזרים ל-back עם ?cloud=connected | denied
 *   await drives.connect({ layers: ['libs'] }) — הדלקת שכבה: מסך האישור מציג רק אותה, והיא נוספת לאותו חיבור
 *   await drives.disconnect({ layers: ['libs'] }) → status — כיבוי שכבה, מיד. כיבוי של השכבה האחרונה = ניתוק
 *   await drives.disconnect()
 *
 *   const s = drives.suite;        — ענן MBE. בלי חיבור — כל קריאה זורקת DriveError עם code 'not-connected'.
 *   await s.list(folder?)          → { folder, path, items:[node] }
 *   await s.stat(id) · s.read(id) → Blob · s.mkdir(name, parent?) · s.rename(id, name) · s.move(id, parent) · s.trash(id)
 *   await s.write(name, blob, { parent, ifRev, onConflict })                  → node  (עד 4MB)
 *   await s.upload(file, { parent, ifRev, onConflict, onProgress, signal })  → node  (בחלקים, כל גודל)
 *   await s.quota()                → { quota, used, max_file }
 *
 *   drives.fonts — ספריית הגופנים של המשתמש (mbe-cloud/docs/spec-2-fonts.md):
 *   await drives.fonts.list()      → { fonts: [{ id, family, weight, style, format, rev, source, … }], families: [{ family, source, items }] }
 *   await drives.fonts.list({ builtin: true }) — גם הגופנים המובנים (OFL, source: 'builtin', id שמתחיל ב-b_). גופן משתנה —
 *        axes: { wght: [min, max] }, ו-weight הוא ברירת המחדל. license — יש רישיון: drives.fonts.license(item) → טקסט.
 *   await drives.fonts.load(item)  → { face: FontFace (כבר ב-document.fonts, בשם המשפחה; משתנה — בכל טווח המשקלים),
 *                                      bytes: Uint8Array — להטמעה בקובץ }
 *   await drives.fonts.add(file, { family, weight, style })   → פריט (replaced: true אם החליף)
 *
 *   drives.ai — שכבת הבינה המלאכותית של הענן (mbe-cloud/docs/spec-3-ai.md):
 *   await drives.ai.capabilities() → { connected, tasks: {…: bool}, providers, default, quota }  (בלי חיבור — connected:false והכול false)
 *   await drives.ai.run('inpaint', { image, mask, prompt }, { signal })
 *        → { png: Blob, size: [w, h], transparent, provider } | { masks: [{ png: Blob, label, box }] } | { json } | { text }
 *
 *   כוננים חיצוניים — אותה צורה כמו drives.suite, והדפדפן מדבר עם הספק ישירות (הקבצים לא עוברים בשרת):
 *   await drives.list()            → [{ id: 'suite' | 'dropbox', name, connected, account? }]  — רק כוננים שמוגדרים
 *   await drives.link('dropbox')   — מעבר להרשאה אצל Dropbox; חוזרים ל-back עם ?drive=connected | denied
 *   await drives.unlink('dropbox')
 *   const x = drives.dropbox;      — list · stat · read · mkdir · rename · move · trash · write · upload · quota (כמו suite).
 *        id הוא מזהה של Dropbox ("id:…"), תיקיית השורש היא null, ו-rev הוא מחרוזת. בתיקיית האפליקציה (App folder) בלבד.
 *
 * נתוני משתמש — 🗂️ (שלב 4, mbe-cloud/docs/spec-4-appdata.md). רק לאפליקציה שהצהירה "appdata" ב-layers:
 *   const a = drives.appdata;                     // מרחב user. מרחב האפליקציה (קריאה בלבד מהדפדפן): drives.appdata.space('app')
 *   await a.get('settings', 'chart')              → { value, rev, updated_at } | null
 *   await a.put('settings', 'chart', value, { ifRev })   → { rev, seq }. התנגשות → DriveError 'conflict' עם rev ו-value
 *   await a.delete('settings', 'chart', { ifRev })
 *   await a.list({ collection, prefix, cursor, limit })  → { items: [{ collection, key, rev, size, updated_at }], cursor }
 *   await a.getMany('watchlist', ['a', 'b'])      → { items: [{ key, value, rev, updated_at }] }
 *   await a.changes(since)                        → { changes, cursor, more }. 'gone' (410) — טוענים הכול מחדש ב-list
 *   await a.history(col, key) · a.restore(col, key, rev, { ifRev }) · a.usage() → { used, quota } · a.clear() (אחרי אישור)
 *
 * קבצים שבוחרים — 📁 files.app (שלב 3, mbe-cloud/docs/spec-3-files-scopes.md). האפליקציה רואה רק מה שיצרה ומה שהמשתמש בחר:
 *   await drives.pick({ kind: 'file' | 'folder' | 'any', multiple, mime: ['application/pdf'] }) — מעבר לבורר בענן; חוזרים ל-back
 *        עם ?cloud=picked (או pick-cancelled)
 *   await drives.picked()          → [node] — מה שנבחר (אחרי stat; רק מה שבאמת נגיש). מנקה את ?cloud=picked מהכתובת
 *   status().suite.files_scope     → 'files' | 'files.readonly' | 'files.app'
 *
 * שגיאות: DriveError עם code — not-connected, read-only (files.readonly — קריאה בלבד), layer-off (עם layer: 'files' | 'libs' | 'ai' — השכבה כבויה), conflict (if_rev), quota_exceeded, too_large, not_found, no-provider,
 * not-allowed, quota, too-big, provider, aborted. האסימון (15 דקות) מתחדש לבד, ונשמר בזיכרון בלבד.
 */

export class DriveError extends Error {
    constructor(message, code, extra = {}) { super(message); this.code = code; Object.assign(this, extra); }
}

const CHUNK_HASH_MAX = 64 * 1024 * 1024;

export function createDrives({ endpoint = 'api.php' } = {}) {
    let tok = null;          // { token, api, until }
    let pending = null;

    async function app(action, body) {
        const res = await fetch(`${endpoint}?action=${action}`, body === undefined ? { credentials: 'same-origin' }
            : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const j = await res.json().catch(() => ({}));
        if (!res.ok) throw new DriveError(j.error || 'תקלה בחיבור לענן', j.code || 'cloud');
        return j;
    }

    async function token(force = false) {
        if (!force && tok && tok.until > Date.now()) return tok;
        pending ??= app('drives_token').then(r => {
            tok = r.connected ? { token: r.token, api: r.api, until: Date.now() + (r.expires_in - 60) * 1000 } : null;
            return tok;
        }).finally(() => { pending = null; });
        const t = await pending;
        if (!t) throw new DriveError('לא מחובר לענן MBE', 'not-connected');
        return t;
    }

    /** קריאה לענן עם האסימון. 401 של אסימון שפג — אסימון חדש ונסיון אחד נוסף. */
    async function cloud(action, { query = '', body, init = {}, raw = false, signal } = {}, retry = true) {
        const t = await token();
        const headers = { 'X-Cloud-Token': t.token, ...(init.headers || {}) };
        let req = { method: 'GET', ...init, headers, signal };
        if (body !== undefined) req = { ...req, method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
        let res;
        try { res = await fetch(`${t.api}?action=${action}${query}`, req); }
        catch (e) { throw e.name === 'AbortError' ? new DriveError('בוטל', 'aborted') : new DriveError('אין חיבור לענן', 'network'); }
        if (res.status === 401 && retry) {
            const j = await res.clone().json().catch(() => ({}));
            if (j.code === 'expired' || j.code === 'bad_token') { tok = null; return cloud(action, { query, body, init, raw, signal }, false); }
            if (j.code === 'disconnected') { tok = null; throw new DriveError('החיבור לענן נותק', 'not-connected'); }
        }
        if (raw && res.ok) return res;
        const j = await res.json().catch(() => ({ error: 'תשובה לא תקינה מהענן' }));
        // השכבה כבויה (כיבוי באפליקציה או בדף הענן) — layer-off, כדי להציע להדליק את המתג הנכון
        if (res.status === 403 && j.code === 'forbidden' && j.layer) throw new DriveError(j.error || 'השכבה כבויה', 'layer-off', { layer: j.layer, scope: j.scope });
        if (res.status === 403 && j.code === 'read_only') throw new DriveError(j.error || 'קריאה בלבד', 'read-only');
        if (!res.ok) throw new DriveError(j.error || 'תקלה', j.code || 'cloud', j);
        return j;
    }

    const q = (k, v) => v == null || v === '' ? '' : `&${k}=${encodeURIComponent(v)}`;

    async function sha256(file) {
        if (file.size > CHUNK_HASH_MAX || !globalThis.crypto?.subtle) return undefined;
        const h = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
        return [...new Uint8Array(h)].map(b => b.toString(16).padStart(2, '0')).join('');
    }

    const b64 = async blob => {
        const s = String.fromCharCode;
        const u = new Uint8Array(await blob.arrayBuffer());
        let out = '';
        for (let i = 0; i < u.length; i += 0x8000) out += s(...u.subarray(i, i + 0x8000));
        return btoa(out);
    };

    const suite = {
        quota: () => cloud('quota'),
        list: folder => cloud('list', { query: q('folder', folder) }),
        stat: id => cloud('stat', { query: q('id', id) }),
        read: async (id, { signal } = {}) => (await cloud('read', { query: q('id', id), raw: true, signal })).blob(),
        mkdir: (name, parent = null, onConflict = 'replace') => cloud('mkdir', { body: { name, parent, on_conflict: onConflict } }),
        rename: (id, name) => cloud('rename', { body: { id, name } }),
        move: (id, parent) => cloud('move', { body: { id, parent } }),
        trash: id => cloud('trash', { body: { id } }),
        async write(name, blob, { parent = null, ifRev, onConflict, mime } = {}) {
            return cloud('write', { body: { name, parent, content_b64: await b64(blob), mime: mime || blob.type || undefined, if_rev: ifRev, on_conflict: onConflict } });
        },
        async upload(file, { parent = null, ifRev, onConflict, onProgress, signal, name } = {}) {
            const start = await cloud('upload_start', { body: { parent, name: name || file.name, size: file.size, sha256: await sha256(file),
                mime: file.type || undefined, if_rev: ifRev, on_conflict: onConflict }, signal });
            let got = start.received;
            onProgress?.(got, file.size);
            for (let i = Math.floor(got / start.chunk); got < file.size; i++) {
                const part = file.slice(i * start.chunk, (i + 1) * start.chunk);
                for (let tries = 0; ; tries++) {
                    try {
                        got = (await cloud('upload_chunk', { query: `&upload=${start.upload}&index=${i}`, signal,
                            init: { method: 'PUT', headers: { 'Content-Type': 'application/octet-stream' }, body: part } })).received;
                        break;
                    } catch (e) {
                        if (e.code !== 'network' || tries >= 3) throw e;
                        await new Promise(r => setTimeout(r, 1500 * (tries + 1)));
                    }
                }
                onProgress?.(got, file.size);
            }
            return cloud('upload_finish', { body: { upload: start.upload }, signal });
        },
    };

    const fonts = {
        list: ({ builtin = false } = {}) => cloud('fonts_list', { query: builtin ? '&builtin=1' : '' }),
        license: async item => (await cloud('font_license', { query: q('id', item.id), raw: true })).text(),
        async load(item) {
            const res = await cloud('font_file', { query: q('id', item.id) + q('rev', item.rev), raw: true });
            const bytes = new Uint8Array(await res.arrayBuffer());
            const w = item.axes?.wght;
            const face = new FontFace(item.family, bytes, { weight: w ? `${w[0]} ${w[1]}` : String(item.weight), style: item.style });
            await face.load();
            document.fonts.add(face);
            return { face, bytes };
        },
        async add(file, { family, weight, style } = {}) {
            const fd = new FormData();
            fd.append('file', file, file.name || 'font');
            fd.append('license_ack', '1');   // האפליקציה הציגה את אזהרת הרישיון לפני הקריאה (הכרעה 7)
            if (family) fd.append('family', family);
            if (weight) fd.append('weight', String(weight));
            if (style) fd.append('style', style);
            return cloud('font_add', { init: { method: 'POST', body: fd } });
        },
    };

    // ─── Dropbox ───────────────────────────────────────────────────────

    const ptok = {};   // provider → { token, until }
    async function providerToken(p, force = false) {
        if (!force && ptok[p]?.until > Date.now()) return ptok[p].token;
        const r = await app(`drives_ptoken&provider=${p}`);
        if (!r.connected) { delete ptok[p]; throw new DriveError('לא מחובר ל-Dropbox', 'not-connected'); }
        ptok[p] = { token: r.token, until: Date.now() + (r.expires_in - 60) * 1000 };
        return r.token;
    }

    /** Dropbox-API-Arg חייב להיות ASCII — שמות בעברית נשלחים כ-\uXXXX. */
    const dbxArg = o => JSON.stringify(o).replace(/[\u007f-\uffff]/g, c => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0'));

    async function dbx(route, { arg, body, content = false, raw = false, signal } = {}, retry = true) {
        const token = await providerToken('dropbox');
        const headers = { Authorization: `Bearer ${token}` };
        let req;
        if (content) {
            headers['Dropbox-API-Arg'] = dbxArg(arg ?? {});
            if (body !== undefined) headers['Content-Type'] = 'application/octet-stream';
            req = { method: 'POST', headers, body, signal };
        } else {
            headers['Content-Type'] = 'application/json';
            req = { method: 'POST', headers, body: JSON.stringify(arg ?? null), signal };
        }
        const host = content ? 'https://content.dropboxapi.com/2/' : 'https://api.dropboxapi.com/2/';
        let res;
        try { res = await fetch(host + route, req); }
        catch (e) { throw e.name === 'AbortError' ? new DriveError('בוטל', 'aborted') : new DriveError('אין חיבור ל-Dropbox', 'network'); }
        if (res.status === 401 && retry) { delete ptok.dropbox; return dbx(route, { arg, body, content, raw, signal }, false); }
        if (res.ok) return raw ? res : res.json();
        const j = await res.json().catch(() => ({}));
        const summary = String(j.error_summary || '');
        if (res.status === 401) throw new DriveError('החיבור ל-Dropbox בוטל', 'not-connected');
        if (summary.includes('not_found')) throw new DriveError('לא נמצא', 'not_found');
        if (summary.includes('insufficient_space')) throw new DriveError('אין מקום ב-Dropbox', 'quota_exceeded');
        if (summary.includes('conflict')) throw new DriveError('כבר יש פריט כזה, או שהקובץ השתנה', 'conflict');
        if (res.status === 429) throw new DriveError('יותר מדי בקשות ל-Dropbox — נסו שוב בעוד רגע', 'rate_limited');
        throw new DriveError(summary || 'תקלה ב-Dropbox', 'provider');
    }

    const dbxPath = (folder, name) => folder ? (name ? `${folder}/${name}` : folder) : (name ? `/${name}` : '');
    const parentOf = p => { const i = p.lastIndexOf('/'); return i > 0 ? p.slice(0, i) : null; };
    const dbxNode = (m, parent) => {
        const folder = m['.tag'] === 'folder';
        const out = { id: m.id, kind: folder ? 'folder' : 'file', name: m.name, parent: parent !== undefined ? parent : parentOf(m.path_lower || ''),
                      path: m.path_display };
        if (!folder) Object.assign(out, { rev: m.rev, size: m.size, updated_at: m.server_modified, content_hash: m.content_hash });
        return out;
    };
    const mode = (ifRev, onConflict) => ifRev ? { mode: { '.tag': 'update', update: ifRev }, autorename: false }
        : onConflict === 'replace' ? { mode: 'overwrite', autorename: false }
        : { mode: 'add', autorename: onConflict !== 'fail' };
    const UPLOAD_ONE = 8 * 1024 * 1024;

    const dropbox = {
        async quota() {
            const u = await dbx('users/get_space_usage');
            return { quota: u.allocation?.allocated ?? 0, used: u.used, max_file: 350 * 1024 ** 3 };
        },
        async list(folder = null) {
            let r = await dbx('files/list_folder', { arg: { path: dbxPath(folder), limit: 2000 } });
            const items = [...r.entries];
            while (r.has_more) { r = await dbx('files/list_folder/continue', { arg: { cursor: r.cursor } }); items.push(...r.entries); }
            let path = [];
            if (folder) {
                const m = await dbx('files/get_metadata', { arg: { path: folder } });
                const parts = (m.path_display || '').split('/').filter(Boolean);
                path = parts.map((name, i) => ({ id: i === parts.length - 1 ? m.id : '/' + parts.slice(0, i + 1).join('/'), name }));
            }
            const nodes = items.filter(m => m['.tag'] !== 'deleted').map(m => dbxNode(m, folder))
                .sort((a, b) => (a.kind === 'file') - (b.kind === 'file') || a.name.localeCompare(b.name, 'he'));
            return { folder, path, items: nodes };
        },
        stat: async id => dbxNode(await dbx('files/get_metadata', { arg: { path: id } })),
        read: async (id, { signal } = {}) => (await dbx('files/download', { arg: { path: id }, content: true, raw: true, signal })).blob(),
        async mkdir(name, parent = null, onConflict = 'replace') {
            try { return dbxNode((await dbx('files/create_folder_v2', { arg: { path: dbxPath(parent, name), autorename: onConflict === 'rename' } })).metadata, parent); }
            catch (e) {
                if (e.code !== 'conflict' || onConflict !== 'replace') throw e;
                return dbxNode(await dbx('files/get_metadata', { arg: { path: dbxPath(parent, name) } }), parent);   // "ודא שקיימת"
            }
        },
        async rename(id, name) {
            const m = await dbx('files/get_metadata', { arg: { path: id } });
            const parent = parentOf(m.path_display);
            return dbxNode((await dbx('files/move_v2', { arg: { from_path: id, to_path: dbxPath(parent, name) } })).metadata);
        },
        async move(id, parent) {
            const m = await dbx('files/get_metadata', { arg: { path: id } });
            return dbxNode((await dbx('files/move_v2', { arg: { from_path: id, to_path: dbxPath(parent, m.name) } })).metadata, parent);
        },
        trash: async id => { await dbx('files/delete_v2', { arg: { path: id } }); return { ok: true }; },   // נשמר ב"קבצים שנמחקו" של Dropbox
        write: (name, blob, opts = {}) => dropbox.upload(blob, { ...opts, name }),
        async upload(file, { parent = null, ifRev, onConflict, onProgress, signal, name } = {}) {
            const commit = { path: dbxPath(parent, name || file.name), ...mode(ifRev, onConflict), mute: true };
            if (file.size <= UPLOAD_ONE) {
                const m = await dbx('files/upload', { arg: commit, body: file, content: true, signal });
                onProgress?.(file.size, file.size);
                return dbxNode(m, parent);
            }
            const s = await dbx('files/upload_session/start', { arg: { close: false }, body: new Blob([]), content: true, signal });
            let off = 0;
            onProgress?.(0, file.size);
            while (off + UPLOAD_ONE < file.size) {
                await dbx('files/upload_session/append_v2', { arg: { cursor: { session_id: s.session_id, offset: off }, close: false },
                    body: file.slice(off, off + UPLOAD_ONE), content: true, signal });
                off += UPLOAD_ONE;
                onProgress?.(off, file.size);
            }
            const m = await dbx('files/upload_session/finish', { arg: { cursor: { session_id: s.session_id, offset: off }, commit },
                body: file.slice(off), content: true, signal });
            onProgress?.(file.size, file.size);
            return dbxNode(m, parent);
        },
    };

    /** נתוני משתמש (🗂️). כל קריאה — POST עם JSON, דרך האסימון. */
    function appdataSpace(space) {
        const call = (action, body = {}) => cloud(action, { body: space === 'user' ? body : { ...body, space } });
        return {
            async get(collection, key) {
                try { return await call('appdata_get', { collection, key }); }
                catch (e) { if (e.code === 'not_found') return null; throw e; }
            },
            put: (collection, key, value, { ifRev } = {}) => call('appdata_put', { collection, key, value, ...(ifRev != null ? { if_rev: ifRev } : {}) }),
            delete: (collection, key, { ifRev } = {}) => call('appdata_delete', { collection, key, ...(ifRev != null ? { if_rev: ifRev } : {}) }),
            list: (opts = {}) => call('appdata_list', opts),
            getMany: (collection, keys) => call('appdata_get_many', { collection, keys }),
            changes: (since = 0, { limit } = {}) => call('appdata_changes', { since, ...(limit ? { limit } : {}) }),
            history: (collection, key) => call('appdata_history', { collection, key }),
            restore: (collection, key, rev, { ifRev } = {}) => call('appdata_restore', { collection, key, rev, ...(ifRev != null ? { if_rev: ifRev } : {}) }),
            usage: () => call('appdata_usage'),
            clear: () => call('appdata_clear', { confirm: 'clear' }),
            space: s => appdataSpace(s),
        };
    }
    const appdata = appdataSpace('user');

    const NAMES = { suite: 'ענן MBE', dropbox: 'Dropbox' };

    const NONE = { connected: false, tasks: { 'remove-background': false, segment: false, 'generate-image': false, inpaint: false, complete: false }, providers: [], default: {}, quota: null };

    const ai = {
        async capabilities() {
            try { return { connected: true, ...(await cloud('ai_capabilities')) }; }
            catch (e) { if (e.code === 'not-connected' || e.code === 'layer-off' || e.code === 'forbidden') return NONE; throw e; }
        },
        async run(task, input = {}, { signal } = {}) {
            const fd = new FormData();
            fd.append('task', task);
            for (const k of ['prompt', 'system', 'size', 'provider']) if (input[k] != null) fd.append(k, input[k]);
            if (input.transparent != null) fd.append('transparent', input.transparent ? '1' : '0');
            if (input.schema) fd.append('schema', JSON.stringify(input.schema));
            if (input.image) fd.append('image', input.image, 'image.png');
            if (input.mask) fd.append('mask', input.mask, 'mask.png');
            for (const im of input.images || []) fd.append('images[]', im, 'image.png');
            const res = await cloud('ai_run', { init: { method: 'POST', body: fd }, raw: true, signal });
            if ((res.headers.get('content-type') || '').startsWith('image/')) {
                const size = (res.headers.get('x-ai-size') || '').split('x').map(Number);
                return { png: await res.blob(), size, transparent: res.headers.get('x-ai-transparent') === '1', provider: res.headers.get('x-ai-provider') };
            }
            const j = await res.json();
            if (j.masks) {
                j.masks = await Promise.all(j.masks.map(async m => ({ ...m, png: await (await fetch(`data:image/png;base64,${m.png}`)).blob() })));
            }
            return j;
        },
    };

    return {
        status: () => app('drives_status'),
        /** connect() — כל השכבות שהוצהרו; connect({ layers }) — הדלקת שכבות; connect('files,ai') — היקפים (התאמה לאחור). */
        async connect(arg) {
            const body = Array.isArray(arg?.layers) ? { layers: arg.layers } : typeof arg === 'string' && arg ? { scope: arg } : {};
            const r = await app('drives_connect', body);
            location.assign(r.url);
        },
        /** disconnect() — ניתוק מלא; disconnect({ layers }) — כיבוי שכבות (האחרונה = ניתוק). מחזיר status. */
        async disconnect(arg) {
            tok = null;
            return app('drives_disconnect', Array.isArray(arg?.layers) ? { layers: arg.layers } : {});
        },
        async list() {
            const s = await app('drives_status');
            return Object.entries(s).map(([id, v]) => ({ id, name: NAMES[id] || id, connected: !!v.connected, ...(v.name ? { account: v.name } : {}) }));
        },
        /** הבורר של הענן (files.app): המשתמש בוחר שם, והענן מעניק לאפליקציה רק את מה שנבחר. */
        async pick(opts = {}) {
            const r = await app('drives_pick', { kind: opts.kind || 'file', multiple: !!opts.multiple, mime: opts.mime || [] });
            location.assign(r.url);
        },
        /** מה שנבחר בפעם האחרונה — nodes אחרי stat. מנקה את ?cloud=picked. */
        async picked() {
            try {
                const u = new URL(location.href);
                if (['picked', 'pick-cancelled'].includes(u.searchParams.get('cloud'))) {
                    u.searchParams.delete('cloud');
                    history.replaceState(history.state, '', u.pathname + u.search + u.hash);
                }
            } catch { /* בלי location */ }
            const { ids = [] } = await app('drives_picked');
            const out = [];
            for (const id of ids) {
                try { out.push(await suite.stat(id)); } catch (e) { if (e.code !== 'not_found') throw e; }
            }
            return out;
        },
        async link(provider) {
            const r = await app('drives_link', { provider });
            location.assign(r.url);
        },
        async unlink(provider) { delete ptok[provider]; return app('drives_unlink', { provider }); },
        dropbox,
        suite,
        fonts,
        ai,
        appdata,
    };
}
