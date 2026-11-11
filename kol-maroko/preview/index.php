<?php
/**
 * התצוגה החיה המבודדת (פרוסה F4) — נקודת הכניסה. כל בקשה ל-preview/** מגיעה לכאן (preview/.htaccess).
 * ⚠️ הקובץ ב-"public" של app.json: מסגרת במקור אטום אינה שולחת עוגייה. ההרשאה היא האסימון שבכתובת (lib/preview.php).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/preview.php';

pv_serve();
