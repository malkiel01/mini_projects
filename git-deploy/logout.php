<?php
require_once __DIR__ . '/lib/auth.php';
Auth::logout();
header('Location: login.php');
exit;
