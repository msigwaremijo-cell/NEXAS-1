<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sales invoice.
 *
 * The fillable list holds the four figures a caller is allowed to supply —
 * quantity, unit_price, discount_rate and the tax code — and nothing else.
 * discount_amount, tax_rate, tax_amount, line_subtotal and line_total are all
 * computed from those by DocumentLineCalculator and assigned directly by the
 * action, which is what stops a client posting a line whose arithmetic does
 * not add up.
 *
 * tax_rate is a snapshot of the dated tax_rates row that applied on the
 * document date, so re-pricing a tax code later never rewrites history.
 */
#[Fillable([
    'company_id',
    'invoice_id',
    'line_no',
    'product_id',
    'description',
    'quantity',
    'unit_price',
    'discount_rate',
    'tax_code_id',
    'revenue_account_id',
])]
class InvoiceLine extends Model
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }
}
