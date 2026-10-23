<?php

use App\Enums\CashTransferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A transfer always moves physical cash from a teller's drawer to the main vault.
     */
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teller_id')->constrained('users')->onDelete('restrict');
            $table->integer('amount')->unsigned();
            $table->enum('status', array_column(CashTransferStatus::cases(), 'value'))->default(CashTransferStatus::PENDING->value);
            $table->text('note')->nullable();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->onDelete('restrict');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('supervisor_note')->nullable();
            $table->timestamps();

            $table->index(['teller_id', 'status']);
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
