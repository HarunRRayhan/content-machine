#!/bin/sh

set -eu

if [ "${SERVERSIDEUP_DEFAULT_COMMAND:-false}" != "true" ]; then
    exit 0
fi

case "${CM_SYNC_PRESENTATION_LIBRARY:-true}" in
    0|false|FALSE|no|NO)
        exit 0
        ;;
esac

cd "${APP_BASE_DIR:-/var/www/html}"
php artisan cm:sync-presentation-library --allow-empty

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data "${APP_BASE_DIR:-/var/www/html}/storage/app/uploads"
fi
