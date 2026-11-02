<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer identity for the app login (Firebase Authentication).
     *
     * Existing customers keep working: every column is nullable, so accounts
     * created before this change stay "pending" until an administrator adds an
     * email and the customer accepts the invitation. account_status is not
     * touched; activation is tracked separately so that a new account can
     * receive deposits at the counter before the student activates the app.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Required for new accounts (validation); nullable for older rows.
            $table->string('email')->nullable()->unique()->after('full_name');
            // The Firebase Authentication user bound at activation. Logins are matched on this, never on the email.
            $table->string('firebase_uid', 128)->nullable()->unique()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('firebase_uid');
            $table->timestamp('activated_at')->nullable()->after('email_verified_at');
        });

        // An activated account always has the Firebase identity it was activated with.
        DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_activation CHECK (activated_at IS NULL OR firebase_uid IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE customers DROP CONSTRAINT chk_customers_activation');
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropUnique(['firebase_uid']);
            $table->dropColumn(['email', 'firebase_uid', 'email_verified_at', 'activated_at']);
        });
    }
};
