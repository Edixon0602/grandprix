<?php
// Copie este archivo como whatsapp.php o ejecute install/actualizar-v22-whatsapp.php.
// La carpeta config debe permanecer bloqueada desde la web.
return [
    'enabled' => false,
    'flowbot_base_url' => 'https://flowbot.nevox.pro',
    'flowbot_api_key' => 'fb_live_TU_API_KEY_DE_FLOWBOT',
    // Solo para pruebas locales con un stub de FlowBot por HTTP. Nunca true en producción.
    'flowbot_allow_insecure' => false,
    // Selección explícita de línea/WABA en FlowBot (opcional; si se omite, FlowBot usa la del tenant).
    'phone_number_id' => '',
    // Token para invocar el cron por HTTP: /tools/reminders-whatsapp.php?token=...
    'cron_token' => '',
    // Cuántos días antes del vencimiento se envía el recordatorio. 0 = el mismo día del vencimiento.
    'reminder_days_before' => 0,
    'templates' => [
        'reminder' => 'gp_recordatorio_cuota',
        'receipt' => 'gp_pago_conciliado',
        'language' => 'es',
    ],
    // Número E.164 (58412...) usado por el diagnóstico para pruebas.
    'test_recipient' => '',
];
