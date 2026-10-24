// ספריית הלקוח של תוסף התשלומים — לשרתי ‏Node 18+ (‏fetch ו-crypto מובנים, בלי תלויות).
// רק בשרת! הקוד הסודי לא נכנס לדפדפן לעולם. המדריך: payments/docs/connect.md
//
//   import { PaymentsClient } from './payments-client.mjs';
//   const pay = new PaymentsClient({ baseUrl: 'https://mbe-plus.com/mini_projects/payments/', key: process.env.PAY_KEY, app: 'my-app',
//                                    webhookSecret: process.env.PAY_WHSEC });
//   const { url } = await pay.checkout('user:7', 'payment', [{ product: 'report' }], returnUrl, cancelUrl);
//   const ent = await pay.entitlement('user:7');
//   const event = pay.webhook(rawBodyBuffer, req.headers);   // זורק אם לא תקין

import { createHmac, createHash, randomBytes, timingSafeEqual, createPublicKey, verify } from 'node:crypto';

export class PaymentsError extends Error {
  constructor(message, code = 'error', status = 0) { super(message); this.code = code; this.status = status; }
}

export class PaymentsClient {
  static VERSION = '1.1.0';

  constructor({ baseUrl, key, webhookSecret = '', app = null, env = null, fail = 'open', timeoutMs = 10000 }) {
    if (!key) throw new Error('צריך key (קוד סודי)');
    this.base = baseUrl.replace(/\/?$/, '/');
    [this.keyId, this.secret] = key.split('.', 2);
    this.webhookSecret = webhookSecret.includes('.') ? webhookSecret.split('.', 2)[1] : webhookSecret;
    this.app = app;
    this.env = env ?? (key.startsWith('sk_live_') ? 'live' : key.startsWith('sk_test_') ? 'test' : null);
    this.fail = fail;
    this.timeoutMs = timeoutMs;
    this.cache = new Map();
    this.pluginKeys = null;
  }

  ping() { return this.call('ping', {}); }
  catalog() { return this.call('catalog', {}); }
  customer(externalId, fields = {}) { return this.call('customer_upsert', { external_id: externalId, ...fields }); }
  checkout(externalId, mode, items, returnUrl, cancelUrl, extra = {}) {
    return this.call('checkout_create', { external_id: externalId, mode, items, return_url: returnUrl, cancel_url: cancelUrl, ...extra });
  }
  checkoutStatus(checkout) { return this.call('checkout_get', { checkout }); }
  charge(externalId, items, idempotencyKey, returnUrl, cancelUrl) {
    return this.call('charge', { external_id: externalId, items, idempotency_key: idempotencyKey, ...(returnUrl ? { return_url: returnUrl, cancel_url: cancelUrl } : {}) });
  }
  usage(externalId, product, quantity, idempotencyKey, meta = {}) {
    return this.call('usage_record', { external_id: externalId, product, quantity, idempotency_key: idempotencyKey, meta });
  }
  reserve(externalId, product, estimate, ttl = 900) { return this.call('usage_reserve', { external_id: externalId, product, estimate, ttl }); }
  commit(reservation, quantity, idempotencyKey) { return this.call('usage_commit', { reservation, quantity, idempotency_key: idempotencyKey }); }
  release(reservation) { return this.call('usage_release', { reservation }); }
  async subscriptions(externalId) { return (await this.call('subscription_list', { external_id: externalId })).data ?? []; }
  cancelSubscription(externalId, subscription, now = false) { return this.call('subscription_cancel', { external_id: externalId, subscription, at: now ? 'now' : 'period_end' }); }
  async portalUrl(externalId, returnUrl) { return (await this.call('portal_session', { external_id: externalId, return_url: returnUrl })).url; }

  /** מה מותר ללקוח עכשיו — במטמון לפי ttl; התוסף לא עונה → הערך האחרון, או לפי fail. */
  async entitlement(externalId, fresh = false) {
    const c = this.cache.get(externalId);
    if (!fresh && c && c.until > Date.now()) return c.value;
    try {
      const e = await this.call('entitlement', { external_id: externalId });
      this.cache.set(externalId, { value: e, until: Date.now() + (e.ttl ?? 300) * 1000, saved: Date.now() });
      return e;
    } catch (err) {
      if (err.status && err.status < 500) throw err;
      if (c && c.saved > Date.now() - 86400_000) return { ...c.value, stale: true };
      const open = this.fail !== 'closed';
      return { active: open, state: open ? 'unknown' : 'none', stale: true, plans: [], features: {}, limits: {}, balance: 0, can_spend: open };
    }
  }

  /** אימות הודעה מהתוסף. ‏raw — הגוף הגולמי (Buffer או מחרוזת), לפני כל JSON.parse. */
  async webhook(raw, headers) {
    const h = Object.fromEntries(Object.entries(headers).map(([k, v]) => [k.toLowerCase(), String(v)]));
    const body = Buffer.isBuffer(raw) ? raw : Buffer.from(raw);
    const evt = h['x-payments-event'] || '', time = h['x-payments-time'] || '', sig = h['x-payments-signature'] || '';
    if (!evt || !/^\d+$/.test(time) || Math.abs(Date.now() / 1000 - Number(time)) > 300) throw new PaymentsError('הודעה ישנה או חסרה', 'stale', 400);
    const msg = `webhook\n${time}\n${evt}\n${createHash('sha256').update(body).digest('hex')}`;
    let ok = false;
    if (sig.startsWith('hmac.')) {
      const want = Buffer.from(createHmac('sha256', this.webhookSecret).update(msg).digest('hex'));
      const got = Buffer.from(sig.slice(5));
      ok = !!this.webhookSecret && want.length === got.length && timingSafeEqual(want, got);
    } else {
      const [kid, b64] = sig.split('.', 2);
      for (const refresh of [false, true]) {
        const raw32 = (await this.keys(refresh))[kid];
        if (!raw32) continue;
        const pub = createPublicKey({ key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), raw32]), format: 'der', type: 'spki' });
        if (verify(null, Buffer.from(msg), pub, Buffer.from(b64 || '', 'base64'))) { ok = true; break; }
      }
    }
    if (!ok) throw new PaymentsError('חתימה לא תקינה', 'bad_signature', 401);
    const j = JSON.parse(body.toString('utf8'));
    if (j.id !== evt) throw new PaymentsError('גוף לא תקין', 'bad_body', 400);
    // אותו מפתח חותם לכל המערכות ולשתי הסביבות — הודעה של מערכת אחרת, או מבדיקות, לא מתקבלת כאן
    if (this.env && j.env !== this.env) throw new PaymentsError('הודעה מסביבה אחרת', 'wrong_env', 400);
    if (this.app && j.app !== this.app) throw new PaymentsError('הודעה של מערכת אחרת', 'wrong_app', 400);
    if (j.external_id) this.cache.delete(j.external_id);
    return j;
  }

  async keys(refresh) {
    if (!refresh && this.pluginKeys && this.pluginKeys.at > Date.now() - 3600_000) return this.pluginKeys.keys;
    const r = await fetch(this.base + 'api.php?action=key');
    const j = await r.json();
    const keys = {};
    for (const k of j.keys || []) if (k.alg === 'Ed25519') keys[k.kid] = Buffer.from(k.key, 'base64');
    this.pluginKeys = { keys, at: Date.now() };
    return keys;
  }

  async call(action, payload) {
    const body = JSON.stringify(payload);
    const time = String(Math.floor(Date.now() / 1000));
    const nonce = randomBytes(16).toString('hex');
    const msg = `${action}\n${time}\n${nonce}\n${createHash('sha256').update(body).digest('hex')}`;
    let r;
    try {
      r = await fetch(`${this.base}api.php?action=${encodeURIComponent(action)}`, {
        method: 'POST', body, signal: AbortSignal.timeout(this.timeoutMs),
        headers: { 'Content-Type': 'application/json', 'X-Pay-Key': this.keyId, 'X-Pay-Time': time, 'X-Pay-Nonce': nonce,
          'X-Pay-Signature': createHmac('sha256', this.secret).update(msg).digest('hex'), 'User-Agent': 'payments-client-js/' + PaymentsClient.VERSION },
      });
    } catch (e) { throw new PaymentsError('התוסף לא זמין: ' + e.message, 'network', 0); }
    let j = null;
    try { j = await r.json(); } catch { /* ריק */ }
    if (!r.ok || !j) throw new PaymentsError(j?.error || `שגיאה ${r.status}`, j?.code || 'error', r.status);
    return j;
  }
}
