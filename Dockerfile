FROM php:8.4-apache-bookworm

# SMS2 uses MariaDB/MySQL through PDO. The official PHP image already
# provides mbstring, curl, openssl, json, and fileinfo.
RUN docker-php-ext-install -j2 pdo_mysql \
    && sed -ri 's/^Listen 80$/Listen 8000/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8000>/' /etc/apache2/sites-available/000-default.conf \
    && a2enmod headers rewrite

WORKDIR /var/www/html
COPY . /var/www/html/

# vendor/ is tracked in this repository, so the deployment does not need
# to download Composer packages. Fail the build if required pieces are absent.
RUN php -r 'foreach (["pdo_mysql", "mbstring", "curl", "openssl", "json", "fileinfo"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: $ext\n"); exit(1); } }' \
    && php -r 'require "vendor/autoload.php";' \
    && mkdir -p storage/keys storage/uploads storage/backups uploads/receipts \
    && chown -R www-data:www-data storage uploads/receipts

EXPOSE 8000
CMD ["apache2-foreground"]
