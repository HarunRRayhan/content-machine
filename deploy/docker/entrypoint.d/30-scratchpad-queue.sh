#!/bin/sh
# Consume the "scratchpad" queue on cm-web only. That queue owns jobs that
# read/write storage/app/uploads (Telegram photo/voice capture, voice
# transcription). The uploads volume is mounted only on cm-web; cm-worker
# has no access to it. Set SCRATCHPAD_QUEUE_WORKER=1 on the web service
# (and leave it unset on the worker) so this process starts here only.
set -eu

if [ "${SCRATCHPAD_QUEUE_WORKER:-0}" != "1" ]; then
    exit 0
fi

# Backgrounded under the image entrypoint: the web process tree is
# managed by /init (nginx + php-fpm). A dedicated s6 unit would be
# cleaner long-term; this keeps the web start command unchanged.
if [ "$(id -u)" = "0" ]; then
    su -s /bin/sh www-data -c \
        'php /var/www/html/artisan queue:work --queue=scratchpad --tries=3 --backoff=10,60,300 --max-time=3600 --sleep=1 --rest=0.5' \
        >/proc/1/fd/1 2>/proc/1/fd/2 &
else
    php /var/www/html/artisan queue:work --queue=scratchpad --tries=3 --backoff=10,60,300 --max-time=3600 --sleep=1 --rest=0.5 \
        >/proc/1/fd/1 2>/proc/1/fd/2 &
fi
