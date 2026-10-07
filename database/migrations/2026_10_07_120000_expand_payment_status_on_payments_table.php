<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Widen "payment_status" from an enum to a string so new lifecycle states
     * (e.g. "refunded") can be stored without a schema change.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('payment_status', ['pending', 'success', 'failed'])
                ->default('pending')
                ->change();
        });
    }
};
