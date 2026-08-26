#!/bin/sh
# Railway mounts the persistent volume at storage/app/uploads as root:root.
# PHP-FPM and the queue worker run as www-data and must be able to mkdir
# workspace folders under that path. This script is a no-op when the
# process is not root (local `php artisan serve`, image USER www-data).
set -eu

UPLOADS="${APP_BASE_DIR:-/var/www/html}/storage/app/uploads"

mkdir -p "$UPLOADS"

if [ "$(id -u)" = "0" ]; then
    chown www-data:www-data "$UPLOADS"
    chmod 0775 "$UPLOADS"
fi
