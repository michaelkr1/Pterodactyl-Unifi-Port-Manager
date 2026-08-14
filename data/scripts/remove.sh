#!/bin/bash

# Reverts the one line install.sh added to EventServiceProvider.php.
#
# Note: this does NOT delete any port-forward/firewall rules already created
# in UniFi -- uninstalling the extension shouldn't silently tear down live
# port forwards. Use the "Resync all now" / per-row "Remove" buttons on the
# admin page beforehand if you want a clean slate in UniFi too.

EVENT_PROVIDER="$PTERODACTYL_DIRECTORY/app/Providers/EventServiceProvider.php"

if [ ! -f "$EVENT_PROVIDER" ]; then
  exit 0
fi

sed -i '/ServerEventSubscriber::class/d' "$EVENT_PROVIDER"
