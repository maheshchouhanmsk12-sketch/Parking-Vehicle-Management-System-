# Dockerfile for Vehicle Parking Management System on Render
FROM php:8.2-apache

# Install required system packages and PHP extension libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) mysqli pdo_mysql gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable required Apache modules
RUN a2enmod rewrite headers remoteip

# Enable PHP output buffering globally to prevent headers already sent issues
RUN echo "output_buffering = 4096" > /usr/local/etc/php/conf.d/output-buffering.ini

# Configure Apache for Render reverse proxy and AllowOverride All
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo "ServerTokens Prod" >> /etc/apache2/apache2.conf \
    && echo "ServerSignature Off" >> /etc/apache2/apache2.conf \
    && echo "UseCanonicalName Off" >> /etc/apache2/apache2.conf \
    && echo "UseCanonicalPhysicalPort Off" >> /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod +x /var/www/html/start.sh

# Default port (Render assigns $PORT at runtime)
EXPOSE 10000

# Run Apache startup script
CMD ["/var/www/html/start.sh"]
