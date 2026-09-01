<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/PaymentReconcileV26.php';

gp_start_session();
gp_require_admin(false);
$admin = gp_current_admin();

function v26_json(array $payload, int $status=200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function v26_input(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    return is_array($json) ? $json : $_POST;
}
function v26_csrf(array $input): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf'] ?? ''));
    if (function_exists('gp_csrf_token')) {
        $expected = (string)gp_csrf_token();
        if ($expected !== '' && ($sent === '' || !hash_equals($expected, $sent))) throw new RuntimeException('Sesión vencida o token CSRF inválido. Recarga la página.');
    }
}

try {
    $action = strtolower(trim((string)($_GET['action'] ?? '')));
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? v26_input() : $_GET;
    v26_csrf($input);
    switch ($action) {
        case 'health':
            PaymentReconcileV26::ensureSchema();
            v26_json(['ok'=>true,'version'=>'26.0.0']);
        case 'search':
            v26_json(['ok'=>true,'customers'=>PaymentReconcileV26::searchCustomers((string)($input['q']??''))]);
        case 'account':
            v26_json(['ok'=>true,'account'=>PaymentReconcileV26::getAccount((string)($input['customer_key']??''))]);
        case 'preview':
            v26_json(['ok'=>true,'preview'=>PaymentReconcileV26::preview($input)]);
        case 'reconcile':
            v26_json(['ok'=>true,'result'=>PaymentReconcileV26::reconcile($input, is_array($admin)?$admin:[])]);
        case 'history':
            v26_json(['ok'=>true,'history'=>PaymentReconcileV26::history((string)($input['customer_key']??''))]);
        default:
            v26_json(['ok'=>false,'error'=>'Acción no válida.'],404);
    }
} catch (Throwable $e) {
    v26_json(['ok'=>false,'error'=>$e->getMessage()],400);
}
