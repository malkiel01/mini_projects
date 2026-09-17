<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
startAdminSession();
$_SESSION = [];
session_destroy();
header('Location: login.php');
