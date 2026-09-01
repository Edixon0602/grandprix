FROM php:8.3-apache

# Instalar dependencias necesarias y extensiones de PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libonig-dev \
        libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring curl opcache \
    && a2enmod headers rewrite deflate expires

# Configuración personalizada de Apache
RUN { \
    echo '<Directory /var/www/html>'; \
    echo '  Options -Indexes +FollowSymLinks'; \
    echo '  AllowOverride All'; \
    echo '  Require all granted'; \
    echo '</Directory>'; \
    echo 'ServerTokens Prod'; \
    echo 'ServerSignature Off'; \
} > /etc/apache2/conf-available/grandprix.conf \
    && a2enconf grandprix

# Copiar configuraciones de PHP y OPcache
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

RUN { \
    echo 'upload_max_filesize = 32M'; \
    echo 'post_max_size = 35M'; \
    echo 'memory_limit = 256M'; \
    echo 'max_execution_time = 60'; \
    echo 'expose_php = Off'; \
    echo 'date.timezone = America/Caracas'; \
} > /usr/local/etc/php/conf.d/grandprix.ini

WORKDIR /var/www/html
