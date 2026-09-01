/*
 * Compatibilidad V7.1.
 *
 * El antiguo cliente de consulta periodica fue retirado. La telemetria se
 * recibe en api/traccar-webhook.php y se entrega al navegador por WebSocket
 * privado desde assets/realtime.js. Este archivo se conserva vacio para que
 * una cache antigua no vuelva a activar polling por error.
 */
