#!/bin/bash
set -e

# Crear carpetas de runtime si no existen
mkdir -p /var/www/html/config/runtime \
         /var/www/html/config/customer-documents \
         /var/www/html/config/client-dossiers \
         /var/www/html/config/payment-proofs \
         /var/www/html/config/clientes \
         /var/www/html/config/application-files

# Asignar permisos a carpetas dinámicas para que www-data y el host puedan escribir
chown -R www-data:www-data /var/www/html/config/runtime \
                           /var/www/html/config/customer-documents \
                           /var/www/html/config/client-dossiers \
                           /var/www/html/config/payment-proofs \
                           /var/www/html/config/clientes \
                           /var/www/html/config/application-files 2>/dev/null || true

chmod -R 777 /var/www/html/config/runtime 2>/dev/null || true

# Permitir actualización de configuraciones editables desde el panel web
touch /var/www/html/config/traccar-audit.log 2>/dev/null || true
chown www-data:www-data /var/www/html/config/traccar.php /var/www/html/config/traccar-audit.log 2>/dev/null || true
chmod 666 /var/www/html/config/traccar.php /var/www/html/config/traccar-audit.log 2>/dev/null || true

# Garantizar permisos para recursos y archivos estáticos (persistente en cada arranque)
chmod -R 777 /var/www/html/assets /var/www/html/public/assets 2>/dev/null || true
chmod 666 /var/www/html/*.png /var/www/html/*.ico /var/www/html/*.json /var/www/html/*.js 2>/dev/null || true

exec apache2-foreground "$@"
