<?php

// Ticks every minute (see Console.yml) but only does real work once the
// admin-configured interval has elapsed -- see Settings::dueForScheduledReconcile().
// This is what actually catches allocation changes made through the admin
// panel's build form or the client API's allocation-delete endpoint, both of
// which don't fire Laravel events (see ServerEventSubscriber for the paths
// that do get instant treatment).

$settings = \Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\Settings::current();

if (!$settings->isActive()) {
    $this->line('unifisync: disabled or not fully configured, skipping.');
    return;
}

if (!$settings->dueForScheduledReconcile()) {
    return;
}

try {
    $result = (new \Pterodactyl\BlueprintFramework\Extensions\{identifier}\SyncService())->reconcileAll();
    $settings->markReconciled();

    $this->line("unifisync: created={$result['created']} removed={$result['removed']} errored={$result['errored']}");
} catch (\Throwable $e) {
    $this->error('unifisync: reconcile failed -- ' . $e->getMessage());
    report($e);
}
