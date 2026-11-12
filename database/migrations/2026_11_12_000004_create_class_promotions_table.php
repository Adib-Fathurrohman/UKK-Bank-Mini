<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kenaikan kelas: once a year, after the graduating class XII was paid out
     * and closed, every open account moves up one class (XI -> XII, X -> XI),
     * except the students the administrator marks as staying (tinggal kelas).
     * One run per school year; every moved account is listed for the audit.
     */
    public function up(): void
    {
        Schema::create('class_promotions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // The school year the students move into (start year: 2027 = 2027/2028). One run each.
            $table->smallInteger('school_year')->unsigned()->unique();
            $table->foreignId('executed_by')->constrained('users')->onDelete('restrict');
            $table->integer('promoted_count')->unsigned();
            $table->integer('retained_count')->unsigned();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('class_promotion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_promotion_id')->constrained('class_promotions')->onDelete('restrict');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->enum('from_class', ['X', 'XI', 'XII']);
            // Equal to from_class for a student who stays (tinggal kelas).
            $table->enum('to_class', ['X', 'XI', 'XII']);
            $table->timestamp('created_at')->nullable();

            $table->unique(['class_promotion_id', 'customer_id']);
        });
        DB::statement("ALTER TABLE class_promotion_items ADD CONSTRAINT chk_cpi_move CHECK (
            (from_class = 'X' AND to_class IN ('X', 'XI')) OR (from_class = 'XI' AND to_class IN ('XI', 'XII'))
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('class_promotion_items');
        Schema::dropIfExists('class_promotions');
    }
};
