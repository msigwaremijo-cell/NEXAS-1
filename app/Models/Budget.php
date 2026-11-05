<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a company intends to earn and spend inside one fiscal period.
 *
 * A budget is a plan and never a posting. Nothing in this model reaches the
 * ledger: writing one records no journal, moves no balance and appears in no
 * trial balance. It exists to be read beside the period's actual figures, which
 * is what the variance report does — the actual column comes from the ledger,
 * the budget column comes from here, and the two meet only in that report.
 *
 * One header per company per period, with a row_version so two members editing
 * the same plan cannot both believe they wrote the last word.
 */
#[Fillable([
    'ulid',
    'company_id',
    'fiscal_period_id',
    'name',
    'notes',
    'row_version',
])]
class Budget extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('account_id');
    }
}
