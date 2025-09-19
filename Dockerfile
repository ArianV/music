# ---- Base image: Apache + PHP 8.2 ----
FROM php:8.2-apache

# Set a default ServerName to quiet Apache FQDN warning
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

# Enable useful Apache modules
RUN a2enmod rewrite headers

# Install system deps + PostgreSQL PDO extension
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev ca-certificates curl unzip git \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Workdir
WORKDIR /var/www/html

# Copy app source
COPY . /var/www/html

# Ensure public dir ownership and allow .htaccess overrides
RUN chown -R www-data:www-data /var/www/html \
 && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT%/*}/!g' /etc/apache2/apache2.conf \
 && sed -i 's#<Directory /var/www/>#<Directory /var/www/html/>#' /etc/apache2/apache2.conf \
 && sed -i 's/AllowOverride None/AllowOverride All/i' /etc/apache2/apache2.conf

# ---- Persistent uploads via Railway Volume ----
# Expect a Railway Volume mounted at /mnt/data
# Create durable uploads there & symlink into the web root, so existing code keeps working.
RUN mkdir -p /mnt/data/uploads \
 && rm -rf /var/www/html/uploads || true \
 && ln -s /mnt/data/uploads /var/www/html/uploads \
 && chown -h www-data:www-data /var/www/html/uploads \
 && chown -R www-data:www-data /mnt/data/uploads

# Optional: Composer/vendor (uncomment if you later add PHPMailer via composer)
# RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
#  && composer install --no-dev --prefer-dist --no-interaction

# PHP runtime tweaks (mirror your .user.ini)
ENV BASE_URL="http://localhost" \
    PHP_MEMORY_LIMIT=256M
RUN { \
      echo "file_uploads=On"; \
      echo "upload_max_filesize=16M"; \
      echo "post_max_size=16M"; \
      echo "memory_limit=256M"; \
      echo "max_file_uploads=50"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80

# Simple healthcheck (relies on your /health route)
HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1/health || exit 1

CMD ["apache2-foreground"]
