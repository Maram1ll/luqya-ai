# Luqya AI - Railway/Apache runtime
FROM php:8.2-apache

# ── Apache MPM guard ──────────────────────────────────────────────
# AH00534 "Configuration error: More than one MPM loaded" occurs when a
# second MPM (event/worker) is enabled alongside prefork. Idempotently
# disable every MPM, then re-enable exactly one: prefork (required by
# libapache2-mod-php). This also tolerates fresh images that only ship
# mpm_prefork, where plain `a2dismod mpm_event` would error.
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2dismod mpm_prefork 2>/dev/null || true \
    && a2enmod mpm_prefork

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN a2enmod rewrite headers

# Validate the Apache configuration during the build so an MPM or parse
# error fails the build instead of crashing the container on Railway.
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && apachectl configtest

WORKDIR /var/www/html
COPY . /var/www/html/

# .htaccess is load-bearing (hardens secrets, rewrites routes): honour it,
# and make the upload folder writable by Apache's www-data user.
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html

ENV PORT=80
EXPOSE 80

# Bind Apache to Railway's $PORT at boot, then serve in the foreground.
CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-80}/g\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT:-80}>/g\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]