<?php

namespace App\Domain\Importing\DTOs;

/**
 * One thing wrong with one row of an import file.
 *
 * The physical file line is carried rather than the row's position, because a
 * person acting on a refusal is looking at a spreadsheet: "line 47" is a row
 * they can find, whereas "the 46th data row" is not once the file has blank
 * lines in it.
 *
 * The named constructors below are the whole vocabulary of what a row can get
 * wrong, kept here rather than in the importers so that the sentence a person
 * reads is written once. Line 0 is used for a complaint about the file as a
 * whole rather than about one row of it — an opening journal that does not
 * balance, or a stock value the layers do not agree with.
 */
final readonly class ImportRowError
{
    public function __construct(
        public int $line,
        public string $field,
        public string $message,
    ) {}

    /**
     * A complaint about the file rather than about one of its rows.
     */
    public static function file(string $message): self
    {
        return new self(0, '', $message);
    }

    /**
     * @param  list<int>  $lines
     */
    public static function duplicateInFile(int $line, string $field, string $label, string $value, array $lines): self
    {
        return new self($line, $field, sprintf(
            'The file gives the %s "%s" more than once (lines %s). Each row has to be a different %s.',
            $label,
            $value,
            self::list($lines),
            $label
        ));
    }

    public static function alreadyExists(int $line, string $field, string $label, string $value, string $entity): self
    {
        return new self($line, $field, sprintf(
            'A %s with the %s "%s" is already in the books, so importing it would create a second one. Remove the row, or correct its %s.',
            $entity,
            $label,
            $value,
            $label
        ));
    }

    public static function missing(int $line, string $field, string $label): self
    {
        return new self($line, $field, sprintf(
            'The %s is empty, and every row needs one.',
            $label
        ));
    }

    public static function tooLong(int $line, string $field, string $label, int $limit): self
    {
        return new self($line, $field, sprintf(
            'The %s is longer than the %d characters the books hold.',
            $label,
            $limit
        ));
    }

    public static function unreadable(int $line, string $field, string $label, string $value): self
    {
        return new self($line, $field, sprintf(
            '"%s" is not a valid %s.',
            $value,
            $label
        ));
    }

    public static function unreadableAmount(int $line, string $field, string $label, string $value): self
    {
        return new self($line, $field, sprintf(
            '"%s" is not an amount, so the %s column cannot be read.',
            $value,
            $label
        ));
    }

    public static function commaDecimal(int $line, string $field, string $label, string $value): self
    {
        return new self($line, $field, sprintf(
            '"%s" in the %s column looks like a figure written with a comma as the decimal separator. '
            .'This importer reads a full stop as the decimal separator, so convert the column before importing.',
            $value,
            $label
        ));
    }

    public static function amountOutOfRange(int $line, string $field, string $label, string $value): self
    {
        return new self($line, $field, sprintf(
            '"%s" in the %s column is larger than the books can hold: an amount may have at most 15 digits before the decimal point.',
            $value,
            $label
        ));
    }

    public static function unreadableQuantity(int $line, string $field, string $label, string $value): self
    {
        return new self($line, $field, sprintf(
            '"%s" is not a quantity, so the %s column cannot be read.',
            $value,
            $label
        ));
    }

    public static function negative(int $line, string $field, string $label, string $value): self
    {
        return new self($line, $field, sprintf(
            '"%s" in the %s column is negative. Write each side as a positive figure and put it in the column it belongs to.',
            $value,
            $label
        ));
    }

    public static function unreadableFlag(int $line, string $field, string $label, string $value): self
    {
        return new self($line, $field, sprintf(
            '"%s" is not a yes or no, so the %s column cannot be read. Write yes or no (or leave it empty).',
            $value,
            $label
        ));
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unreadableOption(int $line, string $field, string $label, string $value, array $allowed): self
    {
        return new self($line, $field, sprintf(
            '"%s" is not one of the values the %s column takes (%s).',
            $value,
            $label,
            implode(', ', $allowed)
        ));
    }

    public static function unknownReference(int $line, string $field, string $label, string $value, string $entity): self
    {
        return new self($line, $field, sprintf(
            'There is no %s with the code "%s" in these books, so the %s cannot be resolved.',
            $entity,
            $value,
            $label
        ));
    }

    public static function notPostable(int $line, string $field, string $label, string $code): self
    {
        return new self($line, $field, sprintf(
            'Account %s is a heading and cannot be posted to, so it cannot appear in the %s column.',
            $code,
            $label
        ));
    }

    public static function bothSidesFilled(int $line): self
    {
        return new self($line, 'amount', sprintf(
            'Line %d fills in both a debit and a credit. One opening line moves money one way, so it cannot be both.',
            $line
        ));
    }

    public static function zeroQuantity(int $line, string $field, string $label): self
    {
        return new self($line, $field, sprintf(
            'The %s is zero, so this row would lay down an empty stock layer.',
            $label
        ));
    }

    public static function noAmount(int $line): self
    {
        return new self($line, 'amount', sprintf(
            'Line %d moves nothing, so there is nothing to open the books with.',
            $line
        ));
    }

    public static function unbalancedOpening(string $debit, string $credit): self
    {
        return self::file(sprintf(
            'The opening journal does not balance: its debits come to %s and its credits to %s. '
            .'An opening journal is one balanced entry, so the two sides have to agree.',
            $debit,
            $credit
        ));
    }

    public static function inventoryMismatch(string $journalValue, string $stockValue): self
    {
        return self::file(sprintf(
            'The opening journal opens the inventory accounts at %s, but the opening stock laid down is worth %s. '
            .'The two have to agree before the books can be opened, because the ledger and the layers would otherwise disagree from the first day.',
            $journalValue,
            $stockValue
        ));
    }

    /**
     * @return array{line: int, field: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'field' => $this->field,
            'message' => $this->message,
        ];
    }

    public function __toString(): string
    {
        return $this->line === 0
            ? $this->message
            : sprintf('Line %d: %s', $this->line, $this->message);
    }

    /**
     * @param  list<int>  $lines
     */
    private static function list(array $lines): string
    {
        sort($lines);

        $shown = array_slice($lines, 0, 10);

        $text = implode(', ', $shown);

        return count($lines) > count($shown)
            ? $text.', and '.(count($lines) - count($shown)).' more'
            : $text;
    }
}
