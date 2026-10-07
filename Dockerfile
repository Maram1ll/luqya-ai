FROM php:8.2-apache

# ── Strictly one MPM: prefork (required by libapache2-mod-php) ──
RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && rm -f /etc/apache2/mods-available/mpm_event.load \
           /etc/apache2/mods-available/mpm_worker.load \
    && a2enmod mpm_prefork

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN a2enmod rewrite

# ── Explicit DocumentRoot + directory policy for the app ──
# The project has no /public folder: index.php and routes live at the root,
# so Apache must serve /var/www/html, honor .htaccess, and deny listings.
RUN printf 'ServerName localhost\n<Directory /var/www/html>\n    Options -Indexes\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/luqya-app.conf \
    && a2enconf luqya-app \
    && sed -ri 's#^(\s*)DocumentRoot .*#\1DocumentRoot /var/www/html#' /etc/apache2/sites-enabled/000-default.conf

# Fail the build if Apache's configuration is invalid
RUN apachectl configtest

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html

ENV PORT=80
EXPOSE 80

# ── Bind Apache to EXACTLY Railway's $PORT ──
# Overwrite every current "Listen" line in ports.conf with $PORT, retarget the
# virtual host accordingly, then start Apache in the foreground.
CMD ["sh", "-c", "P=${PORT:-80}; echo \"Luqya: binding Apache to port $P\"; sed -i \"s/^Listen .*/Listen $P/\" /etc/apache2/ports.conf; sed -i \"s/:80>/:$P>/g\" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground"]