<?php

use App\Enums\TransactionTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            // The teller who executed the transaction.
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            // The teller's shift (daily report) the transaction was made in.
            $table->foreignId('daily_report_id')->constrained('daily_reports')->onDelete('restrict');
            $table->enum('type', array_column(TransactionTypes::cases(), 'value'));
            $table->integer('amount')->unsigned();
            $table->integer('balance_before')->unsigned();
            $table->integer('balance_after')->unsigned();
            // Corrections: the transaction being reversed (once only), the
            // supervisor who authorized it, and the reason.
            $table->foreignId('corrects_transaction_id')->nullable()->unique()->constrained('transactions')->onDelete('restrict');
            $table->foreignId('authorized_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->string('note')->nullable();
            // Client-chosen key so a retried request cannot create a second transaction.
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->unique(['user_id', 'idempotency_key']);
        });

        $types = "'".implode("', '", array_column(TransactionTypes::cases(), 'value'))."'";
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT chk_type CHECK (type IN ({$types}))");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_transactions_amount CHECK (amount > 0)');
        // Corrections always point at the reversed transaction and the authorizing supervisor;
        // ordinary transactions never do.
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT chk_transactions_correction CHECK (
            (type IN ('deposit', 'withdrawal') AND corrects_transaction_id IS NULL AND authorized_by IS NULL)
            OR (type IN ('deposit_correction', 'withdrawal_correction') AND corrects_transaction_id IS NOT NULL AND authorized_by IS NOT NULL)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
