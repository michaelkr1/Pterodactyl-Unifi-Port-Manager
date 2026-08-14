<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Settings extends Model
{
    protected $table = 'unifisync_settings';

    protected $fillable = [
        'enabled',
        'host',
        'verify_tls',
        'api_key',
        'v1_site_id',
        'classic_site_name',
        'wan_zone_id',
        'lan_zone_id',
        'reconcile_interval_minutes',
        'last_reconciled_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'verify_tls' => 'boolean',
        'last_reconciled_at' => 'datetime',
    ];

    /**
     * There is only ever one row: the extension's single settings record.
     * Created on first access so a fresh install starts fully unconfigured.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    public function setApiKeyAttribute(?string $value): void
    {
        $this->attributes['api_key'] = $value === null || $value === ''
            ? null
            : Crypt::encryptString($value);
    }

    public function getApiKeyAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function isEnabled(): bool
    {
        return (bool) $this->enabled;
    }

    public function host(): ?string
    {
        return $this->host;
    }

    public function verifyTls(): bool
    {
        return (bool) $this->verify_tls;
    }

    public function apiKey(): ?string
    {
        return $this->api_key;
    }

    public function v1SiteId(): ?string
    {
        return $this->v1_site_id;
    }

    public function classicSiteName(): ?string
    {
        return $this->classic_site_name;
    }

    public function wanZoneId(): ?string
    {
        return $this->wan_zone_id;
    }

    public function lanZoneId(): ?string
    {
        return $this->lan_zone_id;
    }

    /**
     * The scheduled console command ticks every minute (see Console.yml) but
     * only actually does work once this many minutes have passed since the
     * last run -- keeps the effective sync interval admin-configurable
     * without needing to rebuild the extension to change a fixed cron entry.
     */
    public function reconcileIntervalMinutes(): int
    {
        return max(1, (int) ($this->reconcile_interval_minutes ?? 2));
    }

    public function dueForScheduledReconcile(): bool
    {
        if ($this->last_reconciled_at === null) {
            return true;
        }

        return $this->last_reconciled_at->diffInMinutes(now()) >= $this->reconcileIntervalMinutes();
    }

    public function markReconciled(): void
    {
        $this->forceFill(['last_reconciled_at' => now()])->save();
    }

    /**
     * Whether enough is configured to actually talk to a controller
     * (independent of the `enabled` toggle, which is a separate kill switch).
     */
    public function isConfigured(): bool
    {
        return filled($this->host)
            && filled($this->api_key)
            && filled($this->v1_site_id)
            && filled($this->classic_site_name)
            && filled($this->wan_zone_id)
            && filled($this->lan_zone_id);
    }

    public function isActive(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }
}
