<?php

use App\Enums\TransactionTypes;
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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->enum('type', array_column(TransactionTypes::cases(), 'value'));
            $table->integer('amount')->unsigned();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_type CHECK (type IN ("deposit", "withdrawal"))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT chk_type');
        Schema::dropIfExists('transactions');
    }
};
