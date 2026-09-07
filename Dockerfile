FROM php:8.2-apache

# Install required system packages and PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd fileinfo

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite for URL rewriting
RUN a2enmod rewrite

# Configure Apache to listen on $PORT and allow overrides
RUN echo "Listen \${PORT:-8000}\n" > /etc/apache2/ports.conf
RUN echo "<VirtualHost *:\${PORT:-8000}>\n\tDocumentRoot /var/www/html\n\n\t<Directory /var/www/html>\n\t\tAllowOverride All\n\t\tRequire all granted\n\t</Directory>\n</VirtualHost>" > /etc/apache2/sites-available/000-default.conf

# Set the working directory
WORKDIR /var/www/html

# Copy the composer files first for better caching
COPY composer.json ./

# Install dependencies using Composer
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy the rest of the application files
COPY . /var/www/html/

# Set proper permissions for Apache
RUN chown -R www-data:www-data /var/www/html

# Expose port (Documentation purpose, actual binding uses $PORT)
EXPOSE 8000

# Start Apache in the foreground
CMD ["apache2-foreground"]
