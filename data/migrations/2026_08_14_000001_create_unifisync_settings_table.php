<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unifisync_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('host')->nullable();
            $table->boolean('verify_tls')->default(false);
            $table->text('api_key')->nullable();
            $table->string('v1_site_id')->nullable();
            $table->string('classic_site_name')->nullable();
            $table->string('wan_zone_id')->nullable();
            $table->string('lan_zone_id')->nullable();
            $table->unsignedInteger('reconcile_interval_minutes')->default(2);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unifisync_settings');
    }
};
