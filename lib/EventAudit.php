<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class EventAudit
{
    private static ?bool $readyCache = null;
    public const TIMEZONE = 'America/Caracas';

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d H:i:s');
    }

    public static function tablesReady(?PDO $pdo = null): bool
    {
        if(self::$readyCache!==null)return self::$readyCache;
        try {
            $pdo ??= Database::connection();
            $stmt = $pdo->query("SHOW TABLES LIKE 'gp_event_audit'");
            return self::$readyCache=(bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return self::$readyCache=false;
        }
    }

    public static function record(
        array $actor,
        string $actorType,
        string $module,
        string $action,
        string $eventType,
        ?string $entityType = null,
        ?int $entityId = null,
        string $summary = '',
        array $metadata = [],
        ?PDO $pdo = null
    ): void {
        try {
            $pdo ??= Database::connection();
            if (!self::tablesReady($pdo)) return;
            $ip = self::clientIp();
            $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
            $route = mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 500);
            $method = mb_substr((string)($_SERVER['REQUEST_METHOD'] ?? 'SYSTEM'), 0, 12);
            $sessionHash = session_status() === PHP_SESSION_ACTIVE ? hash('sha256', session_id().'|grandprix-v18') : '';
            $stmt = $pdo->prepare(
                'INSERT INTO gp_event_audit
                (event_at,timezone_name,actor_type,user_id,user_name,user_email,user_role,module_key,action_key,event_type,entity_type,entity_id,summary,http_method,route,ip_address,user_agent,session_hash,metadata_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                self::now(), self::TIMEZONE, mb_substr($actorType,0,30),
                ((int)($actor['id'] ?? 0)) ?: null,
                mb_substr((string)($actor['name'] ?? ''),0,180) ?: null,
                mb_substr((string)($actor['email'] ?? ''),0,190) ?: null,
                mb_substr((string)($actor['role'] ?? ''),0,120) ?: null,
                mb_substr($module,0,80), mb_substr($action,0,100), mb_substr($eventType,0,40),
                $entityType ? mb_substr($entityType,0,120) : null, $entityId ?: null,
                mb_substr($summary,0,500) ?: null,
                $method, $route, mb_substr($ip,0,45) ?: null, $ua ?: null, $sessionHash ?: null,
                json_encode(self::sanitizeMetadata($metadata), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // La auditoría nunca debe impedir una operación principal.
        }
    }

    public static function recordAdmin(array $actor, string $module, string $action, string $eventType, ?string $entityType = null, ?int $entityId = null, string $summary = '', array $metadata = [], ?PDO $pdo = null): void
    {
        self::record($actor, 'admin', $module, $action, $eventType, $entityType, $entityId, $summary, $metadata, $pdo);
    }

    public static function recordCustomer(array $actor, string $module, string $action, string $eventType, ?string $entityType = null, ?int $entityId = null, string $summary = '', array $metadata = [], ?PDO $pdo = null): void
    {
        self::record($actor, 'customer', $module, $action, $eventType, $entityType, $entityId, $summary, $metadata, $pdo);
    }

    public static function recordPublic(string $module, string $action, string $eventType, string $summary = '', array $metadata = [], ?PDO $pdo = null): void
    {
        self::record([], 'public', $module, $action, $eventType, null, null, $summary, $metadata, $pdo);
    }

    public static function adminActorFromSession(): array
    {
        return [
            'id'=>(int)($_SESSION['grandprix_admin_user_id'] ?? 0),
            'name'=>(string)($_SESSION['grandprix_admin_name'] ?? 'Administrador GRANDPRIX'),
            'email'=>(string)($_SESSION['grandprix_admin_email'] ?? ''),
            'role'=>(string)($_SESSION['grandprix_admin_role'] ?? 'Administrador'),
        ];
    }

    public static function customerActor(int $customerId, ?PDO $pdo = null): array
    {
        try {
            $pdo ??= Database::connection();
            $stmt=$pdo->prepare('SELECT id,full_name,email FROM gp_customers WHERE id=? LIMIT 1');
            $stmt->execute([$customerId]);
            $row=$stmt->fetch() ?: [];
            return ['id'=>(int)($row['id']??$customerId),'name'=>(string)($row['full_name']??($_SESSION['grandprix_customer_name']??'Cliente GRANDPRIX')),'email'=>(string)($row['email']??''),'role'=>'Cliente'];
        } catch (Throwable) {
            return ['id'=>$customerId,'name'=>(string)($_SESSION['grandprix_customer_name']??'Cliente GRANDPRIX'),'email'=>'','role'=>'Cliente'];
        }
    }

    public static function classifyAction(string $action): string
    {
        $a = strtolower($action);
        if (str_contains($a,'login')) return 'login';
        if (str_contains($a,'logout')) return 'logout';
        if (str_contains($a,'download') || str_contains($a,'export') || str_contains($a,'pdf')) return 'download';
        if (str_contains($a,'view') || str_contains($a,'open')) return 'view';
        if (str_contains($a,'create') || str_contains($a,'add') || str_contains($a,'record')) return 'create';
        if (str_contains($a,'update') || str_contains($a,'save') || str_contains($a,'edit') || str_contains($a,'assign')) return 'update';
        if (str_contains($a,'delete') || str_contains($a,'archive')) return 'delete';
        if (str_contains($a,'approve') || str_contains($a,'reconcile') || str_contains($a,'reject')) return 'workflow';
        return 'action';
    }

    private static function clientIp(): string
    {
        $candidates = [$_SERVER['HTTP_CF_CONNECTING_IP'] ?? '', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', $_SERVER['REMOTE_ADDR'] ?? ''];
        foreach ($candidates as $candidate) {
            $candidate = trim(explode(',', (string)$candidate)[0] ?? '');
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
        }
        return '';
    }

    private static function sanitizeMetadata(array $metadata): array
    {
        $safe=[];
        foreach($metadata as $key=>$value){
            $key=(string)$key;
            if(preg_match('/password|passwd|secret|token|authorization|cookie|csrf|hash/i',$key))continue;
            if(is_scalar($value)||$value===null)$safe[$key]=mb_substr((string)$value,0,500);
            elseif(is_array($value))$safe[$key]=self::sanitizeMetadata(array_slice($value,0,50,true));
        }
        return $safe;
    }
}
