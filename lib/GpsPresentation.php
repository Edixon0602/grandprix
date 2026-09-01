<?php
declare(strict_types=1);

final class GpsPresentation
{
    public static function mapConfig(array $config): array
    {
        $key = trim((string) ($config['maptiler_key'] ?? ''));
        $style = in_array(($config['map_style'] ?? ''), ['hybrid', 'streets-v4', 'dataviz-dark', 'streets-v2', 'streets-v2-dark'], true)
            ? (string) $config['map_style'] : 'hybrid';
        return [
            'provider' => 'maptiler',
            'key' => $key,
            'defaultStyle' => $style,
            'configured' => $key !== '' && $key !== 'PEGAR_LLAVE_MAPTILER_AQUI',
        ];
    }

    public static function findPosition(array $positions, int $deviceId): ?array
    {
        if (is_array($positions[(string) $deviceId] ?? null)) return $positions[(string) $deviceId];
        if (is_array($positions[$deviceId] ?? null)) return $positions[$deviceId];
        foreach ($positions as $position) {
            if (is_array($position) && (int) ($position['deviceId'] ?? 0) === $deviceId) return $position;
        }
        return null;
    }

    public static function device(array $device, ?array $position): array
    {
        return [
            'id' => (int) ($device['id'] ?? 0),
            'name' => (string) ($device['name'] ?? 'GPS sin nombre'),
            'uniqueId' => (string) ($device['uniqueId'] ?? ''),
            'status' => (string) ($device['status'] ?? 'unknown'),
            'model' => $device['model'] ?? null,
            'category' => $device['category'] ?? null,
            'contact' => $device['contact'] ?? null,
            'phone' => $device['phone'] ?? null,
            'groupId' => isset($device['groupId']) ? (int) $device['groupId'] : null,
            'disabled' => (bool) ($device['disabled'] ?? false),
            'lastUpdate' => $device['lastUpdate'] ?? null,
            'position' => $position ? self::position($position) : null,
        ];
    }

    public static function customerPosition(array $position): array
    {
        $public = self::position($position);
        unset($public['id'], $public['deviceId'], $public['protocol']);
        return $public;
    }

    /**
     * Representacion de minimo privilegio para Mi GRANDPRIX.
     * No expone IMEI, telefono SIM, contacto, grupo ni identificadores internos.
     */
    public static function customerDevice(array $device, ?array $position, array $vehicle = []): array
    {
        return [
            'name' => (string) ($vehicle['code'] ?? $device['name'] ?? 'Mi motocicleta'),
            'status' => (string) ($device['status'] ?? 'unknown'),
            'model' => $vehicle['model'] ?? $device['model'] ?? null,
            'category' => 'motorcycle',
            'lastUpdate' => $device['lastUpdate'] ?? null,
            'position' => $position ? self::customerPosition($position) : null,
        ];
    }

    public static function position(array $position): array
    {
        $attributes = is_array($position['attributes'] ?? null) ? $position['attributes'] : [];
        $latitude = array_key_exists('latitude', $position) && is_numeric($position['latitude']) ? (float) $position['latitude'] : null;
        $longitude = array_key_exists('longitude', $position) && is_numeric($position['longitude']) ? (float) $position['longitude'] : null;
        $altitude = array_key_exists('altitude', $position) && is_numeric($position['altitude']) ? round((float) $position['altitude'], 1) : null;
        $speedKmh = array_key_exists('speed', $position) && is_numeric($position['speed']) ? round(((float) $position['speed']) * 1.852, 1) : null;
        $course = array_key_exists('course', $position) && is_numeric($position['course']) ? round((float) $position['course'], 1) : null;
        return [
            'id' => (int) ($position['id'] ?? 0),
            'deviceId' => (int) ($position['deviceId'] ?? 0),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude' => $altitude,
            'speedKmh' => $speedKmh,
            'course' => $course,
            'accuracy' => isset($position['accuracy']) ? round((float) $position['accuracy'], 1) : null,
            'address' => $position['address'] ?? null,
            'protocol' => $position['protocol'] ?? null,
            'valid' => array_key_exists('valid', $position) ? (bool) $position['valid'] : null,
            'fixTime' => $position['fixTime'] ?? null,
            'serverTime' => $position['serverTime'] ?? null,
            'ignition' => isset($attributes['ignition']) ? (bool) $attributes['ignition'] : null,
            'motion' => isset($attributes['motion']) ? (bool) $attributes['motion'] : null,
            'battery' => $attributes['batteryLevel'] ?? $attributes['battery'] ?? null,
            'satellites' => $attributes['sat'] ?? $attributes['satellites'] ?? null,
            'signal' => $attributes['rssi'] ?? null,
            'totalDistanceKm' => isset($attributes['totalDistance']) ? round(((float) $attributes['totalDistance']) / 1000, 1) : null,
            'distanceKm' => isset($attributes['distance']) ? round(((float) $attributes['distance']) / 1000, 2) : null,
            'alarm' => $attributes['alarm'] ?? null,
        ];
    }

    public static function event(array $event): array
    {
        return [
            'id' => (int) ($event['id'] ?? 0),
            'type' => (string) ($event['type'] ?? 'unknown'),
            'deviceId' => (int) ($event['deviceId'] ?? 0),
            'positionId' => isset($event['positionId']) ? (int) $event['positionId'] : null,
            'geofenceId' => isset($event['geofenceId']) ? (int) $event['geofenceId'] : null,
            'maintenanceId' => isset($event['maintenanceId']) ? (int) $event['maintenanceId'] : null,
            'eventTime' => $event['eventTime'] ?? $event['serverTime'] ?? null,
            'attributes' => self::safeAttributes($event['attributes'] ?? []),
        ];
    }

    public static function customerEvent(array $event): array
    {
        $public = self::event($event);
        unset($public['id'], $public['deviceId'], $public['positionId'], $public['geofenceId'], $public['maintenanceId']);
        return $public;
    }

    private static function safeAttributes(mixed $attributes): array
    {
        if (!is_array($attributes)) return [];
        $allowed = ['alarm', 'message', 'result', 'speed', 'motion', 'ignition', 'batteryLevel', 'sat', 'rssi'];
        return array_intersect_key($attributes, array_flip($allowed));
    }
}
