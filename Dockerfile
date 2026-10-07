# استخدام صورة PHP 8.2 الرسمية مع خادم Apache
FROM php:8.2-apache

# تثبيت الحزم والمتطلبات الأساسية للنظام وتفعيل تمديدات PHP المطلوبة
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql mysqli zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# تفعيل وحدات Apache الضرورية (mod_rewrite و mod_headers)
RUN a2enmod rewrite headers

# ضبط إعدادات Apache لتفادي تحذيرات ServerName ودعم ملفات .htaccess
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# تحديد مسار العمل الافتراضي داخل الحاوية
WORKDIR /var/www/html

# نسخ كامل ملفات المشروع إلى مسار استضافة الويب
COPY . /var/www/html/

# إنشاء مجلد رفع الصور وتعيين الصلاحيات الكاملة لمستخدم خادم الويب (www-data)
RUN mkdir -p /var/www/html/assets/images/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/assets/images/uploads

# إنشاء سكربت بدء التشغيل لضبط منفذ Apache ديناميكياً عند إقلاع الحاوية وفق متغير $PORT في Render
RUN printf '#!/bin/sh\n\
set -e\n\
TARGET_PORT="${PORT:-80}"\n\
echo "==> Configuring Apache to listen dynamically on port: $TARGET_PORT"\n\
sed -i "s/Listen 80/Listen $TARGET_PORT/g" /etc/apache2/ports.conf\n\
sed -i "s/<VirtualHost \\*:80>/<VirtualHost \\*:$TARGET_PORT>/g" /etc/apache2/sites-available/000-default.conf\n\
echo "==> Starting Apache in foreground..."\n\
exec apache2-foreground\n' > /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# تعيين المنفذ الافتراضي
ENV PORT=80
EXPOSE 80

# تشغيل سكربت الإقلاع الذي يوجه خادم Apache للمنفذ المطلوب ثم يشغله
CMD ["/usr/local/bin/docker-entrypoint.sh"]
