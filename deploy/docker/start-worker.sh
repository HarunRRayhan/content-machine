#!/bin/sh

set -eu

cd /var/www/html

exec docker-php-serversideup-entrypoint supervisord -c /etc/supervisor/conf.d/supervisord.conf
