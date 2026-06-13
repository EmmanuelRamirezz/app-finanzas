# syntax = docker/dockerfile:experimental

ARG PHP_VERSION=8.4
ARG NODE_VERSION=24.14
FROM ubuntu:22.04 as base
LABEL fly_launch_runtime="laravel"

# PHP_VERSION needs to be repeated here
# See https://docs.docker.com/engine/reference/builder/#understand-how-arg-and-from-interact
ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer \
    COMPOSER_MAX_PARALLEL_HTTP=24 \
    PHP_PM_MAX_CHILDREN=10 \
    PHP_PM_START_SERVERS=3 \
    PHP_MIN_SPARE_SERVERS=2 \
    PHP_MAX_SPARE_SERVERS=4 \
    PHP_DATE_TIMEZONE=UTC \
    PHP_DISPLAY_ERRORS=Off \
    PHP_ERROR_REPORTING=22527 \
    PHP_MEMORY_LIMIT=256M \
    PHP_MAX_EXECUTION_TIME=90 \
    PHP_POST_MAX_SIZE=100M \
    PHP_UPLOAD_MAX_FILE_SIZE=100M \
    PHP_ALLOW_URL_FOPEN=Off

# Prepare base container:
# 1. Install PHP, Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY .fly/php/ondrej_ubuntu_php.gpg /etc/apt/trusted.gpg.d/ondrej_ubuntu_php.gpg


RUN apt-get update \
    && apt-get install -y --no-install-recommends gnupg2 ca-certificates git-core curl zip unzip \
                                                  rsync vim-tiny htop sqlite3 nginx supervisor cron \
    && ln -sf /usr/bin/vim.tiny /etc/alternatives/vim \
    && ln -sf /etc/alternatives/vim /usr/bin/vim \
    && echo "deb http://ppa.launchpad.net/ondrej/php/ubuntu jammy main" > /etc/apt/sources.list.d/ondrej-ubuntu-php-focal.list \
    && apt-get update \
    && apt-get -y --no-install-recommends install \
        php8.4-cli php8.4-fpm php8.4-xml php8.4-mbstring php8.4-curl \
        php8.4-tokenizer php8.4-zip php8.4-sqlite3 php8.4-mysql \
        php8.4-bcmath php8.4-intl php8.4-readline php8.4-opcache \
    && update-alternatives --set php /usr/bin/php8.4 \
    && ln -sf /usr/sbin/php-fpm8.4 /usr/sbin/php-fpm \
    && mkdir -p /var/www/html/public && echo "index" > /var/www/html/public/index.php \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# 2. Copy config files to proper locations
COPY .fly/nginx/ /etc/nginx/
COPY .fly/fpm/ /etc/php/${PHP_VERSION}/fpm/
COPY .fly/supervisor/ /etc/supervisor/
COPY .fly/entrypoint.sh /entrypoint
COPY .fly/start-nginx.sh /usr/local/bin/start-nginx
RUN chmod 754 /usr/local/bin/start-nginx

# CORRECCIÓN CRÍTICA 1: Forzar a PHP-FPM a no correr en segundo plano
RUN sed -i 's/;*daemonize\s*=\s*yes/daemonize = no/g' /etc/php/${PHP_VERSION}/fpm/php-fpm.conf || true
RUN echo "daemonize = no" >> /etc/php/${PHP_VERSION}/fpm/php-fpm.conf

# CORRECCIÓN CRÍTICA 2: Actualizar Nginx para que busque el socket real de PHP 8.4
RUN find /etc/nginx -type f -exec sed -i 's/php-fpm.sock/php8.4-fpm.sock/g' {} +

# 3. Copy application code, skipping files based on .dockerignore
COPY . /var/www/html
WORKDIR /var/www/html

# 4. Setup application dependencies
RUN composer install --optimize-autoloader --no-dev
RUN mkdir -p storage/logs
RUN php artisan config:clear || true
RUN php artisan event:clear || true
RUN php artisan route:clear || true
RUN php artisan view:clear || true
RUN chown -R www-data:www-data /var/www/html
RUN echo 'MAILTO=""\n* * * * * www-data /usr/bin/php /var/www/html/artisan schedule:run' > /etc/cron.d/laravel
RUN if [ -f app/Http/Middleware/TrustProxies.php ]; then sed -i 's/protected \$proxies/protected \$proxies = "*"/g' app/Http/Middleware/TrustProxies.php; fi
RUN if [ -d .fly ]; then cp .fly/entrypoint.sh /entrypoint; chmod +x /entrypoint; fi
EXPOSE 8080

ENTRYPOINT ["/entrypoint"]
