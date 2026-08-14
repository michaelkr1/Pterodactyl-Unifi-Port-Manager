<?php

// Namespace required for Blueprint admin controllers -- {identifier} is
// substituted with this extension's identifier (unifisync) at install time.
namespace Pterodactyl\Http\Controllers\Admin\Extensions\{identifier};

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\RuleRecord;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\ServerSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\Settings;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\UnifiApiException;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support\UnifiClient;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\SyncService;

class {identifier}ExtensionController extends Controller
{
    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
    ) {
    }

    /** GET /admin/extensions/{identifier} */
    public function index(Request $request): View|JsonResponse
    {
        // Lightweight JSON branch used by the wrapper script on
        // /admin/servers/view/{id} to read a server's current preference --
        // this is not a real "page" of the extension, just a small API
        // surface reused off the same route so no new routing is needed.
        if ($request->query('action') === 'get_auto_configure') {
            $serverId = (int) $request->query('server_id');

            return response()->json([
                'auto_configure' => $serverId > 0 ? ServerSetting::autoConfigureEnabled($serverId) : true,
            ]);
        }

        $settings = Settings::current();
        [$classicSites, $v1Sites, $zones, $discoveryError] = $this->discover($settings);

        return $this->view->make('admin.extensions.{identifier}.index', [
            'root' => '/admin/extensions/{identifier}',
            'blueprint' => $this->blueprint,
            'settings' => $settings,
            'rows' => $this->buildRows(),
            'classicSites' => $classicSites,
            'v1Sites' => $v1Sites,
            'zones' => $zones,
            'discoveryError' => $discoveryError,
        ]);
    }

    /**
     * One row per actual rule, plus one placeholder row per server that has
     * auto-configure turned off -- those servers have no RuleRecord at all
     * (turning it off tears down and deletes any existing rows), so without
     * this they'd just disappear from the table instead of showing as
     * intentionally inactive.
     */
    private function buildRows(): \Illuminate\Support\Collection
    {
        $rules = RuleRecord::query()->orderByDesc('updated_at')->get();

        $disabledServerIds = ServerSetting::query()->where('auto_configure', false)->pluck('server_id');
        $disabledServers = Server::query()->whereIn('id', $disabledServerIds)->get(['id', 'uuid', 'name']);

        $serverNames = Server::query()
            ->whereIn('id', $rules->pluck('server_id')->unique())
            ->pluck('name', 'id');

        $rows = collect();

        foreach ($rules as $rule) {
            $rows->push((object) [
                'rule_id' => $rule->id,
                'server_id' => $rule->server_id,
                'server_uuid' => $rule->server_uuid,
                'server_name' => $serverNames[$rule->server_id] ?? null,
                'name' => $rule->name,
                'ip' => $rule->ip,
                'port' => $rule->port,
                'status' => $rule->status,
                'note' => $rule->last_error,
                'updated_at' => $rule->updated_at,
            ]);
        }

        foreach ($disabledServers as $server) {
            $rows->push((object) [
                'rule_id' => null,
                'server_id' => $server->id,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'name' => null,
                'ip' => null,
                'port' => null,
                'status' => 'inactive',
                'note' => 'Auto-configure disabled',
                'updated_at' => null,
            ]);
        }

        return $rows->sortBy(fn ($row) => $row->server_id)->values();
    }

    /**
     * Fetches the current site/zone lists live from UniFi on every page
     * load (whenever enough is configured to do so) instead of stashing a
     * one-shot result in the session -- the saved selections in `settings`
     * are the source of truth either way, this is just what populates the
     * dropdowns next to them. Never throws: a broken/unreachable controller
     * must not take down the page that's meant to help you fix it.
     *
     * @return array{0: array, 1: array, 2: array, 3: ?string}
     */
    private function discover(Settings $settings): array
    {
        $classicSites = [];
        $v1Sites = [];
        $zones = [];

        if (!filled($settings->host()) || !filled($settings->apiKey())) {
            return [$classicSites, $v1Sites, $zones, null];
        }

        $client = new UnifiClient($settings);

        try {
            $classicSites = $client->listSitesClassic();
            $v1Sites = $client->listSitesV1();

            if (filled($settings->v1SiteId())) {
                $zones = $client->listFirewallZones($settings->v1SiteId());
            }
        } catch (\Throwable $e) {
            return [$classicSites, $v1Sites, $zones, $e->getMessage()];
        }

        return [$classicSites, $v1Sites, $zones, null];
    }

    /** POST /admin/extensions/{identifier} -- every mutation funnels through here via an `action` field */
    public function post(Request $request): RedirectResponse
    {
        try {
            match ($request->input('action', 'save')) {
                'save' => $this->handleSave($request),
                'resync_all' => $this->handleResyncAll(),
                'remove_rule' => $this->handleRemoveRule($request),
                'set_auto_configure' => $this->handleSetAutoConfigure($request),
                'bulk_set_auto_configure' => $this->handleBulkSetAutoConfigure($request),
                default => null,
            };
        } catch (UnifiApiException $e) {
            $this->blueprint->alert('danger', 'UniFi API error (HTTP ' . $e->status . '): ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->blueprint->alert('danger', 'Error: ' . $e->getMessage());
        }

        return redirect('/admin/extensions/{identifier}');
    }

    private function handleSave(Request $request): void
    {
        $settings = Settings::current();
        $settings->fill([
            'enabled' => $request->boolean('enabled'),
            'host' => trim((string) $request->input('host')),
            'verify_tls' => $request->boolean('verify_tls'),
            'v1_site_id' => $request->input('v1_site_id') ?: $settings->v1_site_id,
            'classic_site_name' => $request->input('classic_site_name') ?: $settings->classic_site_name,
            'wan_zone_id' => $request->input('wan_zone_id') ?: $settings->wan_zone_id,
            'lan_zone_id' => $request->input('lan_zone_id') ?: $settings->lan_zone_id,
            'reconcile_interval_minutes' => max(1, (int) $request->input('reconcile_interval_minutes', 2)),
        ]);

        if (filled($request->input('api_key'))) {
            $settings->api_key = $request->input('api_key');
        }

        $settings->save();
        $this->blueprint->alert('success', 'Settings saved.');
    }

    private function handleResyncAll(): void
    {
        $result = (new SyncService())->reconcileAll();
        Settings::current()->markReconciled();
        $this->blueprint->alert('success', "Resync complete -- created={$result['created']} removed={$result['removed']} errored={$result['errored']}");
    }

    private function handleRemoveRule(Request $request): void
    {
        $rule = RuleRecord::query()->findOrFail($request->input('rule_id'));
        (new SyncService())->removeRule($rule);
        $this->blueprint->alert('success', 'Rule removed.');
    }

    /**
     * Hit via a background fetch() from the wrapper script -- either right
     * after a new server's creation (/admin/servers/new redirecting to its
     * view page), or from the toggle shown on an existing server's view
     * page. Both are fire-and-forget from the caller's side, so errors are
     * swallowed here rather than surfaced through $blueprint->alert() (which
     * would otherwise show up as a stray flash message on some unrelated
     * later page).
     */
    private function handleSetAutoConfigure(Request $request): void
    {
        $serverId = (int) $request->input('server_id');
        $server = $serverId > 0 ? Server::query()->find($serverId) : null;

        if ($server === null) {
            return;
        }

        $this->applyAutoConfigure($server, $request->boolean('auto_configure', true));
    }

    /** Bulk version of the above, driven by the Rules table's checkboxes + Mark Active/Inactive buttons. */
    private function handleBulkSetAutoConfigure(Request $request): void
    {
        $serverIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->input('server_ids', [])),
        )));

        if (empty($serverIds)) {
            $this->blueprint->alert('warning', 'No servers selected.');

            return;
        }

        $enabled = $request->boolean('auto_configure', true);
        $servers = Server::query()->whereIn('id', $serverIds)->get();

        foreach ($servers as $server) {
            $this->applyAutoConfigure($server, $enabled);
        }

        $this->blueprint->alert(
            'success',
            'Marked ' . $servers->count() . ' server(s) ' . ($enabled ? 'active' : 'inactive') . ' and resynced.',
        );
    }

    /**
     * Applies the change immediately -- flipping to "No" tears down any
     * existing rules right away, flipping to "Yes" creates them -- instead
     * of waiting for the next scheduled reconcile.
     */
    private function applyAutoConfigure(Server $server, bool $enabled): void
    {
        ServerSetting::query()->updateOrCreate(
            ['server_id' => $server->id],
            ['auto_configure' => $enabled],
        );

        try {
            (new SyncService())->reconcileServer($server);
        } catch (\Throwable $e) {
            // Best-effort -- the scheduled reconcile will retry, and any
            // per-rule failure is already visible in the Rules table.
        }
    }
}
