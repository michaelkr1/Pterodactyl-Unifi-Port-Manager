{{-- Blueprint wraps this fragment in layouts.admin itself (sidebar, topbar,
     page title from conf.yml's info.name) -- this must stay a plain content
     fragment, not @extends a layout, or the whole page renders twice. --}}
    <div class="row">
        <div class="col-xs-12">
            <div class="box {{ $settings->isActive() ? 'box-success' : 'box-warning' }}">
                <div class="box-header with-border">
                    <h3 class="box-title">Status</h3>
                </div>
                <div class="box-body">
                    @if ($settings->isActive())
                        <p><i class="fa fa-check-circle text-green"></i> Enabled and fully configured.</p>
                    @elseif ($settings->isEnabled())
                        <p><i class="fa fa-exclamation-triangle text-yellow"></i> Enabled, but not fully configured yet -- finish the steps below.</p>
                    @else
                        <p><i class="fa fa-power-off text-red"></i> Disabled. Nothing will be created or removed in UniFi until you enable it below.</p>
                    @endif
                    @if ($settings->last_reconciled_at)
                        <p class="text-muted">Last scheduled reconcile: {{ $settings->last_reconciled_at->diffForHumans() }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">1. Controller connection</h3>
                </div>
                <form action="{{ $root }}" method="POST">
                    @csrf
                    <input type="hidden" name="action" value="save">
                    <div class="box-body">
                        <div class="form-group">
                            <label>Controller host</label>
                            <input type="text" name="host" class="form-control" placeholder="192.168.1.1" value="{{ old('host', $settings->host()) }}">
                            <p class="text-muted small">IP or hostname of your UniFi OS console (UDM/UCG/Cloud Gateway). No scheme, no port.</p>
                        </div>
                        <div class="form-group">
                            <label>API key</label>
                            <input type="password" name="api_key" class="form-control" placeholder="{{ $settings->apiKey() ? '•••••••••••••• (unchanged)' : 'Local API key' }}">
                            <p class="text-muted small">Generated on the console under Settings &gt; Control Plane &gt; Integrations. Leave blank to keep the current key. Stored encrypted.</p>
                        </div>
                        <div class="form-group">
                            <div class="checkbox checkbox-primary no-margin-bottom">
                                <input id="unifisyncVerifyTls" name="verify_tls" type="checkbox" value="1" {{ $settings->verifyTls() ? 'checked' : '' }}>
                                <label for="unifisyncVerifyTls" class="strong">Verify TLS certificate</label>
                            </div>
                            <p class="text-muted small">Leave unchecked for consoles using their default self-signed certificate.</p>
                        </div>
                        <div class="form-group">
                            <div class="checkbox checkbox-primary no-margin-bottom">
                                <input id="unifisyncEnabled" name="enabled" type="checkbox" value="1" {{ $settings->isEnabled() ? 'checked' : '' }}>
                                <label for="unifisyncEnabled" class="strong">Enabled</label>
                            </div>
                            <p class="text-muted small">Turns rule creation/removal on. Leave off while you finish setup below.</p>
                        </div>
                        <div class="form-group">
                            <label>Reconcile interval (minutes)</label>
                            <input type="number" name="reconcile_interval_minutes" class="form-control" min="1" step="1" style="max-width:120px;" value="{{ $settings->reconcileIntervalMinutes() }}">
                            <p class="text-muted small">How often the scheduled background sync re-checks every server's allocations against UniFi. Allocation changes made through the admin build form, client API, or a node migration are only picked up on this schedule (or immediately via "Resync all now" below).</p>
                        </div>
                        {{-- Preserve site/zone selections made in step 2/3 so saving step 1 doesn't clear them --}}
                        <input type="hidden" name="v1_site_id" value="{{ $settings->v1SiteId() }}">
                        <input type="hidden" name="classic_site_name" value="{{ $settings->classicSiteName() }}">
                        <input type="hidden" name="wan_zone_id" value="{{ $settings->wanZoneId() }}">
                        <input type="hidden" name="lan_zone_id" value="{{ $settings->lanZoneId() }}">
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary">Save connection settings</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-info">
                <div class="box-header with-border">
                    <h3 class="box-title">2. Site</h3>
                    <div class="box-tools">
                        <a href="{{ $root }}" class="btn btn-default btn-xs">Refresh</a>
                    </div>
                </div>
                <div class="box-body">
                    @if ($discoveryError)
                        <p class="text-red small">Couldn't reach the controller: {{ $discoveryError }}</p>
                    @elseif (!count($classicSites) && !count($v1Sites))
                        <p class="text-muted small">Save your host + API key first -- sites load automatically once a connection is configured.</p>
                    @endif

                    <form action="{{ $root }}" method="POST">
                        @csrf
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="host" value="{{ $settings->host() }}">
                        <input type="hidden" name="verify_tls" value="{{ $settings->verifyTls() ? 1 : 0 }}">
                        <input type="hidden" name="enabled" value="{{ $settings->isEnabled() ? 1 : 0 }}">
                        <input type="hidden" name="wan_zone_id" value="{{ $settings->wanZoneId() }}">
                        <input type="hidden" name="lan_zone_id" value="{{ $settings->lanZoneId() }}">
                        <input type="hidden" name="reconcile_interval_minutes" value="{{ $settings->reconcileIntervalMinutes() }}">

                        <div class="form-group">
                            <label>Site (classic API -- used for port forwarding)</label>
                            <select name="classic_site_name" class="form-control">
                                <option value="">-- select --</option>
                                @foreach ($classicSites as $site)
                                    <option value="{{ $site['name'] ?? '' }}" {{ $settings->classicSiteName() === ($site['name'] ?? null) ? 'selected' : '' }}>
                                        {{ $site['desc'] ?? $site['name'] ?? 'unknown' }} ({{ $site['name'] ?? '?' }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Site (integration API -- used for firewall policies)</label>
                            <select name="v1_site_id" class="form-control">
                                <option value="">-- select --</option>
                                @foreach ($v1Sites as $site)
                                    <option value="{{ $site['id'] ?? '' }}" {{ $settings->v1SiteId() === ($site['id'] ?? null) ? 'selected' : '' }}>
                                        {{ $site['name'] ?? ($site['id'] ?? 'unknown') }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-muted small">Pick the entry matching the same site you selected above -- they're usually listed in the same order.</p>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Save site</button>
                    </form>
                </div>
            </div>

            <div class="box box-info">
                <div class="box-header with-border">
                    <h3 class="box-title">3. Firewall zones</h3>
                    <div class="box-tools">
                        <a href="{{ $root }}" class="btn btn-default btn-xs">Refresh</a>
                    </div>
                </div>
                <div class="box-body">
                    @if (!$discoveryError && filled($settings->v1SiteId()) && !count($zones))
                        <p class="text-muted small">No firewall zones came back for the selected site.</p>
                    @elseif (!$discoveryError && !filled($settings->v1SiteId()))
                        <p class="text-muted small">Save your site selection first -- zones load automatically once a site is set.</p>
                    @endif

                    <form action="{{ $root }}" method="POST">
                        @csrf
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="host" value="{{ $settings->host() }}">
                        <input type="hidden" name="verify_tls" value="{{ $settings->verifyTls() ? 1 : 0 }}">
                        <input type="hidden" name="enabled" value="{{ $settings->isEnabled() ? 1 : 0 }}">
                        <input type="hidden" name="v1_site_id" value="{{ $settings->v1SiteId() }}">
                        <input type="hidden" name="classic_site_name" value="{{ $settings->classicSiteName() }}">
                        <input type="hidden" name="reconcile_interval_minutes" value="{{ $settings->reconcileIntervalMinutes() }}">

                        <div class="form-group">
                            <label>WAN / External zone</label>
                            <select name="wan_zone_id" class="form-control">
                                <option value="">-- select --</option>
                                @foreach ($zones as $zone)
                                    <option value="{{ $zone['id'] ?? '' }}" {{ $settings->wanZoneId() === ($zone['id'] ?? null) ? 'selected' : '' }}>
                                        {{ $zone['name'] ?? 'unknown' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>LAN / Internal zone</label>
                            <select name="lan_zone_id" class="form-control">
                                <option value="">-- select --</option>
                                @foreach ($zones as $zone)
                                    <option value="{{ $zone['id'] ?? '' }}" {{ $settings->lanZoneId() === ($zone['id'] ?? null) ? 'selected' : '' }}>
                                        {{ $zone['name'] ?? 'unknown' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Save zones</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Rules</h3>
                    <div class="box-tools">
                        <form id="unifisyncBulkForm" action="{{ $root }}" method="POST" style="display:inline">
                            @csrf
                            <input type="hidden" name="action" value="bulk_set_auto_configure">
                            <input type="hidden" name="auto_configure" id="unifisyncBulkValue" value="">
                            <span id="unifisyncBulkServerIds"></span>
                            <button type="submit" class="btn btn-sm btn-success" onclick="return unifisyncPrepareBulk('1')">Mark Active</button>
                            <button type="submit" class="btn btn-sm btn-default" onclick="return unifisyncPrepareBulk('0')">Mark Inactive</button>
                        </form>
                        <form action="{{ $root }}" method="POST" style="display:inline">
                            @csrf
                            <input type="hidden" name="action" value="resync_all">
                            <button type="submit" class="btn btn-sm btn-primary">Resync all now</button>
                        </form>
                    </div>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                        <tr>
                            <th><input type="checkbox" id="unifisyncSelectAll"></th>
                            <th>Server</th>
                            <th>Rule name</th>
                            <th>IP : Port</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th>Updated</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td><input type="checkbox" class="unifisync-row-select" value="{{ $row->server_id }}"></td>
                                <td>
                                    <a href="/admin/servers/view/{{ $row->server_id }}">{{ $row->server_name ?? ('Server #' . $row->server_id) }}</a>
                                    <span class="text-muted">{{ substr($row->server_uuid, 0, 8) }}</span>
                                </td>
                                <td>{!! $row->name ? '<code>'.e($row->name).'</code>' : '<span class="text-muted">&mdash;</span>' !!}</td>
                                <td>{!! $row->ip ? e("{$row->ip}:{$row->port}") : '<span class="text-muted">&mdash;</span>' !!}</td>
                                <td>
                                    @if ($row->status === 'active')
                                        <span class="label label-success">active</span>
                                    @elseif ($row->status === 'inactive')
                                        <span class="label label-default">inactive</span>
                                    @else
                                        <span class="label label-danger">error</span>
                                    @endif
                                </td>
                                <td class="text-muted small">{{ $row->note }}</td>
                                <td>{{ $row->updated_at?->diffForHumans() }}</td>
                                <td>
                                    @if ($row->rule_id)
                                        <form action="{{ $root }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="action" value="remove_rule">
                                            <input type="hidden" name="rule_id" value="{{ $row->rule_id }}">
                                            <button type="submit" class="btn btn-xs btn-danger">Remove</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted">No servers yet.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <script>
            (function () {
                var selectAll = document.getElementById('unifisyncSelectAll');
                if (selectAll) {
                    selectAll.addEventListener('change', function () {
                        var checked = this.checked;
                        document.querySelectorAll('.unifisync-row-select').forEach(function (cb) {
                            cb.checked = checked;
                        });
                    });
                }

                window.unifisyncPrepareBulk = function (value) {
                    var selected = document.querySelectorAll('.unifisync-row-select:checked');
                    if (selected.length === 0) {
                        alert('Select at least one server first.');
                        return false;
                    }

                    document.getElementById('unifisyncBulkValue').value = value;

                    var container = document.getElementById('unifisyncBulkServerIds');
                    container.innerHTML = '';
                    selected.forEach(function (cb) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'server_ids[]';
                        input.value = cb.value;
                        container.appendChild(input);
                    });

                    return true;
                };
            })();
            </script>
        </div>
    </div>
