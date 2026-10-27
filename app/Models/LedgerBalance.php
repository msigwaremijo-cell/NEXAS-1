<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per account / per period snapshot, rebuilt by a queued job. Trial balance
 * and general ledger reports read this instead of scanning every journal line,
 * and last_journal_line_id lets the refresh run incrementally.
 *
 * The numbers here are a cache of the ledger. journal_lines remains the
 * source of truth.
 */
#[Fillable([
    'company_id',
    'account_id',
    'fiscal_period_id',
    'opening_debit',
    'opening_credit',
    'period_debit',
    'period_credit',
    'closing_debit',
    'closing_credit',
    'last_journal_line_id',
    'refreshed_at',
])]
class LedgerBalance extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_debit' => 'decimal:4',
            'opening_credit' => 'decimal:4',
            'period_debit' => 'decimal:4',
            'period_credit' => 'decimal:4',
            'closing_debit' => 'decimal:4',
            'closing_credit' => 'decimal:4',
            'refreshed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }
}
