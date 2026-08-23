# syntax=docker/dockerfile:1

# SERBIS API image, for Render's Docker service.
#
# Render runs Docker, not Nixpacks and not a buildpack, so nothing in Procfile
# is read here — the release commands it used to carry now live in
# docker-entrypoint.sh, which also seeds, which Procfile never did.
#
# There is deliberately no Node stage. Nothing in this application calls @vite
# any more: routes/web.php answers `/` with JSON and resources/views holds only
# the verification e-mail. Adding npm here would build a manifest no request
# reads.

# ---------------------------------------------------------------------------
# Stage 1 — vendor. Composer runs on the same php:8.4 base the app runs on, so
# its platform check ("php": "^8.4" in composer.json) is checked against the
# interpreter that will actually execute the code, not against whatever PHP the
# composer image happens to ship.
#
# The whole source is copied in before install because --optimize-autoloader
# builds a classmap by scanning the PSR-4 roots. With only composer.json and
# composer.lock present it would produce an empty map and quietly fall back to
# filesystem lookups on every class in app/.
# ---------------------------------------------------------------------------
FROM php:8.4-apache AS vendor

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

# --no-scripts: post-autoload-dump runs `artisan package:discover`, which boots
# the framework. It is run in the runtime stage instead, where pdo_mysql exists.
RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-progress \
        --no-scripts

# ---------------------------------------------------------------------------
# Stage 2 — runtime.
# ---------------------------------------------------------------------------
FROM php:8.4-apache AS runtime

# `composer check-platform-reqs --no-dev` asks for ctype, dom, fileinfo, filter,
# hash, iconv, json, libxml, mbstring, openssl, pcre, session, simplexml and
# tokenizer — every one of them is compiled into the official php image already.
#
# Two more are needed that no package declares: pdo_mysql, which
# config/database.php guards with extension_loaded() and which the whole app
# depends on, and curl, which Guzzle uses for the PhilSMS calls and which is
# likewise already in the base image. opcache ships with the image but is not
# enabled.
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql opcache

# The stock production ini, plus the settings this app actually needs changed.
# InfoMaterialController accepts uploads up to max:10240 (10 MB) and
# ResidentController up to 4 MB, while PHP's own defaults are 2 MB per file and
# 8 MB per request — those caps are enforced before Laravel sees the request, so
# an oversized upload arrives as an empty $_FILES rather than as a 422.
#
# opcache.validate_timestamps=0 is safe because the image is immutable: the
# files it caches cannot change under it, so stat()-ing them per request buys
# nothing.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=16M'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > "$PHP_INI_DIR/conf.d/serbis.ini"

# Laravel's front controller is public/index.php; serving /var/www/html would
# expose .env, storage/ and vendor/ to the internet. mod_rewrite is what makes
# public/.htaccess route everything through index.php.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html

RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

# Render assigns the port through PORT; 10000 is its default, and the entrypoint
# writes whichever value is present into Apache's config before starting it.
ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["docker-entrypoint"]
CMD ["apache2-foreground"]
