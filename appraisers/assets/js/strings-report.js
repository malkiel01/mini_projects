/* פרוסה 17 — הדוח: מחרוזות המסך (screens/report.js). הודעות שגיאה מגיעות מהשרת. גרסה מאושרת לעולם אינה מוצגת כ"חתומה". */
'use strict';

T.r = {
    tab: 'דוח', title: 'דוח וגרסאות', none: 'עוד אין לתיק טיוטת דוח.', versionN: n => `גרסה ${n}`,
    states: { draft: 'טיוטה', in_review: 'בבדיקה', approved: 'מאושרת', delivered: 'נמסרה', superseded: 'הוחלפה' },
    origins: { auto: 'נוצרה אוטומטית', manual: 'נוצרה ביוזמת השמאי', from_version: 'נפתחה מגרסה מאושרת', from_upload: 'מקובץ' },
    createdBy: (who, when) => `נוצרה ${when}${who ? ' · ' + who : ' · המערכת'}`, submittedBy: (who, when) => `הועברה לבדיקה ${when}${who ? ' · ' + who : ''}`,
    approvedBy: (who, when) => `אושרה ${when}${who ? ' · ' + who : ''}`, reopenReason: 'נימוק לפתיחת הגרסה',
    triggers: { visit_completed: 'הטיוטה נוצרת לבד כשביקור בנכס הושלם', manual: 'הטיוטה נוצרת ביוזמת השמאי' },
    createDraft: 'יצירת טיוטה', creating: 'יוצר את הטיוטה…', newVersion: 'גרסה חדשה', newVersionHint: 'נפתחת טיוטה במספר הבא, כהעתק של הגרסה המאושרת ועם הערכים העדכניים. הגרסה המאושרת נשארת כפי שהיא עד שהחדשה תאושר. אין "ביטול אישור".',
    reason: 'נימוק', draftDelayed: why => `יצירת הטיוטה מתעכבת: ${why}`, jobStates: { failed: 'המשימה נכשלה', needs_action: 'נדרש טיפול' },
    stale: 'נתונים השתנו אחרי האישור', staleHint: 'הגרסה מוצגת כפי שאושרה. כדי לשקף את הנתונים העדכניים — "גרסה חדשה".',
    frozen: 'גרסה שאושרה — לקריאה בלבד, כפי שנשמרה ברגע האישור.', hash: 'טביעת התוכן',
    modes: { edit: 'פרקים', check: 'בקרה', preview: 'תצוגה מקדימה', compare: 'השוואה', comments: 'הערות' },
    sections: 'הפרקים', sources: 'המקורות של הפרק', noSources: 'אין לפרק מקורות מקושרים.', noSections: 'אין בגרסה פרקים.',
    secStates: { empty: 'ריק', drafted: 'נבנה מהנתונים', edited: 'נערך', reviewed: 'נסקר' }, mandatory: 'חובה', userEdited: 'נערך ידנית',
    aiUnreviewed: 'נכתב בבינה — לא נסקר', aiReviewed: who => `נכתב בבינה — נסקר${who ? ' בידי ' + who : ''}`, aiMark: 'בינה',
    aiBanner: 'הטקסט המסומן נכתב בבינה מלאכותית ועדיין לא נסקר בידי שמאי. בלי סקירה אי אפשר לאשר את הדוח.',
    aiWait: why => `ניסוח בבינה ממתין: ${why}`, aiNow: 'ניסוח עכשיו', aiWrite: 'ניסוח בבינה', aiWorking: 'מנסח…', aiDone: 'הפרק נוסח בבינה — ממתין לסקירה', aiAsProposal: 'הפרק נערך בינתיים — הניסוח נשמר כהצעה', aiReplaceAsk: 'הפרק נערך ידנית. ניסוח בבינה יחליף את העריכה. להמשיך?', aiNotDone: why => `הפרק לא נוסח: ${why}`,
    aiWaits: { policy: 'הבינה כבויה למשרד', case_no_ai: 'התיק סומן "בלי בינה"', no_connection: 'אין חיבור לספק', quota: 'המכסה נגמרה', no_assignment: 'אין הקצאה', model_not_allowed: 'אין מודל זמין', failed: 'הניסוח נכשל', no_data: 'אין בפרק נתונים לניסוח', not_allowed: 'הפרק אינו מנוסח בבינה' },
    markReviewed: 'סקרתי את הטקסט', markReviewedAsk: 'לסמן שסקרתם את הטקסט שנכתב בבינה, ושהוא נכון?', numUnknown: nums => `מספר שלא נמצא בנתונים: ${nums}`,
    changedNote: what => `עודכן: ${what}`, unknown: 'לא ידוע', notVerified: 'לא אומת', valueChanged: was => `הערך השתנה: היה ${was}`,
    imageRemoved: 'התמונה הוסרה', imageNotReport: 'לתמונה אין הרשאת שימוש "לדוח"', image: 'תמונה',
    edit: 'עריכה', done: 'סיום עריכה', cancel: 'ביטול', sectionTitle: 'כותרת הפרק', addBlock: 'הוספת בלוק', saveStates: { saved: 'נשמר', dirty: 'שינויים שלא נשמרו', saving: 'שומר…', failed: 'השמירה נכשלה', conflict: 'התנגשות' },
    blockTypes: { p: 'פסקה', h: 'כותרת משנה', list: 'רשימה', table: 'טבלה', data_table: 'טבלה מנתוני התיק', fact: 'נתון מהתיק', image: 'תמונה מהביקור', note_missing: 'חוסר ("לא ידוע")' },
    listHint: 'פריט בכל שורה', tableHint: 'שורה בכל שורה; תאים מופרדים בקו | — אותו מספר תאים בכל שורה', caption: 'כיתוב', noPhotos: 'אין בתיק תמונות עם הרשאת שימוש "לדוח".',
    moveUp: 'הזזה למעלה', moveDown: 'הזזה למטה', removeBlock: 'הסרת הבלוק', dataTableHint: 'הטבלה נבנית מהתיק ומציגה תמיד את הערכים העדכניים.', factHint: 'הערך נלקח מהתיק ומתעדכן לבד.',
    conflict: who => `הפרק עודכן בידי ${who || 'משתמש אחר'} בזמן שערכתם.`, conflictTheirs: 'הגרסה שנשמרה', conflictMine: 'הגרסה שלי', takeTheirs: 'טעינת הגרסה שנשמרה', keepMine: 'שמירת הגרסה שלי במקומה',
    proposal: why => `יש הצעת עדכון${why ? ': ' + why : ''}`, proposalMine: 'התוכן שלי', proposalNew: 'התוכן המוצע', accept: 'קבלה', reject: 'דחייה', merge: 'מיזוג', mergeHint: 'ערכו את הפרק כשההצעה לצידכם, ושמרו.', mergeSave: 'שמירת המיזוג',
    acceptAsk: 'התוכן המוצע יחליף את התוכן שלכם. להמשיך?', onlyMine: 'רק אצלי', onlyNew: 'רק בהצעה', same: 'אין הבדל בטקסט.',
    rebuild: 'יצירה מחדש', rebuildSection: 'יצירה מחדש של הפרק', rebuildAsk: 'לבנות מחדש מהנתונים את הפרקים שלא נערכו ידנית?', rebuildDone: n => `נבנו מחדש ${n} פרקים`,
    rebuildSkipped: 'הפרקים האלה נערכו ידנית ולא נגעו בהם:', rebuildForce: 'לבנות מחדש גם אותם — העריכה הידנית תימחק', rebuildForceAsk: 'הפרק נערך ידנית. יצירה מחדש תמחק את העריכה. להמשיך?',
    addSection: 'הוספת פרק', removeSection: 'הסרת הפרק', removeSectionAsk: t => `להסיר את הפרק "${t}" מהגרסה הזו?`, removeMandatoryAsk: t => `"${t}" הוא פרק חובה. ההסרה תירשם ביומן. להסיר?`,
    submit: 'העברה לבדיקה', submitAsk: 'להעביר את הגרסה לבדיקה? מעכשיו הפרקים אינם נערכים, עד אישור או החזרה לעריכה.', returnToEdit: 'החזרה לעריכה', approve: 'אישור הדוח', toApprove: 'לבקרה ולאישור',
    deleteDraft: 'מחיקת הטיוטה', deleteDraftHint: 'רק טיוטה שלא אושרה. המחיקה נרשמת ביומן.', inReview: 'הגרסה בבדיקה — הפרקים אינם נערכים עד אישור או החזרה לעריכה.',
    checkTitle: 'בקרת הדוח', checkNone: 'אין ממצאים.', blocking: 'חוסמים', warnings: 'אזהרות', blockHint: 'אי אפשר לאשר עד שמתוקן.', warnHint: 'אפשר לאשר — עם נימוק לכל ממצא. הנימוקים נשמרים עם הגרסה.',
    goFix: 'למקום התיקון', goSection: 'לפרק', ackReason: 'נימוק לאישור למרות הממצא', ackSaved: 'הנימוק שנשמר', approveBlocked: n => `יש ${n} ממצאים חוסמים — אי אפשר לאשר.`,
    approveNote: 'האישור נועל את הגרסה ושומר צילום של כל הנתונים. אחרי האישור אין עריכה ואין ביטול — רק גרסה חדשה.', approveNotReview: 'אישור אפשרי רק לגרסה שהועברה לבדיקה.',
    approved: 'הדוח אושר', approvalNotes: 'אזהרות שאושרו בנימוק', checkSummary: (b, w) => `בקרת הדוח: ${b} חוסמים · ${w} אזהרות`, checkClean: 'בקרת הדוח: אין ממצאים',
    previewDraft: 'טיוטה — לא מאושר', previewReview: 'בבדיקה — לא מאושר', previewApproved: (n, who, when) => `גרסה ${n} — מאושרת${who ? ' · ' + who : ''}${when ? ' · ' + when : ''}`,
    compareWith: 'להשוות מול', compareNone: 'אין גרסה אחרת להשוואה.', cmp: { added: 'פרק שנוסף', removed: 'פרק שהוסר', changed: 'השתנה', same: 'ללא שינוי' }, cmpAdded: 'נוסף', cmpRemoved: 'הוסר', cmpSameAll: 'אין הבדלים בין הגרסאות.',
    commentsNone: 'אין הערות.', commentAdd: 'הוספת הערה', commentText: 'הערה לבודק', commentSection: 'על פרק', commentAll: 'על הדוח כולו', resolve: 'טופל', resolved: (who, when) => `טופל${who ? ' · ' + who : ''}${when ? ' · ' + when : ''}`, commentsOpen: n => `${n} פתוחות`,
    srcTypes: { case: 'פרטי התיק', fact: 'נתון', file: 'מסמך', source_check: 'בדיקת מקור', visit: 'ביקור', comp: 'עסקה' }, page: n => `עמ' ${n}`, openDoc: 'למסמך', src: 'מקור',
    sharedDraft: 'טיוטת הדוח שותפה איתכם לקריאה.', back: 'לכל הגרסאות',
    // עורך הפרופיל ← "דוח"
    b: {
        hint: 'תבנית הפרקים של הדוח, מתי נוצרת הטיוטה, ומה חוסם אישור. שינוי כאן משפיע על תיקים חדשים בלבד.', trigger: 'מתי נוצרת הטיוטה', triggers: { visit_completed: 'כשביקור בנכס הושלם', manual: 'ביוזמת השמאי' },
        sections: 'הפרקים', title: 'כותרת', mandatory: 'חובה', build: 'נבנה', builds: { data: 'מנתוני התיק', manual: 'נכתב ידנית' }, ai: 'מותר לנסח בבינה', aiNever: 'הפרק הזה אינו מנוסח בבינה',
        add: 'הוספת פרק', remove: 'הסרת הפרק', unverified: 'נתון שלא אומת', unverifiedModes: { marked: 'נכנס לדוח, מסומן "לא אומת"', omit: 'אינו נכנס לדוח' }, excluded: 'לכלול בדוח גם עסקאות שהוצאו מהחישוב',
        findings: 'ממצאי הבקרה', findingsHint: 'מה חוסם אישור, ומה רק מזהיר. ארבעת הראשונים תמיד חוסמים.', levels: { block: 'חוסם', warn: 'אזהרה' }, locked: 'תמיד חוסם',
        kinds: { no_approver: 'אין שמאי מורשה לאשר בתיק', mandatory_empty: 'פרק חובה ריק', ai_unreviewed: 'טקסט בינה שלא נסקר', image_not_report: 'תמונה בדוח בלי הרשאת שימוש "לדוח"', ai_number: 'מספר בטקסט בינה שלא נמצא בנתונים', ai_stale: 'טקסט בינה נכתב לפני שינוי בנתונים', data_new: 'מידע חדש אחרי ההעברה לבדיקה',
            proposal_pending: 'הצעת עדכון שלא הוכרעה', req_open: 'דרישת חובה שלא הושלמה', conflict_open: 'סתירה פתוחה', fact_unverified: 'נתון בדוח שלא אומת', source_unchecked: 'מקור שלא נבדק או לא היה זמין', task_open: 'משימה פתוחה בתיק',
            small_sample: 'מדגם קטן של עסקאות', comp_missing: 'עסקה נבחרת עם נתונים חסרים', alt_no_key_date: 'חלופה בלי מועד קובע', alt_no_assumptions: 'חלופת "לאחר עבודות" בלי הנחות', note_missing: 'חוסר ("לא ידוע") בפרק',
            alt_no_conclusion: 'חלופה בלי מסקנה', fact_changed: 'נתון שהשתנה מאז עריכת הפרק', doc_gone: 'מסמך שנתון נשען עליו נמחק או הוחזר' },
    },
};
T.c.tabs.report = T.r.tab;
T.p.editTabs.report = 'דוח';
T.sh.tabs.report = 'הדוח';
T.sensitive['report-approve'] = 'אישור הדוח: הגרסה ננעלת, נשמר צילום של הנתונים, ומצב התיק משתנה ל"מאושר". אין ביטול אישור.';
Object.assign(T.auditIcon, { report_version: '📘', report_section: '📘' });
Object.assign(T.audit, {
    'report.draft_create': r => `נוצרה טיוטת דוח — גרסה ${r.meta.number ?? ''}`, 'report.section_ai': () => 'פרק בדוח נוסח בבינה — ממתין לסקירה', 'report.section_ai_proposal': () => 'הפרק נערך בזמן הניסוח בבינה — הניסוח נשמר כהצעה', 'report.stale': () => 'נתונים השתנו אחרי אישור הדוח',
    'report.section_save': () => 'פרק בדוח נערך', 'report.section_add': r => `נוסף פרק לדוח: ${r.meta.title || ''}`, 'report.section_delete': r => `הוסר פרק מהדוח: ${r.meta.title || ''}${r.meta.mandatory ? ' (פרק חובה)' : ''}`,
    'report.rebuild': r => `פרקים בדוח נבנו מחדש (${r.meta.sections ?? ''})`, 'report.section_reviewed': () => 'טקסט בינה בדוח סומן כנסקר', 'report.proposal_accept': () => 'הצעת עדכון לפרק התקבלה',
    'report.proposal_reject': () => 'הצעת עדכון לפרק נדחתה', 'report.proposal_merge': () => 'הצעת עדכון לפרק מוזגה', 'report.comment': () => 'נוספה הערה לדוח', 'report.comment_resolve': () => 'הערה בדוח סומנה כטופלה',
    'report.submit': r => `הדוח הועבר לבדיקה — גרסה ${r.meta.number ?? ''}`, 'report.return': r => `הדוח הוחזר לעריכה — גרסה ${r.meta.number ?? ''}`, 'report.superseded': () => 'גרסת דוח הוחלפה בגרסה חדשה שאושרה',
    'report.approve': r => `הדוח אושר — גרסה ${r.meta.number ?? ''}`, 'report.version_delete': r => `טיוטת דוח נמחקה — גרסה ${r.meta.number ?? ''}`,
});
