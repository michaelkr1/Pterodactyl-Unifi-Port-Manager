<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Listeners;

use Illuminate\Events\Dispatcher;
use Pterodactyl\Events\Server\Deleted;
use Pterodactyl\Events\Server\Installed;
use Pterodactyl\Events\Server\Updated;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\SyncService;

/**
 * Registered via a single line inserted into
 * app/Providers/EventServiceProvider.php's `$subscribe` array (see
 * data/scripts/install.sh) -- the same mechanism Pterodactyl core already
 * uses for its own subscribers.
 *
 * `Installed` (not `Created`) is used for the initial-creation path: it
 * fires once the daemon has actually finished provisioning the server, by
 * which point the creation transaction -- allocations included -- has long
 * since committed. `Created` fires mid-transaction, before allocations are
 * attached, and would need extra handling to be useful here.
 */
class ServerEventSubscriber
{
    public function handleServerReady(Installed|Updated $event): void
    {
        try {
            (new SyncService())->reconcileServer($event->server);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function handleServerDeleted(Deleted $event): void
    {
        try {
            (new SyncService())->removeServer($event->server->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Installed::class => 'handleServerReady',
            Updated::class => 'handleServerReady',
            Deleted::class => 'handleServerDeleted',
        ];
    }
}
