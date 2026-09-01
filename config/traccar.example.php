<?php
// Plantilla de producción. Use install/conectar-traccar.php para validarla.
// Nunca coloque el token en JavaScript, HTML, respaldos o repositorios públicos.
return [
    'enabled' => true,
    'production_mode' => true,
    'base_url' => 'https://traccar.nevox.pro',
    'token' => 'PEGAR_TOKEN_NUEVO_AQUI',
    'token_expires_at' => null,
    'auth_mode' => 'bearer',
    // Traccar envia posiciones y eventos. No se realizan consultas periodicas.
    'webhook_enabled' => true,
    // Se genera con random_bytes al guardar el configurador por primera vez.
    'webhook_secret' => '',
    // Canal WebSocket privado. El secret nunca se entrega al navegador.
    'realtime_enabled' => false,
    'realtime_provider' => 'pusher',
    'pusher_app_id' => '',
    'pusher_key' => '',
    'pusher_secret' => '',
    'pusher_cluster' => 'mt1',
    // Llave publica para MapTiler + MapLibre. Restrinja su uso a su dominio desde MapTiler Cloud.
    'map_provider' => 'maptiler',
    'maptiler_key' => 'PEGAR_LLAVE_MAPTILER_AQUI',
    'map_style' => 'hybrid',
    'allow_commands' => true,
    // V7.2 nunca expone texto libre. Los comandos manuales usan el catalogo GT06 protegido.
    'allow_custom_commands' => false,
    'customer_portal_live' => true,
    // En V7.2 la asignacion cliente-GPS se toma exclusivamente de MySQL.
    'customer_auto_assign' => false,
    'customer_device_match' => 'GP-0248',
    'customer_devices' => [
        'yeivert-sanchez' => 0,
    ],
];
