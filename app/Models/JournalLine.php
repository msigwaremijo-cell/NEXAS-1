<?php

namespace App\Models;

use App\Domain\Accounting\Enums\PartyType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single debit or credit. Exactly one of debit/credit is greater than zero;
 * the CHECK constraint chk_journal_lines_debit_xor_credit enforces that at the
 * storage layer, so no model-level guard is needed.
 *
 * posting_year and posting_month are MySQL STORED generated columns. They are
 * intentionally absent from the fillable list so Eloquent can never try to
 * write them, which MySQL would reject.
 */
#[Fillable([
    'company_id',
    'journal_id',
    'line_no',
    'account_id',
    'description',
    'debit',
    'credit',
    'currency_code',
    'exchange_rate',
    'base_debit',
    'base_credit',
    'posting_date',
    'tax_code_id',
    'tax_amount',
    'party_type',
    'party_id',
    'branch_id',
    'row_version',
])]
class JournalLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'base_debit' => 'decimal:4',
            'base_credit' => 'decimal:4',
            'posting_date' => 'date',
            'posting_year' => 'integer',
            'posting_month' => 'integer',
            'tax_amount' => 'decimal:4',
            'party_type' => PartyType::class,
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isDebit(): bool
    {
        return Money::of($this->debit)->isPositive();
    }

    /**
     * The line amount regardless of which side it sits on.
     */
    public function amount(): Money
    {
        return $this->isDebit() ? Money::of($this->debit) : Money::of($this->credit);
    }
}
