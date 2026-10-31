<?php

namespace App\Domain\Files;

use App\Domain\Banking\Enums\PaymentStatus;
use App\Domain\Files\Exceptions\FilesException;
use App\Models\Asset;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;

/**
 * The records a document may be filed against, and which of them have been
 * posted.
 *
 * A document is filed beside a record, never into it: filing one changes
 * nothing about the record it sits next to, which is what makes it possible to
 * add a supplier's invoice to a journal that has already been posted without
 * touching the journal. That is the whole point of the surface, and it is why
 * the register below is only about finding the record and deciding whether it
 * is final — not about writing to it.
 *
 * The type a caller names is a short word — `journal`, `bill` — because that
 * is what a client can reasonably know; the column stores the model's own
 * class name, which is what the morph relation resolves against and what the
 * audit chain already uses for its subjects.
 *
 * `isPosted` is what a withdrawal turns on. A document filed against a posted
 * record is evidence: withdrawing one there leaves the row behind, marked
 * withdrawn, so the account of what was filed survives the file itself. A
 * document filed against a record still in draft is simply removed, because
 * nothing has been asserted yet that anyone needs to be able to look back at.
 * "Posted" here means the record has left its draft state, which is the same
 * line each domain draws for its own immutability.
 */
final class AttachmentTargets
{
    /**
     * @return array<string, class-string<Model>>
     */
    public static function types(): array
    {
        return [
            'journal' => Journal::class,
            'invoice' => Invoice::class,
            'bill' => Bill::class,
            'payment' => Payment::class,
            'asset' => Asset::class,
        ];
    }

    /**
     * The record named by a type and a public id, inside one company.
     *
     * Resolved inside the company in the same query that reads it, so naming
     * another tenant's record is a 404 rather than a 403 that would confirm it
     * exists somewhere else.
     *
     * @throws FilesException when the type is not one this build files against
     */
    public static function resolve(string $type, string $ulid, int $companyId): Model
    {
        $class = self::types()[$type] ?? null;

        if ($class === null) {
            throw FilesException::unsupportedTarget($type, array_keys(self::types()));
        }

        return $class::query()
            ->where('company_id', $companyId)
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    /**
     * The short word for a stored morph class, so a response names a record the
     * same way a request did.
     */
    public static function shortName(string $morphClass): string
    {
        $type = array_search($morphClass, self::types(), true);

        return $type === false ? class_basename($morphClass) : $type;
    }

    /**
     * Whether the record a document is filed against has left its draft state.
     */
    public static function isPosted(Model $record): bool
    {
        return match (true) {
            $record instanceof Journal => $record->isPosted(),
            $record instanceof Invoice, $record instanceof Bill => ! $record->status->isEditable(),
            $record instanceof Payment => $record->status === PaymentStatus::Posted,
            $record instanceof Asset => ! $record->isDraft(),
            // A record of a kind this build does not know is treated as final,
            // because the failure that matters is destroying evidence rather
            // than keeping a row that could have been removed.
            default => true,
        };
    }
}
