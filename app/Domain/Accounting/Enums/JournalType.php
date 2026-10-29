<?php

namespace App\Domain\Accounting\Enums;

/**
 * The kinds of entry the books are written with.
 *
 * This began as the `journals.journal_type` column comment
 * ('general|sales|purchase|receipt|payment|credit_note|opening|closing|reversal|transfer')
 * and has since grown a `stock` case of its own, so the column comment is the
 * original set rather than the whole of it. The column is a plain VARCHAR, so
 * the enum is the authority.
 */
enum JournalType: string
{
    case General = 'general';
    case Sales = 'sales';
    case Purchase = 'purchase';
    case Receipt = 'receipt';
    case Payment = 'payment';
    case CreditNote = 'credit_note';
    case Opening = 'opening';
    case Closing = 'closing';
    case Reversal = 'reversal';

    /**
     * Money moved between two of the company's own accounts. It is not a
     * receipt or a payment because no third party is involved, and it is kept
     * apart from a general journal because a cash flow statement has to be able
     * to leave it out: moving money between your own accounts is not a cash
     * flow, and counting both sides would invent an inflow and an outflow that
     * never happened.
     */
    case Transfer = 'transfer';

    /**
     * Stock moved through a warehouse.
     *
     * A receipt, an issue and an adjustment each change what the inventory is
     * worth, so each posts one of these and names the counter-account it
     * moved the value against. It is kept apart from a general journal
     * because a stock entry has an item and a quantity behind it that a
     * manual entry never does.
     *
     * A transfer between two of the company's own warehouses changes the
     * total by nothing and posts nothing at all, so no stock journal is
     * written for it.
     */
    case Stock = 'stock';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General journal',
            self::Sales => 'Sales journal',
            self::Purchase => 'Purchase journal',
            self::Receipt => 'Receipt',
            self::Payment => 'Payment',
            self::CreditNote => 'Credit note',
            self::Opening => 'Opening balances',
            self::Closing => 'Closing entries',
            self::Reversal => 'Reversal',
            self::Transfer => 'Transfer',
            self::Stock => 'Stock movement',
        };
    }
}
