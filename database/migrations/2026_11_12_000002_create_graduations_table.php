<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Graduation (kelulusan): an administrator pays out the whole balance of a
     * graduating class at once, including the minimum balance, and closes the
     * accounts. The cash comes from the main vault, not from a teller's drawer,
     * so the withdrawals belong to the graduation batch instead of a shift.
     */
    public function up(): void
    {
        Schema::create('graduations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('executed_by')->constrained('users')->onDelete('restrict');
            // The selection the administrator made (class required, major and group optional).
            $table->enum('class', ['X', 'XI', 'XII']);
            $table->foreignId('major_id')->nullable()->constrained('majors')->onDelete('restrict');
            $table->enum('group', ['1', '2', '3', '4'])->nullable();
            $table->integer('customer_count')->unsigned();
            $table->integer('paid_count')->unsigned();
            $table->bigInteger('total_amount')->unsigned();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        // SQLite (tests) rebuilds a table to add a foreign key and loses its CHECK
        // constraints on the way, so there the columns are added without one.
        $foreignKeys = DB::getDriverName() !== 'sqlite';

        Schema::table('transactions', function (Blueprint $table) use ($foreignKeys) {
            $column = $table->foreignId('graduation_id')->nullable()->after('customer_import_id');
            if ($foreignKeys) {
                $column->constrained('graduations')->onDelete('restrict');
            }
        });

        // The batch that closed the account, also for accounts with nothing to pay out.
        Schema::table('customers', function (Blueprint $table) use ($foreignKeys) {
            $column = $table->foreignId('graduation_id')->nullable()->after('deleted_at');
            if ($foreignKeys) {
                $column->constrained('graduations')->onDelete('restrict');
            }
        });

        // A transaction comes from exactly one of: a teller's shift, an import, a graduation.
        // A graduation only pays out (withdrawal).
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT chk_transactions_source');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT chk_transactions_source CHECK (
            (daily_report_id IS NOT NULL AND customer_import_id IS NULL AND graduation_id IS NULL)
            OR (daily_report_id IS NULL AND customer_import_id IS NOT NULL AND graduation_id IS NULL AND type IN ('deposit', 'withdrawal'))
            OR (daily_report_id IS NULL AND customer_import_id IS NULL AND graduation_id IS NOT NULL AND type = 'withdrawal')
        )");
    }

    /**
     * Refused while graduation payouts exist: transactions are never deleted.
     */
    public function down(): void
    {
        if (DB::table('transactions')->whereNotNull('graduation_id')->exists() || DB::table('customers')->whereNotNull('graduation_id')->exists()) {
            throw new RuntimeException('Graduation payouts exist; they cannot be kept without their batch.');
        }

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT chk_transactions_source');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT chk_transactions_source CHECK (
            (daily_report_id IS NOT NULL AND customer_import_id IS NULL)
            OR (daily_report_id IS NULL AND customer_import_id IS NOT NULL AND type IN ('deposit', 'withdrawal'))
        )");

        foreach (['transactions', 'customers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                DB::getDriverName() === 'sqlite' ? $table->dropColumn('graduation_id') : $table->dropConstrainedForeignId('graduation_id');
            });
        }

        Schema::dropIfExists('graduations');
    }
};
