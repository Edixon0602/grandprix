<?php
declare(strict_types=1);

final class CommandService
{
    private array $config;
    private PDO $pdo;
    private TraccarClient $client;
    private TelemetryStore $store;

    public function __construct(array $config, ?PDO $pdo = null)
    {
        $this->config = $config;
        $this->pdo = $pdo ?: Database::connection();
        $this->client = new TraccarClient($config);
        $this->store = new TelemetryStore();
    }

    public function fleet(): array
    {
        $snapshot = $this->store->snapshot();
        $settings = [];
        foreach ($this->pdo->query(
            'SELECT traccar_device_id, code, model, sim_phone, relay_verified, data_commands_verified, commands_enabled FROM gp_vehicles'
        )->fetchAll() as $row) {
            $settings[(int) $row['traccar_device_id']] = $row;
        }
        $devices = [];
        foreach ((array) ($snapshot['devices'] ?? []) as $device) {
            if (!is_array($device) || (int) ($device['id'] ?? 0) < 1) continue;
            $id = (int) $device['id'];
            $position = GpsPresentation::findPosition((array) ($snapshot['positions'] ?? []), $id);
            $setting = $settings[$id] ?? [];
            $devices[] = [
                'id' => $id,
                'name' => (string) ($device['name'] ?? ('GPS ' . $id)),
                'uniqueId' => (string) ($device['uniqueId'] ?? ''),
                'status' => (string) ($device['status'] ?? 'unknown'),
                'lastUpdate' => $device['lastUpdate'] ?? null,
                'position' => $position ? GpsPresentation::position($position) : null,
                'configured' => isset($settings[$id]),
                'code' => $setting['code'] ?? null,
                'model' => $setting['model'] ?? ($device['model'] ?? null),
                'simPhoneConfigured' => !empty($setting['sim_phone']),
                'relayVerified' => !empty($setting['relay_verified']),
                'dataCommandsVerified' => !empty($setting['data_commands_verified']),
                'commandsEnabled' => !isset($setting['commands_enabled']) || !empty($setting['commands_enabled']),
            ];
        }
        usort($devices, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $devices;
    }

    public function catalog(int $deviceId): array
    {
        $vehicle = $this->vehicle($deviceId);
        [$dataTypes, $dataError] = $this->types($deviceId, false);
        [$smsTypes, $smsError] = $this->types($deviceId, true);
        $dataSupported = array_fill_keys($dataTypes, true);
        $smsSupported = array_fill_keys($smsTypes, true);
        $hasSms = isset($smsSupported['custom']) && $vehicle && trim((string) ($vehicle['sim_phone'] ?? '')) !== '';
        $hasSecret = $vehicle && trim((string) ($vehicle['command_secret'] ?? '')) !== '';
        $entries = [];
        foreach (Gt06CommandCatalog::all() as $key => $entry) {
            $channels = [];
            if ($entry['nativeType'] && isset($dataSupported[$entry['nativeType']])) $channels[] = 'data';
            if ($hasSms && $hasSecret) $channels[] = 'sms';
            $reason = null;
            if (!$vehicle) $reason = 'Configura esta unidad en GRANDPRIX.';
            elseif (empty($vehicle['commands_enabled'])) $reason = 'Los comandos estan deshabilitados para esta unidad.';
            elseif ($entry['requiresRelay'] && empty($vehicle['relay_verified'])) $reason = 'Falta verificar fisicamente el relay.';
            elseif (!$channels) $reason = !$hasSecret ? 'Falta guardar la clave tecnica del GPS.' : 'Traccar no reporta un canal compatible.';
            $entries[] = [
                'key' => $key,
                'label' => $entry['label'],
                'description' => $entry['description'],
                'icon' => $entry['icon'],
                'category' => $entry['category'],
                'risk' => $entry['risk'],
                'template' => $entry['template'],
                'params' => $entry['params'],
                'channels' => $channels,
                'available' => $reason === null && !empty($vehicle['commands_enabled']),
                'unavailableReason' => $reason,
            ];
        }
        $device = $this->fleetDevice($deviceId);
        return [
            'device' => $device,
            'configuration' => [
                'registered' => $vehicle !== null,
                'code' => $vehicle['code'] ?? ($device['name'] ?? ''),
                'model' => $vehicle['model'] ?? ($device['model'] ?? ''),
                'simPhone' => $vehicle ? self::maskPhone((string) ($vehicle['sim_phone'] ?? '')) : '',
                'simPhoneConfigured' => $vehicle && trim((string) ($vehicle['sim_phone'] ?? '')) !== '',
                'commandSecretConfigured' => $hasSecret,
                'relayVerified' => $vehicle && !empty($vehicle['relay_verified']),
                'dataCommandsVerified' => $vehicle && !empty($vehicle['data_commands_verified']),
                'commandsEnabled' => !$vehicle || !empty($vehicle['commands_enabled']),
            ],
            'channels' => [
                'data' => ['available' => count($dataTypes) > 0, 'types' => $dataTypes, 'error' => $dataError],
                'sms' => ['available' => $hasSms, 'types' => $smsTypes, 'error' => $smsError],
            ],
            'commands' => $entries,
            'realtime' => RealtimePublisher::publicConfig($this->config, 'private-grandprix-fleet', 'api/realtime-auth.php'),
        ];
    }

    public function configureDevice(int $deviceId, array $input): array
    {
        $device = $this->fleetDevice($deviceId);
        if (!$device) throw new InvalidArgumentException('El dispositivo no existe en la memoria de Traccar.');
        $code = mb_substr(trim((string) ($input['code'] ?? $device['name'] ?? ('GPS-' . $deviceId))), 0, 40);
        $model = mb_substr(trim((string) ($input['model'] ?? $device['model'] ?? 'Motocicleta')), 0, 120);
        $existing = $this->vehicle($deviceId);
        $phoneInput = preg_replace('/\D+/', '', (string) ($input['simPhone'] ?? ''));
        $phone = $phoneInput !== ''
            ? $phoneInput
            : (!empty($input['preserveSimPhone']) ? (preg_replace('/\D+/', '', (string) ($existing['sim_phone'] ?? '')) ?: '') : '');
        if ($code === '' || $model === '') throw new InvalidArgumentException('Codigo y modelo son obligatorios.');
        if (!is_string($phone) || ($phone !== '' && (strlen($phone) < 10 || strlen($phone) > 15))) {
            throw new InvalidArgumentException('El numero de la SIM debe incluir codigo de pais y solo digitos.');
        }
        $secret = trim((string) ($input['commandPassword'] ?? ''));
        if ($secret !== '' && !preg_match('/^\d{6}$/', $secret)) {
            throw new InvalidArgumentException('La clave tecnica del GPS debe tener seis digitos.');
        }
        $encrypted = $existing['command_secret'] ?? null;
        if ($secret !== '') $encrypted = (new SecretBox())->encrypt($secret);
        $statement = $this->pdo->prepare(
            "INSERT INTO gp_vehicles
             (code, model, traccar_device_id, traccar_unique_id, sim_phone, command_secret, relay_verified, data_commands_verified, commands_enabled, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
             ON DUPLICATE KEY UPDATE code = VALUES(code), model = VALUES(model), traccar_unique_id = VALUES(traccar_unique_id),
                sim_phone = VALUES(sim_phone), command_secret = VALUES(command_secret), relay_verified = VALUES(relay_verified),
                data_commands_verified = VALUES(data_commands_verified), commands_enabled = VALUES(commands_enabled)"
        );
        $statement->execute([
            $code,
            $model,
            $deviceId,
            $device['uniqueId'] ?? null,
            $phone ?: null,
            $encrypted,
            !empty($input['relayVerified']) ? 1 : 0,
            !empty($input['dataCommandsVerified']) ? 1 : 0,
            array_key_exists('commandsEnabled', $input) && empty($input['commandsEnabled']) ? 0 : 1,
        ]);

        $phoneSynced = false;
        $phoneError = null;
        if ($phone !== '' && !empty($input['syncTraccarPhone'])) {
            try {
                $remote = (array) $this->client->get('/devices/' . $deviceId);
                $remote['phone'] = '+' . $phone;
                $this->client->put('/devices/' . $deviceId, $remote);
                $phoneSynced = true;
            } catch (Throwable $error) {
                $phoneError = $error->getMessage();
            }
        }
        return ['configured' => true, 'phoneSynced' => $phoneSynced, 'phoneError' => $phoneError];
    }

    public function dispatch(int $deviceId, string $commandKey, array $params, string $requestedChannel, string $reason, string $requestedBy): array
    {
        if (empty($this->config['allow_commands'])) throw new RuntimeException('Los comandos remotos estan deshabilitados.');
        $entry = Gt06CommandCatalog::get($commandKey);
        $vehicle = $this->vehicle($deviceId);
        if (!$vehicle || empty($vehicle['commands_enabled'])) throw new RuntimeException('La unidad no esta habilitada para comandos.');
        if ($entry['requiresRelay'] && empty($vehicle['relay_verified'])) {
            throw new RuntimeException('El relay de esta motocicleta no ha sido verificado.');
        }
        if ($commandKey === 'engine_stop') $this->assertSafeStop($deviceId);

        [$dataTypes] = $this->types($deviceId, false);
        [$smsTypes] = $this->types($deviceId, true);
        $nativeAvailable = $entry['nativeType'] && in_array($entry['nativeType'], $dataTypes, true);
        $smsAvailable = in_array('custom', $smsTypes, true)
            && trim((string) ($vehicle['sim_phone'] ?? '')) !== ''
            && trim((string) ($vehicle['command_secret'] ?? '')) !== '';
        $channel = $this->selectChannel($requestedChannel, $nativeAvailable, $smsAvailable);

        if ($channel === 'data') {
            $type = (string) $entry['nativeType'];
            $attributes = [];
            $commandFingerprint = hash('sha256', $type . ':' . $deviceId . ':' . json_encode($params));
        } else {
            $password = (new SecretBox())->decrypt((string) $vehicle['command_secret']);
            $manual = Gt06CommandCatalog::render($commandKey, $password, $params);
            $type = 'custom';
            $attributes = ['data' => $manual];
            $commandFingerprint = hash('sha256', $manual . ':' . $deviceId);
        }

        $logId = $this->createLog($deviceId, $commandKey, $entry, $channel, $type, $commandFingerprint, $reason, $requestedBy);
        try {
            $result = $this->client->post('/commands/send', [
                'deviceId' => $deviceId,
                'type' => $type,
                'textChannel' => $channel === 'sms',
                'attributes' => $attributes ?: new stdClass(),
            ]);
            $summary = $result === null ? 'Traccar acepto la orden sin contenido de respuesta.' : mb_substr((string) json_encode($result, JSON_UNESCAPED_UNICODE), 0, 500);
            $this->updateLog($logId, 'accepted', $summary);
            if ($commandKey === 'password_change') {
                $newSecret = (new SecretBox())->encrypt((string) ($params['new_password'] ?? ''));
                $this->pdo->prepare('UPDATE gp_vehicles SET command_secret = ?, updated_at = NOW() WHERE traccar_device_id = ?')
                    ->execute([$newSecret, $deviceId]);
            }
            return [
                'id' => $logId,
                'status' => 'accepted',
                'message' => $commandKey === 'password_change'
                    ? 'Traccar acepto el comando y GRANDPRIX actualizo la clave protegida.'
                    : 'Traccar acepto el comando.',
                'channel' => $channel,
                'command' => ['key' => $commandKey, 'label' => $entry['label'], 'risk' => $entry['risk']],
            ];
        } catch (Throwable $error) {
            $this->updateLog($logId, 'failed', mb_substr($error->getMessage(), 0, 500));
            throw $error;
        }
    }

    public function audit(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->query(
            "SELECT id, traccar_device_id AS deviceId, command_key AS commandKey, command_label AS commandLabel,
                    risk_level AS risk, channel, traccar_type AS traccarType, status, result_summary AS result,
                    reason, requested_by AS requestedBy, created_at AS createdAt, updated_at AS updatedAt
             FROM gp_command_logs ORDER BY id DESC LIMIT {$limit}"
        );
        return $statement->fetchAll();
    }

    public static function consumeWebhook(PDO $pdo, array $event, ?array $position = null): ?array
    {
        $attributes = is_array($event['attributes'] ?? null) ? $event['attributes'] : [];
        if ($position && is_array($position['attributes'] ?? null)) $attributes = array_replace($attributes, $position['attributes']);
        $result = trim((string) ($attributes['result'] ?? $attributes['message'] ?? ''));
        $type = (string) ($event['type'] ?? '');
        if ($result === '' && !in_array($type, ['commandResult', 'commandResultEvent'], true)) return null;
        $deviceId = (int) ($event['deviceId'] ?? $position['deviceId'] ?? 0);
        if ($deviceId < 1) return null;
        $statement = $pdo->prepare(
            "SELECT id FROM gp_command_logs
             WHERE traccar_device_id = ? AND status = 'accepted' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([$deviceId]);
        $id = $statement->fetchColumn();
        if ($id === false) return null;
        $summary = $result !== '' ? mb_substr($result, 0, 500) : 'Resultado de comando recibido por Traccar.';
        $pdo->prepare("UPDATE gp_command_logs SET status = 'acknowledged', result_summary = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$summary, (int) $id]);
        return ['id' => (int) $id, 'deviceId' => $deviceId, 'status' => 'acknowledged', 'result' => $summary, 'updatedAt' => gmdate('c')];
    }

    private function vehicle(int $deviceId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM gp_vehicles WHERE traccar_device_id = ? LIMIT 1');
        $statement->execute([$deviceId]);
        $vehicle = $statement->fetch();
        return $vehicle ?: null;
    }

    private function fleetDevice(int $deviceId): ?array
    {
        foreach ($this->fleet() as $device) if ((int) $device['id'] === $deviceId) return $device;
        return null;
    }

    private function types(int $deviceId, bool $textChannel): array
    {
        try {
            $items = (array) $this->client->get('/commands/types', [
                'deviceId' => $deviceId,
                'textChannel' => $textChannel ? 'true' : 'false',
            ]);
            $types = array_values(array_unique(array_filter(array_map(
                static fn(mixed $item): ?string => is_array($item) ? (isset($item['type']) ? (string) $item['type'] : null) : (is_string($item) ? $item : null),
                $items
            ))));
            return [$types, null];
        } catch (Throwable $error) {
            return [[], $error->getMessage()];
        }
    }

    private function selectChannel(string $requested, bool $nativeAvailable, bool $smsAvailable): string
    {
        if (!in_array($requested, ['auto', 'data', 'sms'], true)) throw new InvalidArgumentException('Canal de comando invalido.');
        if ($requested === 'data') {
            if (!$nativeAvailable) throw new RuntimeException('El firmware no reporta soporte nativo para esta orden.');
            return 'data';
        }
        if ($requested === 'sms') {
            if (!$smsAvailable) throw new RuntimeException('El canal SMS no esta listo para esta unidad.');
            return 'sms';
        }
        if ($nativeAvailable) return 'data';
        if ($smsAvailable) return 'sms';
        throw new RuntimeException('No existe un canal compatible para este comando.');
    }

    private function assertSafeStop(int $deviceId): void
    {
        $snapshot = $this->store->snapshot();
        $position = GpsPresentation::findPosition((array) ($snapshot['positions'] ?? []), $deviceId);
        if (!$position) throw new RuntimeException('Corte bloqueado: no existe una posicion valida.');
        $time = strtotime((string) ($position['fixTime'] ?? $position['serverTime'] ?? ''));
        if ($time === false || time() - $time > 30) {
            throw new RuntimeException('Corte bloqueado: la telemetria debe tener menos de 30 segundos.');
        }
        $speedKmh = isset($position['speed']) && is_numeric($position['speed']) ? ((float) $position['speed']) * 1.852 : null;
        $attributes = is_array($position['attributes'] ?? null) ? $position['attributes'] : [];
        if ($speedKmh === null || $speedKmh > 1.0 || !empty($attributes['motion'])) {
            throw new RuntimeException('Corte bloqueado: la motocicleta debe estar completamente detenida.');
        }
    }

    private function createLog(int $deviceId, string $key, array $entry, string $channel, string $type, string $fingerprint, string $reason, string $requestedBy): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO gp_command_logs
             (traccar_device_id, command_key, command_label, risk_level, channel, traccar_type,
              command_fingerprint, status, reason, requested_by, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'dispatching', ?, ?, ?)"
        );
        $statement->execute([
            $deviceId,
            $key,
            $entry['label'],
            $entry['risk'],
            $channel,
            $type,
            $fingerprint,
            $reason !== '' ? mb_substr($reason, 0, 300) : null,
            $requestedBy,
            hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function updateLog(int $id, string $status, string $summary): void
    {
        $this->pdo->prepare('UPDATE gp_command_logs SET status = ?, result_summary = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, mb_substr($summary, 0, 500), $id]);
    }

    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($digits) < 6) return $digits === '' ? '' : '••••';
        return substr($digits, 0, 3) . str_repeat('•', max(3, strlen($digits) - 6)) . substr($digits, -3);
    }
}
