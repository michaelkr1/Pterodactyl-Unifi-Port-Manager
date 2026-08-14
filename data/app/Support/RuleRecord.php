<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support;

use Illuminate\Database\Eloquent\Model;

class RuleRecord extends Model
{
    protected $table = 'unifisync_rules';

    protected $fillable = [
        'server_id',
        'server_uuid',
        'allocation_id',
        'name',
        'ip',
        'port',
        'unifi_portforward_id',
        'unifi_firewall_policy_id',
        'status',
        'last_error',
    ];
}
