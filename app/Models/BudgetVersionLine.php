<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned amount inside one version of a plan.
 *
 * A version speaks about a whole fiscal year, so the row names the month it
 * belongs to as well as the account and the cost centre: the grid the company
 * edits is twelve columns wide, and a version that only knew a year total could
 * neither be edited nor compared month by month.
 *
 * Like a forecast scenario version, the row is written once and read for ever,
 * and the database refuses a change once its version has been raised. There is
 * no `updated_at` — a row that says what the plan was at a moment in time
 * cannot be edited into saying something else.
 *
 * The amount is signed the way the profit and loss statement presents the
 * account — revenue and expense both positive on their own side — so a line can
 * be read straight against the actual column it is compared with.
 */
#[Fillable([
    'ulid',
    'company_id',
    'budget_version_id',
    'fiscal_period_id',
    'account_id',
    'cost_center_id',
    'amount',
    'created_at',
])]
class BudgetVersionLine extends Model
{
    use HasPublicId;

    /**
     * A line is written once, so there is no updated timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'budget_version_id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
