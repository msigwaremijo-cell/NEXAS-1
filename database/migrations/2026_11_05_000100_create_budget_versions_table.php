<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One authored state of a company's plan for a fiscal year.
 *
 * A budget used to be a bare statement of intent with no history: a company
 * wrote one plan per fiscal period and that plan was the whole of it. This is
 * the artefact that gives the plan a life cycle — Draft, Pending Approval,
 * Approved, Locked — and a version history, so a plan that has been signed off
 * can still be changed without erasing the plan that was signed off.
 *
 * The row is the version's identity and its standing, not its amounts: the
 * lines live in budget_version_lines, one immutable row per period, account and
 * cost centre. Editing a plan that is already approved or locked does not
 * rewrite this row — it writes the *next* version and leaves this one alone,
 * which is what "preserve all previous versions" means in practice.
 *
 * `reason` is deliberately nullable at the database and mandatory in the
 * service for any version after the first: the rule "require a reason for every
 * new version" is a state rule about the plan, not a column rule, and it is
 * enforced where the version number is known.
 *
 * Nothing here reaches the ledger. A budget version is what the company intends
 * to earn and spend; no journal is posted and no balance moves when one is
 * written, approved or locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_versions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('status', 20)->default('draft')->comment('draft|pending_approval|approved|locked');
            $table->string('name', 120)->nullable();
            // Why this version exists. Mandatory in the service for every
            // version after the first, where "a new version was written" is not
            // a sufficient account of what changed.
            $table->string('reason', 500)->nullable();
            // Who wrote it, who raised it, who decided it and who locked it.
            // The author is what the no-self-approval rule is measured against,
            // and it is stamped at the moment of the act rather than inferred.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            // A version number is unique within its fiscal year, which is what
            // makes "the next version" a real number and what stops two
            // concurrent saves from both claiming version N.
            $table->unique(['company_id', 'fiscal_year_id', 'version_no'], 'budget_versions_no_unique');
            $table->index(['company_id', 'fiscal_year_id', 'status'], 'budget_versions_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_versions');
    }
};
