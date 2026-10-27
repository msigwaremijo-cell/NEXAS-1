<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Enums\PartyType;
use App\Support\Money;
use InvalidArgumentException;

/**
 * One debit or credit proposed for a journal.
 *
 * The debit XOR credit rule is checked here as well as by
 * chk_journal_lines_debit_xor_credit, so a caller learns about a mistake from
 * a sentence rather than from a MySQL SQLSTATE.
 *
 * debit, credit and taxAmount are held as Money / decimal strings rather than
 * floats: a float cannot round-trip a DECIMAL(19,4).
 */
final class JournalLineData
{
    public readonly Money $debit;

    public readonly Money $credit;

    public readonly string $taxAmount;

    public function __construct(
        public readonly int $accountId,
        mixed $debit = '0',
        mixed $credit = '0',
        public readonly ?string $description = null,
        public readonly ?string $currencyCode = null,
        public readonly ?string $exchangeRate = null,
        public readonly ?int $taxCodeId = null,
        mixed $taxAmount = '0',
        public readonly ?PartyType $partyType = null,
        public readonly ?int $partyId = null,
        public readonly ?int $branchId = null,
    ) {
        $this->debit = Money::of($debit);
        $this->credit = Money::of($credit);
        $this->taxAmount = Money::of($taxAmount)->toDecimal();

        if ($this->debit->isNegative() || $this->credit->isNegative()) {
            throw new InvalidArgumentException('A journal line cannot carry a negative amount.');
        }

        if ($this->debit->isPositive() === $this->credit->isPositive()) {
            throw new InvalidArgumentException(
                'A journal line must carry an amount on exactly one side: '
                .'debit or credit, never both and never neither.'
            );
        }
    }

    public static function debit(int $accountId, mixed $amount, ?string $description = null): self
    {
        return new self($accountId, $amount, '0', $description);
    }

    public static function credit(int $accountId, mixed $amount, ?string $description = null): self
    {
        return new self($accountId, '0', $amount, $description);
    }

    /**
     * The amount regardless of which side it sits on.
     */
    public function amount(): Money
    {
        return $this->debit->isPositive() ? $this->debit : $this->credit;
    }
}
