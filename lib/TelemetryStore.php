<?php
declare(strict_types=1);

/**
 * Memoria local de telemetria alimentada exclusivamente por webhooks.
 *
 * El archivo vive dentro de config/, carpeta bloqueada por .htaccess. Todas
 * las escrituras usan un lock comun y reemplazo atomico para evitar estados
 * parciales cuando llegan varias posiciones al mismo tiempo.
 */
final class TelemetryStore
{
    private string $directory;
    private string $statePath;
    private string $lockPath;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?: dirname(__DIR__) . '/config/runtime';
        $this->statePath = $this->directory . '/telemetry.json';
        $this->lockPath = $this->directory . '/telemetry.lock';
        $this->ensureDirectory();
    }

    public function snapshot(): array
    {
        $lock = $this->openLock();
        try {
            if (!flock($lock, LOCK_SH)) {
                throw new RuntimeException('No se pudo bloquear la memoria de telemetria.');
            }
            return $this->readUnlocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function bootstrap(array $devices, array $positions, array $groups = [], array $geofences = []): array
    {
        return $this->mutate(function (array $state) use ($devices, $positions, $groups, $geofences): array {
            foreach ($devices as $device) {
                if (!is_array($device) || (int) ($device['id'] ?? 0) < 1) continue;
                $state['devices'][(string) ((int) $device['id'])] = $device;
            }
            foreach ($positions as $position) {
                if (!is_array($position) || (int) ($position['deviceId'] ?? 0) < 1) continue;
                $state['positions'][(string) ((int) $position['deviceId'])] = $position;
            }
            $state['groups'] = array_values(array_filter($groups, 'is_array'));
            $state['geofences'] = array_values(array_filter($geofences, 'is_array'));
            $state['meta']['catalogSyncedAt'] = gmdate('c');
            $state['meta']['updatedAt'] = gmdate('c');
            return [$state, $state];
        });
    }

    /** @return array{changed: bool, device: array, position: array} */
    public function acceptPosition(array $position, ?array $device = null): array
    {
        $deviceId = (int) ($position['deviceId'] ?? $device['id'] ?? 0);
        if ($deviceId < 1) {
            throw new InvalidArgumentException('La posicion no contiene un Device ID valido.');
        }
        $position['deviceId'] = $deviceId;

        return $this->mutate(function (array $state) use ($position, $device, $deviceId): array {
            $key = (string) $deviceId;
            $current = is_array($state['positions'][$key] ?? null) ? $state['positions'][$key] : null;
            $changed = $this->isNewerPosition($position, $current);

            if ($device && (int) ($device['id'] ?? 0) === $deviceId) {
                $state['devices'][$key] = array_replace(
                    is_array($state['devices'][$key] ?? null) ? $state['devices'][$key] : [],
                    $device
                );
            }
            if (!isset($state['devices'][$key]) || !is_array($state['devices'][$key])) {
                $state['devices'][$key] = [
                    'id' => $deviceId,
                    'name' => 'GPS ' . $deviceId,
                    'uniqueId' => '',
                    'status' => 'online',
                    'disabled' => false,
                ];
            }

            if ($changed) {
                $state['positions'][$key] = $position;
                $reportedAt = $position['serverTime'] ?? $position['fixTime'] ?? gmdate('c');
                $state['devices'][$key]['status'] = 'online';
                $state['devices'][$key]['lastUpdate'] = $reportedAt;
                $state['meta']['lastPositionAt'] = gmdate('c');
                $state['meta']['lastWebhookAt'] = gmdate('c');
                $state['meta']['updatedAt'] = gmdate('c');
            }

            return [$state, [
                'changed' => $changed,
                'device' => $state['devices'][$key],
                'position' => $changed ? $position : ($current ?: $position),
            ]];
        });
    }

    /** @return array{changed: bool, event: array, device: ?array, position: ?array} */
    public function acceptEvent(array $event, ?array $device = null, ?array $position = null): array
    {
        $deviceId = (int) ($event['deviceId'] ?? $device['id'] ?? $position['deviceId'] ?? 0);
        if ($deviceId > 0) $event['deviceId'] = $deviceId;

        return $this->mutate(function (array $state) use ($event, $device, $position, $deviceId): array {
            $eventId = (int) ($event['id'] ?? 0);
            $fingerprint = $eventId > 0
                ? 'id:' . $eventId
                : hash('sha256', json_encode([
                    $event['deviceId'] ?? null,
                    $event['type'] ?? null,
                    $event['eventTime'] ?? $event['serverTime'] ?? null,
                    $event['positionId'] ?? null,
                ], JSON_UNESCAPED_SLASHES));

            $changed = true;
            foreach ($state['events'] as $stored) {
                if (is_array($stored) && ($stored['_fingerprint'] ?? '') === $fingerprint) {
                    $changed = false;
                    break;
                }
            }

            if ($deviceId > 0) {
                $key = (string) $deviceId;
                if ($device && (int) ($device['id'] ?? 0) === $deviceId) {
                    $state['devices'][$key] = array_replace(
                        is_array($state['devices'][$key] ?? null) ? $state['devices'][$key] : [],
                        $device
                    );
                }
                if (!isset($state['devices'][$key]) || !is_array($state['devices'][$key])) {
                    $state['devices'][$key] = ['id' => $deviceId, 'name' => 'GPS ' . $deviceId, 'uniqueId' => '', 'status' => 'unknown'];
                }
                $type = (string) ($event['type'] ?? '');
                if ($type === 'deviceOnline') $state['devices'][$key]['status'] = 'online';
                if ($type === 'deviceOffline') $state['devices'][$key]['status'] = 'offline';
                if ($type === 'deviceUnknown') $state['devices'][$key]['status'] = 'unknown';
            }

            if ($changed) {
                $event['_fingerprint'] = $fingerprint;
                $state['events'][] = $event;
                if (count($state['events']) > 100) $state['events'] = array_slice($state['events'], -100);
                $state['meta']['lastEventAt'] = gmdate('c');
                $state['meta']['lastWebhookAt'] = gmdate('c');
                $state['meta']['updatedAt'] = gmdate('c');
            }

            $storedDevice = $deviceId > 0 && is_array($state['devices'][(string) $deviceId] ?? null)
                ? $state['devices'][(string) $deviceId] : null;
            $storedPosition = $deviceId > 0 && is_array($state['positions'][(string) $deviceId] ?? null)
                ? $state['positions'][(string) $deviceId] : $position;

            return [$state, ['changed' => $changed, 'event' => $event, 'device' => $storedDevice, 'position' => $storedPosition]];
        });
    }

    public function recordRealtimeResult(bool $delivered, ?string $error = null): void
    {
        $this->mutate(function (array $state) use ($delivered, $error): array {
            $state['meta']['lastRealtimeAttemptAt'] = gmdate('c');
            $state['meta']['lastRealtimeDelivered'] = $delivered;
            $state['meta']['lastRealtimeError'] = $delivered ? null : mb_substr((string) $error, 0, 300);
            return [$state, null];
        });
    }

    public function resolveDevice(mixed $mapping, bool $autoAssign = false, string $match = ''): ?array
    {
        $state = $this->snapshot();
        $devices = array_values(array_filter($state['devices'], 'is_array'));
        if (is_numeric($mapping) && (int) $mapping > 0) {
            $key = (string) ((int) $mapping);
            return is_array($state['devices'][$key] ?? null) ? $state['devices'][$key] : null;
        }
        if (is_string($mapping) && trim($mapping) !== '') {
            foreach ($devices as $device) {
                if ((string) ($device['uniqueId'] ?? '') === trim($mapping)) return $device;
            }
        }
        if (!$autoAssign) return null;
        $needle = mb_strtolower(trim($match));
        if ($needle !== '') {
            foreach ($devices as $device) {
                $haystack = mb_strtolower((string) ($device['name'] ?? '') . ' ' . (string) ($device['uniqueId'] ?? ''));
                if (str_contains($haystack, $needle)) return $device;
            }
        }
        foreach ($devices as $device) {
            if (empty($device['disabled'])) return $device;
        }
        return null;
    }

    private function mutate(callable $callback): mixed
    {
        $lock = $this->openLock();
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear la memoria de telemetria.');
            }
            $state = $this->readUnlocked();
            [$updated, $result] = $callback($state);
            $this->writeUnlocked($updated);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readUnlocked(): array
    {
        $state = [];
        if (is_file($this->statePath)) {
            $decoded = json_decode((string) @file_get_contents($this->statePath), true);
            if (is_array($decoded)) $state = $decoded;
        }
        return array_replace_recursive([
            'version' => 1,
            'devices' => [],
            'positions' => [],
            'groups' => [],
            'geofences' => [],
            'events' => [],
            'meta' => [
                'updatedAt' => null,
                'catalogSyncedAt' => null,
                'lastWebhookAt' => null,
                'lastPositionAt' => null,
                'lastEventAt' => null,
                'lastRealtimeAttemptAt' => null,
                'lastRealtimeDelivered' => null,
                'lastRealtimeError' => null,
            ],
        ], $state);
    }

    private function writeUnlocked(array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('No se pudo serializar la telemetria.');
        $temporary = tempnam($this->directory, 'telemetry-');
        if ($temporary === false || file_put_contents($temporary, $json, LOCK_EX) === false) {
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
            throw new RuntimeException('No se pudo guardar la telemetria.');
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $this->statePath)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo publicar la telemetria guardada.');
        }
    }

    private function isNewerPosition(array $incoming, ?array $current): bool
    {
        if ($current === null) return true;
        $incomingId = (int) ($incoming['id'] ?? 0);
        $currentId = (int) ($current['id'] ?? 0);
        if ($incomingId > 0 && $incomingId === $currentId) return false;

        $incomingTime = $this->positionTime($incoming);
        $currentTime = $this->positionTime($current);
        if ($incomingTime > 0 && $currentTime > 0) {
            if ($incomingTime < $currentTime) return false;
            if ($incomingTime === $currentTime && $incomingId <= $currentId) return false;
        } elseif ($incomingId > 0 && $currentId > 0 && $incomingId < $currentId) {
            return false;
        }
        return $incoming !== $current;
    }

    private function positionTime(array $position): int
    {
        foreach (['serverTime', 'fixTime', 'deviceTime'] as $field) {
            $value = $position[$field] ?? null;
            if (is_string($value) && ($time = strtotime($value)) !== false) return $time;
        }
        return 0;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear config/runtime.');
        }
    }

    /** @return resource */
    private function openLock()
    {
        $lock = @fopen($this->lockPath, 'c+');
        if ($lock === false) throw new RuntimeException('No se pudo abrir el bloqueo de telemetria.');
        @chmod($this->lockPath, 0640);
        return $lock;
    }
}
