/**
 * מודול הכוננים — צד הדפדפן של האפליקציה. עותק מסונכרן: לא לערוך כאן. המקור: suite-cloud/drives/src/js/.
 *
 *   import { createDrives } from './drives/drives.js';
 *   const drives = createDrives({ endpoint: 'api.php' });     // ה-api.php של האפליקציה (שם רץ Drives.php)
 *
 *   await drives.status()          → { suite: { connected, name, cloud_id, scope } }
 *   await drives.connect()         — מעבר למסך האישור בענן; חוזרים ל-back עם ?cloud=connected | denied
 *   await drives.disconnect()
 *
 *   const s = drives.suite;        — ענן החבילה. בלי חיבור — כל קריאה זורקת DriveError עם code 'not-connected'.
 *   await s.list(folder?)          → { folder, path, items:[node] }
 *   await s.stat(id) · s.read(id) → Blob · s.mkdir(name, parent?) · s.rename(id, name) · s.move(id, parent) · s.trash(id)
 *   await s.write(name, blob, { parent, ifRev, onConflict })                  → node  (עד 4MB)
 *   await s.upload(file, { parent, ifRev, onConflict, onProgress, signal })  → node  (בחלקים, כל גודל)
 *   await s.quota()                → { quota, used, max_file }
 *
 *   drives.fonts — ספריית הגופנים של המשתמש (suite-cloud/docs/spec-2-fonts.md):
 *   await drives.fonts.list()      → { fonts: [{ id, family, weight, style, format, rev, … }], families: [{ family, items }] }
 *   await drives.fonts.load(item)  → { face: FontFace (כבר ב-document.fonts, בשם המשפחה), bytes: Uint8Array — להטמעה בקובץ }
 *   await drives.fonts.add(file, { family, weight, style })   → פריט (replaced: true אם החליף)
 *
 *   drives.ai — שכבת הבינה המלאכותית של הענן (suite-cloud/docs/spec-3-ai.md):
 *   await drives.ai.capabilities() → { tasks: {…: bool}, providers, default, quota }  (בלי חיבור — הכול false)
 *   await drives.ai.run('inpaint', { image, mask, prompt }, { signal })
 *        → { png: Blob, size: [w, h], transparent, provider } | { masks: [{ png: Blob, label, box }] } | { json } | { text }
 *
 * שגיאות: DriveError עם code — not-connected, conflict (if_rev), quota_exceeded, too_large, not_found, no-provider,
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
        if (!t) throw new DriveError('לא מחובר לענן החבילה', 'not-connected');
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
        list: () => cloud('fonts_list'),
        async load(item) {
            const res = await cloud('font_file', { query: q('id', item.id) + q('rev', item.rev), raw: true });
            const bytes = new Uint8Array(await res.arrayBuffer());
            const face = new FontFace(item.family, bytes, { weight: String(item.weight), style: item.style });
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

    const NONE = { tasks: { 'remove-background': false, segment: false, 'generate-image': false, inpaint: false, complete: false }, providers: [], default: {}, quota: null };

    const ai = {
        async capabilities() {
            try { return await cloud('ai_capabilities'); }
            catch (e) { if (e.code === 'not-connected' || e.code === 'forbidden') return NONE; throw e; }
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
        async connect(scope) {
            const r = await app('drives_connect', scope ? { scope } : {});
            location.assign(r.url);
        },
        async disconnect() { tok = null; return app('drives_disconnect', {}); },
        suite,
        fonts,
        ai,
    };
}
