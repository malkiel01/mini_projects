<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
// מחוברת לפלטפורמה: היציאה היא מהכלים הדיגיטליים — כאן הבקשה הבאה הייתה מכניסה שוב.
if (platformIdentity()) { header('Location: index.php'); exit; }
endAdminSession();
header('Location: login.php');
