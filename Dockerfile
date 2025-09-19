# ---- Base image: Apache + PHP 8.2 ----
FROM php:8.2-apache

# Quiet FQDN warning + enable modules
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && a2enmod rewrite headers

# Install system deps + PostgreSQL PDO extension
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev ca-certificates curl unzip git \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# App root
WORKDIR /var/www/html
COPY . /var/www/html

# Apache: allow .htaccess in /var/www/html without touching APACHE_DOCUMENT_ROOT
RUN chown -R www-data:www-data /var/www/html \
 && printf '%s\n' "<Directory /var/www/html>" "  AllowOverride All" "  Require all granted" "</Directory>" \
      > /etc/apache2/conf-available/app-override.conf \
 && a2enconf app-override

# ---- Persistent uploads via Railway Volume ----
# Expect a Railway Volume mounted at /mnt/data
RUN mkdir -p /mnt/data/uploads \
 && rm -rf /var/www/html/uploads || true \
 && ln -s /mnt/data/uploads /var/www/html/uploads \
 && chown -h www-data:www-data /var/www/html/uploads \
 && chown -R www-data:www-data /mnt/data/uploads

# PHP runtime tweaks (mirror your .user.ini)
RUN { \
      echo "file_uploads=On"; \
      echo "upload_max_filesize=16M"; \
      echo "post_max_size=16M"; \
      echo "memory_limit=256M"; \
      echo "max_file_uploads=50"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80

# Healthcheck (relies on /health route)
HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1/health || exit 1

CMD ["apache2-foreground"]
