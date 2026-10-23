<?php

use App\Enums\ReportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teller_id')->constrained('users')->onDelete('restrict');
            $table->date('report_date');
            $table->integer('transaction_count')->unsigned()->default(0);
            $table->integer('total_deposit')->unsigned()->default(0);
            $table->integer('total_withdrawal')->unsigned()->default(0);
            $table->enum('status', array_column(ReportStatus::cases(), 'value'))->default(ReportStatus::DRAFT->value);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->onDelete('restrict');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('supervisor_note')->nullable();
            $table->timestamps();

            // One cash-closing report per teller per day.
            $table->unique(['teller_id', 'report_date']);
            $table->index(['status', 'report_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
