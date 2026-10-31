<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class of fixed asset, and the four accounts every asset in it posts to.
 *
 * The accounts are held on the category rather than on the asset because they
 * are a property of the kind of thing, not of the individual item: every motor
 * vehicle depreciates into the same accumulated depreciation account, and
 * naming it once is what stops an accountant having to remember it each time a
 * vehicle is added. What the asset names is its own cost and its own life.
 *
 * Four accounts, each with a fixed meaning:
 *
 *   - asset_account_id                    what the asset is carried at (an asset,
 *                                         debit-normal account, e.g. 1500);
 *   - accumulated_depreciation_account_id  the contra-asset the credit side of
 *                                         every depreciation run lands on. The
 *                                         chart of accounts has no separate
 *                                         "contra-asset" type, so this is an
 *                                         asset account with a *credit* normal
 *                                         balance — the credit balance is what
 *                                         makes it a contra account;
 *   - depreciation_expense_account_id     the expense the debit side lands on;
 *   - disposal_account_id                 where a gain or loss on disposal is
 *                                         booked. A gain is carried as a credit
 *                                         to it and a loss as a debit, so it is
 *                                         an expense account like the one above.
 *
 * A category may be retired by its status and is never deleted, because assets
 * already written point at it. There is no soft-delete column for the same
 * reason a warehouse has none: a retired category keeps its assets and the
 * audit chain keeps what it held before each change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('description')->nullable();
            // The four accounts. Restricted rather than cascaded: an account a
            // category posts to cannot be removed from under it.
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('disposal_account_id')->constrained('accounts')->restrictOnDelete();
            // The life an asset in this category keeps unless it overrides it.
            $table->unsignedInteger('default_useful_life_months');
            $table->string('status')->default('active');
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'asset_categories_company_code_unique');
            $table->index(['company_id', 'status'], 'asset_categories_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
