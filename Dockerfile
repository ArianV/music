FROM php:8.2-apache
RUN a2enmod rewrite

RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libpng-dev libjpeg-dev libwebp-dev ca-certificates \
  && docker-php-ext-install pdo pdo_pgsql pgsql curl \ 
  && docker-php-ext-configure gd --with-jpeg --with-webp \
  && docker-php-ext-install gd \
  && rm -rf /var/lib/apt/lists/*

RUN printf "<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n" \
      > /etc/apache2/conf-available/allowoverride.conf \
  && a2enconf allowoverride \
  && sed -i 's#</VirtualHost>#  <Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n  </Directory>\n  FallbackResource /index.php\n</VirtualHost>#' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 80
CMD ["apache2-foreground"]
