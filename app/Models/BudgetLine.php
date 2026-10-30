<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned amount for one account in one cost centre.
 *
 * A child of the budget header, like a journal line is a child of its journal,
 * so it carries no public identifier of its own: nothing addresses a plan line
 * directly. The whole plan is written at once, because a plan is a statement
 * about a period rather than a list that is appended to.
 *
 * The amount is signed the way the profit and loss statement presents the
 * account — revenue and expense both positive on their own side — so a line can
 * be read straight against the actual column it is compared with.
 */
#[Fillable([
    'company_id',
    'budget_id',
    'account_id',
    'cost_center_id',
    'amount',
])]
class BudgetLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
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
