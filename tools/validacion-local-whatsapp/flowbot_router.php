<?php
declare(strict_types=1);
// Router del servidor PHP built-in para el stub local de FlowBot.
// Uso: php -S 127.0.0.1:8123 tools/validacion-local-whatsapp/flowbot_router.php
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (str_contains($uri, '/api/v1/whatsapp/templates/send')) {
    require __DIR__ . '/flowbot_handler.php';
} else {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Ruta no encontrada en el stub local.']);
}
