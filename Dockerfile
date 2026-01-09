FROM php:8.2-apache

# Enable commonly needed PHP extensions (mysqli for MySQL)
RUN docker-php-ext-install mysqli

# Apache: enable rewrite (not mandatory but useful)
RUN a2enmod rewrite

# Copy source code into Apache docroot
COPY . /var/www/html

# Fix permissions (basic)
RUN chown -R www-data:www-data /var/www/html
