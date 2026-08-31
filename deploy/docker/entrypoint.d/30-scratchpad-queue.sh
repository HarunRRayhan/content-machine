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

# Register the long-running worker with the web image's s6 init. s6 owns
# restart and shutdown; this entrypoint script only selects the web service.
touch /etc/s6-overlay/s6-rc.d/user/contents.d/scratchpad-queue
