<?php
/**
 * הגופנים של עורך התמלילים ל-PDF: חופשיים (OFL), אותם גופני גיבוי שהעורך מציג על המסך (assets/js/editor.js, cssFont),
 * כך שה-PDF נראה כמו המסך. כל משפחה מגיעה בשני קבצים (עברית / לטינית) — הבחירה בין הקבצים לכל תו.
 * הקריאה של הגופן עצמו — TtfFont ב-pdf-core (lib/pdf-core, עותק של creative-suite/pdf-core/src/php).
 */

require_once __DIR__ . '/../pdf-core/font.php';

/**
 * משפחת גופנים לפי השם ב-Word (David, Frank Ruehl…) → קבצי העברית והלטינית, רגיל ומודגש.
 * לכל תו — הקובץ הראשון שיש בו גליף; אין — Heebo; אין גם שם — הקובץ הראשון (ריבוע ריק).
 */
final class FontSet
{
    private const DIR = __DIR__ . '/../../vendor/fonts/pdf/';
    public const FAMILIES = [
        'David' => 'david-libre', 'David Libre' => 'david-libre',
        'Frank Ruehl' => 'frank-ruhl-libre', 'Frank Ruhl Libre' => 'frank-ruhl-libre', 'Times New Roman' => 'frank-ruhl-libre',
        'Miriam' => 'miriam-libre', 'Miriam Libre' => 'miriam-libre',
        'Arial' => 'heebo', 'Tahoma' => 'heebo', 'Heebo' => 'heebo',
        'Narkisim' => 'assistant', 'Calibri' => 'assistant', 'Assistant' => 'assistant',
        'Rubik' => 'rubik', 'Alef' => 'alef',
    ];
    private static array $cache = [];

    /** @return TtfFont[] לפי סדר העדיפות */
    public static function stack(string $family, bool $bold): array
    {
        $key = (self::FAMILIES[$family] ?? 'heebo') . ($bold ? '-700' : '-400');
        if (isset(self::$cache[$key])) return self::$cache[$key];
        $base = self::FAMILIES[$family] ?? 'heebo';
        $w = $bold ? 700 : 400;
        $files = ["$base-hebrew-$w", "$base-latin-$w"];
        if ($base !== 'heebo') array_push($files, "heebo-hebrew-$w", "heebo-latin-$w");
        return self::$cache[$key] = array_map(fn($f) => self::load($f), $files);
    }

    private static array $files = [];

    private static function load(string $name): TtfFont
    {
        return self::$files[$name] ??= TtfFont::fromFile(self::DIR . $name . '-normal.woff');
    }

    /** הגופן לתו: הראשון ברשימה שיש בו גליף. */
    public static function pick(array $stack, int $cp): TtfFont
    {
        foreach ($stack as $f) if ($f->has($cp)) return $f;
        return $stack[0];
    }

    /** כל הגופנים שנטענו (להטמעה). */
    public static function loaded(): array
    {
        return self::$files;
    }

    public static function reset(): void
    {
        foreach (self::$files as $f) $f->used = [];
    }
}
