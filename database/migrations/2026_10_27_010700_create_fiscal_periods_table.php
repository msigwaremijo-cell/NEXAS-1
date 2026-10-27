<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('period_no');
            $table->string('name', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open')->comment('open|closed|locked');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'period_no'], 'fiscal_periods_number_unique');
            $table->index(['company_id', 'starts_on', 'ends_on'], 'fiscal_periods_date_lookup');
            $table->index(['company_id', 'status'], 'fiscal_periods_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
