FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Configure PHP settings for larger uploads
RUN { \
    echo 'upload_max_filesize = 20M'; \
    echo 'post_max_size = 21M'; \
    echo 'memory_limit = 256M'; \
} > /usr/local/etc/php/conf.d/uploads.ini

# Enable Apache modules
RUN a2enmod rewrite

# Set Apache configuration
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf && \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Create directory structure
RUN mkdir -p /var/www/html/posters/movies \
    /var/www/html/posters/tv-shows \
    /var/www/html/posters/tv-seasons \
    /var/www/html/posters/collections \
    /var/www/html/assets \
    /var/www/html/include

# Copy the application files
COPY index.php /var/www/html/
COPY include/plex-import.php /var/www/html/include/
COPY include/plex-export.php /var/www/html/include/
COPY include/send-to-plex.php /var/www/html/include/
COPY include/get-from-plex.php /var/www/html/include/
COPY include/change-poster.php /var/www/html/include/
COPY include/jellyfin-import.php /var/www/html/include/
COPY include/send-to-jellyfin.php /var/www/html/include/
COPY include/get-from-jellyfin.php /var/www/html/include/
COPY include/config.php /var/www/html/include/

# Copy assets directory
COPY assets/ /var/www/html/assets/

# Copy and set up entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Ensure proper permissions
RUN chown -R www-data:www-data /var/www/html && \
    find /var/www/html -type d -exec chmod 755 {} \; && \
    find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
