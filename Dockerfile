# Luqya AI - Railway/Apache runtime
# Keeps the MPM conflict fix (a2dismod mpm_event) and PHP extensions intact.
# The missing COPY + port-80-only listener in the earlier minimal image is why
# Railway showed "Crashed": empty /var/www/html (403 health check) and no
# listener on $PORT (connection refused -> restart loop).
FROM php:8.2-apache

RUN a2dismod mpm_event
RUN docker-php-ext-install pdo pdo_mysql mysqli
RUN a2enmod rewrite headers

WORKDIR /var/www/html
COPY . /var/www/html/

# .htaccess is load-bearing (hardens secrets, rewrites routes): honour it, and
# make the upload folder writable by Apache's www-data user.
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html

ENV PORT=80
EXPOSE 80

# Bind Apache to Railway's $PORT at boot, then serve in the foreground.
CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-80}/g\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT:-80}>/g\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]