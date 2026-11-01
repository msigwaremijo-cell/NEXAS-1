<?php

namespace App\Domain\Importing\Enums;

use App\Domain\Access\AccessMatrix;

/**
 * The kinds of opening data a company may bring in as a file.
 *
 * Each dataset is a step of a migration rather than a page of a screen: a
 * company moving onto these books names its accounts first, then the parties
 * it trades with, then its item list, then what is on the shelves, and finally
 * the balances the whole lot starts from. The order matters because later
 * datasets name earlier ones — opening stock names a product SKU and a
 * warehouse code, and opening balances name an account code.
 *
 * The permissions are the rights the same job would need done by hand rather
 * than a new set of import rights. Importing a chart of accounts is maintaining
 * the chart of accounts, so it is `account.manage`. Importing opening stock
 * creates the layers a receipt would have created and is checked against the
 * one opening journal, so it needs both the right to keep the stock lists and
 * the right that moves stock. Importing opening balances posts a journal, so it
 * needs the posting right — which is what keeps a Cashier, an Auditor and the
 * Read-only role out of every one of these surfaces.
 */
enum ImportDataset: string
{
    case ChartOfAccounts = 'chart_of_accounts';
    case Customers = 'customers';
    case Suppliers = 'suppliers';
    case Products = 'products';
    case OpeningStock = 'opening_stock';
    case OpeningBalances = 'opening_balances';

    public function label(): string
    {
        return match ($this) {
            self::ChartOfAccounts => 'Chart of accounts',
            self::Customers => 'Customers',
            self::Suppliers => 'Suppliers',
            self::Products => 'Products',
            self::OpeningStock => 'Opening stock',
            self::OpeningBalances => 'Opening balances',
        };
    }

    /**
     * Every right the dataset needs, all of which the caller must hold.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::ChartOfAccounts => [AccessMatrix::PERMISSION_ACCOUNT_MANAGE],
            self::Customers, self::Suppliers => [AccessMatrix::PERMISSION_PARTY_MANAGE],
            self::Products => [AccessMatrix::PERMISSION_STOCK_MANAGE],
            // Creates FIFO layers at the imported cost and is refused unless
            // the books' own inventory balance agrees with their total, so it
            // is both a stock-list job and a stock-moving job.
            self::OpeningStock => [
                AccessMatrix::PERMISSION_STOCK_MANAGE,
                AccessMatrix::PERMISSION_STOCK_POST,
            ],
            // Posts exactly one journal, so it is the posting right that
            // governs it.
            self::OpeningBalances => [AccessMatrix::PERMISSION_JOURNAL_POST],
        };
    }

    /**
     * Whether the dataset needs a date to be read against. Opening stock is
     * dated when it was counted, and the opening journal is dated when the
     * books begin, so both ask the caller for the date rather than reading one
     * out of the file.
     */
    public function needsAsOf(): bool
    {
        return $this === self::OpeningStock || $this === self::OpeningBalances;
    }
}
