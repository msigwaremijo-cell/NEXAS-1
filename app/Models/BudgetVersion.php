<?php

namespace App\Models;

use App\Domain\Reporting\Enums\BudgetStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One authored state of a company's plan for a fiscal year.
 *
 * The row holds the version's identity and its standing — the number, the
 * status and the four acts a member may perform on it — and not its amounts:
 * the figures live on {@see BudgetVersionLine}, one row per period, account and
 * cost centre. Reading a version's plan means reading its lines; changing a
 * version's plan means writing the *next* version, because a version that has
 * been raised is frozen at the database and cannot be edited back into
 * agreement with a later intent.
 *
 * Nothing here is accounting. A version is what the company intends to earn and
 * spend; approving and locking one posts no journal and moves no balance. The
 * live plan the reports read is materialised into `budgets` and `budget_lines`
 * from an approved version, and even that write touches no ledger.
 */
#[Fillable([
    'ulid',
    'company_id',
    'fiscal_year_id',
    'version_no',
    'status',
    'name',
    'reason',
    'created_by_user_id',
    'submitted_by_user_id',
    'submitted_at',
    'decided_by_user_id',
    'decided_at',
    'decision_reason',
    'locked_by_user_id',
    'locked_at',
    'row_version',
])]
class BudgetVersion extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BudgetStatus::class,
            'version_no' => 'integer',
            'row_version' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    /**
     * The plan itself, in account and period order so the twelve-column grid
     * reads it without sorting again.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetVersionLine::class)->orderBy('account_id')->orderBy('fiscal_period_id');
    }

    /**
     * The newest version of its fiscal year.
     *
     * Read as the highest version number rather than by a pointer column, for
     * the same reason forecast scenarios do it: versions are appended and never
     * deleted, so the newest is the live one by construction and only the newest
     * can be eager-loaded in one query.
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(self::class, 'fiscal_year_id', 'fiscal_year_id')->latestOfMany('version_no');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForYear(Builder $query, int $fiscalYearId): Builder
    {
        return $query->where('fiscal_year_id', $fiscalYearId);
    }
}
