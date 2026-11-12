<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-note cash in the teller's drawer.
     *
     * Every cash transaction of a tracked shift records the notes and coins
     * that went into or out of the drawer, so the drawer's content is known per
     * denomination at every moment (opening count + notes in - notes out). A
     * withdrawal can only pay out notes the drawer holds, and closing must match
     * the drawer per denomination, not only in total.
     *
     * Shifts that existed before this migration have no per-note history; they
     * keep the total-only closing check (cash_tracked = false).
     */
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->boolean('cash_tracked')->default(false)->after('opening_balance');
        });

        Schema::create('transaction_cash', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('restrict');
            $table->integer('denomination')->unsigned();
            $table->integer('quantity')->unsigned();
            $table->timestamp('created_at')->nullable();

            $table->unique(['transaction_id', 'denomination']);
        });
        DB::statement('ALTER TABLE transaction_cash ADD CONSTRAINT chk_transaction_cash_denomination CHECK (denomination > 0)');
        DB::statement('ALTER TABLE transaction_cash ADD CONSTRAINT chk_transaction_cash_quantity CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_cash');

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropColumn('cash_tracked');
        });
    }
};
