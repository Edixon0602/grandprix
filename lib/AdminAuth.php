<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EventAudit.php';

final class AdminAuth
{
    public static function tablesReady(?PDO $pdo = null): bool
    {
        try {
            $pdo ??= Database::connection();
            foreach (['gp_admin_users','gp_admin_roles','gp_admin_permissions','gp_admin_role_permissions','gp_admin_user_permissions'] as $table) {
                $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            }
            return (int) $pdo->query('SELECT COUNT(*) FROM gp_admin_users')->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function attempt(string $email, string $password): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || $password === '' || !self::tablesReady()) return null;
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.email, u.password_hash, u.status, u.role_id,
                    r.name AS role_name
             FROM gp_admin_users u
             LEFT JOIN gp_admin_roles r ON r.id = u.role_id
             WHERE LOWER(u.email) = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || ($row['status'] ?? '') !== 'active' || !password_verify($password, (string) $row['password_hash'])) return null;

        $pdo->prepare('UPDATE gp_admin_users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
        $permissions = self::effectivePermissions((int) $row['id'], (int) ($row['role_id'] ?? 0), $pdo);
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['full_name'],
            'email' => (string) $row['email'],
            'role' => (string) ($row['role_name'] ?: 'Sin rol'),
            'permissions' => $permissions,
        ];
    }

    public static function effectivePermissions(int $userId, int $roleId = 0, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $base = [];
        if ($roleId > 0) {
            $stmt = $pdo->prepare(
                'SELECT p.permission_key FROM gp_admin_role_permissions rp
                 INNER JOIN gp_admin_permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ?'
            );
            $stmt->execute([$roleId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) $base[(string) $key] = true;
        }
        $stmt = $pdo->prepare(
            'SELECT p.permission_key, up.allowed FROM gp_admin_user_permissions up
             INNER JOIN gp_admin_permissions p ON p.id = up.permission_id
             WHERE up.user_id = ?'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $override) {
            $key = (string) $override['permission_key'];
            if ((int) $override['allowed'] === 1) $base[$key] = true;
            else unset($base[$key]);
        }
        return array_values(array_keys($base));
    }

    public static function hydrateSession(array $profile): void
    {
        $_SESSION['grandprix_admin'] = true;
        $_SESSION['grandprix_admin_user_id'] = (int) ($profile['id'] ?? 0);
        $_SESSION['grandprix_admin_name'] = (string) ($profile['name'] ?? 'Administrador');
        $_SESSION['grandprix_admin_email'] = (string) ($profile['email'] ?? '');
        $_SESSION['grandprix_admin_role'] = (string) ($profile['role'] ?? 'Administrador');
        $_SESSION['grandprix_admin_permissions'] = array_values(array_unique(array_map('strval', (array) ($profile['permissions'] ?? []))));
    }

    public static function overview(): array
    {
        $pdo = Database::connection();
        $permissions = $pdo->query(
            'SELECT id, permission_key, module_key, label, description FROM gp_admin_permissions ORDER BY sort_order, module_key, label'
        )->fetchAll();
        $roles = $pdo->query(
            "SELECT r.id, r.name, r.description, r.is_system,
                    GROUP_CONCAT(p.permission_key ORDER BY p.sort_order SEPARATOR ',') AS permission_keys
             FROM gp_admin_roles r
             LEFT JOIN gp_admin_role_permissions rp ON rp.role_id = r.id
             LEFT JOIN gp_admin_permissions p ON p.id = rp.permission_id
             GROUP BY r.id, r.name, r.description, r.is_system ORDER BY r.is_system DESC, r.name"
        )->fetchAll();
        foreach ($roles as &$role) {
            $role['id'] = (int) $role['id'];
            $role['is_system'] = (bool) $role['is_system'];
            $role['permissions'] = $role['permission_keys'] ? explode(',', (string) $role['permission_keys']) : [];
            unset($role['permission_keys']);
        }
        unset($role);

        $users = $pdo->query(
            "SELECT u.id, u.full_name, u.email, u.status, u.role_id, r.name AS role_name,
                    u.last_login_at, u.created_at,
                    SUM(CASE WHEN up.allowed = 1 THEN 1 ELSE 0 END) AS allowed_overrides,
                    SUM(CASE WHEN up.allowed = 0 THEN 1 ELSE 0 END) AS denied_overrides,
                    COUNT(up.id) AS override_count
             FROM gp_admin_users u
             LEFT JOIN gp_admin_roles r ON r.id = u.role_id
             LEFT JOIN gp_admin_user_permissions up ON up.user_id = u.id
             GROUP BY u.id, u.full_name, u.email, u.status, u.role_id, r.name, u.last_login_at, u.created_at ORDER BY u.status = 'active' DESC, u.full_name"
        )->fetchAll();
        foreach ($users as &$user) {
            $user['id'] = (int) $user['id'];
            $user['role_id'] = $user['role_id'] ? (int) $user['role_id'] : null;
            $user['override_count'] = (int) $user['override_count'];
            $user['custom_permissions'] = self::userOverrides((int) $user['id'], $pdo);
            $user['effective_permissions'] = self::effectivePermissions((int) $user['id'], (int) ($user['role_id'] ?? 0), $pdo);
        }
        unset($user);
        return ['permissions' => $permissions, 'roles' => $roles, 'users' => $users];
    }

    private static function userOverrides(int $userId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT p.permission_key, up.allowed FROM gp_admin_user_permissions up
             INNER JOIN gp_admin_permissions p ON p.id = up.permission_id WHERE up.user_id = ?'
        );
        $stmt->execute([$userId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) $result[(string) $row['permission_key']] = (bool) $row['allowed'];
        return $result;
    }

    public static function saveUser(array $input, array $actor): array
    {
        $pdo = Database::connection();
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $id = $id === false ? 0 : (int) $id;
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 160);
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $roleId = filter_var($input['roleId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = in_array((string) ($input['status'] ?? 'active'), ['active', 'suspended', 'blocked'], true) ? (string) $input['status'] : 'active';
        $customize = !empty($input['customizePermissions']);
        $preserveOverrides = !empty($input['_preservePermissions']);
        $selected = array_values(array_unique(array_map('strval', (array) ($input['permissions'] ?? []))));
        $actorPermissions = array_values(array_unique(array_map('strval', (array) ($actor['permissions'] ?? []))));
        $actorIsSuper = in_array('*', $actorPermissions, true) || (string) ($actor['role'] ?? '') === 'Superadministrador';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Nombre y correo valido son obligatorios.');
        if ($roleId === false) throw new InvalidArgumentException('Selecciona un rol valido.');
        if ($id === 0 && strlen($password) < 8) throw new InvalidArgumentException('La clave inicial debe tener al menos 8 caracteres.');
        if ($password !== '' && strlen($password) < 8) throw new InvalidArgumentException('La clave debe tener al menos 8 caracteres.');

        if ($id > 0 && $id === (int) ($actor['id'] ?? 0) && $status !== 'active') {
            throw new InvalidArgumentException('No puedes suspender tu propia cuenta mientras estas conectado.');
        }

        $roleStmt = $pdo->prepare(
            'SELECT r.id, r.name, p.permission_key FROM gp_admin_roles r '
            . 'LEFT JOIN gp_admin_role_permissions rp ON rp.role_id = r.id '
            . 'LEFT JOIN gp_admin_permissions p ON p.id = rp.permission_id WHERE r.id = ?'
        );
        $roleStmt->execute([(int) $roleId]);
        $roleRows = $roleStmt->fetchAll();
        if (!$roleRows) throw new InvalidArgumentException('Selecciona un rol valido.');
        $requestedRoleName = (string) ($roleRows[0]['name'] ?? '');
        $requestedRolePermissions = array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['permission_key'] ?? ''), $roleRows)));
        if (!$actorIsSuper) {
            foreach ($requestedRolePermissions as $permissionKey) {
                if (!in_array($permissionKey, $actorPermissions, true)) {
                    throw new InvalidArgumentException('No puedes asignar un rol con permisos superiores a los tuyos.');
                }
            }
            if ($requestedRoleName === 'Superadministrador') {
                throw new InvalidArgumentException('Solo un Superadministrador puede asignar ese rol.');
            }
            if ($customize) {
                foreach ($selected as $permissionKey) {
                    if (!in_array($permissionKey, $actorPermissions, true)) {
                        throw new InvalidArgumentException('No puedes conceder permisos que tu propia cuenta no posee.');
                    }
                }
            }
        }

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $beforeStmt = $pdo->prepare(
                    'SELECT u.id, u.full_name, u.email, u.status, u.role_id, r.name AS role_name '
                    . 'FROM gp_admin_users u LEFT JOIN gp_admin_roles r ON r.id = u.role_id WHERE u.id = ? FOR UPDATE'
                );
                $beforeStmt->execute([$id]);
                $before = $beforeStmt->fetch();
                if (!$before) throw new InvalidArgumentException('El usuario no existe.');
                if (!$actorIsSuper && (string) ($before['role_name'] ?? '') === 'Superadministrador') {
                    throw new InvalidArgumentException('Solo un Superadministrador puede modificar otra cuenta Superadministradora.');
                }
                if ((string) ($before['role_name'] ?? '') === 'Superadministrador' && ($requestedRoleName !== 'Superadministrador' || $status !== 'active')) {
                    $count = $pdo->prepare(
                        "SELECT COUNT(*) FROM gp_admin_users u INNER JOIN gp_admin_roles r ON r.id=u.role_id "
                        . "WHERE r.name='Superadministrador' AND u.status='active' AND u.id<>?"
                    );
                    $count->execute([$id]);
                    if ((int) $count->fetchColumn() < 1) {
                        throw new InvalidArgumentException('Debe quedar al menos una cuenta Superadministradora activa.');
                    }
                }
                $sql = 'UPDATE gp_admin_users SET full_name = ?, email = ?, role_id = ?, status = ?';
                $params = [$name, $email, (int) $roleId, $status];
                if ($password !== '') { $sql .= ', password_hash = ?'; $params[] = password_hash($password, PASSWORD_DEFAULT); }
                $sql .= ' WHERE id = ?'; $params[] = $id;
                $pdo->prepare($sql)->execute($params);
            } else {
                $pdo->prepare(
                    'INSERT INTO gp_admin_users (full_name, email, password_hash, role_id, status) VALUES (?, ?, ?, ?, ?)'
                )->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), (int) $roleId, $status]);
                $id = (int) $pdo->lastInsertId();
                $before = null;
            }

            if (!$preserveOverrides) {
                $pdo->prepare('DELETE FROM gp_admin_user_permissions WHERE user_id = ?')->execute([$id]);
                if ($customize) {
                    $permissionRows = $pdo->query('SELECT id, permission_key FROM gp_admin_permissions')->fetchAll();
                    $selectedMap = array_fill_keys($selected, true);
                    $insert = $pdo->prepare('INSERT INTO gp_admin_user_permissions (user_id, permission_id, allowed) VALUES (?, ?, ?)');
                    foreach ($permissionRows as $permission) {
                        $insert->execute([$id, (int) $permission['id'], isset($selectedMap[(string) $permission['permission_key']]) ? 1 : 0]);
                    }
                }
            }
            self::audit($pdo, $actor, 'users', $id > 0 && $before ? 'update_user' : 'create_user', 'gp_admin_users', $id,
                $before ?: [], ['full_name' => $name, 'email' => $email, 'role_id' => (int) $roleId, 'status' => $status, 'custom_permissions' => $customize]);
            $pdo->commit();
            return ['id' => $id];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && (string) $error->getCode() === '23000') throw new InvalidArgumentException('Ese correo ya pertenece a otro usuario.');
            throw $error;
        }
    }

    public static function saveRole(array $input, array $actor): array
    {
        $pdo = Database::connection();
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $id = $id === false ? 0 : (int) $id;
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 100);
        $description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 300);
        $selected = array_values(array_unique(array_map('strval', (array) ($input['permissions'] ?? []))));
        if ($name === '') throw new InvalidArgumentException('El nombre del rol es obligatorio.');
        $actorPermissions = array_values(array_unique(array_map('strval', (array) ($actor['permissions'] ?? []))));
        if (!in_array('*', $actorPermissions, true) && (string) ($actor['role'] ?? '') !== 'Superadministrador') {
            foreach ($selected as $permissionKey) {
                if (!in_array($permissionKey, $actorPermissions, true)) {
                    throw new InvalidArgumentException('No puedes crear o editar un rol con permisos superiores a los tuyos.');
                }
            }
        }
        $pdo->beginTransaction();
        try {
            $before = null;
            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT id, name, description, is_system FROM gp_admin_roles WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]); $before = $stmt->fetch();
                if (!$before) throw new InvalidArgumentException('El rol no existe.');
                if ((int) $before['is_system'] === 1 && (string) $before['name'] === 'Superadministrador') {
                    throw new InvalidArgumentException('El rol Superadministrador no puede reducirse.');
                }
                $pdo->prepare('UPDATE gp_admin_roles SET name = ?, description = ? WHERE id = ?')->execute([$name, $description ?: null, $id]);
            } else {
                $pdo->prepare('INSERT INTO gp_admin_roles (name, description, is_system) VALUES (?, ?, 0)')->execute([$name, $description ?: null]);
                $id = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM gp_admin_role_permissions WHERE role_id = ?')->execute([$id]);
            if ($selected) {
                $placeholders = implode(',', array_fill(0, count($selected), '?'));
                $stmt = $pdo->prepare("SELECT id FROM gp_admin_permissions WHERE permission_key IN ($placeholders)");
                $stmt->execute($selected);
                $insert = $pdo->prepare('INSERT IGNORE INTO gp_admin_role_permissions (role_id, permission_id) VALUES (?, ?)');
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $permissionId) $insert->execute([$id, (int) $permissionId]);
            }
            self::audit($pdo, $actor, 'users', $before ? 'update_role' : 'create_role', 'gp_admin_roles', $id,
                $before ?: [], ['name' => $name, 'description' => $description, 'permissions' => $selected]);
            $pdo->commit();
            return ['id' => $id];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && (string) $error->getCode() === '23000') throw new InvalidArgumentException('Ya existe un rol con ese nombre.');
            throw $error;
        }
    }

    public static function audit(PDO $pdo, array $actor, string $module, string $action, string $entityType, int $entityId, array $before, array $after): void
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ipHash = hash('sha256', $ip . '|grandprix-v8');
        $stmt = $pdo->prepare(
            'INSERT INTO gp_admin_audit (user_id, user_email, module_key, action_key, entity_type, entity_id, summary, before_json, after_json, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $summary = mb_substr(sprintf('%s · %s #%d', $action, $entityType, $entityId), 0, 300);
        $stmt->execute([
            (int) ($actor['id'] ?? 0) ?: null,
            mb_substr((string) ($actor['email'] ?? 'legacy-admin'), 0, 190),
            mb_substr($module, 0, 80), mb_substr($action, 0, 80), mb_substr($entityType, 0, 100), $entityId ?: null,
            $summary,
            json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $ipHash,
        ]);
        EventAudit::recordAdmin($actor,$module,$action,EventAudit::classifyAction($action),$entityType,$entityId,$summary,['before'=>$before,'after'=>$after],$pdo);
    }
}
