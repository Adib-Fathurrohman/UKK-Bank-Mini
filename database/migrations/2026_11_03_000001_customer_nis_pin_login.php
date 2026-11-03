<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers sign in with NIS (or account number) + PIN; Firebase is only
     * used to deliver push notifications (FCM).
     *
     * - The Firebase identity columns and their CHECKs are dropped.
     * - pin is NOT NULL again. The system generates a temporary PIN that the
     *   customer must replace at the first login (pin_must_change). Accounts
     *   that had no PIN get an unusable random one and must_change: an
     *   administrator resets it to hand out a temporary PIN.
     * - customer_push_tokens: FCM registration tokens, each tied to the session
     *   (Sanctum token) that registered it, so logging out removes it.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE customers DROP CONSTRAINT chk_customers_pin');
            DB::statement('ALTER TABLE customers DROP CONSTRAINT chk_customers_activation');
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('pin_must_change')->default(false)->after('pin');
        });

        DB::table('customers')->whereNull('pin')->orderBy('id')->each(function ($customer) {
            DB::table('customers')->where('id', $customer->id)->update([
                'pin' => bcrypt(bin2hex(random_bytes(16))),
                'pin_must_change' => true,
            ]);
        });

        // On SQLite this rebuilds the table, which also drops its CHECK
        // constraints; that has to happen before the columns they use go.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('pin')->nullable(false)->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['firebase_uid']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['firebase_uid', 'email_verified_at', 'activated_at']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_balance CHECK (balance >= 0)');
        }

        Schema::create('customer_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // The session that registered it: logout, logout-all and token expiry remove the push token too.
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            // FCM registration tokens are long; 255 is the documented practical maximum (~163 today).
            $table->string('token', 255)->unique();
            $table->string('platform', 10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. Every customer becomes "pending" without a PIN,
     * as in the Firebase activation flow.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_push_tokens');

        Schema::table('customers', function (Blueprint $table) {
            $table->string('firebase_uid', 128)->nullable()->unique()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('firebase_uid');
            $table->timestamp('activated_at')->nullable()->after('email_verified_at');
            $table->string('pin')->nullable()->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('pin_must_change');
        });

        DB::table('customers')->update(['pin' => null, 'pin_failed_attempts' => 0, 'pin_locked_until' => null]);

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_balance CHECK (balance >= 0)');
        }

        DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_activation CHECK (activated_at IS NULL OR firebase_uid IS NOT NULL)');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_pin CHECK (pin IS NULL OR activated_at IS NOT NULL)');
    }
};
