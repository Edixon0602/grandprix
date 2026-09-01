<?php
declare(strict_types=1);

final class PaymentReconcileV27
{
    private static ?PDO $pdo = null;
    private static array $schemaCache = [];
    private static bool $schemaReady = false;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        if (!class_exists('Database')) {
            $file = __DIR__ . '/Database.php';
            if (is_file($file)) require_once $file;
        }
        if (!class_exists('Database')) throw new RuntimeException('No fue posible cargar la conexión de base de datos de GRANDPRIX.');

        foreach (['pdo', 'connection', 'getConnection', 'db'] as $method) {
            if (method_exists('Database', $method)) {
                $pdo = call_user_func(['Database', $method]);
                if ($pdo instanceof PDO) {
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$pdo = $pdo;
                    return $pdo;
                }
            }
        }
        throw new RuntimeException('La clase Database no expone una conexión PDO compatible.');
    }

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) return;
        $pdo = self::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS gp_v26_payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_key VARCHAR(190) NOT NULL,
            customer_name VARCHAR(220) NOT NULL DEFAULT '',
            document VARCHAR(80) NOT NULL DEFAULT '',
            contract_key VARCHAR(190) NOT NULL DEFAULT '',
            amount_original DECIMAL(16,2) NOT NULL,
            currency ENUM('USD','BS') NOT NULL DEFAULT 'USD',
            exchange_rate DECIMAL(18,6) NULL,
            amount_usd DECIMAL(16,2) NOT NULL,
            applied_usd DECIMAL(16,2) NOT NULL DEFAULT 0,
            unapplied_usd DECIMAL(16,2) NOT NULL DEFAULT 0,
            payment_date DATE NOT NULL,
            method VARCHAR(80) NOT NULL DEFAULT '',
            bank VARCHAR(160) NOT NULL DEFAULT '',
            reference VARCHAR(160) NOT NULL DEFAULT '',
            notes TEXT NULL,
            status ENUM('reconciled','rejected') NOT NULL DEFAULT 'reconciled',
            created_by BIGINT NULL,
            created_by_name VARCHAR(220) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gp_v26_customer (customer_key),
            KEY idx_gp_v26_reference (reference),
            KEY idx_gp_v26_date (payment_date),
            KEY idx_gp_v26_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS gp_v26_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT UNSIGNED NOT NULL,
            customer_key VARCHAR(190) NOT NULL,
            external_week_key VARCHAR(220) NOT NULL,
            external_table VARCHAR(128) NOT NULL DEFAULT '',
            external_id VARCHAR(100) NOT NULL DEFAULT '',
            week_number INT UNSIGNED NOT NULL,
            due_date DATE NULL,
            week_amount_usd DECIMAL(16,2) NOT NULL,
            balance_before_usd DECIMAL(16,2) NOT NULL,
            allocated_usd DECIMAL(16,2) NOT NULL,
            balance_after_usd DECIMAL(16,2) NOT NULL,
            completed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gp_v26_alloc_payment (payment_id),
            KEY idx_gp_v26_alloc_customer (customer_key),
            KEY idx_gp_v26_alloc_week (external_week_key),
            CONSTRAINT fk_gp_v26_alloc_payment FOREIGN KEY (payment_id) REFERENCES gp_v26_payments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS gp_v27_customer_weekly_plan (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_key VARCHAR(190) NOT NULL,
            customer_name VARCHAR(220) NOT NULL DEFAULT '',
            document VARCHAR(80) NOT NULL DEFAULT '',
            weekly_amount_usd DECIMAL(16,2) NOT NULL,
            source_synced TINYINT(1) NOT NULL DEFAULT 0,
            updated_by BIGINT NULL,
            updated_by_name VARCHAR(220) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gp_v27_weekly_customer (customer_key),
            KEY idx_gp_v27_weekly_document (document)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$schemaReady = true;
    }

    public static function searchCustomers(string $query, int $limit = 20): array
    {
        self::ensureSchema();
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];
        $sources = self::discoverCustomerSources();
        $found = [];
        $seen = [];

        foreach (array_slice($sources, 0, 6) as $source) {
            $map = $source['map'];
            $fields = array_values(array_filter([$map['name'] ?? null, $map['document'] ?? null, $map['contract'] ?? null, $map['plate'] ?? null]));
            if (!$fields) continue;
            $where = [];
            $params = [];
            foreach ($fields as $i => $field) {
                $where[] = self::qi($field) . " LIKE :q{$i}";
                $params[":q{$i}"] = '%' . $query . '%';
            }
            $sql = 'SELECT * FROM ' . self::qi($source['table']) . ' WHERE (' . implode(' OR ', $where) . ') LIMIT ' . max(1, min(30, $limit));
            try {
                $st = self::connection()->prepare($sql);
                $st->execute($params);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $id = (string)($row[$map['id']] ?? '');
                    if ($id === '') continue;
                    $key = $source['table'] . ':' . $id;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $customer = self::customerSummary($source, $row);
                    $found[] = $customer;
                    if (count($found) >= $limit) break 2;
                }
            } catch (Throwable) {
                continue;
            }
        }
        return $found;
    }

    public static function getWeeklyPlan(string $customerKey): array
    {
        self::ensureSchema();
        [$table, $id] = self::parseKey($customerKey);
        $source = self::customerSourceForTable($table);
        if (!$source) throw new RuntimeException('No se encontró el cliente en la cartera financiera.');
        $row = self::fetchById($table, (string)$source['map']['id'], $id);
        if (!$row) throw new RuntimeException('Cliente no encontrado.');
        $customer = self::customerSummary($source, $row);
        $contract = self::resolveContractContext($source, $row);
        $sourceWeekly = self::numericValue($contract['row'], $contract['map']['weekly'] ?? null);
        if ($sourceWeekly <= 0) $sourceWeekly = self::numericValue($row, $source['map']['weekly'] ?? null);
        $record = self::weeklyPlanRecord($customerKey);
        $effective = $record ? (float)$record['weekly_amount_usd'] : $sourceWeekly;
        return [
            'customer' => $customer,
            'weekly_amount_usd' => round($effective, 2),
            'source_weekly_amount_usd' => round($sourceWeekly, 2),
            'explicit_plan' => (bool)$record,
            'source_synced' => $record ? (bool)$record['source_synced'] : false,
            'configured' => $effective > 0,
        ];
    }

    public static function saveWeeklyPlan(string $customerKey, mixed $amountInput, array $admin = []): array
    {
        self::ensureSchema();
        $amount = self::parseAmount($amountInput);
        if ($amount <= 0) throw new RuntimeException('La cuota semanal debe ser mayor que cero.');
        if ($amount > 10000) throw new RuntimeException('La cuota semanal indicada es demasiado alta.');

        [$table, $id] = self::parseKey($customerKey);
        $source = self::customerSourceForTable($table);
        if (!$source) throw new RuntimeException('No se encontró el cliente en la cartera financiera.');
        $row = self::fetchById($table, (string)$source['map']['id'], $id);
        if (!$row) throw new RuntimeException('Cliente no encontrado.');
        $customer = self::customerSummary($source, $row);
        $contract = self::resolveContractContext($source, $row);

        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $sourceSynced = false;
            $targetTable = (string)($contract['table'] ?? '');
            $targetMap = (array)($contract['map'] ?? []);
            $targetId = (string)($contract['id'] ?? '');
            $weeklyCol = $targetMap['weekly'] ?? null;
            $idCol = $targetMap['id'] ?? null;

            if ($weeklyCol && $idCol && $targetTable !== '' && $targetId !== '') {
                $st = $pdo->prepare('UPDATE '.self::qi($targetTable).' SET '.self::qi((string)$weeklyCol).'=:amount WHERE '.self::qi((string)$idCol).'=:id LIMIT 1');
                $st->execute([':amount'=>$amount, ':id'=>$targetId]);
                $sourceSynced = true;
            } elseif (!empty($source['map']['weekly'])) {
                $st = $pdo->prepare('UPDATE '.self::qi($table).' SET '.self::qi((string)$source['map']['weekly']).'=:amount WHERE '.self::qi((string)$source['map']['id']).'=:id LIMIT 1');
                $st->execute([':amount'=>$amount, ':id'=>$id]);
                $sourceSynced = true;
            }

            $st = $pdo->prepare("INSERT INTO gp_v27_customer_weekly_plan
                (customer_key,customer_name,document,weekly_amount_usd,source_synced,updated_by,updated_by_name)
                VALUES (:customer_key,:customer_name,:document,:weekly_amount_usd,:source_synced,:updated_by,:updated_by_name)
                ON DUPLICATE KEY UPDATE customer_name=VALUES(customer_name),document=VALUES(document),weekly_amount_usd=VALUES(weekly_amount_usd),source_synced=VALUES(source_synced),updated_by=VALUES(updated_by),updated_by_name=VALUES(updated_by_name),updated_at=CURRENT_TIMESTAMP");
            $st->execute([
                ':customer_key'=>$customerKey,
                ':customer_name'=>(string)($customer['name'] ?? ''),
                ':document'=>(string)($customer['document'] ?? ''),
                ':weekly_amount_usd'=>$amount,
                ':source_synced'=>$sourceSynced ? 1 : 0,
                ':updated_by'=>isset($admin['id']) ? (int)$admin['id'] : null,
                ':updated_by_name'=>mb_substr((string)($admin['name'] ?? 'Administrador GRANDPRIX'),0,220),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        self::$schemaCache = [];
        self::syncPendingWeekAmounts($customerKey, $amount);
        self::auditWeeklyPlan($admin, $customerKey, $customer, $amount);
        return self::getWeeklyPlan($customerKey);
    }

    public static function getAccount(string $customerKey): array
    {
        self::ensureSchema();
        [$table, $id] = self::parseKey($customerKey);
        $source = self::customerSourceForTable($table);
        if (!$source) throw new RuntimeException('No se encontró la fuente financiera del cliente.');
        $map = $source['map'];
        $sql = 'SELECT * FROM ' . self::qi($table) . ' WHERE ' . self::qi($map['id']) . ' = :id LIMIT 1';
        $st = self::connection()->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Cliente no encontrado.');

        $customer = self::customerSummary($source, $row);
        $contract = self::resolveContractContext($source, $row);
        $sourceWeekly = self::numericValue($contract['row'], $contract['map']['weekly'] ?? null);
        if ($sourceWeekly <= 0) $sourceWeekly = self::numericValue($row, $map['weekly'] ?? null);
        $weeklyPlan = self::weeklyPlanRecord($customerKey);
        $weekly = $weeklyPlan ? (float)$weeklyPlan['weekly_amount_usd'] : $sourceWeekly;
        $start = self::dateValue($contract['row'], $contract['map']['start'] ?? null);
        if ($start === null) $start = self::dateValue($row, $map['start'] ?? null);
        $paidWeeks = self::intValue($contract['row'], $contract['map']['paid_weeks'] ?? null);
        if ($paidWeeks <= 0) $paidWeeks = self::intValue($row, $map['paid_weeks'] ?? null);
        $contractLabel = self::stringValue($contract['row'], $contract['map']['contract'] ?? null);
        if ($contractLabel === '') $contractLabel = (string)($customer['contract'] ?? '');

        $weeksContext = self::resolveWeeks($customerKey, $source, $row, $contract, $weekly, $start, $paidWeeks);
        if ($weeklyPlan && $weekly > 0) {
            foreach ($weeksContext['weeks'] as &$planWeek) {
                if (empty($planWeek['external_paid'])) $planWeek['amount_usd'] = round($weekly, 2);
            }
            unset($planWeek);
        }
        $weeks = self::attachV27Balances($customerKey, $weeksContext['weeks']);

        $summary = [
            'weekly_amount_usd' => round($weekly, 2),
            'overdue_balance_usd' => 0.0,
            'pending_balance_usd' => 0.0,
            'partial_paid_usd' => 0.0,
            'paid_weeks' => 0,
            'partial_weeks' => 0,
            'overdue_weeks' => 0,
        ];
        foreach ($weeks as $week) {
            if ($week['status'] === 'paid') $summary['paid_weeks']++;
            if ($week['status'] === 'partial') $summary['partial_weeks']++;
            if ($week['status'] === 'overdue' || ($week['status'] === 'partial' && !empty($week['overdue']))) $summary['overdue_weeks']++;
            if (!empty($week['overdue']) && $week['status'] !== 'paid') $summary['overdue_balance_usd'] += (float)$week['balance_usd'];
            if ($week['status'] !== 'paid') $summary['pending_balance_usd'] += (float)$week['balance_usd'];
            $summary['partial_paid_usd'] += (float)$week['v27_paid_usd'];
        }
        foreach ($summary as $k => $v) if (is_float($v)) $summary[$k] = round($v, 2);

        return [
            'customer' => $customer,
            'contract' => [
                'number' => $contractLabel,
                'start_date' => $start,
                'weekly_amount_usd' => round($weekly, 2),
                'weekly_source' => $weeklyPlan ? 'client_plan' : 'existing_contract',
                'weekly_configured' => $weekly > 0,
                'source_weekly_amount_usd' => round($sourceWeekly, 2),
                'source' => $contract['table'] ?? $table,
            ],
            'summary' => $summary,
            'weeks' => $weeks,
            'adapter' => [
                'customer_table' => $table,
                'contract_table' => $contract['table'] ?? null,
                'weeks_table' => $weeksContext['table'] ?? null,
                'synthetic_weeks' => (bool)($weeksContext['synthetic'] ?? false),
            ],
        ];
    }

    public static function preview(array $input): array
    {
        $account = self::getAccount((string)($input['customer_key'] ?? ''));
        $money = self::moneyInput($input);
        $remaining = $money['amount_usd'];
        $allocations = [];

        foreach ($account['weeks'] as $week) {
            if ($remaining <= 0.00001) break;
            if ($week['status'] === 'paid') continue;
            $balance = (float)$week['balance_usd'];
            if ($balance <= 0.00001) continue;
            $allocated = min($balance, $remaining);
            $after = max(0, $balance - $allocated);
            $weekAmount = max(0.01, (float)$week['amount_usd']);
            $paidBefore = max(0.0, $weekAmount - $balance);
            $paidAfter = max(0.0, $weekAmount - $after);
            $allocations[] = [
                'external_week_key' => $week['external_week_key'],
                'week_number' => (int)$week['week_number'],
                'due_date' => $week['due_date'],
                'week_amount_usd' => round($weekAmount, 2),
                'balance_before_usd' => round($balance, 2),
                'allocated_usd' => round($allocated, 2),
                'balance_after_usd' => round($after, 2),
                'paid_before_usd' => round($paidBefore, 2),
                'paid_after_usd' => round($paidAfter, 2),
                'payment_percentage' => round(min(100, ($allocated / $weekAmount) * 100), 2),
                'paid_percentage_after' => round(min(100, ($paidAfter / $weekAmount) * 100), 2),
                'completed' => $after <= 0.00001,
                'full_week_from_this_payment' => $balance >= ($weekAmount - 0.00001) && $allocated >= ($weekAmount - 0.00001),
                'overdue' => (bool)$week['overdue'],
            ];
            $remaining -= $allocated;
        }

        $applied = round($money['amount_usd'] - max(0, $remaining), 2);
        $weeklyAmount = (float)($account['contract']['weekly_amount_usd'] ?? 0);
        $fullWeeks = 0;
        $completedWeeks = 0;
        $partial = null;
        foreach ($allocations as $a) {
            if (!empty($a['full_week_from_this_payment'])) $fullWeeks++;
            if (!empty($a['completed'])) $completedWeeks++;
            if (empty($a['completed']) && (float)$a['allocated_usd'] > 0) $partial = $a;
        }
        return [
            'money' => $money,
            'allocations' => $allocations,
            'applied_usd' => $applied,
            'unapplied_usd' => round(max(0, $remaining), 2),
            'weekly_amount_usd' => round($weeklyAmount, 2),
            'equivalent_weeks' => $weeklyAmount > 0 ? round($applied / $weeklyAmount, 4) : 0,
            'full_weeks_from_payment' => $fullWeeks,
            'completed_weeks' => $completedWeeks,
            'partial_week' => $partial,
            'account_summary' => $account['summary'],
        ];
    }

    public static function reconcile(array $input, array $admin = []): array
    {
        self::ensureSchema();
        $customerKey = (string)($input['customer_key'] ?? '');
        $account = self::getAccount($customerKey);
        $preview = self::preview($input);
        if (!$preview['allocations'] && $preview['applied_usd'] <= 0) throw new RuntimeException('No hay cuotas pendientes a las que aplicar este pago.');

        $paymentDate = self::normalizeDate((string)($input['payment_date'] ?? '')) ?: date('Y-m-d');
        $method = mb_substr(trim((string)($input['method'] ?? '')), 0, 80);
        $bank = mb_substr(trim((string)($input['bank'] ?? '')), 0, 160);
        $reference = mb_substr(trim((string)($input['reference'] ?? '')), 0, 160);
        $notes = trim((string)($input['notes'] ?? ''));
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $money = $preview['money'];
            $customer = $account['customer'];
            $contract = $account['contract'];
            $st = $pdo->prepare("INSERT INTO gp_v26_payments
                (customer_key,customer_name,document,contract_key,amount_original,currency,exchange_rate,amount_usd,applied_usd,unapplied_usd,payment_date,method,bank,reference,notes,status,created_by,created_by_name)
                VALUES (:customer_key,:customer_name,:document,:contract_key,:amount_original,:currency,:exchange_rate,:amount_usd,:applied_usd,:unapplied_usd,:payment_date,:method,:bank,:reference,:notes,'reconciled',:created_by,:created_by_name)");
            $st->execute([
                ':customer_key' => $customerKey,
                ':customer_name' => (string)($customer['name'] ?? ''),
                ':document' => (string)($customer['document'] ?? ''),
                ':contract_key' => (string)($contract['number'] ?? ''),
                ':amount_original' => $money['amount_original'],
                ':currency' => $money['currency'],
                ':exchange_rate' => $money['exchange_rate'],
                ':amount_usd' => $money['amount_usd'],
                ':applied_usd' => $preview['applied_usd'],
                ':unapplied_usd' => $preview['unapplied_usd'],
                ':payment_date' => $paymentDate,
                ':method' => $method,
                ':bank' => $bank,
                ':reference' => $reference,
                ':notes' => $notes,
                ':created_by' => isset($admin['id']) ? (int)$admin['id'] : null,
                ':created_by_name' => mb_substr((string)($admin['name'] ?? 'Administrador GRANDPRIX'), 0, 220),
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            $weekByKey = [];
            foreach ($account['weeks'] as $w) $weekByKey[$w['external_week_key']] = $w;
            $ins = $pdo->prepare("INSERT INTO gp_v26_allocations
                (payment_id,customer_key,external_week_key,external_table,external_id,week_number,due_date,week_amount_usd,balance_before_usd,allocated_usd,balance_after_usd,completed)
                VALUES (:payment_id,:customer_key,:external_week_key,:external_table,:external_id,:week_number,:due_date,:week_amount_usd,:balance_before_usd,:allocated_usd,:balance_after_usd,:completed)");

            foreach ($preview['allocations'] as $allocation) {
                $week = $weekByKey[$allocation['external_week_key']] ?? [];
                $ins->execute([
                    ':payment_id' => $paymentId,
                    ':customer_key' => $customerKey,
                    ':external_week_key' => $allocation['external_week_key'],
                    ':external_table' => (string)($week['external_table'] ?? ''),
                    ':external_id' => (string)($week['external_id'] ?? ''),
                    ':week_number' => (int)$allocation['week_number'],
                    ':due_date' => $allocation['due_date'] ?: null,
                    ':week_amount_usd' => $allocation['week_amount_usd'],
                    ':balance_before_usd' => $allocation['balance_before_usd'],
                    ':allocated_usd' => $allocation['allocated_usd'],
                    ':balance_after_usd' => $allocation['balance_after_usd'],
                    ':completed' => !empty($allocation['completed']) ? 1 : 0,
                ]);
                if (!empty($allocation['completed'])) self::markExternalWeekPaid($week, $paymentDate, $reference);
            }
            self::syncSyntheticPaidWeeks($customerKey, $account);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        self::audit($admin, $paymentId, $account, $preview, $input);
        $fresh = self::getAccount($customerKey);
        return [
            'payment_id' => $paymentId,
            'receipt_number' => 'GP-' . str_pad((string)$paymentId, 8, '0', STR_PAD_LEFT),
            'money' => $preview['money'],
            'allocations' => $preview['allocations'],
            'applied_usd' => $preview['applied_usd'],
            'unapplied_usd' => $preview['unapplied_usd'],
            'account' => $fresh,
        ];
    }

    public static function history(string $customerKey, int $limit = 30): array
    {
        self::ensureSchema();
        $st = self::connection()->prepare("SELECT * FROM gp_v26_payments WHERE customer_key=:customer_key ORDER BY payment_date DESC,id DESC LIMIT " . max(1, min(100, $limit)));
        $st->execute([':customer_key' => $customerKey]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $a = self::connection()->prepare("SELECT week_number,due_date,allocated_usd,balance_after_usd,completed FROM gp_v26_allocations WHERE payment_id=:id ORDER BY week_number");
            $a->execute([':id' => $row['id']]);
            $row['allocations'] = $a->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        unset($row);
        return $rows;
    }

    private static function weeklyPlanRecord(string $customerKey): ?array
    {
        if ($customerKey === '') return null;
        self::ensureSchema();
        try {
            $st = self::connection()->prepare('SELECT * FROM gp_v27_customer_weekly_plan WHERE customer_key=:customer_key LIMIT 1');
            $st->execute([':customer_key'=>$customerKey]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function syncPendingWeekAmounts(string $customerKey, float $amount): void
    {
        try {
            $account = self::getAccount($customerKey);
            foreach ($account['weeks'] as $week) {
                if (($week['status'] ?? '') === 'paid') continue;
                $table = (string)($week['external_table'] ?? '');
                $id = (string)($week['external_id'] ?? '');
                $idCol = (string)($week['id_column'] ?? '');
                if ($table === '' || $id === '' || $idCol === '' || !empty($week['synthetic'])) continue;
                $map = self::mapWeekColumns(self::columns($table));
                $amountCol = $map['amount'] ?? null;
                if (!$amountCol) continue;
                try {
                    $st = self::connection()->prepare('UPDATE '.self::qi($table).' SET '.self::qi((string)$amountCol).'=:amount WHERE '.self::qi($idCol).'=:id LIMIT 1');
                    $st->execute([':amount'=>$amount, ':id'=>$id]);
                } catch (Throwable) {}
            }
        } catch (Throwable) {}
    }

    private static function auditWeeklyPlan(array $admin, string $customerKey, array $customer, float $amount): void
    {
        $file=__DIR__.'/EventAudit.php'; if(!class_exists('EventAudit')&&is_file($file)) require_once $file;
        if(!class_exists('EventAudit')||!method_exists('EventAudit','recordAdmin')) return;
        $summary='Cuota semanal configurada en USD '.number_format($amount,2,',','.').' para '.(string)($customer['name']??'cliente').'.';
        try { EventAudit::recordAdmin($admin,'finance','weekly_plan_updated','update','gp_v27_customer_weekly_plan',$customerKey,$summary,[
            'customer_key'=>$customerKey,
            'customer_name'=>$customer['name']??'',
            'document'=>$customer['document']??'',
            'weekly_amount_usd'=>$amount,
        ]); } catch (Throwable) {}
    }

    private static function moneyInput(array $input): array
    {
        $amount = self::parseAmount($input['amount'] ?? 0);
        if ($amount <= 0) throw new RuntimeException('El monto debe ser mayor que cero.');
        $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
        if (!in_array($currency, ['USD', 'BS'], true)) throw new RuntimeException('Moneda inválida. Usa USD o BS.');
        $rate = null;
        if ($currency === 'BS') {
            $rate = self::parseAmount($input['exchange_rate'] ?? 0, 6);
            if ($rate <= 0) throw new RuntimeException('Para pagos en Bs. debes indicar la tasa Bs./USD usada en la conciliación.');
            $usd = $amount / $rate;
        } else {
            $usd = $amount;
        }
        return [
            'amount_original' => round($amount, 2),
            'currency' => $currency,
            'exchange_rate' => $rate !== null ? round($rate, 6) : null,
            'amount_usd' => round($usd, 2),
        ];
    }

    private static function parseAmount(mixed $value, int $scale = 2): float
    {
        if (is_int($value) || is_float($value)) return round((float)$value, $scale);
        $v = trim((string)$value);
        $v = str_replace([' ', '\u{00A0}'], '', $v);
        if (str_contains($v, ',') && str_contains($v, '.')) {
            if (strrpos($v, ',') > strrpos($v, '.')) $v = str_replace(['.', ','], ['', '.'], $v);
            else $v = str_replace(',', '', $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }
        return round((float)$v, $scale);
    }

    private static function discoverCustomerSources(): array
    {
        $sources = [];
        foreach (self::tables() as $table) {
            if (str_starts_with($table, 'gp_v26_') || str_starts_with($table, 'gp_v27_')) continue;
            $cols = self::columns($table);
            $map = self::mapCustomerColumns($cols);
            if (empty($map['id']) || empty($map['name'])) continue;
            $score = 0;
            $tn = self::norm($table);
            if (preg_match('/client|cliente|customer/', $tn)) $score += 12;
            if (preg_match('/finance|finanz|credit|credito|cartera|account|cuenta/', $tn)) $score += 7;
            if (preg_match('/application|solicitud|user|usuario|audit|log|event|payment|pago/', $tn)) $score -= 8;
            foreach (['document'=>4,'contract'=>5,'weekly'=>7,'start'=>5,'paid_weeks'=>3,'plate'=>2] as $k=>$points) if (!empty($map[$k])) $score += $points;
            if ($score >= 8) $sources[] = ['table'=>$table,'map'=>$map,'score'=>$score];
        }
        usort($sources, fn($a,$b)=>$b['score']<=>$a['score']);
        return $sources;
    }

    private static function customerSourceForTable(string $table): ?array
    {
        foreach (self::discoverCustomerSources() as $source) if ($source['table'] === $table) return $source;
        $cols = self::columns($table);
        $map = self::mapCustomerColumns($cols);
        return (!empty($map['id']) && !empty($map['name'])) ? ['table'=>$table,'map'=>$map,'score'=>0] : null;
    }

    private static function customerSummary(array $source, array $row): array
    {
        $map = $source['map'];
        $id = (string)($row[$map['id']] ?? '');
        $key = $source['table'] . ':' . $id;
        $sourceWeekly = self::numericValue($row, $map['weekly'] ?? null);
        $plan = self::weeklyPlanRecord($key);
        return [
            'key' => $key,
            'id' => $id,
            'name' => self::stringValue($row, $map['name'] ?? null),
            'document' => self::stringValue($row, $map['document'] ?? null),
            'contract' => self::stringValue($row, $map['contract'] ?? null),
            'plate' => self::stringValue($row, $map['plate'] ?? null),
            'weekly_amount_usd' => $plan ? round((float)$plan['weekly_amount_usd'],2) : $sourceWeekly,
            'weekly_plan_explicit' => (bool)$plan,
            'start_date' => self::dateValue($row, $map['start'] ?? null),
            'source_table' => $source['table'],
        ];
    }

    private static function resolveContractContext(array $source, array $customerRow): array
    {
        $customerMap = $source['map'];
        if (!empty($customerMap['weekly']) && !empty($customerMap['start'])) {
            return ['table'=>$source['table'],'row'=>$customerRow,'map'=>$customerMap,'id'=>(string)($customerRow[$customerMap['id']]??'')];
        }
        $customerId = (string)($customerRow[$customerMap['id']] ?? '');
        $best = null;
        foreach (self::tables() as $table) {
            if ($table === $source['table'] || str_starts_with($table, 'gp_v26_') || str_starts_with($table, 'gp_v27_')) continue;
            $cols = self::columns($table);
            $map = self::mapContractColumns($cols);
            if (empty($map['id']) || (empty($map['weekly']) && empty($map['start']) && empty($map['contract']))) continue;
            $links = self::possibleLinkColumns($cols, $source['table'], 'customer');
            foreach ($links as $link) {
                try {
                    $st = self::connection()->prepare('SELECT * FROM '.self::qi($table).' WHERE '.self::qi($link).'=:id ORDER BY '.self::qi($map['id']).' DESC LIMIT 1');
                    $st->execute([':id'=>$customerId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$row) continue;
                    $score = 0;
                    $tn = self::norm($table);
                    if (preg_match('/contract|contrato|credit|credito|finance|finanz|account|cuenta|plan/', $tn)) $score += 8;
                    if (!empty($map['weekly'])) $score += 6;
                    if (!empty($map['start'])) $score += 5;
                    if (!empty($map['contract'])) $score += 4;
                    if (!$best || $score > $best['score']) $best = ['table'=>$table,'row'=>$row,'map'=>$map,'id'=>(string)($row[$map['id']]??''),'score'=>$score];
                } catch (Throwable) {}
            }
        }
        return $best ?: ['table'=>$source['table'],'row'=>$customerRow,'map'=>$customerMap,'id'=>$customerId];
    }

    private static function resolveWeeks(string $customerKey, array $customerSource, array $customerRow, array $contract, float $weekly, ?string $start, int $paidWeeks): array
    {
        $customerId = (string)($customerRow[$customerSource['map']['id']] ?? '');
        $contractId = (string)($contract['id'] ?? '');
        $best = null;
        foreach (self::tables() as $table) {
            if (str_starts_with($table, 'gp_v26_') || str_starts_with($table, 'gp_v27_')) continue;
            $cols = self::columns($table);
            $map = self::mapWeekColumns($cols);
            if (empty($map['id']) || empty($map['week'])) continue;
            $scoreBase = 0;
            $tn = self::norm($table);
            if (preg_match('/week|semana|installment|cuota|schedule|cronograma|plan/', $tn)) $scoreBase += 10;
            if (!empty($map['due'])) $scoreBase += 5;
            if (!empty($map['amount'])) $scoreBase += 4;
            if (!empty($map['status'])) $scoreBase += 4;
            if ($scoreBase < 10) continue;

            $linkOptions = [];
            foreach (self::possibleLinkColumns($cols, $customerSource['table'], 'customer') as $link) $linkOptions[] = [$link,$customerId,'customer'];
            if (($contract['table'] ?? '') !== $customerSource['table']) {
                foreach (self::possibleLinkColumns($cols, (string)$contract['table'], 'contract') as $link) $linkOptions[] = [$link,$contractId,'contract'];
            }
            foreach ($linkOptions as [$link,$linkId,$kind]) {
                if ($linkId === '') continue;
                try {
                    $order = !empty($map['week']) ? self::qi($map['week']) : self::qi($map['id']);
                    $st = self::connection()->prepare('SELECT * FROM '.self::qi($table).' WHERE '.self::qi($link).'=:id ORDER BY '.$order.' ASC LIMIT 100');
                    $st->execute([':id'=>$linkId]);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    if (!$rows) continue;
                    $score = $scoreBase + ($kind === 'contract' ? 4 : 2) + min(4,count($rows));
                    if (!$best || $score > $best['score']) $best = compact('table','map','rows','score');
                } catch (Throwable) {}
            }
        }
        if ($best) {
            $weeks = [];
            foreach ($best['rows'] as $row) {
                $weekNo = self::intValue($row, $best['map']['week']);
                if ($weekNo <= 0) continue;
                $id = (string)($row[$best['map']['id']] ?? $weekNo);
                $amount = self::numericValue($row, $best['map']['amount'] ?? null);
                if ($amount <= 0) $amount = $weekly;
                $statusRaw = self::stringValue($row, $best['map']['status'] ?? null);
                $due = self::dateValue($row, $best['map']['due'] ?? null);
                $paid = self::isPaidStatus($statusRaw);
                $weeks[] = [
                    'external_week_key' => $best['table'].':'.$id,
                    'external_table' => $best['table'],
                    'external_id' => $id,
                    'week_number' => $weekNo,
                    'due_date' => $due,
                    'amount_usd' => round($amount,2),
                    'external_paid' => $paid,
                    'status_raw' => $statusRaw,
                    'status_column' => $best['map']['status'] ?? null,
                    'paid_at_column' => $best['map']['paid_at'] ?? null,
                    'reference_column' => $best['map']['reference'] ?? null,
                    'id_column' => $best['map']['id'],
                    'synthetic' => false,
                ];
            }
            if ($weeks) return ['table'=>$best['table'],'synthetic'=>false,'weeks'=>$weeks];
        }

        if ($weekly <= 0) throw new RuntimeException('No fue posible detectar la cuota semanal del contrato. Revisa que el cliente tenga monto semanal configurado.');
        if ($start === null) throw new RuntimeException('No fue posible detectar la fecha de inicio del plan. Revisa el contrato del cliente.');
        $first = self::firstWednesday($start);
        $weeks = [];
        for ($i=1;$i<=50;$i++) {
            $due = (clone $first)->modify('+'.(($i-1)*7).' days')->format('Y-m-d');
            $weeks[] = [
                'external_week_key' => 'SYN:'.$customerKey.':'.$i,
                'external_table' => '',
                'external_id' => '',
                'week_number' => $i,
                'due_date' => $due,
                'amount_usd' => round($weekly,2),
                'external_paid' => $i <= $paidWeeks,
                'status_raw' => $i <= $paidWeeks ? 'paid' : 'pending',
                'status_column' => null,
                'paid_at_column' => null,
                'reference_column' => null,
                'id_column' => null,
                'synthetic' => true,
            ];
        }
        return ['table'=>null,'synthetic'=>true,'weeks'=>$weeks];
    }

    private static function attachV27Balances(string $customerKey, array $weeks): array
    {
        $st = self::connection()->prepare("SELECT external_week_key,SUM(allocated_usd) allocated FROM gp_v26_allocations WHERE customer_key=:customer_key GROUP BY external_week_key");
        $st->execute([':customer_key'=>$customerKey]);
        $allocated = [];
        while ($r=$st->fetch(PDO::FETCH_ASSOC)) $allocated[(string)$r['external_week_key']] = (float)$r['allocated'];
        $today = date('Y-m-d');
        foreach ($weeks as &$week) {
            $v27 = round((float)($allocated[$week['external_week_key']] ?? 0),2);
            $amount = round((float)$week['amount_usd'],2);
            $externalPaid = (bool)$week['external_paid'];
            $balance = $externalPaid ? 0.0 : max(0,$amount-$v27);
            $paid = $externalPaid || $balance <= 0.00001;
            $overdue = !$paid && !empty($week['due_date']) && (string)$week['due_date'] < $today;
            if ($paid) $status='paid';
            elseif ($v27 > 0.00001) $status='partial';
            elseif ($overdue) $status='overdue';
            else $status='pending';
            $week['v27_paid_usd'] = $v27;
            $week['balance_usd'] = round($balance,2);
            $week['paid_percentage'] = $paid ? 100.0 : round(min(100, $amount > 0 ? (($amount - $balance) / $amount) * 100 : 0), 2);
            $week['status'] = $status;
            $week['overdue'] = $overdue;
        }
        unset($week);
        usort($weeks, fn($a,$b)=>((string)$a['due_date'] <=> (string)$b['due_date']) ?: ($a['week_number'] <=> $b['week_number']));
        return $weeks;
    }

    private static function markExternalWeekPaid(array $week, string $paymentDate, string $reference): void
    {
        if (!empty($week['synthetic']) || empty($week['external_table']) || empty($week['external_id']) || empty($week['id_column'])) return;
        $table = (string)$week['external_table'];
        $cols = self::columns($table);
        $sets = [];
        $params = [':id'=>$week['external_id']];
        if (!empty($week['status_column'])) {
            $paidLiteral = self::paidLiteral($table, (string)$week['status_column']);
            if ($paidLiteral !== null) { $sets[] = self::qi((string)$week['status_column']).'=:paid_status'; $params[':paid_status']=$paidLiteral; }
        }
        if (!empty($week['paid_at_column'])) { $sets[] = self::qi((string)$week['paid_at_column']).'=:paid_at'; $params[':paid_at']=$paymentDate.' 12:00:00'; }
        if (!empty($week['reference_column']) && $reference !== '') { $sets[] = self::qi((string)$week['reference_column']).'=:reference'; $params[':reference']=$reference; }
        $amountPaid = self::pick($cols, ['paid_amount','amount_paid','monto_pagado','paid','abono']);
        if ($amountPaid) { $sets[] = self::qi($amountPaid).'=:paid_amount'; $params[':paid_amount']=(float)$week['amount_usd']; }
        if (!$sets) return;
        $sql='UPDATE '.self::qi($table).' SET '.implode(',',$sets).' WHERE '.self::qi((string)$week['id_column']).'=:id LIMIT 1';
        try { $st=self::connection()->prepare($sql);$st->execute($params); } catch (Throwable) {}
    }

    private static function syncSyntheticPaidWeeks(string $customerKey, array $account): void
    {
        if (empty($account['adapter']['synthetic_weeks'])) return;
        [$table,$id]=self::parseKey($customerKey);
        $source=self::customerSourceForTable($table);
        if(!$source) return;
        $targetTable=$table;$targetId=$id;$map=$source['map'];
        $paidCol=$map['paid_weeks']??null;
        if(!$paidCol && !empty($account['adapter']['contract_table']) && $account['adapter']['contract_table']!==$table){
            $customerRow=self::fetchById($table,$map['id'],$id);
            if($customerRow){$contract=self::resolveContractContext($source,$customerRow);$targetTable=(string)$contract['table'];$targetId=(string)$contract['id'];$map=$contract['map'];$paidCol=$map['paid_weeks']??null;}
        }
        if(!$paidCol) return;
        $fresh=self::getAccount($customerKey);
        $contiguous=0;
        foreach($fresh['weeks'] as $w){if((int)$w['week_number']===$contiguous+1 && $w['status']==='paid')$contiguous++;else if((int)$w['week_number']>$contiguous+1)break;}
        $idCol=$map['id']??'id';
        try{$st=self::connection()->prepare('UPDATE '.self::qi($targetTable).' SET '.self::qi($paidCol).'=:n WHERE '.self::qi($idCol).'=:id LIMIT 1');$st->execute([':n'=>$contiguous,':id'=>$targetId]);}catch(Throwable){}
    }

    private static function paidLiteral(string $table, string $statusCol): ?string
    {
        $detail = self::columnDetails($table)[$statusCol] ?? null;
        $candidates = ['pagada','pagado','paid','conciliada','conciliado','completada','completo'];
        if ($detail && preg_match('/^enum\((.*)\)$/i',(string)$detail['Type'],$m)) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/",$m[1],$mm);
            foreach ($mm[1] as $value) foreach ($candidates as $c) if (self::norm($value)===self::norm($c)) return stripcslashes($value);
            foreach ($mm[1] as $value) if (self::isPaidStatus($value)) return stripcslashes($value);
            return null;
        }
        try {
            $sql='SELECT '.self::qi($statusCol).' v, COUNT(*) c FROM '.self::qi($table).' WHERE '.self::qi($statusCol).' IS NOT NULL GROUP BY '.self::qi($statusCol).' ORDER BY c DESC LIMIT 20';
            foreach (self::connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) if (self::isPaidStatus((string)$r['v'])) return (string)$r['v'];
        } catch (Throwable) {}
        return 'paid';
    }

    private static function audit(array $admin, int $paymentId, array $account, array $preview, array $input): void
    {
        $file=__DIR__.'/EventAudit.php'; if(!class_exists('EventAudit')&&is_file($file)) require_once $file;
        if(!class_exists('EventAudit')||!method_exists('EventAudit','recordAdmin')) return;
        $currency=(string)$preview['money']['currency'];
        $summary='Pago conciliado '.number_format((float)$preview['money']['amount_original'],2,',','.').' '.$currency.'; aplicado USD '.number_format((float)$preview['applied_usd'],2,',','.').'.';
        try { EventAudit::recordAdmin($admin,'finance','payment_reconciled_partial','reconcile','gp_v26_payments',$paymentId,$summary,[
            'customer_key'=>$account['customer']['key']??'',
            'customer_name'=>$account['customer']['name']??'',
            'currency'=>$currency,
            'exchange_rate'=>$preview['money']['exchange_rate'],
            'amount_usd'=>$preview['money']['amount_usd'],
            'allocations'=>$preview['allocations'],
            'reference'=>(string)($input['reference']??''),
        ]); } catch (Throwable) {}
    }

    private static function mapCustomerColumns(array $cols): array
    {
        return [
            'id'=>self::pick($cols,['id','customer_id','client_id','cliente_id']),
            'name'=>self::pick($cols,['full_name','name','customer_name','client_name','nombre_completo','nombre','nombres_apellidos','titular']),
            'document'=>self::pick($cols,['document','document_id','document_number','id_number','cedula','cedula_identidad','dni','identity']),
            'contract'=>self::pick($cols,['contract_number','contract_no','contract','contrato','numero_contrato','contract_code','codigo_contrato']),
            'weekly'=>self::pick($cols,['weekly_amount','weekly_fee','weekly_payment','weekly_installment','cuota_semanal','monto_semanal','installment_amount','week_amount']),
            'start'=>self::pick($cols,['start_date','contract_start','plan_start','fecha_inicio','fecha_inicio_contrato','started_at','contract_date']),
            'paid_weeks'=>self::pick($cols,['weeks_paid','paid_weeks','semanas_pagadas','cuotas_pagadas']),
            'plate'=>self::pick($cols,['plate','license_plate','placa','vehicle_plate']),
        ];
    }

    private static function mapContractColumns(array $cols): array
    {
        $map=self::mapCustomerColumns($cols);
        $map['id']=self::pick($cols,['id','contract_id','credit_id','account_id','plan_id']) ?: $map['id'];
        return $map;
    }

    private static function mapWeekColumns(array $cols): array
    {
        return [
            'id'=>self::pick($cols,['id','installment_id','week_id','cuota_id']),
            'week'=>self::pick($cols,['week_number','week_no','week','semana','numero_semana','installment_number','cuota_numero','number']),
            'due'=>self::pick($cols,['due_date','payment_due_date','fecha_vencimiento','fecha_cuota','due_at','date_due','scheduled_date']),
            'amount'=>self::pick($cols,['amount','week_amount','weekly_amount','installment_amount','cuota','monto','amount_due','monto_cuota']),
            'status'=>self::pick($cols,['status','payment_status','estado','state']),
            'paid_at'=>self::pick($cols,['paid_at','payment_date','fecha_pago','reconciled_at','conciliated_at']),
            'reference'=>self::pick($cols,['reference','payment_reference','referencia','bank_reference']),
        ];
    }

    private static function possibleLinkColumns(array $cols, string $parentTable, string $kind): array
    {
        $singular = preg_replace('/s$/','',self::norm($parentTable));
        $candidates = [$singular.'_id',self::norm($parentTable).'_id'];
        if($kind==='customer') $candidates=array_merge($candidates,['customer_id','client_id','cliente_id','finance_customer_id','credit_customer_id']);
        else $candidates=array_merge($candidates,['contract_id','contrato_id','credit_id','credito_id','account_id','cuenta_id','plan_id']);
        $out=[];
        foreach($candidates as $c){$p=self::pick($cols,[$c]);if($p&&!in_array($p,$out,true))$out[]=$p;}
        return $out;
    }

    private static function tables(): array
    {
        $key='__tables'; if(isset(self::$schemaCache[$key])) return self::$schemaCache[$key];
        $rows=self::connection()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) ?: [];
        return self::$schemaCache[$key]=array_map(fn($r)=>(string)$r[0],$rows);
    }

    private static function columns(string $table): array
    {
        $key='cols:'.$table;if(isset(self::$schemaCache[$key]))return self::$schemaCache[$key];
        $details=self::columnDetails($table);return self::$schemaCache[$key]=array_keys($details);
    }

    private static function columnDetails(string $table): array
    {
        $key='details:'.$table;if(isset(self::$schemaCache[$key]))return self::$schemaCache[$key];
        $out=[];foreach(self::connection()->query('SHOW COLUMNS FROM '.self::qi($table))->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(string)$r['Field']]=$r;
        return self::$schemaCache[$key]=$out;
    }

    private static function pick(array $cols, array $candidates): ?string
    {
        $norm=[];foreach($cols as $col)$norm[self::norm($col)]=$col;
        foreach($candidates as $candidate){$n=self::norm($candidate);if(isset($norm[$n]))return $norm[$n];}
        return null;
    }

    private static function norm(string $value): string
    {
        $value=mb_strtolower(trim($value));
        $value=strtr($value,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
        return preg_replace('/[^a-z0-9_]+/','_',$value) ?: '';
    }

    private static function qi(string $identifier): string
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/',$identifier))throw new RuntimeException('Identificador SQL no válido.');
        return '`'.$identifier.'`';
    }

    private static function parseKey(string $key): array
    {
        if(!preg_match('/^([A-Za-z0-9_]+):(.+)$/',$key,$m))throw new RuntimeException('Identificador de cliente inválido.');
        return [$m[1],$m[2]];
    }

    private static function fetchById(string $table,string $idCol,string $id): ?array
    {
        $st=self::connection()->prepare('SELECT * FROM '.self::qi($table).' WHERE '.self::qi($idCol).'=:id LIMIT 1');$st->execute([':id'=>$id]);$r=$st->fetch(PDO::FETCH_ASSOC);return $r?:null;
    }

    private static function stringValue(array $row, ?string $col): string { return $col ? trim((string)($row[$col]??'')) : ''; }
    private static function numericValue(array $row, ?string $col): float { return $col ? self::parseAmount($row[$col]??0) : 0.0; }
    private static function intValue(array $row, ?string $col): int { return $col ? (int)($row[$col]??0) : 0; }
    private static function dateValue(array $row, ?string $col): ?string { return $col ? self::normalizeDate((string)($row[$col]??'')) : null; }

    private static function normalizeDate(string $value): ?string
    {
        $value=trim($value);if($value==='')return null;
        try{$d=new DateTimeImmutable($value);return $d->format('Y-m-d');}catch(Throwable){return null;}
    }

    private static function firstWednesday(string $start): DateTimeImmutable
    {
        $d=new DateTimeImmutable($start.' 00:00:00');$day=(int)$d->format('N');$add=(3-$day+7)%7;return $add? $d->modify('+'.$add.' days'):$d;
    }

    private static function isPaidStatus(string $status): bool
    {
        $n=self::norm($status);return $n!=='' && (str_contains($n,'paid')||str_contains($n,'pagad')||str_contains($n,'concili')||str_contains($n,'complet'));
    }
}
