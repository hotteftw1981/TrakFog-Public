FROM php:8.4-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libcurl4-openssl-dev \
        libzip-dev \
        mariadb-client \
        postgresql-client \
        openssl \
        pkg-config \
    && docker-php-ext-install pdo_mysql curl zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/web/trakfog.conf /etc/apache2/conf-available/trakfog.conf
COPY docker/web/000-default.conf /etc/apache2/sites-available/000-default.conf
RUN a2enconf trakfog

WORKDIR /var/www/html
COPY . /var/www/html

RUN printf '%s\n' \
        'upload_max_filesize=8G' \
        'post_max_size=9G' \
        'max_execution_time=0' \
        'max_input_time=-1' \
        'memory_limit=512M' \
        > /usr/local/etc/php/conf.d/trakfog-migration.ini \
    && chmod +x /var/www/html/docker/web/entrypoint.sh \
    && mkdir -p /var/www/html/storage/logs /var/lib/trakfog \
    && chown -R www-data:www-data /var/www/html/storage /var/lib/trakfog

ENTRYPOINT ["/var/www/html/docker/web/entrypoint.sh"]
CMD ["apache2-foreground"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=5 \
    CMD curl -fsS http://127.0.0.1/health.php >/dev/null || exit 1
