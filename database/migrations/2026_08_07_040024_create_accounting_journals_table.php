<?php

use App\Enums\AccountCode;
use App\Enums\Position;
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
        Schema::create('accounting_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('restrict');
            $table->enum('account_code', array_column(AccountCode::cases(), 'value'));
            $table->enum('position', array_column(Position::cases(), 'value'));
            $table->integer('amount')->unsigned();
            $table->timestamps();

            // At most one debit and one credit line per transaction.
            $table->unique(['transaction_id', 'position']);
            $table->index('account_code');
        });
        DB::statement('ALTER TABLE accounting_journals ADD CONSTRAINT chk_position CHECK (position IN ("debit", "credit"))');
        DB::statement('ALTER TABLE accounting_journals ADD CONSTRAINT chk_journals_amount CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_journals');
    }
};
