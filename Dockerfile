FROM ghcr.io/linuxserver/baseimage-alpine-nginx:3.21

RUN apk add --no-cache \
    php83-curl \
    php83-gd

# Configure php-fpm to pass env vars
RUN sed -E -i 's/^;?clear_env ?=.*$/clear_env = no/g' /etc/php83/php-fpm.d/www.conf && \
  grep -qxF 'clear_env = no' /etc/php83/php-fpm.d/www.conf || echo 'clear_env = no' >> /etc/php83/php-fpm.d/www.conf && \
  echo "env[PATH] = /usr/local/bin:/usr/bin:/bin" >> /etc/php83/php-fpm.conf

# Configure PHP settings for larger uploads
RUN echo 'upload_max_filesize = 20M' >/etc/php83/conf.d/uploads.ini && \
  echo 'post_max_size = 21M' >>/etc/php83/conf.d/uploads.ini && \
  echo 'memory_limit = 256M' >>/etc/php83/conf.d/uploads.ini

# Enable exec function for shell scripts
RUN echo 'disable_functions = ' >/etc/php83/conf.d/enable-exec.ini

# Copy the application files
COPY src/ /app/www/public/

# Copy container files
COPY docker/root/ /

EXPOSE 80
VOLUME /config
