<?php
// Configuración de la integración WhatsApp vía FlowBot.
return [
    'enabled' => true,
    'flowbot_base_url' => 'https://flowbot.nevox.pro',
    'flowbot_api_key' => 'fb_live_e87dd0e41e72dfa779fbce3fb5eb8b189d01561877688fd3',
    'flowbot_allow_insecure' => false,
    'phone_number_id' => '1086700861193466',
    'cron_token' => '81ed738d1069c4609f68cf5b3bcac4cf226eb0da505abfc68dda357a7abab68a',
    'reminder_days_before' => 0,
    'templates' => [
        'reminder' => 'gp_recordatorio_cuota',
        'receipt' => 'gp_pago_conciliado',
        'language' => 'es',
    ],
    'test_recipient' => '04124078366',
];
