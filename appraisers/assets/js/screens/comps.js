/*
 * פרוסה 15 — עסקאות השוואה ומנוע החישוב (docs/spec/15-comparables.md §8).
 *   כרטיס תיק ← "עסקאות" (compsTab): הקבוצות, העסקאות לפי מצב, החיפושים שנרשמו
 *   #/cases/<תיק>/comps/<עסקה> (viewComp): ערכים מול המקור, תיקונים, דומה / שונה, התאמות, הנוסחה שורה-שורה
 *   #/cases/<תיק>/compset/<קבוצה> (viewCompSet): הטבלה, הסיכום, והמסקנה של השמאי — בנפרד מהממוצע
 *   #/cases/<תיק>/compimport/<חיפוש> (viewCompImport): קובץ או הדבקה ← מיפוי עמודות ← תצוגה מקדימה ← ייבוא
 *   עורך פרופיל ← "עסקאות" (peComps): ברירות מחדל למסך
 * כל מספר מגיע מהשרת כמחרוזת מדויקת ומוצג כפי שהוא — הדפדפן אינו מחשב דבר. "לא ידוע" מוצג במילים, לעולם לא כאפס.
 * כל טקסט שמשתמש הקליד עובר דרך esc(). ההרשאות נאכפות בשרת — כאן רק מסתירים כפתורים.
 */
'use strict';

const K = T.k;
/** אגורות (מחרוזת שלמה) ← "2,070,000" / "12,500.5" — פעולות מחרוזת בלבד, בלי מספר עשרוני של הדפדפן. */
function kAg(s) {
    if (s === null || s === undefined) return null;
    const neg = String(s).startsWith('-'), d = String(s).replace('-', '').padStart(3, '0');
    const frac = d.slice(-2).replace(/0+$/, '');
    return (neg ? '−' : '') + fNum(d.slice(0, -2)) + (frac ? '.' + frac : '');
}
const kUnit = basis => basis === 'per_sqm' ? K.perSqm : K.nis;
/** סכום מהשרת ({agorot, nis}) מעוגל לשקל — כך מוצגות תוצאות הביניים. */
const kNis = m => m ? fNum(String(m.nis)).replace('-', '−') : null;
const kMoney = (m, basis) => m ? `${kNis(m)} ${kUnit(basis)}` : K.unknown;
const kAgMoney = (ag, basis) => ag === null || ag === undefined ? K.unknown : `${kAg(ag)} ${kUnit(basis)}`;
const kStChip = st => `<span class="chip ${K.statusCls[st] || ''}">${esc(K.statuses[st] || st)}</span>`;
const kAreaKind = k => T.f.areaKinds[k] || k;
/**
 * איך קוראים לעסקה ברשימה — **טקסט, לא HTML**: מי שמציג אותו עוטף ב-esc(). מיקום מקורב מסומן ככזה, ואינו מוצג ככתובת.
 * (בונים טקסט בשרשור ולא בתבנית: בקובץ הזה תבנית עם שדה של משתמש היא תמיד HTML, ותמיד דרך esc — כך בודקת גם p-comps.)
 */
function kLabel(v) {
    if (v.address) return v.address + (v.locality ? ', ' + v.locality : '');
    if (v.approx_location) return K.approx + ': ' + v.approx_location;
    if (v.gush) return K.fields.gush + ' ' + v.gush + (v.helka ? ' ' + K.fields.helka + ' ' + v.helka : '') + (v.tat_helka ? '/' + v.tat_helka : '');
    return v.locality || K.unknown;
}
const kPrice = v => v.price === null ? K.unknown : `${fNum(v.price)} ${K.currencies[v.currency] || v.currency}`;
const kArea = v => v.area === null ? null : `${fNum(v.area)} ${K.sqm}${v.area_kind !== 'unknown' ? ` (${kAreaKind(v.area_kind)})` : ''}`;
/** ערך של שדה לתצוגה — טקסט. null ← "לא ידוע". */
function kFieldText(f, val) {
    if (val === null || val === undefined || val === '') return K.unknown;
    if (f === 'deal_date') return fmtDate(val);
    if (f === 'price' || f === 'area' || f === 'rooms') return fNum(val);
    if (f === 'currency') return K.currencies[val] || val;
    if (f === 'location_precision') return K.precisions[val] || val;
    if (f === 'area_kind') return val === 'unknown' ? K.unknown : kAreaKind(val);
    return String(val);
}
function kToastError(x, reload) {
    toastError(x);
    if (x.code === 'comp_changed') reload();
}
const kWhyNot = (calc, compKind, setKind) => calc.reason === 'area_kind_mismatch' ? K.reasonMismatch(kAreaKind(compKind), kAreaKind(setKind)) : (K.reasons[calc.reason] || calc.reason);

// ── לשונית "עסקאות" בכרטיס התיק ──
let kFilter = 'all';
async function compsTab(pid, reload) {
    const x = await api('case-comps', { params: { case: pid } });
    const count = st => x.comps.filter(k => k.status === st).length;
    const shown = x.comps.filter(k => kFilter === 'all' || k.status === kFilter);
    const compRow = k => {
        const v = k.values, bits = [v.deal_date ? fmtDate(v.deal_date) : null, kArea(v), v.rooms !== null ? K.room(fNum(v.rooms)) : null, v.floor !== null ? K.floorN(v.floor) : null].filter(Boolean);
        return `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/comps/${k.pid}"><div class="body"><div class="l1"><span dir="auto">${esc(kLabel(v))}</span><span class="t"><b>${esc(kPrice(v))}</b></span></div>
            <div class="l2 wrap">${esc(bits.join(' · ') || K.unknown)}${k.status_reason ? ` · <q dir="auto">${esc(k.status_reason)}</q>` : ''}</div>
            <div class="chips">${kStChip(k.status)}${k.dup_of ? `<span class="chip warn">⚠️ ${esc(K.dup)}</span>` : ''}${k.corrected_fields.length ? `<span class="chip">${esc(K.corrected)}</span>` : ''}${
                k.missing.length ? `<span class="chip">${esc(K.missingN(k.missing.length))}</span>` : ''}${k.adjustments ? `<span class="chip">${esc(K.adjN(k.adjustments))}</span>` : ''}${
                k.weight !== null ? `<span class="chip">${esc(K.weight)} ${esc(k.weight)}</span>` : ''}</div></div></li>`;
    };
    const setRow = g => {
        const s = g.summary, line = s.selected === 0 ? K.noneSelected : s.rounded !== null ? `${K.methods[g.method]}: ${kAgMoney(s.rounded, g.unit_basis)}` : s.result_note ? K.resultNotes[s.result_note] : s.min ? `${K.range}: ${kNis(s.min)} – ${kMoney(s.max, g.unit_basis)}` : '';
        return `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/compset/${g.pid}"><span class="use-ic" aria-hidden="true">🧮</span><div class="body"><div class="l1"><span dir="auto">${esc(g.name)}</span>
            <span class="t">${esc(K.unitBases[g.unit_basis])}${g.area_kind ? ` · ${esc(kAreaKind(g.area_kind))}` : ''}</span></div>
            <div class="l2 wrap">${esc(line)}${s.selected ? ` · ${esc(K.selectedN(s.selected, s.counted))}` : ''}</div>
            <div class="l2 wrap">${g.conclusion ? `<b>${esc(K.conclusion)}: ${esc(kAgMoney(g.conclusion.agorot, g.unit_basis))}</b>` : `<span class="muted">${esc(K.noConclusion)}</span>`}</div>
            <div class="chips">${s.caveats.map(c => `<span class="chip warn">${esc(K.caveats[c.code] ? K.caveats[c.code](c.n) : c.code)}</span>`).join('')}${g.subject?.stale ? `<span class="chip bad">${esc(K.staleSubject)}</span>` : ''}</div></div></li>`;
    };
    const critText = c => Object.keys(c).map(key => `${K.crit[key] || key}: ${['date_from', 'date_to'].includes(key) ? fmtDate(c[key]) : c[key]}`).join(' · ');
    const searchRow = s => `<li class="card static"><span class="use-ic" aria-hidden="true">🔎</span><div class="body"><div class="l1"><span dir="auto">${esc(s.source ? s.source.name : s.source_text)}</span><span class="t">${esc(K.resultsN(s.result_count))}</span></div>
        <div class="l2 wrap">${esc(K.searchBy(s.by, fmtDateTime(s.at)))} · ${esc(K.enteredN(s.comps))}</div>
        ${Object.keys(s.criteria).length ? `<div class="l2 wrap" dir="auto">${esc(critText(s.criteria))}</div>` : ''}
        ${s.limitations ? `<div class="l2 wrap" dir="auto">⚠️ ${esc(s.limitations)}</div>` : ''}
        ${s.import ? `<div class="l2 wrap">📥 ${s.import.file ? `<a href="#/cases/${pid}/file/${s.import.file}">${esc(K.imported(s.import.name, s.import.rows))}</a>` : esc(K.imported(s.import.name, s.import.rows))}</div>` : ''}
        <div class="row-btns wrap">${s.source?.url ? sLink(s.source.url, esc(K.openSource), 'btn ghost sm') : ''}${x.perms.write ? `<button type="button" class="btn ghost sm" data-ks-edit="${s.pid}">${K.editSearch}</button>
            <button type="button" class="btn ghost sm" data-ks-add="${s.pid}">${K.addComp}</button>${s.import ? '' : `<a class="btn ghost sm" href="#/cases/${pid}/compimport/${s.pid}">${K.importBtn}</a>`}` : ''}</div></div></li>`;
    const html = `${x.perms.write || x.perms.judge ? `<div class="row-btns wrap">${x.perms.write ? `<button type="button" class="btn primary" id="ks-new">${K.newSearch}</button><button type="button" class="btn ghost" id="kc-new">${K.addComp}</button>` : ''}${
            x.perms.judge ? `<button type="button" class="btn ghost" id="kg-new">${K.newSet}</button>` : ''}</div>` : ''}
        <h2 class="sec-title">🧮 ${K.sets} <small>${x.sets.length || ''}</small></h2>
        ${x.sets.length ? `<ul class="list">${x.sets.map(setRow).join('')}</ul>` : `<p class="empty">${K.noSets}</p>`}
        <h2 class="sec-title">🏘️ ${K.comps} <small>${x.comps.length || ''}</small></h2>
        ${x.comps.length ? `<div class="seg-tabs" role="tablist">${['all', ...x.lists.statuses].map(st => `<button type="button" role="tab" aria-selected="${st === kFilter}" data-kf="${st}">${st === 'all' ? K.all : K.statuses[st]} <small>${st === 'all' ? x.comps.length : count(st) || ''}</small></button>`).join('')}</div>
          ${shown.length ? `<ul class="list">${shown.map(compRow).join('')}</ul>` : `<p class="empty">${K.noCompsFilter}</p>`}` : `<p class="empty">${K.noComps}</p>`}
        <h2 class="sec-title">🔎 ${K.searches} <small>${x.searches.length || ''}</small></h2>
        ${x.searches.length ? `<ul class="list">${x.searches.map(searchRow).join('')}</ul><p class="hint">${K.openSourceHint}</p>` : `<p class="empty">${K.noSearches}</p>`}`;
    return { html, bind: () => {
        $$('[data-kf]').forEach(b => b.addEventListener('click', () => { kFilter = b.dataset.kf; reload(); }));
        $('#ks-new')?.addEventListener('click', () => kSearchSheet(pid, x, null, reload));
        $('#kc-new')?.addEventListener('click', () => kCompSheet(pid, x, null, reload));
        $('#kg-new')?.addEventListener('click', () => kSetSheet(pid, x, null, reload));
        $$('[data-ks-edit]').forEach(b => b.addEventListener('click', () => kSearchSheet(pid, x, x.searches.find(s => s.pid === b.dataset.ksEdit), reload)));
        $$('[data-ks-add]').forEach(b => b.addEventListener('click', () => kCompSheet(pid, x, b.dataset.ksAdd, reload)));
    } };
}

// ── חיפוש ──
function kSearchSheet(pid, x, s, reload) {
    const c = s?.criteria || {}, inp = (key, type = 'text', more = '') => `<label class="f"><span>${K.crit[key]}</span><input name="c_${key}" type="${type}" value="${esc(c[key] ?? '')}" ${more}></label>`;
    sheet(s ? K.editSearch : K.newSearch, `<label class="f"><span>${K.searchSource}</span><select name="source"><option value="">${K.searchSourceOther}</option>${x.sources.map(o => cOpt(o.key, o.name, s?.source?.key || '')).join('')}</select></label>
        <label class="f"><span>${K.searchSourceText}</span><input name="source_text" maxlength="200" value="${esc(s?.source_text || '')}"></label>
        <p class="sec-title">${K.criteria}</p>
        ${inp('area_text', 'text', 'maxlength="200"')}<div class="fields two">${inp('gush', 'text', 'maxlength="20" inputmode="numeric"')}${inp('helka', 'text', 'maxlength="20" inputmode="numeric"')}
          ${inp('date_from', 'date')}${inp('date_to', 'date')}${inp('area_min', 'text', 'inputmode="decimal"')}${inp('area_max', 'text', 'inputmode="decimal"')}
          ${inp('rooms_min', 'text', 'inputmode="decimal"')}${inp('rooms_max', 'text', 'inputmode="decimal"')}</div>
        ${inp('property_type', 'text', 'maxlength="80"')}${inp('other', 'text', 'maxlength="300"')}
        <label class="f"><span>${K.resultCount}</span><input name="result_count" inputmode="numeric" value="${esc(s?.result_count ?? '')}"></label><p class="hint">${K.resultCountHint}</p>
        <label class="f"><span>${K.limitations}</span><textarea name="limitations" maxlength="500" rows="2">${esc(s?.limitations || '')}</textarea></label>`, async f => {
        const criteria = {};
        Object.keys(K.crit).forEach(key => { if (f['c_' + key]) criteria[key] = f['c_' + key].trim(); });
        const body = { source: f.source || null, source_text: f.source_text, criteria, result_count: f.result_count === '' ? null : f.result_count.trim(), limitations: f.limitations };
        await api(s ? 'comp-search-update' : 'comp-search-add', { body: s ? { search: s.pid, rev: s.rev, ...body } : { case: pid, ...body } });
        reload();
    });
}

// ── הזנה ותיקון של עסקה ──
/** שדות הטופס של עסקה. cur — הערכים הנוכחיים (בתיקון). */
function kValueFields(lists, cur = {}) {
    const val = f => esc(cur[f] ?? ''), txt = (f, max, more = '') => `<label class="f"><span>${K.fields[f]}</span><input name="${f}" maxlength="${max}" value="${val(f)}" ${more}></label>`;
    const num = (f, mode = 'decimal') => `<label class="f"><span>${K.fields[f]}</span><input name="${f}" inputmode="${mode}" value="${val(f)}" dir="ltr"></label>`;
    const sel = (f, opts, labels, def) => `<label class="f"><span>${K.fields[f]}</span><select name="${f}">${opts.map(o => cOpt(o, labels(o), cur[f] ?? def)).join('')}</select></label>`;
    return `<div class="fields two"><label class="f"><span>${K.fields.deal_date}</span><input type="date" name="deal_date" value="${val('deal_date')}"></label>${num('price')}</div>
        ${txt('address_text', 200)}<div class="fields two">${txt('locality', 80)}${sel('location_precision', lists.precisions, o => K.precisions[o], 'unknown')}
          ${txt('gush', 20, 'inputmode="numeric" dir="ltr"')}${txt('helka', 20, 'inputmode="numeric" dir="ltr"')}${txt('tat_helka', 20, 'inputmode="numeric" dir="ltr"')}${num('distance_m', 'numeric')}
          ${num('area')}${sel('area_kind', lists.area_kinds, o => o === 'unknown' ? K.unknown : kAreaKind(o), 'unknown')}${num('rooms')}${num('floor', 'text')}${num('floors_total', 'numeric')}${num('build_year', 'numeric')}</div>
        ${txt('property_type', 80)}${txt('condition', 200)}${txt('attachments', 300)}
        <div class="fields two">${txt('source_ref', 120, 'dir="ltr"')}${sel('currency', lists.currencies, o => K.currencies[o], 'ILS')}</div>`;
}
const K_FORM_FIELDS = ['deal_date', 'price', 'currency', 'address_text', 'locality', 'gush', 'helka', 'tat_helka', 'location_precision', 'distance_m', 'property_type', 'area', 'area_kind', 'rooms', 'floor', 'floors_total', 'build_year', 'condition', 'attachments', 'source_ref'];
const kFormValues = f => Object.fromEntries(K_FORM_FIELDS.map(key => [key, (f[key] ?? '').trim() === '' ? null : f[key].trim()]));

function kCompSheet(pid, x, searchPid, reload) {
    sheet(K.addComp, `<p class="hint">${K.addHint}</p>
        <label class="f"><span>${K.forSearch}</span><select name="search"><option value="">${K.noSearch}</option>${x.searches.map(s => cOpt(s.pid, `${s.source ? s.source.name : s.source_text} · ${fmtDate(s.at)}`, searchPid || '')).join('')}</select></label>
        ${kValueFields(x.lists)}<label class="f"><span>${K.note}</span><textarea name="note" maxlength="1000" rows="2"></textarea></label>`, async f => {
        const r = await api('comp-add', { body: { case: pid, search: f.search || null, values: kFormValues(f), note: f.note } });
        if (r.dup_of) toast(K.dupOf, true);
        location.hash = `#/cases/${pid}/comps/${r.comp}`;
    }, K.addComp);
}

// ── מסך עסקה ──
async function viewComp(pid, compPid) {
    const x = await api('comp', { params: { comp: compPid } });
    const k = x.comp, v = k.values, reload = () => viewComp(pid, compPid);
    setHead(K.tab, null);
    const corr = k.corrected || {};
    const shownFields = ['deal_date', 'price', 'address', 'locality', 'gush', 'helka', 'tat_helka', 'location_precision', 'property_type', 'area', 'rooms', 'floor', 'floors_total', 'build_year', 'condition', 'attachments', 'source_ref'];
    const fieldRow = f => {
        const key = f === 'address' ? 'address_text' : f, cx = corr[key];
        let val = f === 'address' ? (v.address ?? (v.approx_location ? v.approx_location + ' — ' + K.approxNote : null)) : f === 'price' ? (v.price === null ? null : kPrice(v)) : f === 'area' ? kArea(v) : v[f];
        const text = f === 'address' || f === 'price' || f === 'area' ? (val ?? K.unknown) : kFieldText(f, val);
        const known = !(val === null || val === undefined || (f === 'location_precision' && val === 'unknown'));
        return `<li class="card static"><div class="body"><div class="l1"><span>${esc(f === 'address' && !v.address && v.approx_location ? K.approx : K.fields[f])}</span><span class="t">${known ? `<b dir="auto">${esc(text)}</b>` : `<span class="muted">${esc(K.unknown)}</span>`}</span></div>
            ${cx ? `<div class="l2 wrap"><span class="chip">${esc(K.corrected)}</span> ${esc(K.correctedFrom(kFieldText(key, cx.from), cx.by, fmtDate(cx.at)))} · <q dir="auto">${esc(cx.reason)}</q></div>` : ''}</div></li>`;
    };
    const precise = ['exact', 'street'].includes(v.location_precision);
    const simRow = r => `<li class="card static"><div class="body"><div class="l1"><span>${esc(K.fields[r.field] || r.field)}</span><span class="t"><b dir="auto">${esc(kFieldText(r.field, r.comp))}</b> ${esc(K.vs)} <b dir="auto">${esc(r.field === 'area' ? fNum(r.subject) : r.subject)}</b>${
        r.area_kind ? ` <small class="muted">(${esc(K.bothKind(kAreaKind(r.area_kind)))})</small>` : ''}</span></div><div class="chips"><span class="chip ${r.verdict === 'same' ? 'ok' : 'warn'}">${esc(K[r.verdict])}</span></div></div></li>`;
    const adjRow = (a, i, n) => `<li class="card static"><div class="body"><div class="l1"><span dir="auto">${esc(a.label)}</span><span class="t"><b dir="ltr">${a.sign === 'minus' ? '−' : '+'}${esc(fNum(a.value))}${a.kind === 'percent' ? '%' : ''}</b></span></div>
        <div class="l2 wrap">${a.kind === 'percent' ? esc(K.bases[a.basis]) + ' · ' : ''}${esc(K.adjApplies)}: ${esc(K.applies[a.applies_to])} · <q dir="auto">${esc(a.reason)}</q></div>
        ${x.perms.judge ? `<div class="row-btns wrap"><button type="button" class="btn ghost sm" data-ka-up="${i}"${i === 0 ? ' disabled' : ''} aria-label="${K.up}">▲</button><button type="button" class="btn ghost sm" data-ka-down="${i}"${i === n - 1 ? ' disabled' : ''} aria-label="${K.down}">▼</button>
            <button type="button" class="btn ghost sm" data-ka-edit="${a.pid}">${K.editAdj}</button></div>` : ''}</div></li>`;
    const formula = c => {
        const basis = c.set.unit_basis, calc = c.calc;
        if (!calc.ok) return `<h3 class="sec-title">${esc(K.formulaFor(c.set.name))}</h3><p class="banner" role="note">${basis === 'per_sqm' ? esc(K.notComparable) : esc(K.notInCalc)}: ${esc(kWhyNot(calc, v.area_kind, c.set.area_kind))}</p>`;
        // הסימן נגזר מהשינוי עצמו, לא מהסימן שהוזן: הפחתה באחוז מסכום שוטף שלילי היא תוספת (ביקורת 15, 🟡5)
        const sg = s => (String(s.delta.agorot).startsWith('-') ? '−' : '+');
        const stepLine = s => `<li><span dir="ltr">${sg(s)} ${esc(kNis({ nis: String(s.delta.nis).replace('-', '') }))}</span> <span dir="auto">(${esc(s.label)}${s.kind === 'percent' ? ` · ${esc(fNum(s.value))}% ${esc(s.basis === 'running' ? K.ofRunning : K.ofBase)}` : ''})</span> <small class="muted" dir="ltr">= ${esc(kNis(s.running))}</small></li>`;
        const oneLine = kNis(calc.base) + calc.steps.map(s => ' ' + sg(s) + ' ' + kNis({ nis: String(s.delta.nis).replace('-', '') }) + ' (' + s.label + ')').join('') + ' = ' + kMoney(calc.adjusted, basis);      // טקסט — מוצג דרך esc()
        return `<h3 class="sec-title">${esc(K.formulaFor(c.set.name))} <small class="muted">${esc(K.unitBases[basis])}${c.set.area_kind ? ` · ${esc(kAreaKind(c.set.area_kind))}` : ''}</small></h3>
            <div class="summary kformula"><div class="line"><span>${esc(K.base)}</span><b dir="ltr">${esc(kMoney(calc.base, basis))}</b></div>
              ${calc.steps.length ? `<ol class="ksteps">${calc.steps.map(stepLine).join('')}</ol>` : `<div class="line muted">${esc(K.noAdj)}</div>`}
              <div class="line"><span>${esc(K.adjusted)}</span><b dir="ltr">${esc(kMoney(calc.adjusted, basis))}</b></div>
              <p class="hint" dir="auto">${esc(oneLine)}</p>${calc.adjusted.exact ? `<p class="hint">${esc(K.exact)}: <span dir="ltr">${esc(calc.adjusted.exact)}</span> (אג׳)</p>` : ''}
              ${calc.nonpositive ? `<p class="banner bad" role="alert">⚠️ ${esc(K.nonpositive)}</p>` : ''}</div>`;
    };
    const rawRows = k.raw.row || k.raw.entered || {};
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one" dir="auto">${esc(kLabel(v))}</b></div>
          <div class="line"><b>${esc(kPrice(v))}</b><span class="muted">${v.deal_date ? esc(fmtDate(v.deal_date)) : esc(K.fields.deal_date + ': ' + K.unknown)}</span></div>
          <div class="chips">${x.perms.judge ? `<button type="button" class="chip info" id="k-status">${esc(K.statuses[k.status])} ▾</button>` : kStChip(k.status)}${k.weight !== null ? `<span class="chip">${esc(K.weight)} ${esc(k.weight)}</span>` : ''}</div>
          ${k.status_reason ? `<div class="line muted wrap"><q dir="auto">${esc(k.status_reason)}</q></div>` : ''}</div>
        ${k.dup_of ? `<p class="banner" role="note">⚠️ ${esc(K.dupOf)} <a class="btn ghost sm" href="#/cases/${pid}/comps/${k.dup_of}">${K.dupSee}</a><br><small>${esc(K.dupNote)}</small></p>` : ''}
        ${k.status !== 'selected' && x.calcs.length ? `<p class="hint">${esc(K.notInSetYet)}</p>` : ''}
        ${x.calcs.length ? x.calcs.map(formula).join('') : `<p class="hint">${esc(K.noSetsForFormula)}</p>`}
        <h2 class="sec-title">${K.adjustments} <small>${x.adjustments.length || ''}</small></h2>
        ${x.adjustments.length ? `<ul class="list">${x.adjustments.map((a, i) => adjRow(a, i, x.adjustments.length)).join('')}</ul>${x.adjustments.length > 1 ? `<p class="hint">${esc(K.orderHint)}</p>` : ''}` : `<p class="empty">${K.noAdj}</p>`}
        ${x.perms.judge ? `<div class="row-btns wrap"><button type="button" class="btn ghost" id="ka-new"><span aria-hidden="true">+</span> ${K.addAdj}</button><button type="button" class="btn ghost" id="k-weight">${K.weight}</button></div>` : ''}
        <h2 class="sec-title">${K.similar}</h2>
        ${x.similar.rows.length ? `<ul class="list">${x.similar.rows.map(simRow).join('')}</ul>` : `<p class="empty">${K.similarNone}</p>`}
        ${x.similar.unknown.length ? `<p class="hint">${x.similar.unknown.map(u => `${esc(K.fields[u.field] || u.field)}: <b>${esc(K.unknownSide[u.side])}</b>`).join(' · ')}</p>` : ''}
        <h2 class="sec-title">${K.details}</h2>
        <ul class="list">${shownFields.map(fieldRow).join('')}</ul>
        <p class="hint">${precise ? (v.distance_m === null ? esc(K.distanceNone) : `${esc(K.distance)}: ${esc(K.meters(v.distance_m))}`) : esc(K.distanceUnknown)}</p>
        <p class="hint">${k.missing.length ? `${esc(K.missingList)}: ${k.missing.map(f => esc(K.fields[f] || f)).join(', ')}` : esc(K.nothingMissing)}</p>
        ${x.perms.write ? `<div class="row-btns wrap"><button type="button" class="btn ghost" id="k-correct">${K.correct}</button><button type="button" class="btn ghost" id="k-note">${K.note}</button></div>` : ''}
        ${k.note ? `<p class="banner" role="note" dir="auto">📝 ${esc(k.note)}</p>` : ''}
        ${x.search ? `<p class="hint">🔎 ${esc(x.search.source ? x.search.source.name : x.search.source_text)} · ${esc(K.searchBy(x.search.by, fmtDateTime(x.search.at)))}</p>` : ''}
        <details><summary class="hint">${esc(K.raw)} · ${esc(k.origin === 'import' ? K.rawImported : K.rawEntered)}</summary>
          <ul class="list">${Object.keys(rawRows).map(h => `<li class="card static"><div class="body"><div class="l1"><span dir="auto">${esc(k.raw.entered ? (K.fields[h] || h) : h)}</span><span class="t" dir="auto">${rawRows[h] === null || rawRows[h] === '' ? `<span class="muted">—</span>` : esc(rawRows[h])}</span></div></div></li>`).join('')}</ul>
          ${k.raw.unparsed ? `<p class="hint">⚠️ ${esc(K.unparsed)}: ${k.raw.unparsed.map(f => esc(K.fields[f] || f)).join(', ')}</p>` : ''}</details>
        <details><summary class="hint">${esc(T.c.tabs.activity)}</summary>${x.history.length ? `<ul class="list">${x.history.map(h => `<li class="card static"><div class="body"><div class="l1"><span>${esc((T.audit[h.action] || (() => h.action))(h))}</span></div>
            <div class="l2 wrap">${esc(h.by || '')} · ${esc(fmtDateTime(h.at))}${h.reason ? ` · <q dir="auto">${esc(h.reason)}</q>` : ''}</div></div></li>`).join('')}</ul>` : ''}</details>
        ${x.perms.write ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="k-delete">${K.deleteComp}</button></div>` : ''}`;

    const act = async (action, body) => { try { await api(action, { body: { comp: k.pid, rev: k.rev, ...body } }); reload(); } catch (e) { kToastError(e, reload); } };
    $('#k-status')?.addEventListener('click', () => sheet(K.statusTitle, `<fieldset class="seg grid" style="--cols:2" aria-label="${K.statusTitle}">${x.lists.statuses.map(st => `<label><input type="radio" name="status" value="${st}"${st === k.status ? ' checked' : ''}><span>${K.statuses[st]}</span></label>`).join('')}</fieldset>
        <p class="hint">${x.lists.statuses.map(st => `<b>${K.statuses[st]}</b> — ${K.statusHint[st]}`).join(' · ')}</p>
        <label class="f"><span>${K.statusReason}</span><textarea name="reason" maxlength="500" rows="2">${esc(k.status_reason || '')}</textarea></label>`,
        async f => { await api('comp-status', { body: { comp: k.pid, rev: k.rev, status: f.status, reason: f.reason } }); reload(); }));
    $('#k-weight')?.addEventListener('click', () => sheet(K.weight, `<p class="hint">${K.weightHint}</p><label class="f"><span>${K.weight}</span><input name="weight" inputmode="decimal" dir="ltr" value="${esc(k.weight ?? '')}"></label>`,
        async f => { await api('comp-weight', { body: { comp: k.pid, rev: k.rev, weight: f.weight.trim() === '' ? null : f.weight.trim() } }); reload(); }));
    $('#k-note')?.addEventListener('click', () => sheet(K.note, `<label class="f"><span>${K.note}</span><textarea name="note" maxlength="1000" rows="4">${esc(k.note || '')}</textarea></label>`,
        async f => { await api('comp-note', { body: { comp: k.pid, rev: k.rev, note: f.note } }); reload(); }));
    $('#k-correct')?.addEventListener('click', () => {
        const cur = { ...v, address_text: v.address ?? v.approx_location };
        sheet(K.correct, `<p class="hint">${K.correctHint}</p>${kValueFields(x.lists, cur)}<label class="f"><span>${K.correctReason}</span><textarea name="reason" maxlength="500" rows="2" required></textarea></label>`, async f => {
            const next = kFormValues(f), changes = {};
            K_FORM_FIELDS.forEach(key => { if (String(next[key] ?? '') !== String(cur[key] ?? '')) changes[key] = next[key]; });
            await api('comp-correct', { body: { comp: k.pid, rev: k.rev, changes, reason: f.reason } });
            reload();
        });
    });
    $('#k-delete')?.addEventListener('click', () => sheet(K.deleteComp, `<p class="hint">${K.deleteHint}</p><label class="f"><span>${K.deleteReason}</span><textarea name="reason" maxlength="500" rows="2" required></textarea></label>`,
        async f => { await api('comp-delete', { body: { comp: k.pid, rev: k.rev, reason: f.reason } }); location.hash = `#/cases/${pid}/comps`; }, K.deleteComp));
    const move = (i, d) => { const order = x.adjustments.map(a => a.pid); [order[i], order[i + d]] = [order[i + d], order[i]]; act('comp-adj-order', { order }); };
    $$('[data-ka-up]').forEach(b => b.addEventListener('click', () => move(+b.dataset.kaUp, -1)));
    $$('[data-ka-down]').forEach(b => b.addEventListener('click', () => move(+b.dataset.kaDown, 1)));
    const adjSheet = a => {
        const radio = (name, opts, labels, cur) => `<fieldset class="seg" aria-label="${name}">${opts.map(o => `<label><input type="radio" name="${name}" value="${o}"${o === cur ? ' checked' : ''}><span>${labels[o]}</span></label>`).join('')}</fieldset>`;
        const dlg = sheet(a ? K.editAdj : K.addAdj, `<label class="f"><span>${K.adjLabel}</span><input name="label" maxlength="80" required value="${esc(a?.label || '')}"></label>
            <p class="sec-title">${K.adjKind}</p>${radio('kind', ['amount', 'percent'], K.kinds, a?.kind || 'amount')}
            <p class="sec-title">${K.adjSign}</p>${radio('sign', ['plus', 'minus'], K.signs, a?.sign || 'plus')}
            <label class="f"><span>${K.adjValue}</span><input name="value" inputmode="decimal" dir="ltr" required value="${esc(a?.value || '')}"></label>
            <div data-only="percent"><p class="sec-title">${K.adjBasis}</p><fieldset class="seg grid" style="--cols:1">${['base', 'running'].map(o => `<label><input type="radio" name="basis" value="${o}"${o === (a?.basis || 'base') ? ' checked' : ''}><span>${K.bases[o]}</span></label>`).join('')}</fieldset></div>
            <p class="sec-title">${K.adjApplies}</p>${radio('applies_to', ['total', 'per_sqm', 'both'], K.applies, a?.applies_to || (x.calcs[0]?.set.unit_basis ?? 'total'))}
            <p class="hint" data-only="amount">${K.adjAmountHint}</p>
            <label class="f"><span>${K.adjReason}</span><textarea name="reason" maxlength="500" rows="2" required>${esc(a?.reason || '')}</textarea></label><p class="hint">${K.adjReasonHint}</p>
            ${a ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="ka-del">${K.deleteAdj}</button></div>` : ''}`,
            async f => { await api('comp-adj-save', { body: { comp: k.pid, rev: k.rev, ...(a ? { adj: a.pid } : {}), label: f.label, kind: f.kind, value: f.value.trim(), sign: f.sign, basis: f.basis, applies_to: f.applies_to, reason: f.reason } }); reload(); });
        const sync = () => {
            const kind = dlg.querySelector('[name=kind]:checked').value, both = dlg.querySelector('[name=applies_to][value=both]');
            $$('[data-only]', dlg).forEach(el => { el.hidden = el.dataset.only !== kind; });
            both.disabled = kind === 'amount';
            both.closest('label').hidden = kind === 'amount';
            if (kind === 'amount' && both.checked) dlg.querySelector('[name=applies_to][value=total]').checked = true;
        };
        $$('[name=kind]', dlg).forEach(el => el.addEventListener('change', sync));
        sync();
        $('#ka-del', dlg)?.addEventListener('click', async () => { if (!confirm(K.deleteAdjAsk)) return; dlg.close(); act('comp-adj-delete', { adj: a.pid }); });
    };
    $('#ka-new')?.addEventListener('click', () => adjSheet(null));
    $$('[data-ka-edit]').forEach(b => b.addEventListener('click', () => adjSheet(x.adjustments.find(a => a.pid === b.dataset.kaEdit))));
}

// ── קבוצת השוואה ──
function kSetSheet(pid, x, g, reload) {
    const d = g || { name: '', unit_basis: x.defaults?.unit_basis || 'total', area_kind: x.defaults?.area_kind || 'registered', method: x.defaults?.method || 'mean', rounding: x.defaults?.rounding || 'none' };
    const facts = x.subject_areas || [], curFact = x.subject?.fact?.pid || '';
    const dlg = sheet(g ? K.setSettings : K.newSet, `${g ? '' : `<p class="hint">${K.defaultsHint}</p>`}<label class="f"><span>${K.setName}</span><input name="name" maxlength="80" value="${esc(d.name)}" placeholder="${K.setDefaultName}"></label>
        <p class="sec-title">${K.unitBasis}</p><fieldset class="seg" aria-label="${K.unitBasis}">${['total', 'per_sqm'].map(o => `<label><input type="radio" name="unit_basis" value="${o}"${o === d.unit_basis ? ' checked' : ''}><span>${K.unitBases[o]}</span></label>`).join('')}</fieldset>
        <div data-sqm><label class="f"><span>${K.setAreaKind}</span><select name="area_kind">${x.lists.set_area_kinds.map(o => cOpt(o, kAreaKind(o), d.area_kind || 'registered')).join('')}</select></label>
          <label class="f"><span>${K.subjectArea}</span><select name="subject_area_fact"><option value="">${K.subjectNone}</option>${facts.map(f => `<option value="${f.pid}" data-kind="${esc(f.area_kind)}"${f.pid === curFact ? ' selected' : ''}>${esc(fNum(f.num))} ${esc(K.sqm)} (${esc(kAreaKind(f.area_kind))}) · ${esc(T.f.states[f.state] || f.state)}</option>`).join('')}</select></label>
          <p class="hint" id="kg-nofact" hidden>${K.subjectNoFacts}</p></div>
        <label class="f"><span>${K.method}</span><select name="method">${x.lists.methods.map(o => cOpt(o, K.methods[o], d.method)).join('')}</select></label>
        <label class="f"><span>${K.rounding}</span><select name="rounding">${x.lists.roundings.map(o => cOpt(o, K.roundings[o], d.rounding)).join('')}</select></label><p class="hint">${K.roundingNote}</p>
        ${g ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="kg-del">${K.deleteSet}</button></div>` : ''}`, async f => {
        const body = { name: f.name, unit_basis: f.unit_basis, area_kind: f.unit_basis === 'per_sqm' ? f.area_kind : null, method: f.method, rounding: f.rounding, subject_area_fact: f.unit_basis === 'per_sqm' ? (f.subject_area_fact || null) : null };
        const r = await api('comp-set-save', { body: g ? { set: g.pid, rev: g.rev, ...body } : { case: pid, ...body } });
        if (g) reload(); else location.hash = `#/cases/${pid}/compset/${r.set}`;
    });
    const sync = () => {
        const sqm = dlg.querySelector('[name=unit_basis]:checked').value === 'per_sqm', kind = dlg.querySelector('[name=area_kind]').value, sel = dlg.querySelector('[name=subject_area_fact]');
        $('[data-sqm]', dlg).hidden = !sqm;
        let any = false;
        [...sel.options].forEach(o => { if (!o.value) return; o.hidden = o.disabled = o.dataset.kind !== kind; any = any || !o.disabled; });
        if (sel.selectedOptions[0]?.disabled) sel.value = '';
        $('#kg-nofact', dlg).hidden = any;
    };
    $$('[name=unit_basis], [name=area_kind]', dlg).forEach(el => el.addEventListener('change', sync));
    sync();
    $('#kg-del', dlg)?.addEventListener('click', async () => {
        if (!confirm(K.deleteSetAsk)) return;
        try { await api('comp-set-delete', { body: { set: g.pid, rev: g.rev } }); dlg.close(); location.hash = `#/cases/${pid}/comps`; } catch (e) { toastError(e); }
    });
}

async function viewCompSet(pid, setPid) {
    const x = await api('comp-set', { params: { set: setPid } });
    const g = x.set, s = x.summary, basis = g.unit_basis, reload = () => viewCompSet(pid, setPid);
    setHead(g.name, null);
    App.main.classList.add('wide');
    const row = r => {
        const v = r.comp.values, c = r.calc, delta = c.ok ? c.steps.length : 0;
        return `<tr data-go="#/cases/${pid}/comps/${r.comp.pid}" tabindex="0" role="link"><td data-l="${K.table.comp}"><span dir="auto">${esc(kLabel(v))}</span><br><small class="muted">${v.deal_date ? esc(fmtDate(v.deal_date)) : ''}${kArea(v) ? ' · ' + esc(kArea(v)) : ''}</small>
              ${r.outlier ? `<br><span class="chip warn" title="${esc(K.outlierHint)}">${esc(K.outlier)}</span>` : ''}${r.comp.dup_of ? `<span class="chip warn">${esc(K.dup)}</span>` : ''}${r.comp.missing.length ? `<span class="chip">${esc(K.missingN(r.comp.missing.length))}</span>` : ''}</td>
            ${c.ok ? `<td data-l="${K.table.base}" dir="ltr">${esc(kNis(c.base))}</td><td data-l="${K.table.adj}">${delta ? esc(K.adjN(delta)) : '—'}</td>
              <td data-l="${K.table.adjusted}" dir="ltr"><b>${esc(kNis(c.adjusted))}</b>${c.nonpositive ? ' ⚠️' : ''}</td>`
                : `<td colspan="3" data-l="${basis === 'per_sqm' ? K.notComparable : K.notInCalc}"><span class="chip bad">${esc(kWhyNot(c, v.area_kind, g.area_kind))}</span></td>`}
            <td data-l="${K.table.weight}">${r.comp.weight === null ? `<span class="muted">${esc(K.noWeight)}</span>` : `${esc(r.comp.weight)}${r.share !== null ? ` <small class="muted">(${esc(K.share(r.share))})</small>` : ''}`}</td></tr>`;
    };
    const stat = (label, val, strong = false) => `<div class="line"><span>${esc(label)}</span>${strong ? `<b dir="ltr">${esc(val)}</b>` : `<span dir="ltr">${esc(val)}</span>`}</div>`;
    const sub = x.subject;
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one" dir="auto">${esc(g.name)}</b></div>
          <div class="line muted">${esc(K.unitBases[basis])}${g.area_kind ? ` · ${esc(kAreaKind(g.area_kind))}` : ''} · ${esc(K.methods[g.method])} · ${esc(K.roundings[g.rounding])}</div>
          ${x.perms.judge ? `<div class="row-btns"><button type="button" class="btn ghost sm" id="kg-edit">${K.setSettings}</button></div>` : ''}</div>
        ${s.selected ? `<div class="ktable-wrap"><table class="ktable"><thead><tr><th>${K.table.comp}</th><th>${K.table.base} (${esc(kUnit(basis))})</th><th>${K.table.adj}</th><th>${K.table.adjusted}</th><th>${K.table.weight}</th></tr></thead><tbody>${x.rows.map(row).join('')}</tbody></table></div>`
            : `<p class="empty">${K.noneSelected}</p>`}
        <h2 class="sec-title">${K.summary}</h2>
        ${s.selected ? `<div class="summary">${stat(K.comps, K.selectedN(s.selected, s.counted))}
          ${s.skipped.length ? `<div class="line muted wrap">${s.skipped.map(k => { const r = x.rows.find(q => q.comp.pid === k.pid); return esc(`${kLabel(r.comp.values)} — ${kWhyNot(r.calc, r.comp.values.area_kind, g.area_kind)}`); }).join(' · ')}</div>` : ''}
          ${s.min ? stat(K.range, `${kNis(s.min)} – ${kMoney(s.max, basis)}`) : ''}${s.mean ? stat(K.mean, kMoney(s.mean, basis)) : ''}${s.median ? stat(K.median, kMoney(s.median, basis)) : ''}
          ${s.weighted ? stat(K.weighted, kMoney(s.weighted, basis)) : ''}${s.weights_block && (g.method === 'weighted' || x.rows.some(r => r.comp.weight !== null)) ? `<div class="line muted wrap">${esc(K.weighted)}: ${esc(K.resultNotes[s.weights_block])}</div>` : ''}
          ${s.rounded !== null ? `${stat(`${K.result} — ${K.methods[g.method]}`, kAgMoney(s.rounded, basis), true)}<div class="line muted">${esc(K.roundings[g.rounding])} · ${esc(K.unrounded(kAgMoney(s.unrounded, basis)))}</div>`
              : s.result_note ? `<p class="banner" role="note">${esc(K.resultNotes[s.result_note] || s.result_note)}</p>` : ''}
          ${s.date_from ? stat(K.dates, s.date_from === s.date_to ? fmtDate(s.date_from) : `${fmtDate(s.date_from)} – ${fmtDate(s.date_to)}`) : ''}</div>
          ${s.caveats.length ? `<p class="sec-title">${K.caveatsTitle}</p><div class="chips">${s.caveats.map(c => `<span class="chip warn">${esc(K.caveats[c.code] ? K.caveats[c.code](c.n) : c.code)}</span>`).join('')}</div>` : ''}
          <p class="hint">${esc(K.roundingNote)}</p>` : `<p class="empty">${K.noneSelected}</p>`}
        <h2 class="sec-title">⚖️ ${K.conclusion}</h2>
        <div class="summary kconclusion">${g.conclusion ? `<div class="line"><span>${esc(K.conclusionValue)}</span><b dir="ltr" class="big">${esc(kAgMoney(g.conclusion.agorot, basis))}</b></div>
            <div class="line wrap"><q dir="auto">${esc(g.conclusion.reason)}</q></div><div class="line muted">${esc(K.concludedBy(g.conclusion.by, fmtDateTime(g.conclusion.at)))}</div>` : `<div class="line muted">${esc(K.noConclusion)}</div>`}
          <p class="hint">${esc(K.conclusionHint)}</p>
          ${x.perms.judge ? `<div class="row-btns"><button type="button" class="btn ${g.conclusion ? 'ghost' : 'primary'}" id="kg-conclude">${g.conclusion ? K.concludeEdit : K.conclude}</button></div>` : ''}</div>
        ${sub ? `${sub.stale ? `<p class="banner bad" role="alert">⚠️ ${esc(K.staleSubject)}${x.perms.judge ? ` <button type="button" class="btn ghost sm" id="kg-recheck">${K.recheck}</button>` : ''}</p>` : ''}
          ${!sub.usable ? `<p class="banner bad" role="alert">⚠️ ${esc(K.subjectUnusable)}</p>` : `<div class="summary"><div class="line"><span>${esc(K.subjectArea)}</span><b>${esc(fNum(sub.area))} ${esc(K.sqm)} (${esc(kAreaKind(sub.area_kind))})</b></div>
            ${sub.fact ? `<div class="line muted wrap">${fSource(sub.fact, pid)} · ${fStateChip(sub.fact)}</div>` : ''}
            ${sub.total ? `<div class="line wrap"><span>${esc(K.helper)}</span><span dir="auto">${esc(K.helperLine(kAgMoney(g.conclusion.agorot, basis), fNum(sub.area), kAreaKind(sub.area_kind), kMoney(sub.total, 'total')))}</span></div>` : ''}</div>`}` : ''}`;
    cOn('tr[data-go]', el => { location.hash = el.dataset.go; });
    $('#kg-edit')?.addEventListener('click', () => kSetSheet(pid, x, g, reload));
    $('#kg-recheck')?.addEventListener('click', async () => {
        try { await api('comp-set-save', { body: { set: g.pid, rev: g.rev, name: g.name, unit_basis: g.unit_basis, area_kind: g.area_kind, method: g.method, rounding: g.rounding, subject_area_fact: sub.fact?.pid || null, recheck: true } }); reload(); } catch (e) { kToastError(e, reload); }
    });
    $('#kg-conclude')?.addEventListener('click', () => {
        const plain = ag => { const d = String(ag).padStart(3, '0'), f = d.slice(-2).replace(/0+$/, ''); return d.slice(0, -2) + (f ? '.' + f : ''); };
        const meanAg = s.mean ? s.mean.agorot : null, medAg = s.median ? s.median.agorot : null;
        const dlg = sheet(K.conclusion, `<p class="hint">${K.conclusionHint}</p>
            <div class="summary">${s.min ? stat(K.range, `${kNis(s.min)} – ${kMoney(s.max, basis)}`) : ''}${s.mean ? stat(K.mean, kMoney(s.mean, basis)) : ''}${s.median ? stat(K.median, kMoney(s.median, basis)) : ''}${s.rounded !== null ? stat(K.result, kAgMoney(s.rounded, basis)) : ''}</div>
            <label class="f"><span>${K.conclusionValue} (${esc(kUnit(basis))})</span><input name="value" inputmode="decimal" dir="ltr" value="${esc(g.conclusion?.value || '')}"></label>
            <div class="row-btns wrap">${meanAg !== null && !String(meanAg).startsWith('-') ? `<button type="button" class="btn ghost sm" data-copy="${esc(plain(meanAg))}">${K.copyMean}</button>` : ''}${medAg !== null && !String(medAg).startsWith('-') ? `<button type="button" class="btn ghost sm" data-copy="${esc(plain(medAg))}">${K.copyMedian}</button>` : ''}</div>
            <label class="f"><span>${K.conclusionReason}</span><textarea name="reason" maxlength="500" rows="3" required>${esc(g.conclusion?.reason || '')}</textarea></label>
            ${g.conclusion ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="kg-clear">${K.clearConclusion}</button></div>` : ''}`,
            async f => { await api('comp-set-conclude', { body: { set: g.pid, rev: g.rev, value: f.value.trim() === '' ? null : f.value.trim(), reason: f.reason } }); reload(); });
        // "העתקה" ממלאת רק את שדה הערך: הנימוק נשאר חובה, והשמירה — רק בלחיצה של השמאי
        $$('[data-copy]', dlg).forEach(b => b.addEventListener('click', () => { dlg.querySelector('[name=value]').value = b.dataset.copy; dlg.querySelector('[name=reason]').focus(); toast(K.copiedNeedReason); }));
        $('#kg-clear', dlg)?.addEventListener('click', async () => { try { await api('comp-set-conclude', { body: { set: g.pid, rev: g.rev, value: null } }); dlg.close(); reload(); } catch (e) { kToastError(e, reload); } });
    });
}

// ── ייבוא ──
let KI = null;
async function viewCompImport(pid, searchPid) {
    setHead(K.imp.title, null);
    if (!KI || KI.search !== searchPid) KI = { search: searchPid, src: null, hasHeader: true, columns: null, mapping: {}, dateOrder: 'dmy', areaKind: 'unknown', precision: 'unknown', preview: null, lists: null };
    const lists = KI.lists || (KI.lists = (await api('case-comps', { params: { case: pid } })).lists);
    const draw = () => (KI.columns ? drawMap() : drawPick());
    const src = () => (KI.src.file ? { file: KI.src.file } : { text: KI.src.text, name: KI.src.name });
    const body = extra => ({ case: pid, ...src(), has_header: KI.hasHeader, ...extra });
    const opts = () => ({ mapping: KI.mapping, date_order: KI.dateOrder, defaults: { area_kind: KI.areaKind, location_precision: KI.precision } });
    function drawPick() {
        App.main.innerHTML = `<p class="hint">${K.imp.step1} ${esc(K.imp.max(fNum(String(lists.import_max))))}</p>
            <form class="panel" id="ki-pick" novalidate><label class="f"><span>${K.imp.file}</span><input type="file" name="file" accept=".csv,.tsv,.txt,.xlsx,text/csv"></label>
              <label class="f"><span>${K.imp.paste}</span><textarea name="paste" rows="5" placeholder="${K.imp.pastePh}" dir="auto"></textarea></label>
              <label class="switch"><input type="checkbox" name="hdr" checked><span>${K.imp.hasHeader}</span></label>
              <div class="row-btns"><button type="submit" class="btn primary">${K.imp.read}</button></div><p class="error" role="alert" hidden></p></form>`;
        $('#ki-pick').addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.target, file = f.file.files[0], err = $('.error', f);
            err.hidden = true;
            try {
                KI.hasHeader = f.hdr.checked;
                if (file && /\.xlsx$/i.test(file.name)) {
                    const up = await apiUpload({ action: 'comp-import-upload', case: pid, name: file.name, key: opId() }, file);
                    KI.src = { file: up.file, name: file.name };
                } else if (file) {
                    const buf = await file.arrayBuffer();
                    let text;
                    try { text = new TextDecoder('utf-8', { fatal: true }).decode(buf); } catch { text = new TextDecoder('windows-1255').decode(buf); }    // קובץ ישן בקידוד עברית של Windows
                    KI.src = { text, name: file.name };
                } else if (f.paste.value.trim()) KI.src = { text: f.paste.value, name: '' };
                else return;
                const r = await api('comp-import-preview', { body: body({}) });
                KI.columns = r.columns; KI.total = r.total; KI.targets = r.targets; KI.preview = null; KI.mapping = {};
                KI.saved = !!r.saved_mapping;
                if (r.saved_mapping) {
                    KI.mapping = r.saved_mapping.mapping || {};
                    KI.dateOrder = r.saved_mapping.date_order || 'dmy';
                    KI.areaKind = r.saved_mapping.defaults?.area_kind || 'unknown';
                    KI.precision = r.saved_mapping.defaults?.location_precision || 'unknown';
                }
                draw();
            } catch (x) { err.textContent = x.message; err.hidden = false; }
        });
    }
    function drawMap() {
        const p = KI.preview, used = Object.values(KI.mapping).filter(t => t && t !== 'extra');
        const cols = p ? KI.columns.filter(c => KI.mapping[c.index] && KI.mapping[c.index] !== 'extra') : [];
        App.main.innerHTML = `<h2 class="sec-title">${K.imp.mapTitle}</h2><p class="hint">${K.imp.mapHint}</p>${KI.saved ? `<p class="banner" role="note">💾 ${esc(K.imp.savedMap)}</p>` : ''}
            <ul class="list">${KI.columns.map(c => `<li class="card static"><div class="body"><div class="l1"><span dir="auto"><b>${esc(c.header)}</b></span></div>
                <div class="l2 wrap" dir="auto">${c.samples.length ? c.samples.map(esc).join(' · ') : '—'}</div>
                <label class="f"><span>${K.imp.target}</span><select data-ki-col="${c.index}"><option value="">${K.imp.skip}</option>${KI.targets.map(t => `<option value="${t}"${KI.mapping[c.index] === t ? ' selected' : ''}${t !== 'extra' && used.includes(t) && KI.mapping[c.index] !== t ? ' disabled' : ''}>${esc(K.fields[t] || t)}</option>`).join('')}</select></label></div></li>`).join('')}</ul>
            <div class="fields"><label class="f"><span>${K.imp.dateOrder}</span><select id="ki-date">${['dmy', 'mdy'].map(o => cOpt(o, K.imp.dateOrders[o], KI.dateOrder)).join('')}</select></label>
              <label class="f"><span>${K.imp.areaKind}</span><select id="ki-kind">${lists.area_kinds.map(o => cOpt(o, o === 'unknown' ? K.unknown : kAreaKind(o), KI.areaKind)).join('')}</select></label>
              <label class="f"><span>${K.imp.precision}</span><select id="ki-prec">${lists.precisions.map(o => cOpt(o, K.precisions[o], KI.precision)).join('')}</select></label></div>
            <div class="row-btns wrap"><button type="button" class="btn ghost" id="ki-back">${K.imp.back}</button><button type="button" class="btn primary" id="ki-preview">${K.imp.preview}</button></div>
            <p class="error" role="alert" id="ki-err" hidden></p>
            ${p ? `<h2 class="sec-title">${esc(K.imp.previewTitle(fNum(String(KI.total))))}</h2>
              ${p.warn_rows ? `<p class="banner" role="note">⚠️ ${esc(K.imp.warnRows(p.warn_rows))}</p>` : ''}${p.empty_rows ? `<p class="banner" role="note">${esc(K.imp.emptyRows(p.empty_rows))}</p>` : ''}
              <div class="ktable-wrap"><table class="ktable plain"><thead><tr><th>${K.imp.rowN}</th>${cols.map(c => `<th>${esc(K.fields[KI.mapping[c.index]])}</th>`).join('')}</tr></thead>
                <tbody>${p.rows.map(r => `<tr${r.empty ? ' class="muted"' : ''}><td>${r.line}</td>${cols.map(c => { const t = KI.mapping[c.index], bad = r.unparsed.includes(t); return `<td dir="auto"${bad ? ' class="bad"' : ''}>${bad ? `⚠️ ${esc(K.imp.unparsedCell)}` : esc(r.values[t] === null ? '—' : kFieldText(t, r.values[t]))}</td>`; }).join('')}</tr>`).join('')}</tbody></table></div>
              <p class="hint">${esc(K.imp.allCandidates)}</p>
              <label class="switch"><input type="checkbox" id="ki-rights"><span>${K.imp.rights}</span></label>
              <div class="row-btns"><button type="button" class="btn primary" id="ki-run" disabled>${esc(K.imp.run(fNum(String(KI.total - p.empty_rows))))}</button></div>` : ''}`;
        const fail = x => { const el = $('#ki-err'); el.textContent = x.message; el.hidden = false; };
        $$('[data-ki-col]').forEach(el => el.addEventListener('change', () => { KI.mapping = { ...KI.mapping, [el.dataset.kiCol]: el.value }; if (!el.value) delete KI.mapping[el.dataset.kiCol]; KI.preview = null; KI.saved = false; draw(); }));
        $('#ki-date').addEventListener('change', e => { KI.dateOrder = e.target.value; KI.preview = null; draw(); });
        $('#ki-kind').addEventListener('change', e => { KI.areaKind = e.target.value; KI.preview = null; draw(); });
        $('#ki-prec').addEventListener('change', e => { KI.precision = e.target.value; KI.preview = null; draw(); });
        $('#ki-back').addEventListener('click', () => { KI.columns = null; KI.src = null; draw(); });
        $('#ki-preview').addEventListener('click', async () => { try { KI.preview = await api('comp-import-preview', { body: body(opts()) }); draw(); } catch (x) { fail(x); } });
        $('#ki-rights')?.addEventListener('change', e => { $('#ki-run').disabled = !e.target.checked; });
        $('#ki-run')?.addEventListener('click', async e => {
            e.target.disabled = true;
            try {
                const r = await api('comp-import', { body: body({ search: searchPid, rights: $('#ki-rights').checked, ...opts() }) });
                KI = null;
                toast(K.imp.done(r.imported, r.skipped, r.dups));
                kFilter = 'candidate';
                location.hash = `#/cases/${pid}/comps`;
            } catch (x) { fail(x); e.target.disabled = false; }
        });
    }
    draw();
}

// ── עורך פרופיל ← "עסקאות" ──
async function peComps(body) {
    const lists = await pLists();
    const k = PE.content.comps = PE.content.comps || { default_criteria: { months_back: 24, radius_m: null, same_property_type: true }, unit_basis: 'per_sqm', area_kind: 'registered', method: 'mean', rounding: '1000', small_sample_under: 3 };
    const dc = k.default_criteria, kinds = ['registered', 'built', 'gross', 'net', 'primary', 'other'];
    body.innerHTML = `<form class="panel" id="pcomps" novalidate><p class="hint">${K.b.hint}</p>
        <label class="f"><span>${K.unitBasis}</span><select name="unit_basis">${['total', 'per_sqm'].map(o => cOpt(o, K.unitBases[o], k.unit_basis)).join('')}</select></label>
        <label class="f"><span>${K.setAreaKind}</span><select name="area_kind">${kinds.map(o => cOpt(o, kAreaKind(o), k.area_kind)).join('')}</select></label>
        <label class="f"><span>${K.method}</span><select name="method">${Object.keys(K.methods).map(o => cOpt(o, K.methods[o], k.method)).join('')}</select></label>
        <label class="f"><span>${K.rounding}</span><select name="rounding">${Object.keys(K.roundings).map(o => cOpt(o, K.roundings[o], String(k.rounding))).join('')}</select></label>
        <label class="f"><span>${K.b.smallSample}</span><input name="small" type="number" min="1" max="50" value="${esc(k.small_sample_under)}"></label>
        <label class="f"><span>${K.b.monthsBack}</span><input name="months" type="number" min="1" max="240" value="${esc(dc.months_back)}"></label>
        <label class="f"><span>${K.b.radius}</span><input name="radius" type="number" min="1" max="100000" value="${esc(dc.radius_m ?? '')}"></label>
        <label class="switch"><input type="checkbox" name="same"${dc.same_property_type ? ' checked' : ''}><span>${K.b.sameType}</span></label></form>`;
    void lists;
    const int = (val, min, max, def) => { const n = parseInt(val, 10); return Number.isInteger(n) && n >= min && n <= max ? n : def; };
    $('#pcomps').addEventListener('change', e => {
        const f = e.currentTarget;
        PE.content.comps = { default_criteria: { months_back: int(f.months.value, 1, 240, 24), radius_m: f.radius.value === '' ? null : int(f.radius.value, 1, 100000, null), same_property_type: f.same.checked },
            unit_basis: f.unit_basis.value, area_kind: f.area_kind.value, method: f.method.value, rounding: f.rounding.value, small_sample_under: int(f.small.value, 1, 50, 3) };
        peChanged();
    });
}
