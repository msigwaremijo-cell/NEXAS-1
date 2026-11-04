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
 *
 * The Financial Manager is the only role that may decide an approval, and the
 * Owner — who otherwise holds every right — is deliberately not among them, so
 * the decision is always a second pair of hands.
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
     * Reopening a closed fiscal period so a correction can be posted into it.
     *
     * The undo of the close above, and the more dangerous half of it: a reopen
     * puts a period that has already been reported back into the books. It is
     * granted to the Owner and the Financial Manager, like the close it
     * reverses, and every reopen has to name a reason, so the trail says why a
     * reported period was opened again rather than merely that it was.
     *
     * A separate key from `fiscal_year.close` on purpose, even though the same
     * person holds both today: the two are granted independently, so handing
     * somebody the close never hands them the reopen by inheritance.
     */
    public const PERMISSION_FISCAL_PERIOD_REOPEN = 'fiscal_period.reopen';

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

    /**
     * Reading what the company plans to earn and spend, and how the plan
     * compares with what actually happened.
     *
     * Its own right rather than part of `report.view`, because a plan is not the
     * books. A budget is a statement of intent that the ledger knows nothing
     * about — writing one posts no journal — so being trusted to read the books
     * is not the same as being trusted with next year's numbers. It is kept
     * from the Read-only role and from the Cashier for that reason: both read
     * what the books report, and neither is shown what the company intends.
     */
    public const PERMISSION_BUDGET_VIEW = 'budget.view';

    /**
     * Writing a plan: the amounts and the cost centres they are split across.
     *
     * Separate from `budget.view` for the ordinary reason — reading a plan is
     * not deciding it — and separate from every posting right for the reason
     * the plan is not a posting. Nothing here reaches the ledger, so this is
     * not a second way to move the books; it is the right to say what the
     * company means to do, which is deliberately held by fewer people than may
     * read the result.
     */
    public const PERMISSION_BUDGET_MANAGE = 'budget.manage';

    /**
     * Reading the fixed-asset register: what the company holds, what it cost,
     * what has been depreciated against it and what it is worth in the books.
     *
     * The register is part of what the books report, so this sits alongside the
     * other `.view` rights rather than behind `report.view`: a member who may
     * read the trial balance may read the assets it is made of.
     */
    public const PERMISSION_ASSET_VIEW = 'asset.view';

    /**
     * Keeping the register: the categories that decide where a class of assets
     * posts, and the assets themselves.
     *
     * Separate from `asset.post` for the reason the other document rights are
     * separate from journal rights: entering an asset is a decision about what
     * the company holds, and depreciation is a posting. An asset enters service
     * by posting its cost, but that posting is the consequence of the entry
     * rather than a separate act, so it travels with `manage`.
     */
    public const PERMISSION_ASSET_MANAGE = 'asset.manage';

    /**
     * Charging depreciation and taking an asset out of the books.
     *
     * These are the two things that move the ledger on their own — a month's
     * charge, and a disposal with its gain or loss — so they are their own right
     * rather than inherited from being able to enter the asset. Deliberately not
     * held by anyone who only reads the register.
     */
    public const PERMISSION_ASSET_POST = 'asset.post';

    /**
     * Reading the documents filed beside records: a supplier's invoice, a
     * delivery note, a signed receipt.
     *
     * A document filed against a posted entry is part of the account of that
     * entry, so reading one is reading the books and this is held by every role
     * that may read them. It is deliberately its own right rather than folded
     * into the `.view` right of whichever record a document happens to sit
     * beside: the documents are one shelf, and a member trusted to read the
     * shelf is decided once rather than once per record type.
     */
    public const PERMISSION_ATTACHMENT_VIEW = 'attachment.view';

    /**
     * Filing a document against a record, and withdrawing one.
     *
     * This is a write, but not a posting: filing a document changes nothing in
     * the ledger and nothing about the record it sits beside, which is why it
     * is separate from every posting right and why a record already posted can
     * still receive one. It is held by the members who keep the books — the
     * Owner, the Accountant and the Finance Officer — because deciding what
     * evidence belongs with an entry is part of keeping it.
     */
    public const PERMISSION_ATTACHMENT_MANAGE = 'attachment.manage';

    /**
     * Reading the approvals queue: which documents are waiting on a decision,
     * who raised them and what became of the ones already decided.
     *
     * Its own right rather than part of the `.view` right of whichever document
     * a request happens to name, because the queue is one shelf: a journal, a
     * bill, a payment, a credit or debit note and an asset disposal all wait in
     * the same line, and the person who watches that line is decided once
     * rather than once per document type.
     */
    public const PERMISSION_APPROVAL_VIEW = 'approval.view';

    /**
     * Raising a document for a decision: putting it in the queue.
     *
     * This is not a posting and not an approval. It is the member who keeps the
     * books saying "this one is ready for the second pair of eyes", which is
     * why it is held by the roles that write the documents rather than by the
     * role that decides them. Raising a request that the raiser then approves
     * is refused by the no-self-approval rule, not by this right.
     */
    public const PERMISSION_APPROVAL_REQUEST = 'approval.request';

    /**
     * Deciding an approval: accepting or rejecting what is in the queue.
     *
     * The one right the Financial Manager holds that nobody else does. It is
     * deliberately withheld from the Owner, who already holds every other
     * right: an approval that the person holding every other grant can give
     * themselves is not a control, and the point of the queue is that the
     * decision is made by a second pair of hands. It is withheld from the
     * Accountant for the same reason a supplier bill's approval is: the
     * accountant is the person who enters the documents, so they are the
     * person most able to enter a fictitious one.
     */
    public const PERMISSION_APPROVAL_DECIDE = 'approval.decide';

    /**
     * Reading the projection of the rest of the open year.
     *
     * Its own right rather than part of `budget.view`, because a projection is
     * neither the plan nor the books. The plan says what the company means to
     * do and the projection says what the year is expected to do, so a member
     * trusted with one is not automatically trusted with the other — even
     * though the roles that read a plan are, in practice, the roles that read a
     * projection, and it is granted to the same set. It is kept from the
     * Read-only role and from the Cashier for the same reason `budget.view` is:
     * both read what the books report, and neither is shown what the company
     * expects. Nothing here reaches the ledger — a projection is read, never
     * posted — and every figure it returns carries the assumption behind it.
     */
    public const PERMISSION_FORECAST_VIEW = 'forecast.view';

    /**
     * Running the projection under changed assumptions: a different revenue
     * growth rate, a different expense rate, or an adjustment to a named
     * account.
     *
     * Separate from `forecast.view` because reading the projection the company
     * actually expects is not the same as asking what it would look like if the
     * year went differently, and the second question changes what a reader is
     * told. It is held by the Financial Manager and the Owner alone: the role
     * that sets the plan is the role that may test it. Nothing here is stored —
     * a scenario is a set of figures passed to a read, and the response records
     * no scenario — so this is not a second way to write anything. It is the
     * right to ask "what if", which is deliberately narrower than the right to
     * see the answer.
     */
    public const PERMISSION_FORECAST_SCENARIO = 'forecast.scenario';

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
     * Sees everything the books report and nothing more: the nine `.view`
     * rights, without `audit.view`. Given to a member who must be able to look
     * but must not be trusted with the trail of who changed what.
     */
    public const ROLE_READ_ONLY = 'Read-only';

    /**
     * The second pair of eyes on the financial books: the member who decides
     * the approvals queue, closes and reopens the calendar, signs off bank
     * reconciliations, keeps the plan and reads every report and the audit
     * trail.
     *
     * The role exists so that the decision on a document is made by somebody
     * other than the member who entered it — which is why it holds the one
     * right the Owner does not (`approval.decide`) and none of the writer's
     * rights: it never posts a journal, records a payment or moves stock. It
     * cannot administer the company's members (`user.manage`) or change the
     * policy the books are kept under (`company.manage`), which is the whole of
     * "cannot manage users or system settings".
     *
     * It is a separate portal as well as a separate grant, because a role that
     * signs off every number in the books should arrive at a screen built to do
     * nothing else.
     */
    public const ROLE_FINANCIAL_MANAGER = 'Financial Manager';

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
            self::PERMISSION_FISCAL_PERIOD_REOPEN,
            self::PERMISSION_AUDIT_VIEW,
            self::PERMISSION_BUDGET_VIEW,
            self::PERMISSION_BUDGET_MANAGE,
            self::PERMISSION_ASSET_VIEW,
            self::PERMISSION_ASSET_MANAGE,
            self::PERMISSION_ASSET_POST,
            self::PERMISSION_ATTACHMENT_VIEW,
            self::PERMISSION_ATTACHMENT_MANAGE,
            self::PERMISSION_APPROVAL_VIEW,
            self::PERMISSION_APPROVAL_REQUEST,
            self::PERMISSION_APPROVAL_DECIDE,
            self::PERMISSION_FORECAST_VIEW,
            self::PERMISSION_FORECAST_SCENARIO,
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
            // The Owner holds every right the matrix knows, with one deliberate
            // exception: `approval.decide`. The Owner's grant is shorthand for
            // "everything", and an approval right that the shorthand hands back
            // is an approval the person who already holds every other right can
            // give themselves — which is exactly the second signature the queue
            // exists to prevent. The decision is therefore excluded here rather
            // than added to every other role, so a new right arrives at the
            // Owner automatically and this one has to be argued for.
            self::ROLE_OWNER => array_values(array_diff(
                self::permissions(),
                [self::PERMISSION_APPROVAL_DECIDE],
            )),

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
                // The plan, both halves. The accountant keeps the books and is
                // the person who says what a period is expected to do, so the
                // right to write the plan is not separated from the right to
                // read the accounts it is written against. Neither of these is
                // a posting: nothing in a budget reaches the ledger.
                self::PERMISSION_BUDGET_VIEW,
                self::PERMISSION_BUDGET_MANAGE,
                // The projection built from those books. Read here for the same
                // reason the plan is: the accountant keeps the books and says
                // what a period is expected to do, so reading what the rest of
                // the year is expected to do follows. Writing a scenario is not
                // part of it — the assumptions are the Financial Manager's to
                // change, and `forecast.scenario` is absent.
                self::PERMISSION_FORECAST_VIEW,
                // Fixed assets. The accountant keeps the register and charges
                // the depreciation against it: entering an asset, running the
                // month and disposing of one are all part of keeping the books,
                // so the three rights are held together rather than split.
                self::PERMISSION_ASSET_VIEW,
                self::PERMISSION_ASSET_MANAGE,
                self::PERMISSION_ASSET_POST,
                // Documents filed beside the entries they keep. Filing one is
                // not a posting, so it travels with the right to keep the books
                // rather than with any one posting right.
                self::PERMISSION_ATTACHMENT_VIEW,
                self::PERMISSION_ATTACHMENT_MANAGE,
                // The approvals queue, both halves the accountant may hold. They
                // raise the documents that wait in it and read what became of
                // them, because a member who entered a journal needs to know
                // whether it has been accepted. What they do not hold is
                // `approval.decide`: approving their own entry is the second
                // signature the queue exists to prevent, and it is refused to
                // them at the queue rather than left to their discretion.
                self::PERMISSION_APPROVAL_VIEW,
                self::PERMISSION_APPROVAL_REQUEST,
                // Deliberately absent: PERMISSION_FISCAL_YEAR_CLOSE. Closing
                // the year turns a set of books into a historical fact and is
                // reversible only by reopening it, so it is kept for the
                // Financial Manager and the Owner rather than for the member
                // who posts the journals. An accountant may post any number of
                // ordinary journals without being the person who declares the
                // year finished.
                // PERMISSION_FISCAL_PERIOD_REOPEN is absent for the same
                // reason: reopening a reported period is undoing the close, not
                // a correction an accountant makes by posting another journal.
            ],

            // The second pair of hands on a supplier bill. They may read a bill,
            // approve it and post it — but not write or edit one, which is what
            // keeps approval independent of the member who entered the document.
            // They keep the general ledger beside it, so they hold the journal,
            // payment, bank and stock rights the Accountant does; what they do
            // not hold is PERMISSION_BILL_MANAGE, PERMISSION_USER_MANAGE,
            // PERMISSION_FISCAL_YEAR_CLOSE and PERMISSION_FISCAL_PERIOD_REOPEN.
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
                // Reads the plan because a second pair of eyes on a supplier
                // bill needs to know what the period was meant to spend. Does
                // not write it: the second pair of hands is on the approval,
                // and the plan is the accountant's to set.
                self::PERMISSION_BUDGET_VIEW,
                // The projection beside the plan, for the same reason: a second
                // pair of eyes on a supplier bill wants to know what the rest of
                // the year is expected to do, not only what the period was meant
                // to spend. The assumptions behind it are not this role's to
                // change, and `forecast.scenario` is absent.
                self::PERMISSION_FORECAST_VIEW,
                // The fixed-asset rights the Accountant holds, for the same
                // reason: this role keeps the general ledger beside the bills it
                // clears, and the register and its depreciation are part of it.
                self::PERMISSION_ASSET_VIEW,
                self::PERMISSION_ASSET_MANAGE,
                self::PERMISSION_ASSET_POST,
                // The documents filed beside the bills this role clears, for
                // the same reason it keeps the register: they are part of the
                // account of the entry, and filing one touches nothing.
                self::PERMISSION_ATTACHMENT_VIEW,
                self::PERMISSION_ATTACHMENT_MANAGE,
                // The queue they already work in: as the second pair of hands
                // on a supplier bill they read what is waiting and raise the
                // documents they keep for a decision. Deciding is absent — the
                // decision belongs to the Financial Manager, and this role's
                // own bill rights stop at its own bills.
                self::PERMISSION_APPROVAL_VIEW,
                self::PERMISSION_APPROVAL_REQUEST,
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
                // What the company holds in the way of equipment, but not what
                // it is worth this month and not the right to take one out of
                // the books: both of those post, and a cashier does not.
                self::PERMISSION_ASSET_VIEW,
                // Documents filed beside the entries this role reads. Reading
                // them is part of reading the books; filing one is not, so the
                // manage right is absent.
                self::PERMISSION_ATTACHMENT_VIEW,
                // A cashier takes money in, so a receipt is theirs to raise for
                // a decision. Reading the queue is not: they raise one and are
                // told its outcome, rather than reading every document in the
                // company's line.
                self::PERMISSION_APPROVAL_REQUEST,
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
                // A delivery note filed beside a movement is part of reading
                // the stock books, so it is read here. Filing one is a write to
                // the documents rather than a move of stock, and is absent.
                self::PERMISSION_ATTACHMENT_VIEW,
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
                // Auditing a period is comparing what the company meant to do
                // with what it did, so the plan is read here as well. Reading
                // the plan is not the same as reading the trail behind it,
                // which is why the two rights are kept apart.
                self::PERMISSION_BUDGET_VIEW,
                // The projection as well as the plan: an audit compares what the
                // company meant to do with what it expects to do, and reads the
                // answer without changing a single assumption. `forecast.scenario`
                // is absent — an audit reads the projection the company holds to,
                // not a set of hypotheticals it asks for.
                self::PERMISSION_FORECAST_VIEW,
                // The register is part of what the books report, so an audit
                // reads it. Nothing here writes to it: depreciation and
                // disposals are postings, and an audit posts nothing.
                self::PERMISSION_ASSET_VIEW,
                // The documents filed beside the entries under audit. Reading
                // them is exactly what an audit is for; filing or withdrawing
                // one is not, and is absent.
                self::PERMISSION_ATTACHMENT_VIEW,
                // The queue is evidence: who raised what, who decided it and
                // what reason was given. An audit reads it and never joins it —
                // no raising and no deciding.
                self::PERMISSION_APPROVAL_VIEW,
            ],

            // Sees everything the books report and nothing more: the `.view`
            // right of each part of the books, without `audit.view` and without
            // `budget.view`. A budget is not something the books report — it is
            // what the company intends to do next — so a member trusted to look
            // at the results is not thereby shown the plan.
            self::ROLE_READ_ONLY => [
                self::PERMISSION_JOURNAL_VIEW,
                self::PERMISSION_REPORT_VIEW,
                self::PERMISSION_PARTY_VIEW,
                self::PERMISSION_INVOICE_VIEW,
                self::PERMISSION_BILL_VIEW,
                self::PERMISSION_PAYMENT_VIEW,
                self::PERMISSION_BANK_VIEW,
                self::PERMISSION_STOCK_VIEW,
                self::PERMISSION_ASSET_VIEW,
                // Everything the books report includes the documents filed
                // beside them, so they are read here too. Filing one is a write
                // and is absent.
                self::PERMISSION_ATTACHMENT_VIEW,
            ],

            // The second pair of eyes on every number in the books, and the
            // only role that may decide an approval.
            //
            // Read every part of the ledger and its documents, so the decision
            // is made with the whole picture in view rather than from a summary
            // of it. Write nothing to the ledger: the journal, payment, stock
            // and asset rights here stop at reading, which is what keeps the
            // approver out of the entries they approve.
            self::ROLE_FINANCIAL_MANAGER => [
                self::PERMISSION_JOURNAL_VIEW,
                self::PERMISSION_REPORT_VIEW,
                self::PERMISSION_PARTY_VIEW,
                self::PERMISSION_INVOICE_VIEW,
                self::PERMISSION_BILL_VIEW,
                self::PERMISSION_PAYMENT_VIEW,
                self::PERMISSION_BANK_VIEW,
                self::PERMISSION_STOCK_VIEW,
                self::PERMISSION_ASSET_VIEW,
                self::PERMISSION_ATTACHMENT_VIEW,
                // The plan, both halves: "sets budgets and runs forecasts" is
                // writing the plan and reading the projection built from it.
                // Neither reaches the ledger, so neither is a posting right the
                // role has to be talked out of.
                self::PERMISSION_BUDGET_VIEW,
                self::PERMISSION_BUDGET_MANAGE,
                // The projection built from the plan, and the right to run it
                // under different assumptions. "Sets budgets and runs forecasts"
                // is the plan, the projection and the scenario tests: the role
                // says what the year should do and may ask what it would do if it
                // did not. Neither right is a posting and neither stores a
                // figure, so the scenario right is not a write this role has to
                // be talked out of — it is the right to ask "what if".
                self::PERMISSION_FORECAST_VIEW,
                self::PERMISSION_FORECAST_SCENARIO,
                // The calendar. Closing declares the year finished and reopening
                // puts a reported period back into the books; both carry a
                // mandatory reason, and both sit here because the member who
                // signs off the year is the member who says when it ended.
                self::PERMISSION_FISCAL_YEAR_CLOSE,
                self::PERMISSION_FISCAL_PERIOD_REOPEN,
                // Signing off a bank reconciliation. The books have already been
                // matched by whoever keeps the cashbook — what is decided here
                // is that the bank's file and the books agree — so it is the
                // reconcile right rather than the manage right beside it.
                self::PERMISSION_BANK_RECONCILE,
                // The evidence behind every other number, so the approver can
                // see who changed what as well as what it says now.
                self::PERMISSION_AUDIT_VIEW,
                // The queue itself. `approval.decide` is held by this role and
                // by no other; `approval.request` is here so the role can put a
                // document in front of a colleague, which is also what makes the
                // no-self-approval rule visible rather than theoretical.
                self::PERMISSION_APPROVAL_VIEW,
                self::PERMISSION_APPROVAL_REQUEST,
                self::PERMISSION_APPROVAL_DECIDE,
                // Deliberately absent, and the whole of "cannot manage users or
                // system settings": PERMISSION_USER_MANAGE and
                // PERMISSION_COMPANY_MANAGE. Also absent are every right that
                // writes to the ledger — create, post, reverse, record, issue,
                // dispose — so an approver cannot be the author of what they
                // approve even by accident.
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
