<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-use invitation links for first-time activation.
     *
     * Only the SHA-256 hash of the token is stored; the plain token exists only
     * in the email. A row is usable while used_at and revoked_at are null and
     * expires_at is in the future. Rows are kept as a record of invitations.
     */
    public function up(): void
    {
        Schema::create('customer_activation_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            // The address the link was sent to; the activation must match it.
            $table->string('email');
            // The administrator who sent it, or null when the customer asked for a new link.
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'used_at', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_activation_tokens');
    }
};
