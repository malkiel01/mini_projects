<?php
/**
 * מצב למידה ביוטיוב (שלב 12 ה) — הגבול במנוע (רק התור), והדוח ממה שהמכשיר אימת בדף.
 *
 *   php guarded-browser/tests/ytlearn.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-ytlearn-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

db();
$learn = ['mode' => 'learn', 'allow_search' => 1, 'allow_shorts' => 1];
$items = ['video' => ['vid_queued1' => 'allow'], 'handle' => ['teacher' => 'allow'], 'channel' => ['UCapproved12345678901234' => 'allow']];
$yt = fn(string $u, bool $main = true) => youTubeVerdict(normalizeUrl($u), $learn, $items, $main,
                                                         fn($id) => ['channel' => 'UCapproved12345678901234', 'handle' => 'teacher'])['code'];

echo "\n— הגבול במנוע —\n";
check('סרטון בתור — נפתח', $yt('https://m.youtube.com/watch?v=vid_queued1'), 'yt_video_allowed');
check('סרטון מערוץ מאושר שאינו בתור — לא (ערוץ לא פותח הכול)', $yt('https://m.youtube.com/watch?v=vid_other22'), 'yt_learn_queue');
check('דף הערוץ — לא', $yt('https://m.youtube.com/@teacher'), 'yt_learn_queue');
check('Shorts — לא, גם של סרטון שבתור', $yt('https://m.youtube.com/shorts/vid_queued1'), 'yt_no_shorts');
check('חיפוש — לא, גם כשהמתג "חיפוש" דלוק', $yt('https://m.youtube.com/results?search_query=x'), 'yt_learn_queue');
check('...וגם בקשת הרקע של החיפוש', $yt('https://m.youtube.com/youtubei/v1/search?key=x', false), 'yt_no_search');
check('דף הבית — לא', $yt('https://m.youtube.com/'), 'yt_learn_queue');
check('נגן הסרטון (משאב) — נטען', $yt('https://rr1---sn.googlevideo.com/videoplayback?x=1', false), 'yt_asset');
check('איסור מפורש גובר', youTubeVerdict(normalizeUrl('https://m.youtube.com/watch?v=vid_queued1'), $learn,
      ['video' => ['vid_queued1' => 'deny']], true)['code'], 'yt_item_denied');

echo "\n— שמירה —\n";
[$acc] = accountCreate(['name' => 'כהן', 'modules' => ['yt_filtered']], 'dana@x.com', 'דנה');
[$kid] = childCreate($acc, 'נועה');
check('מצב למידה — נשמר בחשבון עם יוטיוב', childYouTubeSave($kid, 'learn', false, false, youTubeModesFor($acc)), '');
[$acc2] = accountCreate(['name' => 'לוי', 'modules' => []], 'b@x.com', 'בני');
[$kid2] = childCreate($acc2, 'דן');
check('...ונדחה בחשבון בלי יוטיוב', childYouTubeSave($kid2, 'learn', false, false, youTubeModesFor($acc2)) !== '', true);
childYouTubeAdd($kid, 'https://www.youtube.com/watch?v=vid_second', 'שני');
childYouTubeAdd($kid, 'https://www.youtube.com/watch?v=vid_first1', 'ראשון');
check('התור — לפי סדר ההוספה', array_keys(platformItemsFor($kid)[PLATFORM_YOUTUBE]['video']), ['vid_second', 'vid_first1']);

echo "\n— מה אומת במכשיר —\n";
$st = fn() => array_column(ytLearnStatus($kid), 'state', 'key');
check('לפני דוח — לא ידוע, לא "אומת"', $st(), ['related' => 'unknown', 'autoplay' => 'unknown', 'end_stop' => 'unknown']);
registerDevice($kid, 'Galaxy', 'g1', 5);
$dev = (int) one("SELECT id FROM devices WHERE device_id = 'g1'")['id'];
ytLearnReportSave($dev, ['related' => true, 'autoplay' => false, 'end_stop' => null, 'evil' => true]);
check('דוח: אומת / נכשל / לא נבדק', $st(), ['related' => 'verified', 'autoplay' => 'failed', 'end_stop' => 'unknown']);
check('...עם שם המכשיר', ytLearnStatus($kid)[0]['device'], 'Galaxy');
check('שדה לא מוכר — לא נשמר', array_key_exists('evil', json_decode(one('SELECT yt_learn FROM devices WHERE id = ?', [$dev])['yt_learn'], true)), false);
check('דוח בן יותר משבוע — לא ידוע (יוטיוב משתנה)', array_column(ytLearnStatus($kid, time() + 8 * 86400), 'state', 'key')['related'], 'unknown');
check('ההורה רואה את הבדיקה במצב למידה', count(childDetail(childOf($acc, $kid), youTubeModesFor($acc))['youtube']['learn_check']), 3);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
