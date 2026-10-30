<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\DocumentType;
use App\Models\DocumentSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Hands out the next number of a document series, one series per company /
 * document type / fiscal year.
 *
 * The counter row is locked with SELECT ... FOR UPDATE and incremented inside
 * the caller's transaction, so two concurrent postings cannot be handed the
 * same number: the second waits for the first to commit or roll back. A
 * rollback returns the number to the series, which is what keeps it gapless.
 *
 * Because the database forbids changing journal_no once a journal stops being
 * a draft (see trg_journals_before_update), a journal takes its number when the
 * draft is written. A draft that is deleted therefore consumes its number; the
 * series has no duplicates, but it can show that gap.
 */
final class DocumentNumbering
{
    private const DEFAULT_PADDING = 6;

    public function next(int $companyId, DocumentType $type, int $fiscalYearId): string
    {
        $sequence = $this->lockSequence($companyId, $type, $fiscalYearId);

        $number = $sequence->next_number;

        $sequence->next_number = $number + 1;
        $sequence->save();

        return $sequence->prefix.str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT);
    }

    private function lockSequence(int $companyId, DocumentType $type, int $fiscalYearId): DocumentSequence
    {
        $sequence = $this->lockedRow($companyId, $type, $fiscalYearId);

        if ($sequence !== null) {
            return $sequence;
        }

        // A fiscal year that was never seeded still has to be numbered. Insert
        // the row, then lock the row that now exists; if another transaction
        // won the race, the unique index rejects this insert and the locked
        // read below simply waits for theirs.
        try {
            DocumentSequence::create([
                'company_id' => $companyId,
                'document_type' => $type->value,
                'fiscal_year_id' => $fiscalYearId,
                'prefix' => self::prefixFor($type),
                'next_number' => 1,
                'padding' => self::DEFAULT_PADDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Fall through to the locked read.
        }

        return $this->lockedRow($companyId, $type, $fiscalYearId) ?? throw new RuntimeException(sprintf(
            'The %s sequence for company %d and fiscal year %d could not be opened.',
            $type->value,
            $companyId,
            $fiscalYearId
        ));
    }

    private function lockedRow(int $companyId, DocumentType $type, int $fiscalYearId): ?DocumentSequence
    {
        return DocumentSequence::query()
            ->where('company_id', $companyId)
            ->where('document_type', $type->value)
            ->where('fiscal_year_id', $fiscalYearId)
            ->lockForUpdate()
            ->first();
    }

    private static function prefixFor(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::Journal => 'JV-',
            DocumentType::Invoice => 'INV-',
            DocumentType::Bill => 'BILL-',
            DocumentType::Payment => 'PAY-',
            DocumentType::CreditNote => 'CN-',
            DocumentType::DebitNote => 'DBN-',
        };
    }
}
