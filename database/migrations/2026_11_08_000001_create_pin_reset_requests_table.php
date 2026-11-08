<?php

use App\Enums\PinResetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forgotten PIN, self-service with two safeguards: an administrator must
     * approve the request, and the customer can only set the new PIN after a
     * waiting period (available_at). The new PIN itself is never stored here.
     *
     * Finished requests (rejected, cancelled, expired, completed) are audit
     * records. At most one request per customer is open at a time.
     */
    public function up(): void
    {
        Schema::create('pin_reset_requests', function (Blueprint $table) {
            $table->id();
            // Identifier used in URLs; access is still checked by ownership.
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->enum('status', array_column(PinResetStatus::cases(), 'value'))
                ->default(PinResetStatus::PENDING->value);
            // Equals customer_id while the request is open, NULL afterwards: the
            // unique key allows one open request per customer.
            $table->unsignedBigInteger('open_customer_id')->nullable()->unique();
            // The customer may complete the reset from this moment (and only once approved).
            $table->timestamp('available_at');
            // After this the request is no longer usable (approved or not).
            $table->timestamp('expires_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 20)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        DB::statement("ALTER TABLE pin_reset_requests ADD CONSTRAINT chk_prr_open CHECK (
            (status IN ('pending', 'approved') AND open_customer_id = customer_id)
            OR (status NOT IN ('pending', 'approved') AND open_customer_id IS NULL)
        )");
        DB::statement("ALTER TABLE pin_reset_requests ADD CONSTRAINT chk_prr_approved CHECK (
            status NOT IN ('approved', 'completed') OR (approved_at IS NOT NULL AND decided_by IS NOT NULL)
        )");
        DB::statement("ALTER TABLE pin_reset_requests ADD CONSTRAINT chk_prr_completed CHECK (
            (status = 'completed') = (completed_at IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_reset_requests');
    }
};
