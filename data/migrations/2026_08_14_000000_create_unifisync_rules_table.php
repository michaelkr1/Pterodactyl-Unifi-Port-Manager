<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unifisync_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('server_uuid');
            $table->unsignedInteger('allocation_id')->unique();
            $table->string('ip');
            $table->unsignedInteger('port');
            $table->string('unifi_portforward_id')->nullable();
            $table->string('unifi_firewall_policy_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unifisync_rules');
    }
};
