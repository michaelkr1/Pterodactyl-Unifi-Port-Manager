#!/bin/bash

# Blueprint runs the *pre-update* extension's update.sh before the new
# version's files land and install.sh runs again -- so this just needs to
# make sure the event-subscriber registration survives an upgrade. Same
# idempotent check as install.sh (safe no-op if already present, and the
# following install.sh run will handle a fresh registration either way).

EVENT_PROVIDER="$PTERODACTYL_DIRECTORY/app/Providers/EventServiceProvider.php"
MARKER="ServerEventSubscriber::class"
ANCHOR="AuthenticationListener::class,"

if [ ! -f "$EVENT_PROVIDER" ]; then
  exit 0
fi

if grep -qF "$MARKER" "$EVENT_PROVIDER"; then
  exit 0
fi

LINE_NUM=$(grep -nF "$ANCHOR" "$EVENT_PROVIDER" | head -n1 | cut -d: -f1)

if [ -z "$LINE_NUM" ]; then
  exit 0
fi

NEWLINE_FILE=$(mktemp)
printf '%s\n' '        \Pterodactyl\BlueprintFramework\Extensions\{identifier}\Listeners\ServerEventSubscriber::class,' > "$NEWLINE_FILE"
sed -i "${LINE_NUM}r ${NEWLINE_FILE}" "$EVENT_PROVIDER"
rm -f "$NEWLINE_FILE"
