// js/contact-picker.js — בחירת איש קשר מהטלפון, למילוי שם, מייל וטלפון
//
// שתי דרכים, כי אף אחת לא עובדת בכל מקום:
//   1. הכפתור "📇 מאנשי הקשר בטלפון" (navigator.contacts) — כרום באנדרואיד,
//      בלשונית רגילה. באפליקציה המותקנת כרום מחזיר לפעמים בחירה ריקה
//      (נבדק בפלטפורמה: אנדרואיד 10, כרום 155) — ואז אומרים את זה בגלוי.
//   2. "שיתוף" מאפליקציית אנשי הקשר אל האפליקציה המותקנת (share_target
//      ב-manifest.webmanifest ← share-contact.php ← contacts.php#shared=…).
//
// התוצאה נכתבת בשורה בתוך החלון — הודעה קופצת הייתה נסתרת מאחוריו.

const ContactPicker = {
    supported: 'contacts' in navigator && 'ContactsManager' in window,

    /**
     * @param {Object} fields  { name, email, phone } — מזהי השדות למילוי
     * @param {string} resultId  השורה שבה נכתבת התוצאה
     */
    async pick(fields, resultId) {
        const result = document.getElementById(resultId);
        const say = (text, bad = false) => {
            if (!result) return;
            result.textContent = text;
            result.classList.toggle('bad', bad);
            result.hidden = false;
        };

        let picked;
        try {
            // multiple: true — עם false כרום החזיר רשימה ריקה גם כשנבחר מישהו
            picked = await navigator.contacts.select(['name', 'email', 'tel'], { multiple: true });
        } catch (error) {
            say('לא ניתן לפתוח את אנשי הקשר: ' + (error.message || error.name), true);
            return;
        }

        if (!picked || picked.length === 0) {
            const installed = matchMedia('(display-mode: standalone)').matches;
            say(installed
                ? 'לא התקבל איש קשר. באפליקציה המותקנת: באנשי הקשר בטלפון ← "שיתוף" ← קניות משותפות'
                : 'לא נבחר איש קשר', installed);
            return;
        }

        const contact = picked[0];
        const value = {
            name:  (contact.name || [])[0] || '',
            email: ((contact.email || [])[0] || '').toLowerCase(),
            phone: ContactPicker.bestPhone(contact.tel || []),
        };
        ContactPicker.fill(fields, value);
        say(ContactPicker.describe(value));
    },

    /** נייד ישראלי קודם, אחרת המספר הראשון */
    bestPhone(phones) {
        const mobile = phones.find(p => /^(?:05|\+?9725|009725)/.test(String(p).replace(/[^\d+]/g, '')));
        return mobile || phones[0] || '';
    },

    /** ממלא רק שדות שקיימים ושיש להם ערך — לא מוחק מה שהוקלד */
    fill(fields, value) {
        for (const key of ['name', 'email', 'phone']) {
            const input = fields[key] && document.getElementById(fields[key]);
            if (input && value[key] && !input.disabled) {
                input.value = value[key];
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    },

    describe(value) {
        const got = [value.name, value.email, value.phone].filter(Boolean).join(' · ');
        const missing = !value.email ? ' — חסר מייל, צריך להשלים' : '';
        return got ? 'מולא: ' + got + missing : 'איש הקשר ריק';
    },

    /** מציג את הכפתורים רק במכשיר שתומך */
    init() {
        document.querySelectorAll('.pick-contact').forEach(button => {
            button.hidden = !ContactPicker.supported;
        });
    },
};

document.addEventListener('DOMContentLoaded', ContactPicker.init);
