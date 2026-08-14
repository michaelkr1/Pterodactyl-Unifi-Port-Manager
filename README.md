# UniFi Port Sync

A [Blueprint](https://blueprint.zip) extension for [Pterodactyl](https://pterodactyl.io) that
automatically keeps UniFi port-forward rules and Zone-Based Firewall policies in sync with your
servers' network allocations.

Assign a server the allocation `192.168.1.10:8000` and this extension creates:

- a UniFi port-forward rule: WAN → `192.168.1.10:8000` (TCP + UDP)
- a UniFi firewall policy allowing that inbound traffic

Both are removed automatically when the allocation is unassigned or the server is deleted.

## How it stays in sync

- **Instant**: a server finishing installation, its primary allocation changing, or the server
  being deleted all fire Pterodactyl events this extension listens for directly.
- **Within ~1 interval (default 2 min)**: allocations added/removed after the fact through the
  admin panel's "build" form or the client API don't fire Laravel events in Pterodactyl core, so
  a scheduled `php artisan unifisync:reconcile` (configurable interval, admin page) diffs every
  server's actual allocations against UniFi and fixes any drift. This is also what recovers from
  any transient UniFi API failure.

Nothing is hardcoded: controller host, API key, site, WAN/LAN zone, and the sync interval are
all set from the extension's admin page. It ships disabled and inert until configured.

## Requirements

- Pterodactyl + [Blueprint](https://blueprint.zip/docs) installed.
- A UniFi OS console (UDM/UCG/Cloud Gateway) running **Zone-Based Firewall**, with a local API
  key generated under **Settings → Control Plane → Integrations**.

## Building / installing

```bash
blueprint -init      # scaffold .blueprint/dev from this source
blueprint -build      # install/update the dev copy on your panel, like a real install
blueprint -watch       # optional: auto-rebuild on file changes while developing
blueprint -export       # package into unifisync.blueprint for distribution
```

## First-time setup (admin page)

Open **Admin → Extensions → UniFi Port Sync** and work through the page top to bottom:

1. **Controller connection** — host (no scheme/port, e.g. `192.168.1.1`), API key, whether to
   verify the TLS cert (leave off for a self-signed local console). Save.
2. **Site** — click "Discover sites", then pick the matching site in both dropdowns (classic API
   site + integration API site — these use different IDs internally) and save.
3. **Firewall zones** — click "Load zones", pick your WAN/External and LAN/Internal zones, save.
4. Tick **Enabled**.

The "Managed rules" table at the bottom shows every rule the extension has created, with a
manual **Resync all now** button and a per-row **Remove** button.

## Known rough edge: firewall policy payload

UniFi's official firewall-policy API
(`POST /v1/sites/{siteId}/firewall/policies`, see [developer.ui.com](https://developer.ui.com/network))
uses a discriminated `trafficFilter` object on the policy's `source`/`destination` whose exact
shape for "this specific destination IP *and* this specific port" couldn't be fully confirmed
from the published spec while building this. `data/app/Support/UnifiClient.php`'s
`buildFirewallPolicyPayload()` is a best-effort construction from the confirmed parts of the
schema (`action`, `enabled`, `name`, `source.zoneId`, `destination.zoneId`, and the
`IP_ADDRESS`/`PORT` filter types). It's isolated in that one method on purpose: if your
controller rejects the payload, the port-forward side still succeeds (they're independent API
calls), the failure is captured per-rule with the real error message from UniFi in the admin
page's "Last error" column, and fixing it is a one-method change.

## Uninstalling

`blueprint -remove` reverts the single line it added to Pterodactyl's `EventServiceProvider.php`
but **does not** delete rules already created in UniFi — that's a deliberate choice so removing
the extension can't silently take down live port forwards. Use "Resync all now" after removing
every server you want cleaned up (or the per-row Remove buttons) before uninstalling if you want
UniFi left empty.

## Project layout

```
conf.yml                       extension metadata + bindings
admin/view.blade.php           settings page
admin/controller.php           settings page logic (Pterodactyl\Http\Controllers\Admin\Extensions\unifisync)
data/app/                      requests.app -> Pterodactyl\BlueprintFramework\Extensions\unifisync
  Support/Settings.php           typed settings (Eloquent-backed, encrypted API key)
  Support/UnifiClient.php        UniFi API client (classic + official v1 endpoints)
  Support/RuleRecord.php         local record of what's been created in UniFi
  SyncService.php                diff-and-apply reconciliation logic
  Listeners/ServerEventSubscriber.php   Installed/Updated/Deleted event handling
data/console/                  data.console -> `php artisan unifisync:reconcile`
data/migrations/               unifisync_rules + unifisync_settings tables
data/scripts/                  install.sh / update.sh / remove.sh (EventServiceProvider patch)
```
