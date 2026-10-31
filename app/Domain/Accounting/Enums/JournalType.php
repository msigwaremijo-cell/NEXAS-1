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

    /**
     * A supplier debit note: the purchase side correcting a bill it approved.
     *
     * It is kept apart from the credit note because the two correct opposite
     * sides of the books — one answers a sale, the other a purchase — and a
     * report that lumped them together could not tell a return of goods the
     * company bought from a return of goods it sold.
     */
    case DebitNote = 'debit_note';

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

    /**
     * A period's depreciation charge on the fixed assets.
     *
     * Its own case rather than a general journal because it is written by the
     * depreciation run and by nothing else, and the run has to be able to find
     * the entry it wrote for a period in order to refuse a second one. It
     * carries one line per asset, so a reader can see which assets the charge
     * was made of.
     */
    case Depreciation = 'depreciation';

    /**
     * The purchase of a fixed asset: its cost debited to the category's asset
     * account against whatever met the cost.
     *
     * Its own case rather than a purchase journal because a purchase journal
     * belongs to a supplier bill, and an asset may just as well be bought for
     * cash with no bill behind it. Keeping it apart is what lets the register's
     * cost be found in the ledger without reading every purchase.
     */
    case Acquisition = 'acquisition';

    /**
     * The removal of a fixed asset: its cost and accumulated depreciation
     * taken out of the books, and any gain or loss booked.
     *
     * Its own case so a disposal can be found and read on its own — what was
     * removed, for how much, and which asset it was — rather than a general
     * journal that has to be recognised by its account codes.
     */
    case Disposal = 'disposal';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General journal',
            self::Sales => 'Sales journal',
            self::Purchase => 'Purchase journal',
            self::Receipt => 'Receipt',
            self::Payment => 'Payment',
            self::CreditNote => 'Credit note',
            self::DebitNote => 'Debit note',
            self::Opening => 'Opening balances',
            self::Closing => 'Closing entries',
            self::Reversal => 'Reversal',
            self::Transfer => 'Transfer',
            self::Stock => 'Stock movement',
            self::Depreciation => 'Depreciation',
            self::Acquisition => 'Asset acquisition',
            self::Disposal => 'Asset disposal',
        };
    }
}
