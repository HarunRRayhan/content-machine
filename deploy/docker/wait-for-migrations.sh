#!/bin/sh

set -eu

cd /var/www/html

attempt=0
while ! php artisan cm:assert-migrations-ready --quiet; do
    attempt=$((attempt + 1))

    if [ "$attempt" -ge 60 ]; then
        echo 'Timed out waiting for web migrations to complete.' >&2
        exit 1
    fi

    sleep 5
done
