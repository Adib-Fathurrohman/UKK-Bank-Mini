<?php

use App\Enums\AccountStatus;
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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('account_number')->unique();
            $table->string('nis')->unique();
            $table->string('full_name');
            $table->enum('class', ['X', 'XI', 'XII']);
            $table->foreignId('major_id')->constrained('majors')->onDelete('restrict');
            $table->enum('group', ['1', '2', '3', '4']);
            $table->enum('account_status', array_column(AccountStatus::cases(), 'value'))->default(AccountStatus::ACTIVE->value);
            $table->string('pin');
            $table->integer('balance')->default(0);
            $table->timestamps();
        });
        // Last line of defence: the minimum balance rule lives in the app, but the DB never allows a negative balance.
        DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_balance CHECK (balance >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
