<?php
declare(strict_types=1);
// Stub local de FlowBot para validar GRANDPRIX sin contactar Meta.
// Recibe POST /api/v1/whatsapp/templates/send (JSON o multipart/form-data),
// registra la petición en /tmp/flowbot_stub.log y guarda el PDF en /tmp/flowbot_stub.pdf.
$log = '/tmp/flowbot_stub.log';
$entry = [
    'ts' => date('c'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'api_key' => $_SERVER['HTTP_X_API_KEY'] ?? '',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
];
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($ct, 'multipart/form-data')) {
    $entry['body_len'] = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $entry['payload'] = isset($_POST['payload']) ? json_decode((string) $_POST['payload'], true) : null;
    if (!empty($_FILES['file'])) {
        $entry['file_name'] = (string) $_FILES['file']['name'];
        $entry['file_size'] = (int) $_FILES['file']['size'];
        $entry['file_error'] = (int) $_FILES['file']['error'];
        if (is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
            $bytes = (string) file_get_contents((string) $_FILES['file']['tmp_name']);
            $entry['pdf_len'] = strlen($bytes);
            $entry['pdf_head'] = substr($bytes, 0, 8);
            @file_put_contents('/tmp/flowbot_stub.pdf', $bytes);
        }
    }
} else {
    $entry['body'] = json_decode((string) file_get_contents('php://input'), true);
}
@file_put_contents($log, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'message_id' => 'wamid.LOCAL-' . bin2hex(random_bytes(6))], JSON_UNESCAPED_UNICODE);
