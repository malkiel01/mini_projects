<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['rewritten' => true, 'f' => $_GET['f'] ?? null, 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
