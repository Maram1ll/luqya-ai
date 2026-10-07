FROM php:8.2-apache
RUN a2dismod mpm_event mpm_worker || true
RUN a2enmod mpm_prefork
RUN docker-php-ext-install pdo pdo_mysql mysqli
RUN a2enmod rewrite headers

# Build-time guard: Apache refuses to start when more than one MPM is loaded
# (AH00534). Failing here means the image can never reach Railway broken.
RUN apachectl configtest && apachectl -M

# Copy the project into Apache's document root
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