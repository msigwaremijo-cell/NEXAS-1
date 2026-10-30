<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a supplier bill.
 *
 * Same shape as InvoiceLine on purpose: the fillable list holds only what a
 * caller may supply, and every computed figure is assigned by the action after
 * DocumentLineCalculator has done the arithmetic.
 *
 * The account that differs is expense_account_id rather than revenue_account_id:
 * a purchase debits an expense or inventory account instead of crediting revenue.
 */
#[Fillable([
    'company_id',
    'bill_id',
    'line_no',
    'product_id',
    'description',
    'quantity',
    'unit_price',
    'discount_rate',
    'tax_code_id',
    'expense_account_id',
    'debit_note_for_line_id',
])]
class BillLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'discount_rate' => 'decimal:6',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:6',
            'tax_amount' => 'decimal:4',
            'line_subtotal' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
