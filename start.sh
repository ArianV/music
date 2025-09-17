#!/usr/bin/env bash
set -euo pipefail

DOCROOT="/var/www/html"
UPLOADS="$DOCROOT/uploads"

# Make uploads writable even when the volume is owned by root
mkdir -p "$UPLOADS"
# chown may fail if not root; that's fine
chown -R www-data:www-data "$UPLOADS" 2>/dev/null || true
chmod -R 0777 "$UPLOADS" || true

# Prefer Apache if available; otherwise fall back to php -S
if [ -x "$DOCROOT/vendor/bin/heroku-php-apache2" ]; then
  exec "$DOCROOT/vendor/bin/heroku-php-apache2" "$DOCROOT"
else
  exec php -S "0.0.0.0:${PORT:-8080}" -t "$DOCROOT"
fi
