FROM php:8.2-apache

# ── Strictly one MPM: prefork (required by libapache2-mod-php) ──
# Disable every MPM, then physically delete the event/worker load files so a
# second MPM can never be enabled or loaded (AH00534 is then impossible).
RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && rm -f /etc/apache2/mods-available/mpm_event.load \
           /etc/apache2/mods-available/mpm_worker.load \
    && a2enmod mpm_prefork

RUN docker-php-ext-install pdo pdo_mysql mysqli

# Only the modules the app actually needs
RUN a2enmod rewrite

# Fail the build if Apache's configuration is ever invalid (duplicate MPM, syntax)
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && apachectl configtest

WORKDIR /var/www/html
COPY . /var/www/html/

# Honour .htaccess (hardens secrets, rewrites routes) and make uploads writable
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html

ENV PORT=80
EXPOSE 80

# Rebind Apache to Railway's $PORT at boot, then serve in the foreground
CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-80}/g\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT:-80}>/g\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]