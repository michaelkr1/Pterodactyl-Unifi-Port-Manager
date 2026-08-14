<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\RuleRecord;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\ServerSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\Settings;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\UnifiApiException;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\UnifiClient;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;

/**
 * Keeps UniFi port-forward + firewall-policy rules in sync with whichever
 * allocations are actually attached to each Pterodactyl server. Everything
 * here is idempotent and safe to call repeatedly -- it diffs desired state
 * (the server's current allocations) against unifisync_rules (what we
 * believe we've already created in UniFi) rather than assuming it's only
 * ever called once per change.
 */
class SyncService
{
    private Settings $settings;
    private UnifiClient $client;

    public function __construct()
    {
        $this->settings = Settings::current();
        $this->client = new UnifiClient($this->settings);
    }

    /** @return array{created: int, removed: int, errored: int} */
    public function reconcileServer(Server $server): array
    {
        $summary = ['created' => 0, 'removed' => 0, 'errored' => 0];

        if (!$this->settings->isActive()) {
            return $summary;
        }

        $server->loadMissing('allocations');

        // Per-server opt-out (set from the create-server page's dropdown, or
        // absent entirely -- absent means "yes", the default). Treating it
        // as "nothing desired" reuses the normal teardown path below, so
        // flipping this off after the fact also cleans up any existing rules.
        $desired = ServerSetting::autoConfigureEnabled($server->id)
            ? $server->allocations->keyBy('id')
            : collect();

        $existing = RuleRecord::query()->where('server_id', $server->id)->get()->keyBy('allocation_id');

        foreach ($existing as $allocationId => $rule) {
            if (!$desired->has($allocationId)) {
                $this->teardownRule($rule);
                $summary['removed']++;
            }
        }

        foreach ($desired as $allocationId => $allocation) {
            $existingRule = $existing->get($allocationId);

            // A row already existing isn't "done" by itself -- only skip it
            // once it's actually active in UniFi. Anything left in `error`
            // (e.g. a rejected payload, a transient API failure) gets
            // retried on every reconcile until it succeeds or is removed.
            if ($existingRule !== null && $existingRule->status === 'active') {
                continue;
            }

            try {
                if ($this->createRule($server, $allocation)) {
                    $summary['created']++;
                } else {
                    $summary['errored']++;
                }
            } catch (\Throwable $e) {
                // Only truly unexpected failures (not a rejected API call --
                // createRule handles those itself) land here.
                $this->recordError($server, $allocation, $e);
                $summary['errored']++;
            }
        }

        return $summary;
    }

    /** @return array{removed: int, errored: int} */
    public function removeServer(int $serverId): array
    {
        $summary = ['removed' => 0, 'errored' => 0];

        foreach (RuleRecord::query()->where('server_id', $serverId)->get() as $rule) {
            try {
                $this->teardownRule($rule);
                $summary['removed']++;
            } catch (\Throwable $e) {
                $summary['errored']++;
            }
        }

        ServerSetting::query()->where('server_id', $serverId)->delete();

        return $summary;
    }

    public function removeRule(RuleRecord $rule): void
    {
        $this->teardownRule($rule);
    }

    /** @return array{created: int, removed: int, errored: int} */
    public function reconcileAll(): array
    {
        $totals = ['created' => 0, 'removed' => 0, 'errored' => 0];

        if (!$this->settings->isActive()) {
            return $totals;
        }

        foreach (Server::query()->with('allocations')->get() as $server) {
            $result = $this->reconcileServer($server);
            $totals['created'] += $result['created'];
            $totals['removed'] += $result['removed'];
            $totals['errored'] += $result['errored'];
        }

        // Belt-and-braces: clean up rules (and preferences) left behind for
        // servers that no longer exist at all, in case a Deleted event was
        // ever missed -- also covers rows orphaned before this cleanup
        // existed, e.g. servers deleted with an older extension version.
        $existingServerIds = Server::query()->pluck('id');
        $orphans = RuleRecord::query()->whereNotIn('server_id', $existingServerIds)->get();
        foreach ($orphans as $rule) {
            try {
                $this->teardownRule($rule);
                $totals['removed']++;
            } catch (\Throwable $e) {
                $totals['errored']++;
            }
        }

        ServerSetting::query()->whereNotIn('server_id', $existingServerIds)->delete();

        return $totals;
    }

    /**
     * Creates whatever's still missing for this allocation and returns
     * whether it ended up fully active. Resumable: if a prior attempt
     * already created the port-forward (or the firewall policy) but failed
     * on the other half, this reuses the stored ID instead of creating a
     * duplicate -- re-creating an already-existing port-forward is exactly
     * what caused UniFi's api.err.PortForwardOverlaps on retry before this
     * fix.
     */
    private function createRule(Server $server, Allocation $allocation): bool
    {
        $name = $this->ruleName($server);
        $existing = RuleRecord::query()->where('allocation_id', $allocation->id)->first();

        $attributes = [
            'server_id' => $server->id,
            'server_uuid' => $server->uuid,
            'name' => $name,
            'ip' => $allocation->ip,
            'port' => $allocation->port,
            'unifi_portforward_id' => $existing->unifi_portforward_id ?? null,
            'unifi_firewall_policy_id' => $existing->unifi_firewall_policy_id ?? null,
        ];

        $lastError = null;

        if ($attributes['unifi_portforward_id'] === null) {
            try {
                $portForward = $this->client->createPortForward(
                    $this->client->buildPortForwardPayload($name, $allocation->ip, $allocation->port)
                );
                $attributes['unifi_portforward_id'] = $portForward['_id'] ?? null;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        if ($lastError === null && $attributes['unifi_firewall_policy_id'] === null) {
            try {
                $policy = $this->client->createFirewallPolicy(
                    $this->settings->v1SiteId(),
                    $this->client->buildFirewallPolicyPayload(
                        $name,
                        "Managed by Pterodactyl UniFi Port Sync -- server #{$server->id}",
                        $allocation->ip,
                        $allocation->port,
                    )
                );
                $attributes['unifi_firewall_policy_id'] = $policy['id'] ?? null;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $ok = $lastError === null;
        $attributes['status'] = $ok ? 'active' : 'error';
        $attributes['last_error'] = $lastError;

        RuleRecord::query()->updateOrCreate(['allocation_id' => $allocation->id], $attributes);

        return $ok;
    }

    private function recordError(Server $server, Allocation $allocation, \Throwable $e): void
    {
        RuleRecord::query()->updateOrCreate(
            ['allocation_id' => $allocation->id],
            [
                'server_id' => $server->id,
                'server_uuid' => $server->uuid,
                'name' => $this->ruleName($server),
                'ip' => $allocation->ip,
                'port' => $allocation->port,
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ],
        );
    }

    private function teardownRule(RuleRecord $rule): void
    {
        if ($rule->unifi_portforward_id) {
            try {
                $this->client->deletePortForward($rule->unifi_portforward_id);
            } catch (UnifiApiException $e) {
                // Already gone on the UniFi side (or unreachable) -- proceed
                // with local cleanup regardless so we don't get stuck.
            }
        }

        if ($rule->unifi_firewall_policy_id) {
            try {
                $this->client->deleteFirewallPolicy($this->settings->v1SiteId(), $rule->unifi_firewall_policy_id);
            } catch (UnifiApiException $e) {
                // Same as above.
            }
        }

        $rule->delete();
    }

    /**
     * The port itself is already a visible column in UniFi's port-forward
     * list, so it isn't repeated here. A server with multiple allocations
     * ends up with multiple rules sharing this same name -- fine, since
     * nothing in UniFi treats the name as a unique key (only the port is).
     */
    private function ruleName(Server $server): string
    {
        $clean = trim(preg_replace('/[^A-Za-z0-9 ._-]/', '', $server->name));

        return 'pterodactyl-' . ($clean !== '' ? $clean : $server->uuid);
    }
}
