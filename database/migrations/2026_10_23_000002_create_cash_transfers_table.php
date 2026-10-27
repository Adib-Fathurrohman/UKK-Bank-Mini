<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Moving the closing cash of a shift from the teller's drawer to the main
     * vault. Created automatically when a supervisor approves the daily report
     * (soal 1c), so each report has at most one transfer.
     */
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')->unique()->constrained('daily_reports')->onDelete('restrict');
            $table->integer('amount')->unsigned();
            $table->foreignId('approved_by')->constrained('users')->onDelete('restrict');
            $table->timestamp('transferred_at');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE cash_transfers ADD CONSTRAINT chk_cash_transfers_amount CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_transfers');
    }
};
