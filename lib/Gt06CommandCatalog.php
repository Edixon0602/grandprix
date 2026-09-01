<?php
declare(strict_types=1);

final class Gt06CommandCatalog
{
    public static function all(): array
    {
        return [
            'diagnostic_check' => self::item('Diagnostico general', 'Consulta estado, señal y parametros del GPS.', 'fa-stethoscope', 'diagnostico', 'low', 'check[CLAVE]'),
            'diagnostic_ip_apn' => self::item('Consultar IP y APN', 'Confirma servidor, puerto y APN vigentes.', 'fa-network-wired', 'diagnostico', 'low', 'IPAPN[CLAVE]'),
            'location_sms' => self::item('Solicitar ubicacion', 'Solicita una posicion inmediata o enlace de ubicacion.', 'fa-location-crosshairs', 'diagnostico', 'low', 'smslink[CLAVE]', 'positionSingle'),
            'acc_alarm_on' => self::item('Activar alarma ACC', 'Notifica cambios de ignicion ACC.', 'fa-key', 'alarmas', 'medium', 'acc[CLAVE]'),
            'acc_alarm_off' => self::item('Desactivar alarma ACC', 'Cancela las alertas por cambios ACC.', 'fa-key', 'alarmas', 'medium', 'noacc[CLAVE]'),
            'removal_alarm_on' => self::item('Activar alarma de desconexion', 'Alerta cuando retiran o desconectan el dispositivo.', 'fa-plug-circle-xmark', 'alarmas', 'medium', 'extpower[CLAVE] on'),
            'removal_alarm_off' => self::item('Desactivar alarma de desconexion', 'Cancela la alarma de energia externa.', 'fa-plug', 'alarmas', 'medium', 'extpower[CLAVE] off'),
            'vibration_on' => self::item('Activar alarma de vibracion', 'Detecta movimientos o golpes con la moto estacionada.', 'fa-burst', 'alarmas', 'medium', 'vibrate[CLAVE] 1'),
            'vibration_off' => self::item('Desactivar alarma de vibracion', 'Cancela la deteccion de vibracion.', 'fa-wave-square', 'alarmas', 'medium', 'vibrate[CLAVE] 0'),
            'engine_stop' => self::item('Corte protegido', 'Activa el relay solo con la moto detenida y telemetria reciente.', 'fa-power-off', 'seguridad', 'critical', 'stop[CLAVE]', 'engineStop', true),
            'engine_resume' => self::item('Restaurar corriente', 'Libera el relay y permite volver a encender la moto.', 'fa-rotate-left', 'seguridad', 'high', 'resume[CLAVE]', 'engineResume', true),
            'alarm_none' => self::item('Cancelar envio de alarmas', 'Configura el nivel de alarma en 0.', 'fa-bell-slash', 'alarmas', 'medium', 'KC[CLAVE] 0'),
            'alarm_gprs' => self::item('Alarmas por GPRS', 'Entrega las alarmas al servidor de datos.', 'fa-tower-broadcast', 'alarmas', 'medium', 'KC[CLAVE] 1'),
            'alarm_gprs_sms' => self::item('Alarmas GPRS + SMS', 'Entrega alarmas al servidor y por SMS.', 'fa-comment-sms', 'alarmas', 'medium', 'KC[CLAVE] 2'),
            'alarm_gprs_sms_call' => self::item('Alarmas GPRS + SMS + llamada', 'Activa todos los canales que soporte la SIM.', 'fa-phone-volume', 'alarmas', 'high', 'KC[CLAVE] 3'),
            'sleep_on' => self::item('Activar reposo', 'Reduce transmisiones durante estacionamiento.', 'fa-moon', 'telemetria', 'medium', 'sleep[CLAVE] on'),
            'sleep_off' => self::item('Desactivar reposo', 'Mantiene activo el canal GSM/GPS.', 'fa-sun', 'telemetria', 'medium', 'sleep[CLAVE] off'),
            'report_interval' => self::item('Frecuencia de reporte', 'Configura intervalo en movimiento y detenido.', 'fa-stopwatch', 'telemetria', 'high', 'fix020s060m***n[CLAVE]', null, false, [
                'moving_seconds' => ['label' => 'Segundos en movimiento', 'type' => 'number', 'min' => 10, 'max' => 300, 'default' => 20],
                'stopped_minutes' => ['label' => 'Minutos detenido', 'type' => 'number', 'min' => 1, 'max' => 240, 'default' => 60],
            ]),
            'angle_interval' => self::item('Reporte por cambio de angulo', 'Envía posicion al cambiar el rumbo.', 'fa-location-arrow', 'telemetria', 'medium', 'ANGLE[CLAVE] 30', null, false, [
                'angle' => ['label' => 'Angulo en grados', 'type' => 'number', 'min' => 10, 'max' => 180, 'default' => 30],
            ]),
            'timezone' => self::item('Zona horaria', 'Ajuste tecnico de la hora del dispositivo.', 'fa-clock', 'provisionamiento', 'high', 'time zone[CLAVE] -4', null, false, [
                'timezone' => ['label' => 'Zona UTC', 'type' => 'number', 'min' => -12, 'max' => 14, 'default' => -4],
            ]),
            'restart' => self::item('Reiniciar GPS', 'Reinicia el equipo sin cambiar APN ni servidor.', 'fa-arrows-rotate', 'seguridad', 'high', 'reset[CLAVE]'),
            'admin_number' => self::item('Numero administrador', 'Define el numero autorizado para alarmas.', 'fa-user-shield', 'provisionamiento', 'critical', 'admin[CLAVE] <TELEFONO>', null, false, [
                'phone' => ['label' => 'Telefono internacional', 'type' => 'tel', 'placeholder' => '58412...'],
            ]),
            'password_change' => self::item('Cambiar clave del GPS', 'Cambia la contraseña de seis digitos del dispositivo.', 'fa-key', 'provisionamiento', 'critical', 'password[CLAVE] <NUEVA>', null, false, [
                'new_password' => ['label' => 'Nueva clave de 6 digitos', 'type' => 'password', 'placeholder' => '******'],
            ]),
            'apn' => self::item('Configurar APN', 'Modifica la red movil. Puede dejar el GPS sin conexion.', 'fa-sim-card', 'provisionamiento', 'critical', 'apn[CLAVE] <APN> <USUARIO> <PASSWORD>', null, false, [
                'apn' => ['label' => 'APN', 'type' => 'text'],
                'apn_user' => ['label' => 'Usuario APN (opcional)', 'type' => 'text'],
                'apn_password' => ['label' => 'Clave APN (opcional)', 'type' => 'password'],
            ]),
            'server' => self::item('Configurar servidor GPS', 'Cambia host y puerto de destino. Uso tecnico exclusivo.', 'fa-server', 'provisionamiento', 'critical', 'adminip[CLAVE] <HOST> <PUERTO>', null, false, [
                'host' => ['label' => 'Host de Traccar', 'type' => 'text'],
                'port' => ['label' => 'Puerto GT06', 'type' => 'number', 'min' => 1, 'max' => 65535, 'default' => 5023],
            ]),
        ];
    }

    public static function get(string $key): array
    {
        $catalog = self::all();
        if (!isset($catalog[$key])) throw new InvalidArgumentException('Comando GT06 no reconocido.');
        return $catalog[$key] + ['key' => $key];
    }

    public static function render(string $key, string $password, array $params = []): string
    {
        if (!preg_match('/^\d{6}$/', $password)) {
            throw new InvalidArgumentException('La clave tecnica del GPS debe tener seis digitos.');
        }
        self::get($key);
        return match ($key) {
            'diagnostic_check' => 'check' . $password,
            'diagnostic_ip_apn' => 'IPAPN' . $password,
            'location_sms' => 'smslink' . $password,
            'acc_alarm_on' => 'acc' . $password,
            'acc_alarm_off' => 'noacc' . $password,
            'removal_alarm_on' => 'extpower' . $password . ' on',
            'removal_alarm_off' => 'extpower' . $password . ' off',
            'vibration_on' => 'vibrate' . $password . ' 1',
            'vibration_off' => 'vibrate' . $password . ' 0',
            'engine_stop' => 'stop' . $password,
            'engine_resume' => 'resume' . $password,
            'alarm_none' => 'KC' . $password . ' 0',
            'alarm_gprs' => 'KC' . $password . ' 1',
            'alarm_gprs_sms' => 'KC' . $password . ' 2',
            'alarm_gprs_sms_call' => 'KC' . $password . ' 3',
            'sleep_on' => 'sleep' . $password . ' on',
            'sleep_off' => 'sleep' . $password . ' off',
            'report_interval' => sprintf(
                'fix%03ds%03dm***n%s',
                self::integer($params, 'moving_seconds', 10, 300, 20),
                self::integer($params, 'stopped_minutes', 1, 240, 60),
                $password
            ),
            'angle_interval' => 'ANGLE' . $password . ' ' . self::integer($params, 'angle', 10, 180, 30),
            'timezone' => 'time zone' . $password . ' ' . self::integer($params, 'timezone', -12, 14, -4),
            'restart' => 'reset' . $password,
            'admin_number' => 'admin' . $password . ' ' . self::phone($params['phone'] ?? ''),
            'password_change' => 'password' . $password . ' ' . self::newPassword($params['new_password'] ?? ''),
            'apn' => self::apnCommand($password, $params),
            'server' => 'adminip' . $password . ' ' . self::host($params['host'] ?? '') . ' ' . self::integer($params, 'port', 1, 65535, 5023),
            default => throw new InvalidArgumentException('No existe plantilla para el comando solicitado.'),
        };
    }

    private static function item(
        string $label,
        string $description,
        string $icon,
        string $category,
        string $risk,
        string $template,
        ?string $nativeType = null,
        bool $requiresRelay = false,
        array $params = []
    ): array {
        return compact('label', 'description', 'icon', 'category', 'risk', 'template', 'nativeType', 'requiresRelay', 'params');
    }

    private static function integer(array $params, string $key, int $min, int $max, int $default): int
    {
        $value = $params[$key] ?? $default;
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $min || $number > $max) {
            throw new InvalidArgumentException('El parametro ' . $key . ' esta fuera del rango permitido.');
        }
        return (int) $number;
    }

    private static function phone(mixed $value): string
    {
        $phone = preg_replace('/\D+/', '', (string) $value);
        if (!is_string($phone) || strlen($phone) < 10 || strlen($phone) > 15) {
            throw new InvalidArgumentException('El telefono administrador debe incluir codigo de pais y solo digitos.');
        }
        return $phone;
    }

    private static function newPassword(mixed $value): string
    {
        $password = trim((string) $value);
        if (!preg_match('/^\d{6}$/', $password)) throw new InvalidArgumentException('La nueva clave debe tener seis digitos.');
        return $password;
    }

    private static function host(mixed $value): string
    {
        $host = strtolower(trim((string) $value));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\d{1,3}(?:\.\d{1,3}){3})$/', $host)) {
            throw new InvalidArgumentException('El host de Traccar no es valido.');
        }
        return $host;
    }

    private static function apnCommand(string $password, array $params): string
    {
        $apn = trim((string) ($params['apn'] ?? ''));
        $user = trim((string) ($params['apn_user'] ?? ''));
        $pass = trim((string) ($params['apn_password'] ?? ''));
        foreach ([$apn, $user, $pass] as $value) {
            if ($value !== '' && !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $value)) {
                throw new InvalidArgumentException('El APN contiene caracteres no permitidos.');
            }
        }
        if ($apn === '') throw new InvalidArgumentException('Debes indicar el APN de la operadora.');
        return trim('apn' . $password . ' ' . $apn . ' ' . $user . ' ' . $pass);
    }
}
