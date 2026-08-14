<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-server override of whether UniFi Port Sync should manage this
 * server's allocations at all. No row = auto-configure is on (the default);
 * a row only exists once something -- the create-server page's dropdown, or
 * a future admin toggle -- explicitly records a choice.
 */
class ServerSetting extends Model
{
    protected $table = 'unifisync_server_settings';

    protected $fillable = ['server_id', 'auto_configure'];

    protected $casts = [
        'auto_configure' => 'boolean',
    ];

    public static function autoConfigureEnabled(int $serverId): bool
    {
        $row = static::query()->where('server_id', $serverId)->first();

        return $row === null ? true : (bool) $row->auto_configure;
    }
}
