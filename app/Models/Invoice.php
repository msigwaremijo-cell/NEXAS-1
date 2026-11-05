<?php

namespace App\Models;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales invoice.
 *
 * subtotal, discount_total, tax_total, total and amount_paid are deliberately
 * absent from the fillable list. Every one of them is a server-computed figure:
 * the first four come out of DocumentLineCalculator when the document is
 * written, and amount_paid is derived from the allocations a receipt leaves
 * behind. Mass assignment therefore cannot set them, and the API never accepts
 * them, so a client cannot assert its own invoice total.
 *
 * There is no soft-delete column: once issued, an invoice is a financial
 * document and is corrected by a credit note, never by deletion.
 */
#[Fillable([
    'ulid',
    'company_id',
    'customer_id',
    'branch_id',
    'invoice_no',
    'document_type',
    'status',
    'invoice_date',
    'due_date',
    'currency_code',
    'exchange_rate',
    'reference',
    'notes',
    'journal_id',
    'credit_note_for_invoice_id',
    'issued_at',
    'created_by_user_id',
    'row_version',
])]
class Invoice extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'issued_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'document_id')
            ->where('document_type', 'invoice');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Issued->value,
            InvoiceStatus::PartiallyPaid->value,
        ]);
    }

    /**
     * What the customer still owes on this invoice.
     */
    public function outstanding(): Money
    {
        return Money::of($this->total)->minus(Money::of($this->amount_paid));
    }
}
