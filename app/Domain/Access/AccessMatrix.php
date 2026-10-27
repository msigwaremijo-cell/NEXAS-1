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

    public const ROLE_OWNER = 'Owner';

    public const ROLE_ACCOUNTANT = 'Accountant';

    public const ROLE_CASHIER = 'Cashier';

    public const ROLE_AUDITOR = 'Auditor';

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
            ],

            // Reads the books and never writes to them.
            self::ROLE_AUDITOR => [
                self::PERMISSION_JOURNAL_VIEW,
                self::PERMISSION_REPORT_VIEW,
                self::PERMISSION_PARTY_VIEW,
                self::PERMISSION_INVOICE_VIEW,
                self::PERMISSION_BILL_VIEW,
                self::PERMISSION_PAYMENT_VIEW,
                self::PERMISSION_BANK_VIEW,
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
