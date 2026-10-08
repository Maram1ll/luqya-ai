FROM php:8.2-apache

# ── Apache must stay on a single MPM; libapache2-mod-php needs prefork ──
RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && rm -f /etc/apache2/mods-available/mpm_event.load \
           /etc/apache2/mods-available/mpm_worker.load \
    && a2enmod mpm_prefork

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN a2enmod rewrite

# ── Give the app an explicit DocumentRoot and directory policy ──
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

# ── Startup entrypoint ──
# Runs right before Apache starts: clears any straggler mpm_event/mpm_worker
# files out of both mods-enabled/ and mods-available/, re-asserts prefork,
# binds $PORT, and only then launches Apache. Net effect: prefork is the only
# MPM that can ever end up loaded.
RUN printf '%s\n' \
    '#!/bin/sh' \
    'set -e' \
    '' \
    'cd /etc/apache2' \
    'rm -f mods-enabled/mpm_event.load mods-enabled/mpm_event.conf' \
    'rm -f mods-enabled/mpm_worker.load mods-enabled/mpm_worker.conf' \
    'rm -f mods-available/mpm_event.load mods-available/mpm_event.conf' \
    'rm -f mods-available/mpm_worker.load mods-available/mpm_worker.conf' \
    'a2enmod mpm_prefork >/dev/null 2>&1 || true' \
    'echo "Luqya MPMs active: $(ls mods-enabled | grep "^mpm_" | tr "\n" " ")"' \
    'echo "Luqya: running database migration before Apache starts"' \
    'if command -v php >/dev/null 2>&1 && [ -f /var/www/html/database/migrate.php ]; then' \
    '  i=0; ok=0' \
    '  while [ "$i" -lt 10 ]; do' \
    '    if php /var/www/html/database/migrate.php; then ok=1; break; fi' \
    '    echo "Luqya: migration attempt $((i+1)) failed; retrying in 3s"' \
    '    i=$((i+1)); sleep 3' \
    '  done' \
    '  if [ "$ok" -eq 1 ]; then echo "Luqya: database migration OK"; else echo "Luqya: WARNING migration incomplete; starting Apache anyway"; fi' \
    'fi' \
    'P="${PORT:-80}"' \
    'echo "Luqya: binding Apache to port $P"' \
    'sed -i "s/^Listen .*/Listen $P/" ports.conf' \
    'sed -i "s/:80>/:$P>/g" sites-available/000-default.conf' \
    'exec apache2-foreground' \
    > /usr/local/bin/luqya-entrypoint.sh \
    && chmod +x /usr/local/bin/luqya-entrypoint.sh

CMD ["/usr/local/bin/luqya-entrypoint.sh"]