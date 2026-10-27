<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the stock Laravel users table with the columns Nexas needs.
 * Deliberately additive: the stock migration file is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('ulid', 26)->nullable()->unique()->after('id');
            $table->string('phone', 32)->nullable()->after('email_verified_at');
            $table->string('job_title')->nullable()->after('password');
            $table->string('status', 20)->default('active')->after('job_title');
            $table->unsignedInteger('row_version')->default(0)->after('status');
            $table->timestamp('last_login_at')->nullable()->after('row_version');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['ulid']);
            $table->dropColumn([
                'ulid',
                'phone',
                'job_title',
                'status',
                'row_version',
                'last_login_at',
                'deleted_at',
            ]);
        });
    }
};
