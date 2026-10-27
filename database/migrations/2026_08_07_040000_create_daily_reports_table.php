<?php

use App\Enums\CashCountStage;
use App\Enums\ReportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A daily report is one teller's shift (piket): opened with a cash count
     * (saldo awal), every transaction belongs to it, and it is closed with a
     * physical cash count that must match the system balance.
     */
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teller_id')->constrained('users')->onDelete('restrict');
            $table->date('report_date');
            $table->enum('status', array_column(ReportStatus::cases(), 'value'))->default(ReportStatus::OPEN->value);
            $table->integer('opening_balance')->unsigned()->default(0);
            $table->timestamp('opened_at')->nullable();
            // Snapshot written when the shift is closed.
            $table->integer('transaction_count')->unsigned()->default(0);
            $table->integer('total_deposit')->unsigned()->default(0);
            $table->integer('total_withdrawal')->unsigned()->default(0);
            $table->integer('system_balance')->nullable();
            $table->integer('physical_balance')->unsigned()->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->onDelete('restrict');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('supervisor_note')->nullable();
            $table->timestamps();

            // One shift per teller per day.
            $table->unique(['teller_id', 'report_date']);
            $table->index(['status', 'report_date']);
        });

        // Notes and coins counted at opening and closing, one row per denomination.
        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')->constrained('daily_reports')->onDelete('restrict');
            $table->enum('stage', array_column(CashCountStage::cases(), 'value'));
            $table->integer('denomination')->unsigned();
            $table->integer('quantity')->unsigned();
            $table->timestamps();

            $table->unique(['daily_report_id', 'stage', 'denomination']);
        });
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT chk_cash_counts_denomination CHECK (denomination > 0)');
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT chk_cash_counts_quantity CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('daily_reports');
    }
};
