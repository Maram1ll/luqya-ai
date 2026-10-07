FROM php:8.2-apache
RUN a2dismod mpm_event
RUN docker-php-ext-install pdo pdo_mysql mysqli
RUN a2enmod rewrite