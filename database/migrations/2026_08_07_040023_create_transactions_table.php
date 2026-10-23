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
            $table->enum('type', array_column(TransactionTypes::cases(), 'value'));
            $table->integer('amount')->unsigned();
            $table->integer('balance_before')->unsigned();
            $table->integer('balance_after')->unsigned();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_type CHECK (type IN (\'deposit\', \'withdrawal\'))');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_transactions_amount CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
