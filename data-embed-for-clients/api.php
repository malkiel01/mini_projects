<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$fontsDir = __DIR__ . '/fonts';
$templatesDir = __DIR__ . '/templates';
if (!is_dir($fontsDir)) { mkdir($fontsDir, 0755, true); }
if (!is_dir($templatesDir)) { mkdir($templatesDir, 0755, true); }

$action = $_GET['action'] ?? '';

// --- FONTS ---
if ($action === 'list') {
    $fonts = [];
    foreach (glob($fontsDir . '/*.{ttf,otf}', GLOB_BRACE) as $file) {
        $fonts[] = [
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'filename' => basename($file),
        ];
    }
    echo json_encode(['fonts' => $fonts]);
    exit;
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['font']) || $_FILES['font']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No font file uploaded']);
        exit;
    }
    $file = $_FILES['font'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['ttf', 'otf'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Only TTF and OTF files allowed']);
        exit;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9\x{0590}-\x{05FF}_\- ]/u', '', pathinfo($file['name'], PATHINFO_FILENAME));
    if (empty($safeName)) { $safeName = 'font_' . time(); }
    $dest = $fontsDir . '/' . $safeName . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['success' => true, 'font' => ['name' => $safeName, 'filename' => $safeName . '.' . $ext]]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save font']);
    }
    exit;
}

// --- TEMPLATES ---
if ($action === 'save_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing template name']);
        exit;
    }
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['id'] ?? '') ?: 'tpl_' . time() . '_' . bin2hex(random_bytes(4));
    $input['id'] = $id;
    $input['updated_at'] = date('c');
    file_put_contents($templatesDir . '/' . $id . '.json', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($action === 'list_templates') {
    $templates = [];
    foreach (glob($templatesDir . '/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $templates[] = [
                'id' => $data['id'] ?? pathinfo($file, PATHINFO_FILENAME),
                'name' => $data['name'] ?? '',
                'updated_at' => $data['updated_at'] ?? '',
            ];
        }
    }
    echo json_encode(['templates' => $templates]);
    exit;
}

if ($action === 'get_template') {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $file = $templatesDir . '/' . $id . '.json';
    if (!$id || !file_exists($file)) {
        http_response_code(404);
        echo json_encode(['error' => 'Template not found']);
        exit;
    }
    echo file_get_contents($file);
    exit;
}

// --- API RENDER (accepts field values, returns template + values for client-side PDF generation) ---
if ($action === 'render' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $file = $templatesDir . '/' . $id . '.json';
    if (!$id || !file_exists($file)) {
        http_response_code(404);
        echo json_encode(['error' => 'Template not found']);
        exit;
    }
    $template = json_decode(file_get_contents($file), true);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    // Replace source image if allowed and provided
    if (!empty($template['allowSourceReplace']) && !empty($input['source_image'])) {
        foreach ($template['pages'] as &$page) {
            $page['imageData'] = $input['source_image'];
        }
        unset($page);
    }

    // Fill field values
    if (!empty($input['fields']) && is_array($input['fields'])) {
        foreach ($template['pages'] as &$page) {
            foreach ($page['fields'] as &$field) {
                if ($field['valueType'] === 'list' && isset($input['fields'][$field['id']])) {
                    $field['_value'] = $input['fields'][$field['id']];
                }
            }
            unset($field);
        }
        unset($page);
    }

    echo json_encode($template, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- SAVE SIGNED DOCUMENT ---
if ($action === 'save_signed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $signedDir = __DIR__ . '/signed';
    if (!is_dir($signedDir)) { mkdir($signedDir, 0755, true); }

    $templateId = $_POST['template_id'] ?? '';
    if (!$templateId || !isset($_FILES['pdf'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing template_id or pdf']);
        exit;
    }

    $safeTplId = preg_replace('/[^a-zA-Z0-9_-]/', '', $templateId);
    $tplDir = $signedDir . '/' . $safeTplId;
    if (!is_dir($tplDir)) { mkdir($tplDir, 0755, true); }

    $filename = date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    move_uploaded_file($_FILES['pdf']['tmp_name'], $tplDir . '/' . $filename);

    // Save signatures JSON
    if (!empty($_POST['signatures'])) {
        file_put_contents($tplDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '_sig.json', $_POST['signatures']);
    }

    echo json_encode(['success' => true, 'filename' => $filename]);
    exit;
}

// --- LIST SIGNED DOCUMENTS ---
if ($action === 'list_signed') {
    $signedDir = __DIR__ . '/signed';
    $templateId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $tplDir = $signedDir . '/' . $templateId;
    $files = [];
    if ($templateId && is_dir($tplDir)) {
        foreach (glob($tplDir . '/*.pdf') as $file) {
            $files[] = [
                'filename' => basename($file),
                'date' => date('c', filemtime($file)),
                'size' => filesize($file),
            ];
        }
    }
    echo json_encode(['files' => $files]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
