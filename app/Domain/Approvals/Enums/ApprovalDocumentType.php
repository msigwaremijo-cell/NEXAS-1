<?php

namespace App\Domain\Approvals\Enums;

use App\Models\Asset;
use App\Models\Bill;
use App\Models\BudgetVersion;
use App\Models\Journal;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;

/**
 * The seven kinds of document that may wait in the approvals queue.
 *
 * The type is the queue's own label for what a document is, rather than the
 * name of a class. A credit note, a debit note and a supplier bill are all rows
 * of the `bills` table — that is how the sales and purchases modules store the
 * three — so the type is what tells them apart, and `documentTypeColumn()`
 * names the extra condition the row has to satisfy. Every other type is a model
 * in its own right.
 *
 * `modelClass()` is what lets the queue prove, at the moment a request is
 * raised, that the document really exists inside the caller's company: a
 * document named from another tenant is refused rather than queued.
 */
enum ApprovalDocumentType: string
{
    case Journal = 'journal';
    case Bill = 'bill';
    case Payment = 'payment';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case AssetDisposal = 'asset_disposal';
    case Budget = 'budget';

    /**
     * The Eloquent model the type resolves to.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Journal => Journal::class,
            self::Bill, self::CreditNote, self::DebitNote => Bill::class,
            self::Payment => Payment::class,
            self::AssetDisposal => Asset::class,
            self::Budget => BudgetVersion::class,
        };
    }

    /**
     * For the three types that share the `bills` table, the value the row's
     * `document_type` column must hold. Null everywhere else, where the model
     * itself is the whole of the identity.
     */
    public function documentTypeColumn(): ?string
    {
        return match ($this) {
            self::Bill => 'bill',
            self::CreditNote => 'credit_note',
            self::DebitNote => 'debit_note',
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Journal => 'Journal',
            self::Bill => 'Supplier bill',
            self::Payment => 'Payment',
            self::CreditNote => 'Credit note',
            self::DebitNote => 'Debit note',
            self::AssetDisposal => 'Asset disposal',
            self::Budget => 'Budget plan',
        };
    }
}
