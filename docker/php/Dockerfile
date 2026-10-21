FROM php:8.3-fpm

# libpq-dev: build dependency of pdo_pgsql
# gdal-bin:  ogr2ogr / ogrinfo for importing RÚIAN VFR data
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev gdal-bin unzip git \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /app
