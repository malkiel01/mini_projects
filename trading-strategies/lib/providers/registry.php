<?php
/**
 * רשימת הספקים (spec-stage-a 3.1–3.2). הסדר — סדר ההעדפה בחיפוש כשאין למשתמש ספק מועדף לסימול.
 * ספק חדש = קובץ ב-lib/providers/ + שורה כאן.
 */

declare(strict_types=1);

require_once __DIR__ . '/binance.php';
require_once __DIR__ . '/twelvedata.php';
require_once __DIR__ . '/finnhub.php';
require_once __DIR__ . '/ecb.php';

const TS_PROVIDERS = [
    'binance' => TsProvBinance::class,
    'twelvedata' => TsProvTwelveData::class,
    'finnhub' => TsProvFinnhub::class,
    'ecb' => TsProvEcb::class,
];

function ts_provider(string $id): TsProvider
{
    static $made = [];
    if (!isset(TS_PROVIDERS[$id])) throw new TsProviderError('ספק לא מוכר');
    return $made[$id] ??= new (TS_PROVIDERS[$id])();
}
