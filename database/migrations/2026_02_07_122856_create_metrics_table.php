<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at')->unique();

            // Meter (Shelly)
            $table->float('meter_power_total')->nullable();
            $table->float('meter_power_l1')->nullable();
            $table->float('meter_power_l2')->nullable();
            $table->float('meter_power_l3')->nullable();
            $table->float('meter_current_l1')->nullable();
            $table->float('meter_current_l2')->nullable();
            $table->float('meter_current_l3')->nullable();

            // Solar (Envoy)
            $table->float('solar_power')->nullable();

            // Charger (Lektrico)
            $table->string('charger_state')->nullable();
            $table->float('charger_power')->nullable();
            $table->integer('charger_current')->nullable();
            $table->float('charger_current_l1')->nullable();
            $table->float('charger_current_l2')->nullable();
            $table->float('charger_current_l3')->nullable();

            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
