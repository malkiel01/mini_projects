<?php
/**
 * ארבעת הפרופילים המומלצים לדוגמה (אפיון 03 §1.8, ש-04; האפיון המקורי פרק 24). נזרעים פעם אחת, מפורסמים כגרסה 1,
 * ומסומנים is_sample — "דוגמה — טעון אישור מקצועי". אלה המחשה, לא רשימות מסמכי חובה: מנהל המערכת עורך ומפרסם מחדש.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_content.php';

function apr_seed_req(string $key, string $group, string $docType, string $title, string $party, int $days = 0, bool $mandatory = false, string $priority = 'normal', string $explain = ''): array
{
    return ['key' => $key, 'group' => $group, 'doc_type' => $docType, 'title' => $title, 'explain_recipient' => $explain, 'priority' => $priority, 'mandatory' => $mandatory,
        'party_kind' => $party, 'due' => $days ? ['mode' => 'relative', 'days' => $days] : ['mode' => 'manual'], 'needs_review' => true];
}

function apr_seed_profiles(): array
{
    $groups = [['key' => 'identity', 'title' => 'זהות וזכויות', 'sort' => 1], ['key' => 'deal', 'title' => 'מסמכי עסקה', 'sort' => 2], ['key' => 'planning', 'title' => 'תכנון ורישוי', 'sort' => 3]];
    $base = [
        apr_seed_req('rights', 'identity', 'rights', 'מסמך זכויות (נסח רישום או אישור זכויות)', 'client', 3, true, 'high', 'נסח רישום עדכני, או אישור זכויות מהחברה המשכנת / רשות מקרקעי ישראל'),
        apr_seed_req('id_details', 'identity', 'other', 'פרטי זיהוי של הנכס', 'client', 3, false, 'normal', 'כתובת מלאה, גוש, חלקה ותת-חלקה אם ידועים'),
        apr_seed_req('contract', 'deal', 'contract', 'הסכם ונספחיו', 'lawyer', 3, false, 'high', 'ההסכם החתום, כולל כל הנספחים — אם קיים'),
        apr_seed_req('client_docs', 'deal', 'client_docs', 'מסמכי מזמין', 'bank', 5, false, 'normal', 'דרישות והנחיות של המזמין או הבנק לשומה'),
        apr_seed_req('permit', 'planning', 'permit', 'היתר בנייה או תיק בניין', 'office', 7),
    ];
    $tasks = fn(array $more = []) => ['items' => array_merge([
        ['key' => 'check_rights', 'title' => 'בדיקת מסמך הזכויות', 'assignee' => 'responsible', 'due' => ['mode' => 'relative', 'days' => 5], 'sort' => 1],
        ['key' => 'plan_visit', 'title' => 'תיאום ביקור בנכס', 'assignee' => 'responsible', 'due' => ['mode' => 'relative', 'days' => 7], 'sort' => 2],
        ['key' => 'comparables', 'title' => 'איתור עסקאות להשוואה', 'assignee' => 'responsible', 'due' => ['mode' => 'relative', 'days' => 10], 'sort' => 3],
    ], $more)];
    $sorted = function (array $items): array { foreach ($items as $i => &$r) $r['sort'] = $i + 1; return $items; };
    $renovation = ['key' => 'renovation_quote', 'title' => 'שיפוץ מתוכנן — פירוט עבודות',
        'when' => ['all' => [['field' => 'flags.renovation_planned', 'op' => 'is', 'value' => true]]],
        'then' => [['do' => 'add_requirement', 'item' => apr_seed_req('works_quote', 'planning', 'works_quote', 'הצעת מחיר או פירוט עבודות לשיפוץ', 'client', 5, false, 'normal', 'פירוט העבודות המתוכננות ועלותן, ככל שיש')]]];
    $flag = ['key' => 'renovation_planned', 'title' => 'מתוכנן שיפוץ', 'question' => 'האם מתוכנן שיפוץ בנכס?'];
    return [
        ['name' => 'דירת מגורים לרכישה או בטוחה', 'description' => 'דירה קיימת שנבדקת לצורך רכישה, אשראי או מטרה אחרת שהוגדרה.',
            'purposes' => ['purchase', 'collateral'], 'property_types' => ['apartment', 'house'],
            'content' => ['flags' => [$flag], 'requirements' => ['groups' => $groups, 'items' => $sorted($base)], 'tasks' => $tasks(), 'rules' => [$renovation]]],
        ['name' => 'דירה לשיפוץ או נכס בבנייה', 'description' => 'נוסף לנתוני הנכס הקיים: תוכנית עבודה, כמויות, שלב ביצוע ותצלומי מצב קיים.',
            'purposes' => ['renovation', 'construction'], 'property_types' => ['apartment', 'house'],
            'content' => ['flags' => [], 'requirements' => ['groups' => $groups, 'items' => $sorted(array_merge($base, [
                apr_seed_req('work_plan', 'planning', 'plans', 'תוכנית עבודה ולוח זמנים', 'client', 5),
                apr_seed_req('quantities', 'planning', 'quantities', 'פירוט כמויות או הצעת מחיר', 'client', 5, false, 'normal', 'ככל שיש'),
                apr_seed_req('current_photos', 'planning', 'other', 'תצלומי מצב קיים', 'client', 5)]))],
                'tasks' => $tasks([['key' => 'review_assumptions', 'title' => 'סקירה מחדש של הנחות וחישובים אם העבודות השתנו אחרי הביקור', 'assignee' => 'responsible', 'due' => ['mode' => 'manual'], 'sort' => 4]]), 'rules' => []]],
        ['name' => 'נכס מסחרי, משרד או תעשייה', 'description' => 'שימוש בפועל, שטחים ויחידות, תפוסה וחוזי שכירות — לצד בדיקת זכויות ותכנון.',
            'purposes' => ['purchase', 'collateral', 'income'], 'property_types' => ['commercial', 'office', 'industrial'],
            'content' => ['flags' => [], 'requirements' => ['groups' => $groups, 'items' => $sorted(array_merge($base, [
                apr_seed_req('leases', 'deal', 'lease', 'חוזי שכירות בתוקף', 'client', 5, false, 'high', 'כל חוזי השכירות, כולל תקופות, תשלומים והוצאות'),
                apr_seed_req('occupancy', 'deal', 'other', 'מצב תפוסה ושטחים לפי יחידות', 'client', 5)]))], 'tasks' => $tasks(), 'rules' => []]],
        ['name' => 'קרקע, זכויות או נכס מורכב', 'description' => 'כמה חלקות או מזהים לאותו נכס, שימושים אפשריים, מדידה ותכנון.',
            'purposes' => ['purchase', 'taxation', 'dispute', 'other'], 'property_types' => ['land', 'complex'],
            'content' => ['flags' => [], 'requirements' => ['groups' => $groups, 'items' => $sorted(array_merge($base, [
                apr_seed_req('survey', 'planning', 'survey', 'מסמך מדידה', 'surveyor', 7),
                apr_seed_req('plans', 'planning', 'plans', 'תוכניות ומסמכי תכנון', 'architect', 7)]))], 'tasks' => $tasks(), 'rules' => []]],
    ];
}

/** זורע את הדוגמאות פעם אחת (meta: profiles_seeded). רץ בקריאה הראשונה של הספרייה. */
function apr_profiles_seed(): void
{
    if (apr_meta('profiles_seeded') !== null) return;
    apr_tx(function () {
        if (apr_meta('profiles_seeded') !== null) return;
        $now = apr_now();
        foreach (apr_seed_profiles() as $s) {
            $content = apr_profile_content_clean($s['content']);
            $id = apr_insert('profiles', ['pid' => apr_pid(), 'scope' => 'recommended', 'name' => $s['name'], 'description' => $s['description'],
                'purposes' => json_encode($s['purposes']), 'property_types' => json_encode($s['property_types']), 'status' => 'active', 'is_sample' => 1,
                'created_at' => $now, 'updated_at' => $now]);
            $v = apr_insert('profile_versions', ['pid' => apr_pid(), 'profile_id' => $id, 'number' => 1, 'state' => 'published',
                'content' => json_encode($content, JSON_UNESCAPED_UNICODE), 'change_note' => 'דוגמה ראשונה', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            apr_exec('UPDATE profiles SET current_version_id = ? WHERE id = ?', [$v, $id]);
        }
        apr_meta_set('profiles_seeded', $now);
    });
}
