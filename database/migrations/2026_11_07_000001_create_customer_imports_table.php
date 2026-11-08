<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data migration from the old bookkeeping (Admin: "Impor nasabah").
     *
     * An import creates customers with their balance and, optionally, their
     * transaction history. Imported transactions belong to the import instead
     * of a teller's shift: the money was handled before this system existed,
     * so it must not show up in any shift's cash count or report.
     */
    public function up(): void
    {
        Schema::create('customer_imports', function (Blueprint $table) {
            $table->id();
            // The administrator who uploaded the file.
            $table->foreignId('imported_by')->constrained('users')->onDelete('restrict');
            $table->string('file_name');
            $table->integer('customer_count')->unsigned();
            $table->integer('transaction_count')->unsigned();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('daily_report_id')->nullable()->change();
            $table->foreignId('customer_import_id')->nullable()->after('daily_report_id')->constrained('customer_imports')->onDelete('restrict');
        });

        if (DB::getDriverName() === 'sqlite') {
            // SQLite rebuilds the table for change() and drops its CHECK constraints.
            $this->addOriginalChecks();
        }

        // A transaction comes either from a teller's shift or from an import,
        // and an import only brings plain deposits and withdrawals.
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT chk_transactions_source CHECK (
            (daily_report_id IS NOT NULL AND customer_import_id IS NULL)
            OR (daily_report_id IS NULL AND customer_import_id IS NOT NULL AND type IN ('deposit', 'withdrawal'))
        )");
    }

    /**
     * Reverse the migrations. Refused while imported transactions exist:
     * they have no shift, and transactions are never deleted.
     */
    public function down(): void
    {
        if (DB::table('transactions')->whereNotNull('customer_import_id')->exists()) {
            throw new RuntimeException('Imported transactions exist; they cannot be kept without a shift.');
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT chk_transactions_source');
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_import_id');
            $table->foreignId('daily_report_id')->nullable(false)->change();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->addOriginalChecks();
        }

        Schema::dropIfExists('customer_imports');
    }

    private function addOriginalChecks(): void
    {
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT chk_type CHECK (type IN ('deposit', 'withdrawal', 'deposit_correction', 'withdrawal_correction'))");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_transactions_amount CHECK (amount > 0)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT chk_transactions_correction CHECK (
            (type IN ('deposit', 'withdrawal') AND corrects_transaction_id IS NULL AND authorized_by IS NULL)
            OR (type IN ('deposit_correction', 'withdrawal_correction') AND corrects_transaction_id IS NOT NULL AND authorized_by IS NOT NULL)
        )");
    }
};
