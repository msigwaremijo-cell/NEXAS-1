<?php

namespace App\Models;

use App\Domain\Accounting\Enums\DocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gapless counter, one row per company / document type / fiscal year.
 *
 * This model does not allocate numbers itself. Allocation must happen inside
 * the posting transaction with SELECT ... FOR UPDATE on this row, which is the
 * job of the PostJournal action; a read-modify-write from here would allow two
 * concurrent postings to share a number.
 */
#[Fillable([
    'company_id',
    'document_type',
    'fiscal_year_id',
    'prefix',
    'next_number',
    'padding',
])]
class DocumentSequence extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'next_number' => 'integer',
            'padding' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
