FROM php:8.3-apache

# Instalar dependencias necesarias y extensiones de PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libonig-dev \
        libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring curl opcache \
    && a2enmod headers rewrite deflate expires

# Configuración de VirtualHosts de Apache (dominio raíz -> /public, dashboard -> /)
COPY docker/php/vhost.conf /etc/apache2/sites-available/000-default.conf

# Configuración de seguridad de Apache
RUN { \
    echo 'ServerTokens Prod'; \
    echo 'ServerSignature Off'; \
} > /etc/apache2/conf-available/grandprix-security.conf \
    && a2enconf grandprix-security

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
