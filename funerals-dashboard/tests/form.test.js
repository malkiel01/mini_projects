// פרוסה 02 — בדיקות ל-assets/js/form.js (B4, B5, B7 בצד הממשק). רץ מ-tests/run.sh: node tests/form.test.js [list.json]
'use strict';
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const F = require(path.join(__dirname, '../assets/js/form.js'));

const all = st => Object.fromEntries(F.KEYS.map(k => [k, {status: st, value: '', note: ''}]));
const rec = checks => ({id: 'fd_x_00000000', name: 'x', checks, documents: [], folder_url: ''});

// B4: "הבא בתור" = הראשונה בקבוצת "חסר"; כשכולן מאומתות — אין (התיק הושלם)
{
  const c = all('unknown');
  c.identity_source.status = 'document_present';
  c.hall_service.status = 'missing';
  c.partner_identity.status = 'unreadable';
  assert.strictEqual(F.nextUp(rec(c)), 'hall_service', 'B4: הראשונה בקבוצת חסר (לפי סדר הבדיקות)');
  c.hall_service.status = 'verified'; c.partner_identity.status = 'verified';
  assert.strictEqual(F.nextUp(rec(c)), 'identity_source', 'B4: אין חסר → הראשונה לאימות');
  c.identity_source.status = 'verified';
  assert.strictEqual(F.nextUp(rec(c)), 'license_document', 'B4: אין לאימות → הראשונה שטרם נבדקה');
  const v = all('verified'), s = F.caseState(rec(v));
  assert.strictEqual(F.nextUp(rec(v)), null, 'B4: כולן מאומתות → אין הבא בתור');
  assert.ok(s.complete && s.state === 'done', 'B4: כולן מאומתות → התיק הושלם');
}

// B5: כל הבדיקות unknown — לא "הושלם", ומופיע בסינון "טרם נבדק"
{
  for (const r of [rec(all('unknown')), rec({}), {name: 'בלי checks'}]) {
    const s = F.caseState(r);
    assert.ok(!s.complete, 'B5: לא הושלם');
    assert.strictEqual(s.state, 'unchecked', 'B5: מצב "טרם נבדק"');
    assert.strictEqual(s.pending, F.TOTAL, 'B5: מספר הבדיקות הפתוחות');
    assert.ok(F.matches('unchecked', s) && F.matches('todo', s) && !F.matches('done', s), 'B5: בסינון טרם נבדק, לא בהושלם');
  }
  // "לא משקר": כולן "לא רלוונטי" בלי אף מאומתת — לא הושלם
  const na = F.caseState(rec(all('not_applicable')));
  assert.ok(!na.complete && na.state === 'unchecked', 'B5: אין בדיקה מאומתת → לא תקין');
  // סינונים מהירים
  const c = all('verified'); c.license_number.status = 'missing'; c.identity_document.status = 'document_present';
  const s = F.caseState(rec(c));
  assert.ok(F.matches('bad', s) && F.matches('verify', s) && !F.matches('unchecked', s) && !F.matches('done', s), 'סינון חסר/לאימות');
}

// מקור: בדיקה עם via → מי/מאיפה/מתי; בלי via ועם תוכן → "מייבוא ישן"; ריקה → כלום
{
  assert.deepStrictEqual(F.provenance({status: 'verified', by: 'a', via: 'ui', at: 't'}), {legacy: false, by: 'a', via: 'ממשק', at: 't'});
  assert.strictEqual(F.provenance({status: 'verified', value: '1'}).via, 'מייבוא ישן');
  assert.strictEqual(F.provenance({status: 'unknown', value: '', note: ''}), null);
  assert.strictEqual(F.provenance(undefined), null);
  assert.strictEqual(F.fieldLabel('checks.license_number.status'), 'מספר רישיון קבורה · סטטוס');
  assert.strictEqual(F.valueText('verified'), 'מאומת');
}

// B7: התיקים הישנים (מהקובץ, ואם ניתן — כפי שהשרת החזיר אותם ב-list) נפתחים בלי שגיאה, ובדיקה בלי מקור = "מייבוא ישן"
{
  const files = [path.join(__dirname, 'fixtures/legacy-cases.json')].concat(process.argv.slice(2));
  for (const f of files) {
    const data = JSON.parse(fs.readFileSync(f, 'utf8'));
    const records = Array.isArray(data) ? data : data.records;
    assert.ok(records.length >= 22, 'B7: לפחות 22 תיקים ב-' + path.basename(f));
    let legacy = 0;
    for (const r of records) {
      const s = F.caseState(r);
      assert.ok(['bad', 'verify', 'unchecked', 'open', 'done'].includes(s.state), 'B7: מצב תקין ל-' + r.id);
      const next = F.nextUp(r);
      assert.ok(next === null || F.KEYS.includes(next));
      for (const c of Object.values(r.checks || {})) {
        const p = F.provenance(c);
        if (p) { assert.strictEqual(p.legacy, !c.via, 'B7: בדיקה בלי via → מייבוא ישן; עם via → המקור'); if (p.legacy) legacy++; }
      }
    }
    assert.ok(legacy > 0, 'B7: יש בדיקות "מייבוא ישן"');
  }
}
console.log('PASS form.test.js: B4 (הבא בתור, התיק הושלם), B5 (טרם נבדק ≠ הושלם, סינונים), מקור/מייבוא ישן, B7 (22 תיקים ישנים)');
