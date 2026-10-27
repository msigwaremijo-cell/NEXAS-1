<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one payment went against one invoice or bill.
 *
 * document_type plus document_id is a polymorphic reference that the migration
 * keeps deliberately FK-free, because one allocation table serves both sides.
 * The trade-off is that the database cannot enforce the target: RecordPayment
 * is what checks that the document exists, belongs to the same company, and is
 * still open.
 */
#[Fillable([
    'company_id',
    'payment_id',
    'document_type',
    'document_id',
    'amount',
    'journal_line_id',
])]
class PaymentAllocation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'amount' => 'decimal:4',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}
