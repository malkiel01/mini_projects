<?php
/**
 * מיגרציה 008 — החנות (אפיון 09). רק CREATE — אין כאן מחיקה ואין שינוי של נתונים קיימים.
 * **WooCommerce הוא מקור האמת:** כל הטבלאות כאן הן מטמון של מה שנקרא משם, חוץ מ-title_products (הקישור לכותר — שלנו).
 *
 *   store_products    — מוצר אחד (woo_id = המזהה ב-WooCommerce). מחיר — באגורות (מספר שלם, בלי float). source: woo / sample
 *                       (נתוני דוגמה, נמחקים בסנכרון האמיתי הראשון). raw — מה שצריך כדי לגזור מחדש את השדות הממופים (מחבר,
 *                       הוצאה…) כשהמיפוי משתנה, בלי לפנות שוב לחנות. author / search — נגזרים, לסינון ולחיפוש. removed_at — לא
 *                       חזר בסנכרון מלא / נמחק בחנות (לא מוצג). seen_run — הריצה המלאה האחרונה שראתה אותו
 *   store_categories  — קטגוריה (עם parent)
 *   store_reviews     — ביקורת מאושרת קיימת: שם המבקר, דירוג, טקסט. **בלי המייל** (WooCommerce מחזיר אותו — לא נשמר)
 *   title_products    — רבים-לרבים: כותר בספורנו ↔ מוצר בחנות ("יש גם ספר דיגיטלי"), בלי שכפול תוכן
 *   store_sync_runs   — ריצת סנכרון: full / delta, שלב ועמוד (ממשיכה בסבב הבא של cron), מצב, שגיאה טכנית (בלי מפתח)
 *   store_webhook_log — אירוע webhook שהתקבל (delivery_id ייחודי — אירוע כפול לא מטופל פעמיים). נמחק אחרי 7 ימים
 *   store_stats       — מצרפי לפי יום: צפיות במוצר ומעברים לחנות (10). **בלי קורא ובלי IP**
 */

declare(strict_types=1);

return function (PDO $db, string $driver): void {
    ['id' => $id, 'ref' => $ref, 'int' => $int, 'key' => $key, 'text' => $text, 'ts' => $ts, 'long' => $long, 'tail' => $tail] = sf_ddl_types($driver);

    $db->exec("CREATE TABLE store_products (
        id $id,
        woo_id $ref NOT NULL UNIQUE,
        source $key NOT NULL DEFAULT 'woo',
        name $text NOT NULL,
        slug $text NULL,
        permalink $text NULL,
        price $ref NULL,
        regular_price $ref NULL,
        sale_price $ref NULL,
        on_sale $int NOT NULL DEFAULT 0,
        stock_status $key NULL,
        purchasable $int NOT NULL DEFAULT 1,
        rating $text NULL,
        rating_count $int NOT NULL DEFAULT 0,
        author $text NULL,
        search $long NULL,
        raw $long NOT NULL,
        modified_at $ts NULL,
        synced_at $ts NOT NULL,
        seen_run $ref NULL,
        removed_at $ts NULL
    )$tail");
    $db->exec('CREATE INDEX store_products_list ON store_products (removed_at, name)');
    $db->exec('CREATE INDEX store_products_price ON store_products (removed_at, price)');

    $db->exec("CREATE TABLE store_categories (
        id $id,
        woo_id $ref NOT NULL UNIQUE,
        source $key NOT NULL DEFAULT 'woo',
        name $text NOT NULL,
        slug $text NULL,
        parent $ref NOT NULL DEFAULT 0,
        product_count $int NOT NULL DEFAULT 0,
        synced_at $ts NOT NULL,
        seen_run $ref NULL,
        removed_at $ts NULL
    )$tail");

    $db->exec("CREATE TABLE store_product_categories (
        product_woo_id $ref NOT NULL,
        category_woo_id $ref NOT NULL,
        PRIMARY KEY (product_woo_id, category_woo_id)
    )$tail");
    $db->exec('CREATE INDEX store_pc_cat ON store_product_categories (category_woo_id)');

    $db->exec("CREATE TABLE store_reviews (
        id $id,
        woo_id $ref NOT NULL UNIQUE,
        source $key NOT NULL DEFAULT 'woo',
        product_woo_id $ref NOT NULL,
        reviewer $text NULL,
        rating $int NULL,
        body $long NULL,
        verified $int NOT NULL DEFAULT 0,
        created_at $ts NULL,
        synced_at $ts NOT NULL,
        seen_run $ref NULL,
        removed_at $ts NULL
    )$tail");
    $db->exec('CREATE INDEX store_reviews_product ON store_reviews (product_woo_id, removed_at)');

    $db->exec("CREATE TABLE title_products (
        id $id,
        title_id $ref NOT NULL,
        product_woo_id $ref NOT NULL,
        created_at $ts NOT NULL,
        UNIQUE (title_id, product_woo_id),
        FOREIGN KEY (title_id) REFERENCES titles (id)
    )$tail");
    $db->exec('CREATE INDEX title_products_product ON title_products (product_woo_id)');

    $db->exec("CREATE TABLE store_sync_runs (
        id $id,
        pid $key NOT NULL UNIQUE,
        kind $key NOT NULL,
        source $key NOT NULL DEFAULT 'woo',
        status $key NOT NULL,
        stage $key NOT NULL,
        page $int NOT NULL DEFAULT 1,
        since $ts NULL,
        products $int NOT NULL DEFAULT 0,
        requests $int NOT NULL DEFAULT 0,
        error $text NULL,
        started_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        finished_at $ts NULL
    )$tail");
    $db->exec('CREATE INDEX store_sync_runs_status ON store_sync_runs (status, started_at)');

    $db->exec("CREATE TABLE store_webhook_log (
        id $id,
        delivery_id $key NOT NULL UNIQUE,
        topic $key NOT NULL,
        woo_id $ref NULL,
        received_at $ts NOT NULL
    )$tail");
    $db->exec('CREATE INDEX store_webhook_log_at ON store_webhook_log (received_at)');

    $db->exec("CREATE TABLE store_stats (
        id $id,
        day $key NOT NULL,
        kind $key NOT NULL,
        product_woo_id $ref NOT NULL,
        n $int NOT NULL DEFAULT 0,
        UNIQUE (day, kind, product_woo_id)
    )$tail");
};
