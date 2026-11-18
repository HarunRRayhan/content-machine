#!/bin/sh
# Consume the media-backed queues on cm-web only. The default-connection
# scratchpad worker handles Telegram photo/voice capture and transcription;
# the dedicated postsyncer worker handles post publishes. The uploads volume
# is mounted only on cm-web; cm-worker has no access to it. The base entrypoint
# marks its /init web command with SERVERSIDEUP_DEFAULT_COMMAND=true;
# SCRATCHPAD_QUEUE_WORKER=0 can opt out of both workers.
set -eu

if [ "${SERVERSIDEUP_DEFAULT_COMMAND:-false}" != "true" ] || [ "${SCRATCHPAD_QUEUE_WORKER:-1}" != "1" ]; then
    exit 0
fi

# Register the long-running worker with the web image's s6 init. s6 owns
# restart and shutdown; this entrypoint script only selects the web service.
touch /etc/s6-overlay/s6-rc.d/user/contents.d/scratchpad-queue
touch /etc/s6-overlay/s6-rc.d/user/contents.d/postsyncer-queue
