/*
 * חילוץ וסיווג בבינה (אפיון 12): לוח "הבינה במסמך" במסך הקובץ (xFilePanel), הסימון של הצעת בינה על נתון (xChips),
 * תוצאת חיפוש בטקסט המסמך (xTextHit), והמתגים של העיבוד האוטומטי בכרטיס התיק (xCaseAuto).
 *
 * **כל טקסט שמקורו במסמך או במודל — ציטוט, סיכום, ערך, קטע חיפוש — נכנס לדף רק דרך esc().** הוא מידע להצגה, לא HTML ולא הוראה.
 * המסך אינו מחליט דבר: הוא מציג את מה שהשרת שמר, ושולח פעולה אחת בכל פעם. אין כאן "אישור כל ההצעות".
 */
'use strict';

const X = T.x;
const xKey = () => 'x' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
const xReason = r => (r ? X.reasons[r] || r : '');
const xStateCls = { done: 'ok', failed: 'bad', blocked: 'warn', skipped: '', running: 'info', queued: 'info', none: '' };

/** השבבים של הצעת בינה על נתון: "הצעת בינה", רמת הביטחון, והאם הציטוט אותר. */
function xChips(f) {
    if (!f.ai) return '';
    return `<span class="chip info">🤖 ${esc(X.aiChip)}</span>${f.ai.confidence ? `<span class="chip${f.ai.confidence === 'low' ? ' warn' : ''}">${esc(X.conf[f.ai.confidence] || f.ai.confidence)}</span>` : ''}${
        f.ai.quote_found === false ? `<span class="chip bad">${esc(X.quoteMissing)}</span>` : ''}`;
}

/** תוצאת חיפוש בטקסט שחולץ: שם הקובץ, העמוד, והקטע — כטקסט. */
function xTextHit(x) {
    return `<div class="l2 wrap"><q dir="auto">${esc(x.snippet)}</q></div><div class="chips"><span class="chip">${esc(X.inText(x.page))}</span></div>`;
}

/** המתגים של העיבוד האוטומטי בכרטיס התיק (בתוך "בינה בתיק"). d — התשובה של case-ai. */
function xCaseAuto(pid, d) {
    if (!d.auto) return { html: '', bind: () => {} };
    const sw = (id, on, label) => `<label class="switch"><input type="checkbox" id="${id}"${on ? ' checked' : ''}${d.can_set ? '' : ' disabled'}><span>${label}</span></label>`;
    return { html: `<h3 class="sec-title">${X.auto}</h3>${sw('xautoc', d.auto.classify, X.autoClassify)}${sw('xautoe', d.auto.fields, X.autoExtract)}<p class="hint">${X.autoHint}</p>`,
        bind: () => [['xautoc', 'auto_classify'], ['xautoe', 'auto_extract']].forEach(([id, key]) => $('#' + id)?.addEventListener('change', async e => {
            try { await api('case-ai-auto-set', { body: { case: pid, [key]: e.target.checked } }); toast(T.saved); } catch (err) { e.target.checked = !e.target.checked; toastError(err); }
        })) };
}

/** לוח הבינה במסך הקובץ. reload — טוען מחדש את מסך הקובץ כולו (אחרי שיוך); הלוח והנתונים מתרעננים לבד אחרי הפעלה. */
async function xFilePanel(pid, fpid, reload) {
    const box = $('#file-ai');
    if (!box) return;
    let d;
    try { d = await api('file-ai', { params: { file: fpid } }); } catch { return; }
    if (!$('#file-ai')) return;
    const a = d.ai, p = d.perms;
    const line = w => {
        const s = a[w], est = a.estimate[w];
        return `<div class="panel"><div class="line"><b>${esc(X.whats[w])}</b><span class="chip ${xStateCls[s.state] || ''}">${esc(X.states[s.state] || s.state)}</span></div>
            ${s.reason ? `<p class="hint">${esc(s.state === 'skipped' ? X.notWorked(xReason(s.reason)) : xReason(s.reason))}</p>` : ''}
            ${p.run && a.available && s.state !== 'running' ? `<div class="row-btns wrap"><button type="button" class="btn ghost sm" data-xrun="${w}">${s.state === 'done' ? X.rerun : X.run}</button></div>
                ${est ? `<p class="hint">${esc(X.estimate(Number(est.tokens).toLocaleString('he-IL'), est.pages, est.cost === null ? null : aiMoney(est.cost)))}</p>` : ''}` : ''}</div>`;
    };
    const sug = a.suggestion;
    box.innerHTML = `<h2 class="sec-title">🤖 ${X.title}</h2>
        ${a.embedded_instructions ? `<p class="banner" role="alert">⚠️ ${esc(X.embedded)}</p>` : ''}
        ${a.quality_check ? `<p class="banner" role="note">🔍 ${esc(X.quality)}</p>` : ''}
        ${a.flags.includes('low_quality') ? `<p class="banner" role="note">${esc(X.lowQuality)}</p>` : ''}${a.flags.includes('partial') ? `<p class="hint">${esc(X.partial)}</p>` : ''}
        ${!a.available && a.unavailable ? `<p class="hint">${esc(X.notWorked(xReason(a.unavailable)))}</p>` : ''}
        ${sug ? `<div class="panel"><div class="line"><b>${esc(X.looksLike(sug.doc_type_name, X.conf[sug.confidence] || ''))}</b></div>
            ${sug.state !== 'pending' ? `<div class="chips"><span class="chip">${esc(X.sugStates[sug.state] || sug.state)}</span></div>`
            : `<p class="hint">${sug.requirement ? esc(X.assignAsk(sug.requirement.title)) : esc(X.noReq)}</p>
               ${p.suggestion ? `<div class="row-btns wrap">${sug.requirement ? `<button type="button" class="btn primary sm" data-xsug="accept">${X.yes}</button>` : ''}<button type="button" class="btn ghost sm" data-xsug="reject">${X.no}</button>
                 <button type="button" class="btn ghost sm" data-xsug="other">${X.otherReq}</button></div>` : ''}`}</div>` : ''}
        ${line('classify')}${line('fields')}${line('summary')}
        ${a.summary_text ? `<div class="panel"><div class="line"><b>${X.summary}</b><span class="chip${a.summary_state === 'reviewed' ? ' ok' : ' warn'}">${a.summary_state === 'reviewed' ? X.summaryReviewed : X.summaryAi}</span></div>
            <p dir="auto" style="white-space:pre-wrap">${esc(a.summary_text)}</p>
            ${p.summary_review && a.summary_state === 'pending' ? `<div class="row-btns"><button type="button" class="btn ghost sm" id="xsumok">${X.markReviewed}</button></div>` : ''}</div>` : ''}
        ${a.not_found.length ? `<p class="hint"><b>${X.notFound}:</b> ${a.not_found.map(n => esc(n.label) + (n.status === 'unclear' ? ` (${esc(X.unclear)})` : '')).join(' · ')}</p>` : ''}
        ${a.dropped ? `<p class="hint">${esc(X.dropped(a.dropped))}</p>` : ''}
        ${a.fields.state === 'done' && a.text.state !== 'done' ? `<p class="hint">${esc(X.noText)}</p>` : ''}
        ${p.judge && a.proposed ? `<div class="row-btns"><button type="button" class="btn ghost danger sm" id="xrejall">${X.rejectAll} (${a.proposed})</button></div>` : ''}`;
    const again = () => { xFilePanel(pid, fpid, reload).catch(() => {}); if (typeof fileFactsPanel === 'function' && XF.goPage) fileFactsPanel(pid, fpid, XF.goPage, XF.reloadFacts).catch(() => {}); };
    // ההפעלה נמשכת עד שתי דקות. אם החיבור נקטע בדרך — הבקשה ממשיכה בשרת, והלוח קורא את המצב עד שהיא מסתיימת
    const wait = async w => {
        for (let i = 0; i < 40 && $('#file-ai'); i++) {
            await new Promise(r => setTimeout(r, 5000));
            let now;
            try { now = await api('file-ai', { params: { file: fpid } }); } catch { continue; }
            if (now.ai[w].state !== 'running') break;
        }
        again();
    };
    cOn('[data-xrun]', el => {
        const w = el.dataset.xrun, est = a.estimate[w];
        sheet(X.runAsk(X.whats[w]), `<p class="hint">${esc(X.runHint)}</p>${w === 'fields' && a.proposed ? `<p class="hint">${esc(X.rerunHint)}</p>` : ''}
            ${est ? `<p><b>${esc(X.estimate(Number(est.tokens).toLocaleString('he-IL'), est.pages, est.cost === null ? null : aiMoney(est.cost)))}</b></p>` : ''}`, async () => {
            toast(X.started);
            api('file-ai-run', { body: { file: fpid, what: w, key: xKey() } }).then(r => {
                if (r.run.state === 'done') toast(X.doneToast(r.ai.proposed));
                else if (r.run.state !== 'running') toast(X.notDone(X.states[r.run.state] || r.run.state, xReason(r.run.reason)), true);
                if (r.run.state === 'running') wait(w); else again();
            }).catch(e => { if (e.code && e.code !== 'network') { toastError(e); again(); } else wait(w); });
            setTimeout(again, 800);
        }, X.run);
    }, box);
    const sugDo = async body => {
        const r = await api('file-ai-suggestion', { body: { file: fpid, ...body } });
        toast(r.state === 'accepted' ? X.accepted : X.rejectedSug);
        reload();
    };
    cOn('[data-xsug]', async el => {
        const act = el.dataset.xsug;
        if (act !== 'other') return sugDo({ do: act }).catch(toastError);
        const docs = await api('case-docs', { params: { case: pid } }).catch(toastError);
        if (!docs) return;
        const open = docs.requirements.filter(r => !r.split && ['required', 'requested', 'returned', 'received'].includes(r.status));
        sheet(X.pickReq, `<label class="f"><span>${X.requirement}</span><select name="requirement" required>${open.map(r => cOpt(r.pid, r.title, '')).join('')}</select></label>`, v => sugDo({ do: 'accept', requirement: v.requirement }), X.yes);
    }, box);
    $('#xsumok')?.addEventListener('click', async () => { try { await api('file-ai-summary-reviewed', { body: { file: fpid } }); toast(T.saved); again(); } catch (e) { toastError(e); } });
    $('#xrejall')?.addEventListener('click', () => reasonSheet(X.rejectAll, esc(X.rejectAllHint), async reason => {
        const r = await api('file-ai-reject-all', { body: { file: fpid, reason } });
        toast(X.rejectedAll(r.rejected));
        again();
    }));
    if (['classify', 'fields', 'summary'].some(w => a[w].state === 'running') && !XF.waiting) {
        XF.waiting = true;
        wait(['classify', 'fields', 'summary'].find(w => a[w].state === 'running')).finally(() => { XF.waiting = false; });
    }
}
/** מה שמסך הקובץ מוסר ללוח כדי לרענן את "נתונים מהמסמך" אחרי הפעלה. */
const XF = { goPage: null, reloadFacts: null, waiting: false };
