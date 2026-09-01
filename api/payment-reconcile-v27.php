<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/PaymentReconcileV27.php';

gp_start_session();
gp_require_admin(false);
$admin = gp_current_admin();

function v27_json(array $payload, int $status=200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function v27_input(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    return is_array($json) ? $json : $_POST;
}
function v27_csrf(array $input): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf'] ?? ''));
    if (function_exists('gp_csrf_token')) {
        $expected = (string)gp_csrf_token();
        if ($expected !== '' && ($sent === '' || !hash_equals($expected, $sent))) throw new RuntimeException('Sesión vencida o token CSRF inválido. Recarga la página.');
    }
}

try {
    $action = strtolower(trim((string)($_GET['action'] ?? '')));
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? v27_input() : $_GET;
    v27_csrf($input);
    switch ($action) {
        case 'health':
            PaymentReconcileV27::ensureSchema();
            v27_json(['ok'=>true,'version'=>'27.0.0']);
        case 'search':
            v27_json(['ok'=>true,'customers'=>PaymentReconcileV27::searchCustomers((string)($input['q']??''))]);
        case 'weekly-plan':
            v27_json(['ok'=>true,'plan'=>PaymentReconcileV27::getWeeklyPlan((string)($input['customer_key']??''))]);
        case 'weekly-plan-save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') v27_json(['ok'=>false,'error'=>'Método no permitido.'],405);
            v27_json(['ok'=>true,'plan'=>PaymentReconcileV27::saveWeeklyPlan((string)($input['customer_key']??''), $input['weekly_amount_usd']??0, is_array($admin)?$admin:[])]);
        case 'account':
            v27_json(['ok'=>true,'account'=>PaymentReconcileV27::getAccount((string)($input['customer_key']??''))]);
        case 'preview':
            v27_json(['ok'=>true,'preview'=>PaymentReconcileV27::preview($input)]);
        case 'reconcile':
            v27_json(['ok'=>true,'result'=>PaymentReconcileV27::reconcile($input, is_array($admin)?$admin:[])]);
        case 'history':
            v27_json(['ok'=>true,'history'=>PaymentReconcileV27::history((string)($input['customer_key']??''))]);
        default:
            v27_json(['ok'=>false,'error'=>'Acción no válida.'],404);
    }
} catch (Throwable $e) {
    v27_json(['ok'=>false,'error'=>$e->getMessage()],400);
}
