<?php

namespace App\Domain\Access;

/**
 * The authorisation matrix, in one place.
 *
 * Permissions are named `resource.action` and are global; roles are per
 * company, because config/permission.php runs Spatie in teams mode keyed on
 * company_id. Attaching a role to a member therefore always names the company
 * whose books that membership applies to.
 *
 * Owner is the only role that may manage other members, and Auditor may read
 * but never write: an audit that can post its own journals is not an audit.
 */
final class AccessMatrix
{
    public const PERMISSION_JOURNAL_VIEW = 'journal.view';

    public const PERMISSION_JOURNAL_CREATE = 'journal.create';

    public const PERMISSION_JOURNAL_POST = 'journal.post';

    public const PERMISSION_JOURNAL_REVERSE = 'journal.reverse';

    public const PERMISSION_REPORT_VIEW = 'report.view';

    /**
     * Maintaining the chart of accounts, as opposed to reading the books it
     * describes. Today the only thing that needs it is classifying an account
     * on the cash flow statement, which is a decision about how the books are
     * presented and not a posting, so it is deliberately not folded into any of
     * the journal rights: an Accountant who may post a journal may also say
     * which activity its account belongs to, and that is the whole of it.
     */
    public const PERMISSION_ACCOUNT_MANAGE = 'account.manage';

    public const PERMISSION_USER_MANAGE = 'user.manage';

    public const PERMISSION_PARTY_VIEW = 'party.view';

    public const PERMISSION_PARTY_MANAGE = 'party.manage';

    public const PERMISSION_INVOICE_VIEW = 'invoice.view';

    public const PERMISSION_INVOICE_MANAGE = 'invoice.manage';

    public const PERMISSION_INVOICE_ISSUE = 'invoice.issue';

    public const PERMISSION_BILL_VIEW = 'bill.view';

    public const PERMISSION_BILL_MANAGE = 'bill.manage';

    public const PERMISSION_BILL_APPROVE = 'bill.approve';

    public const PERMISSION_BILL_POST = 'bill.post';

    public const PERMISSION_PAYMENT_VIEW = 'payment.view';

    public const PERMISSION_PAYMENT_RECORD = 'payment.record';

    public const PERMISSION_BANK_VIEW = 'bank.view';

    public const PERMISSION_BANK_MANAGE = 'bank.manage';

    public const PERMISSION_BANK_RECONCILE = 'bank.reconcile';

    /**
     * Reading products, warehouses, quantities on hand and what they are worth.
     */
    public const PERMISSION_STOCK_VIEW = 'stock.view';

    /**
     * Maintaining the item and warehouse lists. Separate from moving stock
     * because adding a product to the catalogue and counting it out of a
     * warehouse are different jobs, and a member trusted with one is not
     * automatically trusted with the other.
     */
    public const PERMISSION_STOCK_MANAGE = 'stock.manage';

    /**
     * Receiving, issuing, adjusting and transferring stock, and reversing any
     * of those. Distinct from the journal rights for the same reason the
     * document rights are: this posts, and posting is the thing that has to be
     * granted rather than inherited.
     */
    public const PERMISSION_STOCK_POST = 'stock.post';

    /**
     * Changing the policy a company keeps its books under, as opposed to
     * reading them or posting to them.
     *
     * Today the only thing that needs it is the inventory policy: which method
     * the stock is valued by, and whether the quantity on hand may go below
     * zero. Neither is a posting — nothing reaches the ledger when either is
     * set — which is why it is not folded into the stock rights that move the
     * stock itself. What it is instead is the choice that decides what every
     * later number means, so it is granted deliberately rather than inherited
     * from being allowed to move stock.
     */
    public const PERMISSION_COMPANY_MANAGE = 'company.manage';

    /**
     * Closing a fiscal year: writing the period's result into retained
     * earnings, then shutting every period of the year and the year itself
     * against further postings.
     *
     * This is the one action in the system that turns a set of books into a
     * historical fact, and it is reversible only by reopening the year. It is
     * therefore its own right rather than part of `journal.post`: an accountant
     * may post any number of ordinary journals without being the person who
     * declares the year finished.
     */
    public const PERMISSION_FISCAL_YEAR_CLOSE = 'fiscal_year.close';

    /**
     * Reading the audit chain: what was changed, by whom, and when.
     *
     * This is deliberately its own right rather than part of `report.view`.
     * A report says what the books now hold; the audit log says who changed
     * them and what they held before, which is the evidence behind every other
     * number. It is granted to the Owner — who answers for every change — and
     * to the Auditor, whose whole job is to read it. It is withheld from the
     * Read-only role, which may see the books but not the trail behind them.
     */
    public const PERMISSION_AUDIT_VIEW = 'audit.view';

    public const ROLE_OWNER = 'Owner';

    public const ROLE_ACCOUNTANT = 'Accountant';

    /**
     * The second pair of hands on a supplier bill: they may approve and post a
     * bill, but not write or edit one. This is what makes approval a control
     * rather than a second signature by the person who entered the document.
     */
    public const ROLE_FINANCE_OFFICER = 'Finance Officer';

    public const ROLE_CASHIER = 'Cashier';

    /**
     * Keeps the stock books and the party lists they name: may receive, issue,
     * adjust, transfer and correct stock, and read who it came from and went
     * to. Deliberately kept away from the financial books — this role does not
     * post journals, take payments or manage members.
     */
    public const ROLE_INVENTORY_MANAGER = 'Inventory Manager';

    public const ROLE_AUDITOR = 'Auditor';

    /**
     * Sees everything the books report and nothing more: the eight `.view`
     * rights, without `audit.view`. Given to a member who must be able to look
     * but must not be trusted with the trail of who changed what.
     */
    public const ROLE_READ_ONLY = 'Read-only';

    /**
     * Every permission the application knows about.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        return [
            self::PERMISSION_JOURNAL_VIEW,
            self::PERMISSION_JOURNAL_CREATE,
            self::PERMISSION_JOURNAL_POST,
            self::PERMISSION_JOURNAL_REVERSE,
            self::PERMISSION_REPORT_VIEW,
            self::PERMISSION_ACCOUNT_MANAGE,
            self::PERMISSION_USER_MANAGE,
            self::PERMISSION_PARTY_VIEW,
            self::PERMISSION_PARTY_MANAGE,
            self::PERMISSION_INVOICE_VIEW,
            self::PERMISSION_INVOICE_MANAGE,
            self::PERMISSION_INVOICE_ISSUE,
            self::PERMISSION_BILL_VIEW,
            self::PERMISSION_BILL_MANAGE,
            self::PERMISSION_BILL_APPROVE,
            self::PERMISSION_BILL_POST,
            self::PERMISSION_PAYMENT_VIEW,
            self::PERMISSION_PAYMENT_RECORD,
            self::PERMISSION_BANK_VIEW,
            self::PERMISSION_BANK_MANAGE,
            self::PERMISSION_BANK_RECONCILE,
            self::PERMISSION_STOCK_VIEW,
            self::PERMISSION_STOCK_MANAGE,
            self::PERMISSION_STOCK_POST,
            self::PERMISSION_COMPANY_MANAGE,
            self::PERMISSION_FISCAL_YEAR_CLOSE,
            self::PERMISSION_AUDIT_VIEW,
        ];
    }

    /**
     * Role name => the permissions granted to it.
     *
     * @return array<string, list<string>>
     */
    public static function grants(): array
    {
        return [
            self::ROLE_OWNER => self::permissions(),

            self::ROLE_ACCOUNTANT => [
                self::PERMISSION_JOURNAL_VIEW,
                self::PERMISSION_JOURNAL_CREATE,
                self::PERMISSION_JOURNAL_POST,
                self::PERMISSION_JOURNAL_REVERSE,
                self::PERMISSION_REPORT_VIEW,
                self::PERMISSION_ACCOUNT_MANAGE,
                self::PERMISSION_PARTY_VIEW,
                self::PERMISSION_PARTY_MANAGE,
                self::PERMISSION_INVOICE_VIEW,
                self::PERMISSION_INVOICE_MANAGE,
                self::PERMISSION_INVOICE_ISSUE,
                self::PERMISSION_BILL_VIEW,
                self::PERMISSION_BILL_MANAGE,
                // Deliberately absent: PERMISSION_BILL_APPROVE. The accountant
                // is the person who enters a supplier bill, so they are also
                // the person most able to enter a fictitious one. Approval is
                // left to the Owner, which is what makes the approval gate a
                // control rather than a second signature by the same hand.
                self::PERMISSION_BILL_POST,
                self::PERMISSION_PAYMENT_VIEW,
                self::PERMISSION_PAYMENT_RECORD,
                // The accountant reconciles the bank, so they must be able to
                // see and open the accounts the money sits in.
                self::PERMISSION_BANK_VIEW,
                self::PERMISSION_BANK_MANAGE,
                // Matching and signing off a reconciliation. This is not a
                // separate pair of hands from opening the account: the person
                // who keeps the cashbook is the person who compares it with the
                // bank's file. What it is separate from is reading, which the
                // Auditor keeps and this does not grant.
                self::PERMISSION_BANK_RECONCILE,
                // Stock. The accountant keeps the stock ledger as well as the
                // financial one, so they may receive, issue and adjust — and,
                // because a wrong movement is corrected rather than removed,
                // reverse what they posted.
                self::PERMISSION_STOCK_VIEW,
                self::PERMISSION_STOCK_MANAGE,
                self::PERMISSION_STOCK_POST,
                // Which method the stock is valued by is an accounting policy,
                // so the person who keeps the stock ledger is the person who
                // may answer it — and the lock holds regardless of who asks.
                // Left out of the Cashier's and the Auditor's grants, because
                // neither keeps the books and neither should change what the
                // numbers already written mean.
                self::PERMISSION_COMPANY_MANAGE,
                // Deliberately absent: PERMISSION_FISCAL_YEAR_CLOSE. Closing
                // the year turns a set of books into a historical fact and is
                // reversible only by reopening it, so it is kept for the Owner
                // alone. An accountant may post any number of ordinary journals
                // without being the person who declares the year finished.
            ],

            // The second pair of hands on a supplier bill. They may read a bill,
            // approve it and post it — but not write or edit one, which is what
            // keeps approval independent of the member who entered the document.
            // They keep the general ledger beside it, so they hold the journal,
            // payment, bank and stock rights the Accountant does; what they do
            // not hold is PERMISSION_BILL_MANAGE, PERMISSION_USER_MANAGE and
            // PERMISSION_FISCAL_YEAR_CLOSE.
            self::ROLE_FINANCE_OFFICER => [
                self::PERMISSION_JOURNAL_VIEW,
                self::PERMISSION_JOURNAL_CREATE,
                self::PERMISSION_JOURNAL_POST,
                self::PERMISSION_JOURNAL_REVERSE,
                self::PERMISSION_REPORT_VIEW,
                self::PERMISSION_ACCOUNT_MANAGE,
                self::PERMISSION_PARTY_VIEW,
                self::PERMISSION_PARTY_MANAGE,
                self::PERMISSION_INVOICE_VIEW,
                self::PERMISSION_INVOICE_MANAGE,
                self::PERMISSION_INVOICE_ISSUE,
                self::PERMISSION_BILL_VIEW,
                // Approve, don't enter: the bill rights they hold stop short of
                // PERMISSION_BILL_MANAGE, so they can never be the person who
                // wrote the bill they are signing off.
                self::PERMISSION_BILL_APPROVE,
                self::PERMISSION_BILL_POST,
                self::PERMISSION_PAYMENT_VIEW,
                self::PERMISSION_PAYMENT_RECORD,
                self::PERMISSION_BANK_VIEW,
                self::PERMISSION_BANK_MANAGE,
                self::PERMISSION_BANK_RECONCILE,
                self::PERMISSION_STOCK_VIEW,
                self::PERMISSION_STOCK_MANAGE,
                self::PERMISSION_STOCK_POST,
            ],

            // A cashier takes money over a counter: they must be able to look a
            // customer up, but maintaining the customer list is not their job.
            // They may see what a customer owes and take the money in, but
            // raising the invoice is the accountant's job.
            self::ROLE_CASHIER => [
                self::PERMISSION_JOURNAL_VIEW,
                self::PERMISSION_JOURNAL_CREATE,
                self::PERMISSION_REPORT_VIEW,
                self::PERMISSION_PARTY_VIEW,
                self::PERMISSION_INVOICE_VIEW,
                self::PERMISSION_PAYMENT_VIEW,
                self::PERMISSION_PAYMENT_RECORD,
                // A cashier has to know which till they are banking into, but
                // opening a new account to bank into is not their call.
                self::PERMISSION_BANK_VIEW,
                // What is on the shelf behind the counter, so a cashier can
                // answer a customer. Counting it in and out is not their job.
                self::PERMISSION_STOCK_VIEW,
            ],

            // Keeps the stock books rather than the financial ones: receives,
            // issues, adjusts, transfers and corrects stock, and reads the
            // customers and suppliers those movements name. Nothing here
            // reaches the ledger — recorded stock movements post through their
            // own right — so this role carries no journal, payment or member
            // rights, and is the one role the financial books cannot see.
            self::ROLE_INVENTORY_MANAGER => [
                self::PERMISSION_STOCK_VIEW,
                self::PERMISSION_STOCK_MANAGE,
                self::PERMISSION_STOCK_POST,
                self::PERMISSION_PARTY_VIEW,
                self::PERMISSION_REPORT_VIEW,
            ],

            // Reads the books and never writes to them. `audit.view` is the
            // Auditor's own right: reading what the books now hold is not the
            // same as reading who changed them and what they held before, and
            // the trail is exactly what an audit is for.
            self::ROLE_AUDITOR => [
                self::PERMISSION_JOURNAL_VIEW,
                self::PERMISSION_REPORT_VIEW,
                self::PERMISSION_PARTY_VIEW,
                self::PERMISSION_INVOICE_VIEW,
                self::PERMISSION_BILL_VIEW,
                self::PERMISSION_PAYMENT_VIEW,
                self::PERMISSION_BANK_VIEW,
                self::PERMISSION_STOCK_VIEW,
                self::PERMISSION_AUDIT_VIEW,
            ],

            // Sees everything the books report and nothing more. The eight
            // `.view` rights without `audit.view`: a member who may look but
            // must not be trusted with the trail of who changed what.
            self::ROLE_READ_ONLY => [
                self::PERMISSION_JOURNAL_VIEW,
                self::PERMISSION_REPORT_VIEW,
                self::PERMISSION_PARTY_VIEW,
                self::PERMISSION_INVOICE_VIEW,
                self::PERMISSION_BILL_VIEW,
                self::PERMISSION_PAYMENT_VIEW,
                self::PERMISSION_BANK_VIEW,
                self::PERMISSION_STOCK_VIEW,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return array_keys(self::grants());
    }

    /**
     * The role a freshly invited member starts with, before an Owner changes
     * it: least privilege that still lets them see the books.
     */
    public static function defaultRole(): string
    {
        return self::ROLE_AUDITOR;
    }
}
