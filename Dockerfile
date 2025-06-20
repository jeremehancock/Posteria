FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev curl wget jq grep sed cron && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j$(nproc) gd

# Configure PHP settings for larger uploads
RUN echo 'upload_max_filesize = 20M' >/usr/local/etc/php/conf.d/uploads.ini && echo 'post_max_size = 21M' >>/usr/local/etc/php/conf.d/uploads.ini && echo 'memory_limit = 256M' >>/usr/local/etc/php/conf.d/uploads.ini

# Enable exec function for shell scripts
RUN echo 'disable_functions = ' >/usr/local/etc/php/conf.d/enable-exec.ini

# Enable Apache modules
RUN a2enmod rewrite

# Set Apache configuration
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf && echo "ServerName localhost" >>/etc/apache2/apache2.conf

# Create directory structure
RUN mkdir -p /var/www/html/posters/movies /var/www/html/posters/tv-shows /var/www/html/posters/tv-seasons /var/www/html/posters/collections /var/www/html/assets /var/www/html/data /var/www/html/include /var/www/html/poster-wall

# Copy the application files
COPY src/ /var/www/html/

# Copy and set up entrypoint script
COPY docker/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Ensure proper permissions
RUN chown -R www-data:www-data /var/www/html && find /var/www/html -type d -exec chmod 755 {} \; && find /var/www/html -type f -exec chmod 644 {} \; && chmod +x /var/www/html/include/auto-import.php && chmod +x /var/www/html/include/remove-overlay-label.sh

EXPOSE 80

# Updated entrypoint that starts cron
ENTRYPOINT ["docker-entrypoint.sh"]
