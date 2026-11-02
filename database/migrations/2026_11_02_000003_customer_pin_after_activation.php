<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers create their own PIN after activating the app, and activation
     * no longer uses emailed links (Firebase sends the verification email).
     *
     * - pin becomes nullable: null until the customer creates it, and again
     *   after an administrator resets it.
     * - Accounts that are not activated lose the PIN an administrator set
     *   earlier, so a PIN only ever exists on an activated account.
     * - The invitation link table is dropped.
     */
    public function up(): void
    {
        Schema::dropIfExists('customer_activation_tokens');

        Schema::table('customers', function (Blueprint $table) {
            $table->string('pin')->nullable()->change();
        });

        if (DB::getDriverName() === 'sqlite') {
            // SQLite rebuilds the table for change() and drops its CHECK constraints.
            DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_balance CHECK (balance >= 0)');
            DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_activation CHECK (activated_at IS NULL OR firebase_uid IS NOT NULL)');
        }

        DB::table('customers')->whereNull('activated_at')->update([
            'pin' => null,
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ]);

        DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_pin CHECK (pin IS NULL OR activated_at IS NOT NULL)');
    }

    /**
     * Reverse the migrations. Accounts without a PIN get an unusable random
     * hash so the column can be NOT NULL again; an administrator must set a PIN.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE customers DROP CONSTRAINT chk_customers_pin');
        }

        DB::table('customers')->whereNull('pin')->orderBy('id')->each(function ($customer) {
            DB::table('customers')->where('id', $customer->id)->update(['pin' => bcrypt(bin2hex(random_bytes(16)))]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('pin')->nullable(false)->change();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_balance CHECK (balance >= 0)');
            DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_activation CHECK (activated_at IS NULL OR firebase_uid IS NOT NULL)');
        }

        Schema::create('customer_activation_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('email');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'used_at', 'revoked_at']);
        });
    }
};
