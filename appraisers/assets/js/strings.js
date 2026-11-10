/*
 * כל הטקסטים של הממשק — במקום אחד (אפיון, ש-39): שפה נוספת = קובץ נוסף כזה, בלי מעבר על המסכים.
 * מחרוזת עם משתנים היא פונקציה.
 */
'use strict';

const T = {
    app: 'שמאות מקרקעין',
    loading: 'טוען…',
    save: 'שמירה', close: 'סגירה', back: 'חזרה', retry: 'נסו שוב', copy: 'העתקה', copied: 'הועתק', saved: 'נשמר',
    noConnection: 'אין חיבור לשרת',
    fault: status => `תקלה (${status})`,
    reqId: id => `מזהה הבקשה: ${id}`,
    devEnv: 'סביבת פיתוח — לא להזין מידע אמיתי של לקוחות',

    roles: { appraiser: 'שמאי', trainee: 'מתמחה', staff: 'עובד משרד' },
    admin: 'מנהל משרד',
    statuses: { active: 'פעיל', suspended: 'מושעה', office_suspended: 'המשרד הושעה' },

    // כותרת ותפריט המשתמש
    meTitle: 'החשבון שלי', meButton: 'החשבון שלי', devButton: 'מסך המפתח', officePick: 'החלפת משרד',
    myOffices: 'המשרדים שלי', myOffice: 'המשרד שלי', systemAdmin: 'ניהול המערכת', devScreen: 'מסך המפתח', newOffice: 'הקמת משרד נוסף',
    noLogout: 'הכניסה והיציאה — דרך הכלים הדיגיטליים.', version: v => `גרסה ${v}`,
    tagDev: 'מפתח', tagSys: 'מנהל המערכת', emailUnverified: 'המייל לא אומת',

    // כניסה
    loginNeeded: 'נדרשת כניסה', loginUnavailable: 'הכניסה לא זמינה כרגע', cannotEnter: 'אי אפשר להיכנס',
    loginTitle: 'כניסה', loginViaPlatform: 'הכניסה — דרך הכלים הדיגיטליים.', loginUnavailableRetry: 'הכניסה לא זמינה כרגע. נסו שוב בעוד דקה.',
    loginPlatform: 'כניסה עם הכלים הדיגיטליים', loginOr: 'או במייל ובסיסמה', password: 'סיסמה *', loginDo: 'כניסה',
    loginErrors: { invalid: 'הזהות לא תקפה. נסו להיכנס שוב.', cancelled: 'הכניסה בוטלה.', suspended: 'החשבון הושעה — פנו למנהל המערכת.' },
    forgot: 'שכחתי סיסמה', forgotTitle: 'שכחתי סיסמה', forgotHint: 'נשלח קישור לבחירת סיסמה חדשה. הוא תקף לשעה.', forgotDo: 'שליחה',
    forgotSent: 'אם הכתובת רשומה — נשלח מייל',
    resetTitle: 'בחירת סיסמה חדשה', newPassword: 'סיסמה חדשה *', passwordRule: 'לפחות 10 תווים.', resetDo: 'שמירת הסיסמה', resetDone: 'הסיסמה נשמרה — אפשר להיכנס',
    passwordMenu: 'סיסמה לכניסה נפרדת', passwordTitle: 'סיסמה לכניסה נפרדת', passwordSaved: 'הסיסמה נשמרה',
    passwordHint: 'הסיסמה משמשת רק כשהמערכת פועלת בנפרד מהכלים הדיגיטליים. קביעת סיסמה מנתקת את שאר המכשירים שלכם.',
    passwordSet: 'נקבעה', passwordNotSet: 'לא נקבעה', logout: 'יציאה',

    // אישור חוזר לפעולות רגישות
    sensitive: { 'sys-office-status': 'השעיית משרד: חברי המשרד לא יוכלו לבצע בו שום פעולה.', 'sys-user-flags': 'הענקת הרשאת מנהל המערכת למשתמש.',
        'dev-token-create': 'יצירת אסימון DevLoop: מי שמחזיק בו קורא את המטלות ומגיב עליהן.' },
    confirmWordAsk: (what, word) => `${what}\n\nכדי לאשר, הקלידו: ${word}`,
    confirmCodeAsk: what => `${what}\n\nנשלח אליכם מייל עם קוד בן 6 ספרות. הקלידו אותו כאן:`,
    confirmCancelled: 'הפעולה לא אושרה',

    // בית
    homeEmpty: 'אין עדיין תיקים — התיקים יופיעו כאן.',
    officeSuspendedTitle: 'המשרד הושעה', officeSuspendedText: 'פנו למנהל המערכת. הנתונים של המשרד שמורים.',
    memberSuspendedTitle: 'החברות שלכם במשרד הושעתה', memberSuspendedText: 'פנו למנהל המשרד.',
    tileOffice: 'המשרד שלי', tileSystem: 'ניהול המערכת', tileDev: 'מסך המפתח',

    // ברוכים הבאים
    welcomeTitle: 'ברוכים הבאים', welcomeHint: 'כדי להתחיל צריך משרד: מקימים משרד חדש, או מצטרפים למשרד קיים בהזמנה.',
    createOffice: 'הקמת משרד', haveInvite: 'יש לי הזמנה',
    officeName: 'שם המשרד *', myRole: 'התפקיד שלי', createOfficeDo: 'הקמת המשרד', officeCreated: 'המשרד הוקם',
    createOfficeHint: 'מי שמקים את המשרד מנהל אותו, ויכול להזמין את הצוות.',
    noInvites: email => `אין הזמנות שממתינות לכתובת ${email}. בקשו ממנהל המשרד לשלוח הזמנה לכתובת הזו.`,
    noInvitesUnverified: 'כדי לראות הזמנות צריך מייל מאומת בכלים הדיגיטליים. אפשר גם לפתוח את קישור ההזמנה שקיבלתם.',
    inviteRow: (role, admin) => role + (admin ? ' · מנהל משרד' : ''),
    join: 'הצטרפות', joined: 'הצטרפתם למשרד', alreadyMember: 'כבר חבר/ה במשרד',
    inviteScreenTitle: 'הזמנה למשרד',
    invitedTo: (office, role) => `הוזמנתם למשרד "${office}" בתפקיד ${role}.`,
    invitedAdmin: 'כולל ניהול המשרד.',

    // המשרד שלי
    officeTitle: 'המשרד שלי', tabTeam: '👥 צוות', tabLog: '🧾 יומן פעולות',
    teamHintAdmin: 'מי עובד במשרד, ובאיזה תפקיד.', teamHint: 'מי עובד איתכם במשרד.',
    invite: 'הזמנה', inviteTitle: 'הזמנה למשרד', email: 'מייל *', role: 'תפקיד', alsoAdmin: 'גם מנהל/ת משרד',
    inviteeName: 'שם המוזמן/ת', inviteResend: 'שליחה שוב', inviteResent: 'ההזמנה נשלחה שוב',
    // מה קרה בפתיחת החשבון במעטפת, ומה קרה למייל — למנהל המשרד
    provision: {
        invited: 'נשלחה הזמנה. המוזמן יקבל מייל לבחירת סיסמה.',
        granted: 'למוזמן יש כבר חשבון; נשלח לו קישור להצטרפות.',
        blocked: 'אי אפשר להזמין את הכתובת הזו. פנו למנהל המערכת.',
        failed: 'ההזמנה נשמרה, אבל פתיחת החשבון נכשלה. ננסה שוב; אפשר גם להעתיק את הקישור.',
    },
    provisionChip: { invited: 'חשבון נפתח', granted: 'יש חשבון', blocked: 'הכתובת חסומה', failed: 'פתיחת החשבון נכשלה' },
    mailFailed: 'המייל לא נשלח — העתיקו את הקישור', mailChip: { sent: 'המייל נשלח', failed: 'המייל לא נשלח' },
    inviteHint: 'המוזמן יקבל מייל עם קישור להצטרפות. אם אין לו חשבון בכלים הדיגיטליים — ייפתח לו חשבון, ויגיע אליו מייל נוסף לבחירת סיסמה.',
    inviteCreate: 'יצירת הזמנה', inviteLinkTitle: 'קישור ההזמנה',
    inviteLinkHint: until => `מסרו את הקישור למוזמן. הוא בתוקף עד ${until}, ומתאים רק לכתובת המייל שהזנתם.`,
    copyLink: '📋 העתקת קישור', linkRotated: 'הונפק קישור חדש — הקודם כבר לא עובד.',
    pendingInvites: 'הזמנות ממתינות', inviteUntil: d => `בתוקף עד ${d}`, revoke: 'ביטול ההזמנה', revokeAsk: email => `לבטל את ההזמנה של ${email}?`, revoked: 'ההזמנה בוטלה',
    me: '(אני)', seenToday: 'נכנס היום', seenYesterday: 'נכנס אתמול', seenDays: d => `נכנס לפני ${d} ימים`, seenNever: 'עוד לא נכנס',
    memberTitle: 'תיאור התפקיד', memberTitlePh: 'למשל: מזכירה, שמאי בכיר', license: 'מספר רישיון', licenseNote: 'לא אומת',
    accountSuspended: 'החשבון הושעה', accountSuspendedHint: 'החשבון הושעה בידי מנהל המערכת, בכל המשרדים. רק הוא יכול לבטל את ההשעיה.',
    isAdmin: 'מנהל/ת משרד', activeInOffice: 'פעיל/ה במשרד', remove: '🗑️ הסרה מהמשרד', removeAsk: name => `להסיר את ${name} מהמשרד?`, removed: 'הוסר/ה מהמשרד',
    renameOffice: 'שינוי שם המשרד', leave: 'עזיבת המשרד', leaveAsk: name => `לעזוב את "${name}"? כדי לחזור תצטרכו הזמנה חדשה.`, left: 'עזבתם את המשרד',
    logEmpty: 'עוד אין פעולות ביומן.', system: 'המערכת', sysadminActor: 'מנהל המערכת',

    // ניהול המערכת
    sysTitle: 'ניהול המערכת', tabOffices: '🏛️ משרדים', tabUsers: '👤 משתמשים',
    sysHint: 'נתונים תפעוליים בלבד: מי המשרדים ומי המשתמשים. התיקים של משרד אינם נראים מכאן.',
    noOffices: 'עוד אין משרדים.', members: n => n === 1 ? 'חבר אחד' : `${n} חברים`, noActiveMembers: 'בלי חבר פעיל',
    created: d => `הוקם ${d}`, lastActivity: d => `פעילות אחרונה ${d}`, noActivity: 'בלי פעילות',
    suspendOffice: 'השעיית המשרד', unsuspendOffice: 'ביטול ההשעיה', suspendUser: 'השעיית המשתמש', unsuspendUser: 'ביטול ההשעיה',
    reason: 'נימוק *', reasonPh: 'למה? נרשם ביומן הפעולות', confirmDo: 'אישור',
    suspendOfficeHint: 'חברי משרד מושעה לא יכולים לבצע בו שום פעולה. הנתונים לא נמחקים.',
    suspendUserHint: 'משתמש מושעה לא נכנס למערכת בכלל, בשום משרד.',
    officesCount: n => n === 1 ? 'משרד אחד' : `${n} משרדים`, noOffice: 'בלי משרד',
    flagSys: 'מנהל המערכת', flagsTitle: 'הרשאות מערכת', devFlagNote: 'מפתח — נקבע בכלים הדיגיטליים (מנהל הפלטפורמה), לא כאן.',
    sysNewOffice: 'משרד', sysNewOfficeTitle: 'הקמת משרד', firstAdminEmail: 'המייל של מנהל המשרד הראשון *',
    sysNewOfficeHint: 'נוצר משרד פעיל בלי חברים, והזמנה למנהל הראשון. אתם לא הופכים לחברים במשרד, ולא רואים את תוכנו.',
    selfCreate: 'כל משתמש יכול להקים משרד', selfCreateHint: 'כבוי — רק מנהל המערכת מקים משרדים.',
    selfCreateOff: 'פתיחת משרד נעשית בידי מנהל המערכת.',

    // מסך המפתח
    devTitle: 'מסך המפתח', devTabs: { tasks: '📋 מטלות', log: '📜 יומן', audit: '🧾 פעולות', health: '🩺 תקינות', jobs: '⚙️ רקע' },
    auditHint: 'כל פעולה שמשנה מצב, בכל המערכת — מזהים, קודים ומצבים בלבד.', auditFilter: 'קוד פעולה (למשל member.)', filterOffice: 'משרד', filterUser: 'משתמש', clear: 'ניקוי',
    health: { ok: 'תקין', warn: 'אזהרה', bad: 'כשל' }, healthRun: '🔄 הרצה', healthChecking: 'בודק…',
    healthAll: n => `כל ${n} הבדיקות תקינות`, healthSome: (bad, n) => `${bad} מתוך ${n} דורשות בדיקה`,
    cronTitle: 'המתזמן', cronNever: 'עוד לא רץ', cronLast: (d, ms) => `הצליח לאחרונה ${d} · ${ms} אלפיות שנייה`,
    cronSource: { cron: 'המתזמן של cPanel', lazy: 'סבב גיבוי מבקשה רגילה — המתזמן עוד לא הוגדר', manual: 'הרצה ידנית מהמסך' },
    cronUrlHint: 'להגדרה ב-cPanel, כל 5 דקות:', cronCopy: '📋 העתקת הפקודה', cronRun: '▶️ הרצה עכשיו', cronRan: r => r.skipped ? 'סבב אחר רץ כרגע' : `רצו ${r.ran}, נכשלו ${r.failed}`,
    queueTitle: 'התור', jobStatus: { pending: 'ממתינה', running: 'רצה', done: 'הסתיימה', partial: 'חלקית', failed: 'נכשלה', needs_action: 'דורשת טיפול', cancelled: 'בוטלה' },
    kindsTitle: 'השירותים', kindEvery: s => s ? `כל ${Math.round(s / 60)} דקות` : 'לפי דרישה', kindLast: d => `רץ לאחרונה ${d}`, kindNever: 'עוד לא רץ',
    mailTitle: 'מיילים אחרונים', mailEmpty: 'עוד לא נשלחו מיילים.', mailHint: 'הנמען מוצג כ-hash — הכתובת עצמה אינה נשמרת ביומן.',
    mailKind: { invite: 'הזמנה', reset: 'איפוס סיסמה', otp: 'קוד אישור' },
    jobsTitle: 'משימות אחרונות', jobsEmpty: 'אין משימות.', attempts: (a, m) => `ניסיון ${a} מתוך ${m}`, jobRetry: 'ניסיון חוזר', jobCancel: 'ביטול',

    // יומן הפעולות — מה קרה (למנהל משרד, עם שמות)
    audit: {
        'office.create': () => 'המשרד הוקם',
        'office.rename': () => 'שם המשרד שונה',
        'office.suspend': () => 'המשרד הושעה',
        'office.unsuspend': () => 'ההשעיה של המשרד בוטלה',
        'member.join': r => `${r.entity_name || 'חבר'} הצטרף/ה כ${T.roles[r.meta.role] || ''}${r.meta.is_admin ? ', מנהל/ת משרד' : ''}`,
        'member.role': r => `${r.entity_name || 'חבר'} — תפקיד: ${T.roles[r.meta.from] || ''} ← ${T.roles[r.meta.to] || ''}`,
        'member.admin_on': r => `${r.entity_name || 'חבר'} מונה/תה לניהול המשרד`,
        'member.admin_off': r => `${r.entity_name || 'חבר'} ירד/ה מניהול המשרד`,
        'member.suspend': r => `${r.entity_name || 'חבר'} הושעה/תה`,
        'member.unsuspend': r => `ההשעיה של ${r.entity_name || 'חבר'} בוטלה`,
        'member.remove': r => `${r.entity_name || 'חבר'} הוסר/ה מהמשרד`,
        'member.leave': r => `${r.entity_name || 'חבר'} עזב/ה את המשרד`,
        'invite.create': r => `נשלחה הזמנה לתפקיד ${T.roles[r.meta.role] || ''}`,
        'invite.revoke': r => r.meta.by === 'replaced' ? 'הזמנה הוחלפה בחדשה' : 'הזמנה בוטלה',
        'invite.accept': () => 'הזמנה התקבלה',
        'invite.resend': () => 'הזמנה נשלחה שוב',
        'invite.provision': r => `פתיחת חשבון למוזמן: ${T.provisionChip[r.meta.status] || ''}`,
        'setting.change': () => 'הגדרת מערכת שונתה',
    },
    auditIcon: { confirm: '✅', office: '🏛️', member: '👤', invite: '✉️', user: '👤', devtoken: '🔑', setting: '⚙️' },
};
