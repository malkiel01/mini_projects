<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['prepended' => defined('PROBE_PREPENDED'), 'sapi' => PHP_SAPI]);
