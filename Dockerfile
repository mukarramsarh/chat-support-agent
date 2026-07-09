# ── support-ai · PHP + Apache image ─────────────────────────────────────────
# Mirrors a typical shared-hosting stack (Apache + mod_php + mysqli/PDO) so
# "works in Docker" == "works on the host".
FROM php:8.2-apache

# System libs for the PHP extensions we use.
# gd is required by phpoffice/phpword (DOCX handling); it needs the image libs
# and an explicit configure step before install.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libonig-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libxml2-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring zip gd \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Point Apache at /public and allow .htaccess overrides.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
    && printf '<Directory /var/www/html/public>\n  AllowOverride All\n  Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/support-ai.conf \
    && a2enconf support-ai

# Composer (from the official composer image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist || true
COPY . .
RUN composer install --no-dev --no-interaction --optimize-autoloader \
    && mkdir -p storage/logs storage/uploads \
    && chown -R www-data:www-data storage

EXPOSE 80
