#!/bin/bash

# Registers Pterodactyl\BlueprintFramework\Extensions\{identifier}\Listeners\ServerEventSubscriber with Pterodactyl's
# EventServiceProvider so Server Installed/Updated/Deleted events reach
# SyncService near-instantly. This is the extension's only core-file patch;
# everything else lives entirely in Blueprint-owned files. See remove.sh for
# the matching revert and Console.yml's `reconcile` command for the
# scheduled safety net that covers changes made through paths that don't
# fire these events at all.
#
# Inserted as its own array line (not appended onto the anchor line) via
# sed's `r` (read file) command, which splices file content in verbatim --
# this deliberately avoids sed's `s///` replacement-text escape rules for
# backslashes, which are not consistent enough across sed builds to trust
# with a namespace string full of backslashes.

EVENT_PROVIDER="$PTERODACTYL_DIRECTORY/app/Providers/EventServiceProvider.php"
MARKER="ServerEventSubscriber::class"
ANCHOR="AuthenticationListener::class,"

if [ ! -f "$EVENT_PROVIDER" ]; then
  echo "unifisync: could not find $EVENT_PROVIDER, skipping event registration (you'll need to rely on the scheduled reconcile command only)."
  exit 0
fi

if grep -qF "$MARKER" "$EVENT_PROVIDER"; then
  echo "unifisync: event subscriber already registered, skipping."
  exit 0
fi

LINE_NUM=$(grep -nF "$ANCHOR" "$EVENT_PROVIDER" | head -n1 | cut -d: -f1)

if [ -z "$LINE_NUM" ]; then
  echo "unifisync: expected anchor line not found in EventServiceProvider.php, skipping event registration (you'll need to rely on the scheduled reconcile command only)."
  exit 0
fi

NEWLINE_FILE=$(mktemp)
printf '%s\n' '        \Pterodactyl\BlueprintFramework\Extensions\{identifier}\Listeners\ServerEventSubscriber::class,' > "$NEWLINE_FILE"
sed -i "${LINE_NUM}r ${NEWLINE_FILE}" "$EVENT_PROVIDER"
rm -f "$NEWLINE_FILE"

echo "unifisync: registered event subscriber in EventServiceProvider.php"
