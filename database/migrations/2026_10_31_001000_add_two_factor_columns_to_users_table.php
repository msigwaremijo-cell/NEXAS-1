<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second factor on a user account.
 *
 * All three columns are nullable because two-factor is opt-in and most rows
 * will never carry one. The secret and the recovery codes are stored as text
 * rather than a short string because both are written through an encrypted
 * cast: what reaches the database is a ciphertext envelope, which is longer
 * than the plaintext and must not be truncated.
 *
 * `two_factor_confirmed_at` is what decides whether the factor is live. A
 * secret is written when the member starts enrolling, and only the confirmed
 * timestamp turns it on — so an abandoned enrolment leaves a secret behind
 * that can never be used to finish a login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('last_login_at');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
