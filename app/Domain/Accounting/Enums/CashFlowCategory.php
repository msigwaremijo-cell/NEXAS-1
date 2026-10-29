<?php

namespace App\Domain\Accounting\Enums;

/**
 * How an account's movements are presented on the cash flow statement.
 *
 * A cash flow statement is built from the movements in the company's cash and
 * bank accounts, classified by the account on the other side of each entry. A
 * receipt from a customer is operating because receivables are operating;
 * buying a vehicle is investing because vehicles are. So the question "which
 * activity is this movement?" is really "which activity is the account on the
 * other side?", and the answer has to live on the account.
 *
 * The category is therefore data on `accounts.cash_flow_category`, and the
 * chart of accounts seeds an answer for every standard account
 * (database/seeders/ChartOfAccountsSeeder.php) so a new company starts with a
 * statement that adds up rather than one that is entirely unclassified. It is
 * null on an account nobody has classified, and the statement reports that
 * rather than hiding it: an unclassified movement appears on a visible line and
 * marks the report incomplete.
 *
 * InternalTransfer is not a fourth activity. It is the label for the company's
 * own money — its tills and bank accounts — and it is what makes the exclusion
 * of internal transfers legible on the chart of accounts. A movement between
 * two of those accounts is not a cash flow at all: the company is no richer for
 * having moved money from one pocket to another, and counting both sides would
 * invent an inflow and an outflow that never happened.
 */
enum CashFlowCategory: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';
    case InternalTransfer = 'internal_transfer';

    /**
     * The activity labels, in the order a statement is read: the trading
     * activity first, then what was bought to trade with, then how it was
     * funded.
     *
     * @return list<self>
     */
    public static function statementOrder(): array
    {
        return [self::Operating, self::Investing, self::Financing];
    }

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Operating activities',
            self::Investing => 'Investing activities',
            self::Financing => 'Financing activities',
            self::InternalTransfer => 'Internal transfers',
        };
    }
}
