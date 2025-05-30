FROM php:8.2-cli

# ติดตั้ง zip, unzip, git, curl, และ extension ที่จำเป็น
RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip git curl \
    && docker-php-ext-install zip

# ติดตั้ง composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# กำหนด working directory
WORKDIR /app

# คัดลอกไฟล์ทั้งหมด
COPY . .

# ติดตั้ง dependency
RUN composer install

# เปิดพอร์ต 8080 และรัน PHP built-in server
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "."]
