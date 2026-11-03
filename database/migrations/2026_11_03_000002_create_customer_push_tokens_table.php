<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FCM registration tokens of the customers' phones, for push notifications
     * of new withdrawal requests. Each token is tied to the session (Sanctum
     * token) that registered it, so logging out removes it.
     */
    public function up(): void
    {
        Schema::create('customer_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // The session that registered it: logout, logout-all and token expiry remove the push token too.
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            // FCM registration tokens are long; 255 is the documented practical maximum (~163 today).
            $table->string('token', 255)->unique();
            $table->string('platform', 10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_push_tokens');
    }
};
