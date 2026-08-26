FROM php:8.2-apache

# Enable Apache mod_rewrite for clean URLs & API routing
RUN a2enmod rewrite

# Enable AllowOverride All in Apache configuration
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Install PDO MySQL and mysqli extensions for PHP
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Copy your repository files into the web root
COPY . /var/www/html/

# Set proper permissions for Apache www-data user
RUN chown -R www-data:www-data /var/www/html/

# Expose web port 80
EXPOSE 80
