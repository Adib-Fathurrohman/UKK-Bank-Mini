<?php

use App\Enums\WithdrawalAuthorizationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A withdrawal started by a teller that the customer approves with their PIN
     * in the mobile app (soal 1d: "Otorisasi Keamanan Penarikan"). It is bound to
     * one teller, customer, amount and shift, and becomes at most one transaction.
     * The PIN itself is never stored here.
     */
    public function up(): void
    {
        Schema::create('withdrawal_authorizations', function (Blueprint $table) {
            $table->id();
            // Identifier used in URLs; access is still checked by ownership.
            $table->ulid('public_id')->unique();
            $table->foreignId('teller_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->foreignId('daily_report_id')->constrained('daily_reports')->onDelete('restrict');
            $table->integer('amount')->unsigned();
            $table->enum('status', array_column(WithdrawalAuthorizationStatus::cases(), 'value'))
                ->default(WithdrawalAuthorizationStatus::PENDING->value);
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('approval_expires_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 20)->nullable();
            $table->timestamp('consumed_at')->nullable();
            // The withdrawal this authorization produced; unique so it is used once.
            $table->foreignId('transaction_id')->nullable()->unique()->constrained('transactions')->onDelete('restrict');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['teller_id', 'status']);
        });

        DB::statement('ALTER TABLE withdrawal_authorizations ADD CONSTRAINT chk_wa_amount CHECK (amount > 0)');
        // A consumed authorization always points at its withdrawal; no other status does.
        DB::statement("ALTER TABLE withdrawal_authorizations ADD CONSTRAINT chk_wa_consumed CHECK (
            (status = 'consumed' AND transaction_id IS NOT NULL AND consumed_at IS NOT NULL)
            OR (status <> 'consumed' AND transaction_id IS NULL)
        )");
        DB::statement("ALTER TABLE withdrawal_authorizations ADD CONSTRAINT chk_wa_approved CHECK (
            status NOT IN ('approved', 'consumed') OR (approved_at IS NOT NULL AND approval_expires_at IS NOT NULL)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawal_authorizations');
    }
};
