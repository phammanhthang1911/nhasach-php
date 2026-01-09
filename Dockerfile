FROM php:8.2-apache

# Enable Apache modules
RUN a2enmod rewrite

# Install system deps needed to build PECL extensions (mongodb)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libssl-dev pkg-config \
 && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install mysqli \
 && pecl install mongodb \
 && docker-php-ext-enable mongodb

# Copy app source
COPY . /var/www/html

# Permissions (optional but ok)
RUN chown -R www-data:www-data /var/www/html

# (Optional) quick sanity check during build
# RUN php -m | grep -i mongodb
