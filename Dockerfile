FROM php:8.2-cli

# ติดตั้ง Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# คัดลอกไฟล์ไปใน container
WORKDIR /app
COPY . .

# ติดตั้ง dependencies
RUN composer install

# ตั้งให้ใช้ php -S เป็น web server
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "."]
