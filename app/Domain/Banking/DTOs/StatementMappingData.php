<?php

namespace App\Domain\Banking\DTOs;

use App\Domain\Banking\Enums\StatementDateFormat;

/**
 * Which column of a statement file means what.
 *
 * The mapping is named by the caller rather than guessed, and the two things it
 * has to settle are the two the file cannot settle for itself.
 *
 * The date layout is named because a statement line is matched to a journal
 * line by its date: a file read one day out is a file that reconciles against
 * the wrong entries, and 03/04/2026 is the third of April in one bank's export
 * and the fourth of March in another's.
 *
 * The sign is named because a single signed amount column means nothing on its
 * own. Most exports print money out as a negative figure, but an export written
 * from the bank's own perspective prints the opposite, and getting it backwards
 * inverts every line in the file without producing a single error.
 *
 * Money is described either as one signed amount column or as a money in and a
 * money out column, never both: a movement described twice cannot be read
 * twice, and the importer refuses the ambiguity rather than picking a side.
 */
final readonly class StatementMappingData
{
    /**
     * @param  string|null  $amountColumn  one signed column, or null
     * @param  string|null  $moneyInColumn  money arriving in the account, or null
     * @param  string|null  $moneyOutColumn  money leaving the account, or null
     * @param  string|null  $balanceColumn  the bank's running balance, or null
     * @param  bool  $positiveIsMoneyIn  only meaningful with $amountColumn
     */
    public function __construct(
        public int $companyId,
        public int $bankAccountId,
        public string $uploadToken,
        public StatementDateFormat $dateFormat,
        public string $dateColumn,
        public ?string $amountColumn = null,
        public bool $positiveIsMoneyIn = true,
        public ?string $moneyInColumn = null,
        public ?string $moneyOutColumn = null,
        public ?string $referenceColumn = null,
        public ?string $descriptionColumn = null,
        public ?string $balanceColumn = null,
        public ?int $userId = null,
    ) {}

    /**
     * The role each named column plays, as role => column. A role with no
     * column is left out, so this is exactly the set of cells the importer will
     * read.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        $roles = [
            'date' => $this->dateColumn,
            'amount' => $this->amountColumn,
            'money in' => $this->moneyInColumn,
            'money out' => $this->moneyOutColumn,
            'reference' => $this->referenceColumn,
            'description' => $this->descriptionColumn,
            'balance' => $this->balanceColumn,
        ];

        return array_filter($roles, static fn (?string $column): bool => $column !== null && $column !== '');
    }
}
