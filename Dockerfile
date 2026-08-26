FROM php:8.2-apache

# تثبيت متطلبات وتفعيل الامتدادات المطلوبة
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install curl zip

# تفعيل ميزة mod_rewrite في Apache
RUN a2enmod rewrite

# ضبط المنفذ الافتراضي لـ Render
ENV PORT=80
EXPOSE 80

# نسخ ملفات المشروع إلى السيرفر وضبط الصلاحيات
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/downloads /var/www/html/jobs || true
