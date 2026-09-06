<?php
/**
 * API for Greeting Cards Generator
 * Handles font uploads, template saving, and background gallery
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$fontsDir = __DIR__ . '/fonts';
$templatesDir = __DIR__ . '/templates';
$backgroundsDir = __DIR__ . '/backgrounds';

// Create directories if needed
foreach ([$fontsDir, $templatesDir, $backgroundsDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // ─── Fonts ───────────────────────────────────
        case 'upload_font':
            handleFontUpload($fontsDir);
            break;

        case 'list_fonts':
            listFonts($fontsDir);
            break;

        case 'get_font':
            getFont($fontsDir);
            break;

        case 'delete_font':
            deleteFont($fontsDir);
            break;

        // ─── Templates ──────────────────────────────
        case 'save_template':
            saveTemplate($templatesDir);
            break;

        case 'list_templates':
            listTemplates($templatesDir);
            break;

        case 'get_template':
            getTemplate($templatesDir);
            break;

        case 'delete_template':
            deleteTemplate($templatesDir);
            break;

        // ─── Backgrounds ────────────────────────────
        case 'upload_background':
            handleBackgroundUpload($backgroundsDir);
            break;

        case 'list_backgrounds':
            listBackgrounds($backgroundsDir);
            break;

        case 'delete_background':
            deleteBackground($backgroundsDir);
            break;

        default:
            respond(false, 'Unknown action: ' . $action);
    }
} catch (Exception $e) {
    respond(false, $e->getMessage());
}

// ─── Font Functions ──────────────────────────────────

function handleFontUpload($dir) {
    if (empty($_FILES['font'])) {
        respond(false, 'No font file provided');
    }

    $file = $_FILES['font'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'])) {
        respond(false, 'Unsupported font format. Use TTF, OTF, WOFF, or WOFF2');
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        respond(false, 'Font file too large (max 10MB)');
    }

    $displayName = $_POST['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME);
    $id = uniqid('font_');
    $filename = $id . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        respond(false, 'Failed to save font file');
    }

    // Save metadata
    $meta = [
        'id' => $id,
        'name' => $displayName,
        'filename' => $filename,
        'ext' => $ext,
        'size' => $file['size'],
        'uploaded' => date('c'),
    ];
    file_put_contents($dir . '/' . $id . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    respond(true, 'Font uploaded', $meta);
}

function listFonts($dir) {
    $fonts = [];
    foreach (glob($dir . '/*.json') as $metaFile) {
        $meta = json_decode(file_get_contents($metaFile), true);
        if ($meta) {
            $fonts[] = $meta;
        }
    }
    usort($fonts, fn($a, $b) => strcmp($b['uploaded'] ?? '', $a['uploaded'] ?? ''));
    respond(true, 'OK', ['fonts' => $fonts]);
}

function getFont($dir) {
    $id = $_GET['id'] ?? '';
    if (!$id) respond(false, 'Missing font ID');

    $metaFile = $dir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'Font not found');

    $meta = json_decode(file_get_contents($metaFile), true);
    $fontFile = $dir . '/' . $meta['filename'];
    if (!file_exists($fontFile)) respond(false, 'Font file missing');

    $base64 = base64_encode(file_get_contents($fontFile));
    $meta['data'] = $base64;

    respond(true, 'OK', $meta);
}

function deleteFont($dir) {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) respond(false, 'Missing font ID');

    $metaFile = $dir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'Font not found');

    $meta = json_decode(file_get_contents($metaFile), true);
    $fontFile = $dir . '/' . $meta['filename'];

    @unlink($fontFile);
    @unlink($metaFile);

    respond(true, 'Font deleted');
}

// ─── Template Functions ─────────────────────────────

function saveTemplate($dir) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        // Try POST data
        $input = $_POST;
    }

    $name = $input['name'] ?? 'ללא שם';
    $id = $input['id'] ?? uniqid('tmpl_');

    $template = [
        'id' => $id,
        'name' => $name,
        'fields' => $input['fields'] ?? [],
        'background' => $input['background'] ?? null,
        'updated' => date('c'),
    ];

    file_put_contents(
        $dir . '/' . $id . '.json',
        json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    respond(true, 'Template saved', $template);
}

function listTemplates($dir) {
    $templates = [];
    foreach (glob($dir . '/*.json') as $file) {
        $tmpl = json_decode(file_get_contents($file), true);
        if ($tmpl) {
            // Don't include full field data in listing
            unset($tmpl['fields']);
            $templates[] = $tmpl;
        }
    }
    usort($templates, fn($a, $b) => strcmp($b['updated'] ?? '', $a['updated'] ?? ''));
    respond(true, 'OK', ['templates' => $templates]);
}

function getTemplate($dir) {
    $id = $_GET['id'] ?? '';
    if (!$id) respond(false, 'Missing template ID');

    $file = $dir . '/' . $id . '.json';
    if (!file_exists($file)) respond(false, 'Template not found');

    $tmpl = json_decode(file_get_contents($file), true);
    respond(true, 'OK', $tmpl);
}

function deleteTemplate($dir) {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) respond(false, 'Missing template ID');

    $file = $dir . '/' . $id . '.json';
    if (!file_exists($file)) respond(false, 'Template not found');

    @unlink($file);
    respond(true, 'Template deleted');
}

// ─── Background Functions ───────────────────────────

function handleBackgroundUpload($dir) {
    if (empty($_FILES['background'])) {
        respond(false, 'No background file provided');
    }

    $file = $_FILES['background'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        respond(false, 'Unsupported image format');
    }

    if ($file['size'] > 20 * 1024 * 1024) {
        respond(false, 'Image too large (max 20MB)');
    }

    $id = uniqid('bg_');
    $filename = $id . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        respond(false, 'Failed to save background');
    }

    $meta = [
        'id' => $id,
        'name' => $_POST['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME),
        'filename' => $filename,
        'ext' => $ext,
        'size' => $file['size'],
        'uploaded' => date('c'),
    ];
    file_put_contents($dir . '/' . $id . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    respond(true, 'Background uploaded', $meta);
}

function listBackgrounds($dir) {
    $bgs = [];
    foreach (glob($dir . '/*.json') as $metaFile) {
        $meta = json_decode(file_get_contents($metaFile), true);
        if ($meta) {
            $bgs[] = $meta;
        }
    }
    respond(true, 'OK', ['backgrounds' => $bgs]);
}

function deleteBackground($dir) {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) respond(false, 'Missing background ID');

    $metaFile = $dir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'Background not found');

    $meta = json_decode(file_get_contents($metaFile), true);
    $bgFile = $dir . '/' . $meta['filename'];

    @unlink($bgFile);
    @unlink($metaFile);

    respond(true, 'Background deleted');
}

// ─── Helpers ────────────────────────────────────────

function respond($success, $message, $data = []) {
    echo json_encode(array_merge(
        ['success' => $success, 'message' => $message],
        $data
    ), JSON_UNESCAPED_UNICODE);
    exit;
}
