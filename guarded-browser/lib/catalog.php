<?php
/**
 * קטלוג הקטגוריות וסוגי התוכן.
 *
 * ההיתר לפי *סוג* ולא לפי כתובת: "לחסום קניות" הוא כלל אחד, במקום
 * רשימה של מאות דומיינים שתמיד תהיה חסרה אחד. הקטלוג הוא נקודת
 * ההתחלה; המנהל מוסיף עליו דומיינים משלו בפאנל.
 */

declare(strict_types=1);

/**
 * קטגוריות אתרים.
 *
 * הסדר הוא סדר התצוגה בפאנל, ולכן הוא מסודר לפי מה שמנהל בפועל
 * מחפש קודם — לא לפי אלפבית.
 */
function categoryCatalog(): array {
    return [
        'video'      => ['שידור ווידאו',      '🎬'],
        'social'     => ['רשתות חברתיות',      '💬'],
        'news'       => ['חדשות',              '📰'],
        'sports'     => ['ספורט',              '⚽'],
        'music'      => ['מוזיקה',             '🎵'],
        'gaming'     => ['משחקים',             '🎮'],
        'shopping'   => ['קניות',              '🛒'],
        'education'  => ['לימודים',            '📚'],
        'reference'  => ['מידע ואנציקלופדיות', '🔎'],
        'finance'    => ['בנקים וכספים',       '🏦'],
        'gov'        => ['ממשלה ורשויות',      '🏛️'],
        'health'     => ['בריאות',             '🩺'],
        'email'      => ['דוא״ל',              '✉️'],
        'ai'         => ['כלי בינה מלאכותית',  '🤖'],
        'files'      => ['שיתוף קבצים',        '📁'],
        'forums'     => ['פורומים',            '🗣️'],
        'dating'     => ['היכרויות',           '💘'],
        'gambling'   => ['הימורים',            '🎰'],
        'adult'      => ['תוכן למבוגרים',      '🔞'],
        'ads'        => ['פרסום ומעקב',        '📊'],
    ];
}

function categoryLabel(string $key): string {
    return categoryCatalog()[$key][0] ?? $key;
}

function categoryIcon(string $key): string {
    return categoryCatalog()[$key][1] ?? '•';
}

/**
 * סוגי תוכן — הציר השני, ואינו זהה לקטגוריה.
 *
 * קטגוריה שואלת *לאן* פונים; סוג תוכן שואל *מה* נטען. לחסום "ווידאו"
 * עוצר סרטון גם באתר חדשות שכולו מותר, בלי לחסום את האתר.
 *
 * הזיהוי לפי סיומת ולפי Content-Type — סיומת מספיקה לרוב, וכשהשרת
 * מצהיר סוג, הוא מכריע.
 */
function contentTypeCatalog(): array {
    return [
        'video' => [
            'label' => 'ווידאו',
            'icon'  => '🎬',
            'ext'   => ['mp4','m4v','mov','avi','mkv','webm','flv','m3u8','mpd','ts'],
            'mime'  => ['video/', 'application/vnd.apple.mpegurl', 'application/x-mpegurl',
                        'application/dash+xml'],
        ],
        'audio' => [
            'label' => 'שמע',
            'icon'  => '🎵',
            'ext'   => ['mp3','m4a','aac','ogg','opus','wav','flac'],
            'mime'  => ['audio/'],
        ],
        'image' => [
            'label' => 'תמונות',
            'icon'  => '🖼️',
            'ext'   => ['jpg','jpeg','png','gif','webp','bmp','svg','avif'],
            'mime'  => ['image/'],
        ],
        'document' => [
            'label' => 'מסמכים',
            'icon'  => '📄',
            'ext'   => ['pdf','doc','docx','xls','xlsx','ppt','pptx','odt'],
            'mime'  => ['application/pdf', 'application/msword',
                        'application/vnd.openxmlformats-officedocument'],
        ],
        'archive' => [
            'label' => 'קבצי ארכיון',
            'icon'  => '🗜️',
            'ext'   => ['zip','rar','7z','tar','gz','bz2'],
            'mime'  => ['application/zip','application/x-rar','application/x-7z',
                        'application/gzip','application/x-tar'],
        ],
        'executable' => [
            'label' => 'קבצי התקנה',
            'icon'  => '⚙️',
            'ext'   => ['apk','exe','msi','dmg','deb','rpm','bat','sh'],
            'mime'  => ['application/vnd.android.package-archive',
                        'application/x-msdownload','application/x-apple-diskimage'],
        ],
    ];
}

/**
 * מזהה את סוג התוכן של כתובת.
 *
 * ‏$declared הוא Content-Type אם התקבל. מחרוזת השאילתה נחתכת לפני
 * בדיקת הסיומת: "movie.mp4?token=abc" הוא ווידאו, והתעלמות מכך
 * הייתה מחמיצה בדיוק את הכתובות שנועדו להסתיר את עצמן.
 */
function contentTypeOf(string $url, string $declared = ''): string {
    $declared = strtolower(trim(explode(';', $declared)[0] ?? ''));

    if ($declared !== '') {
        foreach (contentTypeCatalog() as $key => $def) {
            foreach ($def['mime'] as $prefix) {
                if (str_starts_with($declared, $prefix)) return $key;
            }
        }
    }

    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
    if ($ext === '') return '';

    foreach (contentTypeCatalog() as $key => $def) {
        if (in_array($ext, $def['ext'], true)) return $key;
    }
    return '';
}

/**
 * זריעת שיוכי הדומיינים.
 *
 * רשימה שימושית ולא ממצה — מכסה את מה שנתקלים בו בפועל, כולל אתרים
 * ישראליים. המנהל מוסיף בפאנל, ומה שאינו מסווג נופל לברירת המחדל.
 */
function seedDomainCategories(): array {
    return [
        'video' => ['youtube.com','youtu.be','vimeo.com','dailymotion.com','netflix.com',
                    'twitch.tv','tiktok.com','rutube.ru','hotmart.com','kan.org.il',
                    'mako.co.il','13tv.co.il','sport5.co.il','liveball.sx'],
        'social' => ['facebook.com','instagram.com','twitter.com','x.com','snapchat.com',
                     'linkedin.com','pinterest.com','reddit.com','tumblr.com','threads.net',
                     'whatsapp.com','telegram.org','t.me','discord.com'],
        'news' => ['ynet.co.il','walla.co.il','haaretz.co.il','maariv.co.il','israelhayom.co.il',
                   'jpost.com','bbc.com','cnn.com','nytimes.com','n12.co.il','kikar.co.il',
                   'bhol.co.il','jdn.co.il','srugim.co.il'],
        'sports' => ['sport5.co.il','one.co.il','espn.com','livescore.com','flashscore.com',
                     'sofascore.com','liveball.sx','365scores.com'],
        'music' => ['spotify.com','soundcloud.com','deezer.com','music.apple.com','shazam.com'],
        'gaming' => ['steampowered.com','epicgames.com','roblox.com','minecraft.net',
                     'ea.com','xbox.com','playstation.com','poki.com','crazygames.com'],
        'shopping' => ['amazon.com','ebay.com','aliexpress.com','shein.com','temu.com',
                       'zap.co.il','ksp.co.il','ivory.co.il','terminalx.com','yad2.co.il'],
        'education' => ['coursera.org','udemy.com','khanacademy.org','edx.org','duolingo.com',
                        'campus.gov.il','sefaria.org','hebrewbooks.org','daat.ac.il'],
        'reference' => ['wikipedia.org','wikimedia.org','britannica.com','dictionary.com',
                        'morfix.co.il','translate.google.com'],
        'finance' => ['paypal.com','bankleumi.co.il','bankhapoalim.co.il','mizrahi-tefahot.co.il',
                      'discountbank.co.il','isracard.co.il','max.co.il','cal-online.co.il',
                      'binance.com','coinbase.com'],
        'gov' => ['gov.il','btl.gov.il','misim.gov.il','mrp.gov.il','police.gov.il'],
        'health' => ['clalit.co.il','maccabi4u.co.il','meuhedet.co.il','leumit.co.il',
                     'health.gov.il','webmd.com'],
        'email' => ['gmail.com','mail.google.com','outlook.com','yahoo.com','proton.me',
                    'walla.co.il','mail.com'],
        'ai' => ['claude.ai','anthropic.com','openai.com','chatgpt.com','gemini.google.com',
                 'perplexity.ai','copilot.microsoft.com','midjourney.com'],
        'files' => ['drive.google.com','dropbox.com','mega.nz','wetransfer.com','mediafire.com',
                    'onedrive.live.com','icloud.com'],
        'forums' => ['reddit.com','quora.com','stackoverflow.com','fxp.co.il','tapuz.co.il'],
        'dating' => ['tinder.com','bumble.com','okcupid.com','jdate.com'],
        'gambling' => ['bet365.com','pokerstars.com','888.com','winner.co.il','pais.co.il',
                       '1xbet.com','betway.com'],
        'ads' => ['doubleclick.net','googlesyndication.com','googletagmanager.com',
                  'google-analytics.com','adservice.google.com','taboola.com','outbrain.com',
                  'criteo.com','hotjar.com','facebook.net'],
    ];
}
