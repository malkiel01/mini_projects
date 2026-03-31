<?php
header('Content-Type: application/json; charset=utf-8');

$fontsDir = __DIR__ . '/fonts';
if (!is_dir($fontsDir)) {
    mkdir($fontsDir, 0755, true);
}

$action = $_GET['action'] ?? '';

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
    if (empty($safeName)) {
        $safeName = 'font_' . time();
    }
    $dest = $fontsDir . '/' . $safeName . '.' . $ext;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode([
            'success' => true,
            'font' => [
                'name' => $safeName,
                'filename' => $safeName . '.' . $ext,
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save font']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
