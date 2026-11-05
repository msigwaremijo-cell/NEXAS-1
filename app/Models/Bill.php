<?php

namespace App\Models;

use App\Domain\Purchases\Enums\BillStatus;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier bill.
 *
 * A bill carries one gate an invoice does not: it must be approved by a named
 * member before it can reach the ledger, which is what `approval_status`,
 * `approved_at` and `approved_by_user_id` record.
 *
 * As on Invoice, the five money totals are server-computed and are left out of
 * the fillable list so no caller can assert them.
 */
#[Fillable([
    'ulid',
    'company_id',
    'supplier_id',
    'branch_id',
    'bill_no',
    'supplier_invoice_no',
    'document_type',
    'debit_note_for_bill_id',
    'status',
    'bill_date',
    'due_date',
    'currency_code',
    'exchange_rate',
    'reference',
    'notes',
    'journal_id',
    'approval_status',
    'approved_at',
    'approved_by_user_id',
    'created_by_user_id',
    'row_version',
])]
class Bill extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BillStatus::class,
            'bill_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'approved_at' => 'datetime',
            'row_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * @return HasMany<BillLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'document_id')
            ->where('document_type', 'bill');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BillStatus::Approved->value,
            BillStatus::PartiallyPaid->value,
        ]);
    }

    /**
     * What the company still owes on this bill.
     */
    public function outstanding(): Money
    {
        return Money::of($this->total)->minus(Money::of($this->amount_paid));
    }
}
