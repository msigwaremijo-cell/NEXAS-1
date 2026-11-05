<?php

namespace App\Models;

use App\Domain\Accounting\Enums\PartyType;
use App\Domain\Banking\Enums\PaymentDirection;
use App\Domain\Banking\Enums\PaymentMethod;
use App\Domain\Banking\Enums\PaymentStatus;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One movement of money: a receipt from a customer, a payment to a supplier,
 * or a transfer between two of the company's own accounts.
 *
 * allocated_amount and unapplied_amount are absent from the fillable list.
 * Both are derived — allocated_amount is the sum of the allocations rows, and
 * unapplied_amount is amount minus that sum — so the action recomputes them
 * from the allocations it has just written rather than trusting an input.
 *
 * idempotency_key is unique per company, which is what makes a retried receipt
 * safe: the second attempt is refused by the database rather than doubling the
 * customer's credit.
 */
#[Fillable([
    'ulid',
    'company_id',
    'payment_no',
    'direction',
    'party_type',
    'party_id',
    'bank_account_id',
    'payment_date',
    'currency_code',
    'exchange_rate',
    'amount',
    'method',
    'reference',
    'status',
    'journal_id',
    'idempotency_key',
    'posted_at',
    'created_by_user_id',
    'row_version',
])]
class Payment extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'party_type' => PartyType::class,
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'payment_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'amount' => 'decimal:4',
            'allocated_amount' => 'decimal:4',
            'unapplied_amount' => 'decimal:4',
            'posted_at' => 'datetime',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Posted->value);
    }

    /**
     * The money received but not yet applied to a document.
     */
    public function unapplied(): Money
    {
        return Money::of($this->amount)->minus(Money::of($this->allocated_amount));
    }
}
