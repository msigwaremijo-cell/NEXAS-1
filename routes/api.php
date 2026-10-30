<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BankAccountController;
use App\Http\Controllers\Api\V1\BankStatementController;
use App\Http\Controllers\Api\V1\BankTransferController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\CreditNoteController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DebitNoteController;
use App\Http\Controllers\Api\V1\FiscalYearController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VersionController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

// Controllers registered here stay thin: validation, authorisation and
// serialisation only. All accounting rules live in app/Domain Actions classes.

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');
    Route::get('/version', VersionController::class)->name('api.v1.version');

    // Rate limited because it is the one unauthenticated write on the surface.
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:20,1')
        ->name('api.v1.auth.login');

    // auth:sanctum identifies the member; company then pins which set of books
    // the request works in and rejects a company the member does not belong to.
    // It has to run second, because it reads the authenticated user.
    Route::middleware(['auth:sanctum', 'company'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');

        Route::get('/journals', [JournalController::class, 'index'])->name('api.v1.journals.index');
        Route::post('/journals', [JournalController::class, 'store'])->name('api.v1.journals.store');
        Route::get('/journals/{journal}', [JournalController::class, 'show'])->name('api.v1.journals.show');
        Route::post('/journals/{journal}/post', [JournalController::class, 'post'])->name('api.v1.journals.post');
        Route::post('/journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('api.v1.journals.reverse');

        // Ending the year. Closing writes the closing journal, shuts every
        // period of the year and the year itself, and is the one action that
        // needs its own right rather than the journal rights beside it: it is
        // what turns a set of books into a historical fact.
        Route::post('/fiscal-years/{fiscalYear}/close', [FiscalYearController::class, 'close'])
            ->name('api.v1.fiscal-years.close');

        // Read surface. Same middleware pair, so every report is scoped to the
        // company the caller proved membership of.
        Route::get('/accounts', [AccountController::class, 'index'])->name('api.v1.accounts.index');
        // Classifying an account changes how every period's cash movements are
        // presented, so it is its own route with its own right rather than a
        // general account update.
        Route::patch('/accounts/{account}/cash-flow-category', [AccountController::class, 'updateCashFlowCategory'])
            ->name('api.v1.accounts.cash-flow-category.update');
        Route::get('/reports/general-ledger', [ReportController::class, 'generalLedger'])->name('api.v1.reports.general-ledger');
        Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance'])->name('api.v1.reports.trial-balance');
        Route::get('/reports/receivables-ageing', [ReportController::class, 'receivablesAgeing'])->name('api.v1.reports.receivables-ageing');
        Route::get('/reports/payables-ageing', [ReportController::class, 'payablesAgeing'])->name('api.v1.reports.payables-ageing');
        // The cash flow statement, its indirect reading and the standing cash
        // position. All three read the posted ledger through CashFlowQuery,
        // which is what makes the closing figure of one agree with the total of
        // the others, and the indirect reading agree with the direct one.
        Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow'])->name('api.v1.reports.cash-flow');
        Route::get('/reports/cash-flow-indirect', [ReportController::class, 'cashFlowIndirect'])->name('api.v1.reports.cash-flow-indirect');
        Route::get('/reports/cash-position', [ReportController::class, 'cashPosition'])->name('api.v1.reports.cash-position');

        // The two statements. Read through the same ledger as the trial balance
        // beside them, so the profit they report is the profit the trial
        // balance's temporary accounts hold, and the balance sheet may only be
        // read when it balances.
        Route::get('/reports/profit-and-loss', [ReportController::class, 'profitAndLoss'])->name('api.v1.reports.profit-and-loss');
        Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('api.v1.reports.balance-sheet');

        // Whether the ledger_balances snapshot still agrees with the ledger.
        // Read-only: it reports a drift, it does not repair one, because
        // rebuilding a cache over books nobody has looked at would hide the
        // fault rather than fix it.
        Route::get('/reports/ledger-verification', [ReportController::class, 'ledgerVerification'])
            ->name('api.v1.reports.ledger-verification');

        // The VAT3 return. Read from the tax accounts of the ledger like the
        // statements beside it, and usually named by fiscal period rather than
        // by a hand-picked range, because that is how a return is filed.
        Route::get('/reports/vat-return', [ReportController::class, 'vatReturn'])->name('api.v1.reports.vat-return');

        // The plan beside what the period actually did. Its own right rather
        // than the report right above, because the budget column is what the
        // company intends and not what the books hold: writing a plan posts no
        // journal, so reading the books has never meant being shown the plan.
        Route::get('/reports/budget-vs-actual', [ReportController::class, 'budgetVsActual'])
            ->name('api.v1.reports.budget-vs-actual');

        // Master data the sales and purchase screens pick from. Reading one
        // record and amending it are separate routes from listing, and both are
        // resolved inside the caller's company so another tenant's party is a
        // 404 rather than an existence oracle. There is no delete: a party is
        // retired by moving its status, because documents already issued point
        // at it.
        Route::get('/customers', [CustomerController::class, 'index'])->name('api.v1.customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])->name('api.v1.customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('api.v1.customers.show');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('api.v1.customers.update');
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('api.v1.suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('api.v1.suppliers.store');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('api.v1.suppliers.show');
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('api.v1.suppliers.update');

        // Sales. Issuing is a separate route from writing the draft, because
        // issuing is what reaches the ledger and carries its own permission.
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('api.v1.invoices.index');
        Route::post('/invoices', [InvoiceController::class, 'store'])->name('api.v1.invoices.store');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('api.v1.invoices.show');
        Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('api.v1.invoices.issue');

        // Credit notes. A credit note is the sales side correcting its own
        // issued invoice, so it reuses the invoice rights rather than adding a
        // set of its own. A line names one line of the invoice it answers and a
        // quantity — never a price — and writing the draft is a separate route
        // from issuing it, exactly as it is for an invoice.
        Route::get('/credit-notes', [CreditNoteController::class, 'index'])->name('api.v1.credit-notes.index');
        Route::post('/invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store'])->name('api.v1.invoices.credit-notes.store');
        Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show'])->name('api.v1.credit-notes.show');
        Route::post('/credit-notes/{creditNote}/issue', [CreditNoteController::class, 'issue'])->name('api.v1.credit-notes.issue');

        // Purchases. A bill passes draft -> pending_approval -> approved ->
        // posted, and each move is its own route so the matrix can withhold
        // approval from the member who entered the bill.
        Route::get('/bills', [BillController::class, 'index'])->name('api.v1.bills.index');
        Route::post('/bills', [BillController::class, 'store'])->name('api.v1.bills.store');
        Route::get('/bills/{bill}', [BillController::class, 'show'])->name('api.v1.bills.show');
        Route::post('/bills/{bill}/submit', [BillController::class, 'submit'])->name('api.v1.bills.submit');
        Route::post('/bills/{bill}/approve', [BillController::class, 'approve'])->name('api.v1.bills.approve');
        Route::post('/bills/{bill}/post', [BillController::class, 'post'])->name('api.v1.bills.post');

        // Supplier debit notes. A debit note is the purchase side correcting a
        // bill it already posted, so it reuses the bill rights rather than
        // adding a set of its own. A line names one line of the bill it answers
        // and a quantity — never a price or an account — and writing the draft
        // is a separate route from posting it. There is deliberately no update
        // or delete route: a posted note is immutable and is corrected by
        // reversing it.
        Route::get('/debit-notes', [DebitNoteController::class, 'index'])->name('api.v1.debit-notes.index');
        Route::post('/bills/{bill}/debit-notes', [DebitNoteController::class, 'store'])->name('api.v1.bills.debit-notes.store');
        Route::get('/debit-notes/{debitNote}', [DebitNoteController::class, 'show'])->name('api.v1.debit-notes.show');
        Route::post('/debit-notes/{debitNote}/post', [DebitNoteController::class, 'post'])->name('api.v1.debit-notes.post');
        // The correction of the correction: the opposite entry, with any stock
        // the note sent out put back. A reversal is final, so reversing a
        // reversal is refused rather than treated as a fresh move.
        Route::post('/debit-notes/{debitNote}/reverse', [DebitNoteController::class, 'reverse'])->name('api.v1.debit-notes.reverse');

        Route::get('/payments', [PaymentController::class, 'index'])->name('api.v1.payments.index');
        Route::post('/payments', [PaymentController::class, 'store'])->name('api.v1.payments.store');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('api.v1.payments.show');

        // Cash and bank. Opening an account posts its opening balance as a real
        // journal, so it is a write and carries its own permission.
        Route::get('/bank-accounts', [BankAccountController::class, 'index'])->name('api.v1.bank-accounts.index');
        Route::post('/bank-accounts', [BankAccountController::class, 'store'])->name('api.v1.bank-accounts.store');
        Route::get('/bank-accounts/{bankAccount}', [BankAccountController::class, 'show'])->name('api.v1.bank-accounts.show');

        // The cashbook's own movement: money that never left the company, so
        // there is no party to settle and no allocation to follow.
        Route::post('/bank-transfers', [BankTransferController::class, 'store'])->name('api.v1.bank-transfers.store');

        // Statement import in three steps, because the middle one cannot be
        // guessed: a file's columns are named by whichever tool the bank uses,
        // and the upload hands back what is really in the file so the caller
        // can say what each column is. The lines it produces are the bank's
        // claims, not postings: nothing here reaches the ledger.
        Route::get('/bank-accounts/{bankAccount}/statement-lines', [BankStatementController::class, 'index'])
            ->name('api.v1.bank-accounts.statement-lines.index');
        Route::post('/bank-accounts/{bankAccount}/statement-uploads', [BankStatementController::class, 'storeUpload'])
            ->name('api.v1.bank-accounts.statement-uploads.store');
        Route::post('/bank-accounts/{bankAccount}/statement-imports', [BankStatementController::class, 'import'])
            ->name('api.v1.bank-accounts.statement-imports.store');

        // Reconciliation: comparing what the bank printed with what the books
        // hold. Reading the standing is a report; every route below it decides
        // something and carries the reconcile right instead. Nothing here
        // reaches the ledger — a match records which entry a movement already
        // in the books is. A match is named by its own id rather than a ULID,
        // because a reconciliation is a pair of ids rather than a row with a
        // public handle of its own.
        Route::get('/bank-accounts/{bankAccount}/reconciliation', [ReconciliationController::class, 'show'])
            ->name('api.v1.bank-accounts.reconciliation.show');
        Route::post('/bank-accounts/{bankAccount}/reconciliation/auto-match', [ReconciliationController::class, 'autoMatch'])
            ->name('api.v1.bank-accounts.reconciliation.auto-match');
        Route::post('/bank-accounts/{bankAccount}/reconciliation/matches', [ReconciliationController::class, 'match'])
            ->name('api.v1.bank-accounts.reconciliation.matches.store');
        Route::delete('/bank-accounts/{bankAccount}/reconciliation/matches/{reconciliation}', [ReconciliationController::class, 'release'])
            ->whereNumber('reconciliation')
            ->name('api.v1.bank-accounts.reconciliation.matches.destroy');
        Route::post('/bank-accounts/{bankAccount}/reconciliation/ignored-lines', [ReconciliationController::class, 'ignore'])
            ->name('api.v1.bank-accounts.reconciliation.ignored-lines.store');
        Route::post('/bank-accounts/{bankAccount}/reconciliation/completion', [ReconciliationController::class, 'complete'])
            ->name('api.v1.bank-accounts.reconciliation.completion.store');

        // How the company keeps its stock books. Reading and writing sit
        // together because they answer the same question, and the write is
        // where the costing lock is enforced: the method may only change while
        // the shelves are empty, so this is the one route that may set it.
        Route::get('/company/inventory-settings', [CompanyController::class, 'inventorySettings'])
            ->name('api.v1.company.inventory-settings.show');
        Route::patch('/company/inventory-settings', [CompanyController::class, 'updateInventorySettings'])
            ->name('api.v1.company.inventory-settings.update');

        // Stock master data. Products and warehouses are what every movement
        // names, and a product is what decides which inventory and cost
        // accounts its own stock posts to. Reading the catalogue is a stock
        // right of its own, so a cashier can answer "have we got any" without
        // being able to invent an item.
        Route::get('/products', [ProductController::class, 'index'])->name('api.v1.products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('api.v1.products.store');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('api.v1.products.show');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('api.v1.products.update');

        Route::get('/warehouses', [WarehouseController::class, 'index'])->name('api.v1.warehouses.index');
        Route::post('/warehouses', [WarehouseController::class, 'store'])->name('api.v1.warehouses.store');
        Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('api.v1.warehouses.show');
        Route::patch('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('api.v1.warehouses.update');

        // Stock movements. Each direction is its own route because each is a
        // different decision: a receipt is met by an account the caller names,
        // an issue is met by the product's own cost account, an adjustment can
        // go either way, and a transfer is two movements and no entry at all.
        // A movement is never edited, so there is no update route; correcting
        // one is the reversal below, which is a movement of its own.
        Route::get('/stock/movements', [StockMovementController::class, 'index'])->name('api.v1.stock.movements.index');
        Route::post('/stock/receipts', [StockMovementController::class, 'storeReceipt'])->name('api.v1.stock.receipts.store');
        Route::post('/stock/issues', [StockMovementController::class, 'storeIssue'])->name('api.v1.stock.issues.store');
        Route::post('/stock/adjustments', [StockMovementController::class, 'storeAdjustment'])->name('api.v1.stock.adjustments.store');
        Route::post('/stock/transfers', [StockMovementController::class, 'storeTransfer'])->name('api.v1.stock.transfers.store');
        Route::post('/stock/movements/{movement}/reversal', [StockMovementController::class, 'reverse'])->name('api.v1.stock.movements.reversal.store');

        // What the stock on hand is worth, against the inventory accounts it is
        // carried in.
        Route::get('/reports/stock-valuation', [ReportController::class, 'stockValuation'])->name('api.v1.reports.stock-valuation');

        // The plan for a period: the accounts the company means to earn and
        // spend on, and the cost centres it splits that across. Held behind its
        // own pair of rights rather than the report rights, because a budget is
        // a statement of intent and not a posting — nothing here reaches the
        // ledger. A plan is written as a whole, so there is no per-line route
        // and no delete: a budget is replaced by writing it again, and a cost
        // centre is retired by moving its status. Writing is a put of the plan
        // named by its fiscal period, because the company keeps one plan per
        // period and only a period can be planned for.
        Route::get('/cost-centers', [CostCenterController::class, 'index'])->name('api.v1.cost-centers.index');
        Route::post('/cost-centers', [CostCenterController::class, 'store'])->name('api.v1.cost-centers.store');
        Route::get('/cost-centers/{costCenter}', [CostCenterController::class, 'show'])->name('api.v1.cost-centers.show');
        Route::patch('/cost-centers/{costCenter}', [CostCenterController::class, 'update'])->name('api.v1.cost-centers.update');

        Route::get('/budgets', [BudgetController::class, 'index'])->name('api.v1.budgets.index');
        Route::get('/budgets/{budget}', [BudgetController::class, 'show'])->name('api.v1.budgets.show');
        Route::put('/budgets', [BudgetController::class, 'save'])->name('api.v1.budgets.save');

        // Administering the company's members. Every route here is a
        // `user.manage` route, which in the matrix means the Owner alone, and
        // every one is scoped to the caller's company: naming another company's
        // member is a 404 rather than an existence oracle. There is no delete,
        // because the audit chain must keep naming whoever moved the books — a
        // member is deactivated instead.
        Route::get('/users', [UserController::class, 'index'])->name('api.v1.users.index');
        Route::post('/users', [UserController::class, 'store'])->name('api.v1.users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('api.v1.users.show');
        Route::post('/users/{user}/roles', [UserController::class, 'assignRole'])->name('api.v1.users.roles.store');
        Route::delete('/users/{user}/roles/{role}', [UserController::class, 'removeRole'])->name('api.v1.users.roles.destroy');
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('api.v1.users.deactivate');
        Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('api.v1.users.activate');

        // The roles the company may hand out and what each one grants. Fixed by
        // the matrix rather than editable, and read behind the same right as
        // the member list, because it is what the assignment screen shows.
        Route::get('/roles', [RoleController::class, 'index'])->name('api.v1.roles.index');

        // The audit chain: who changed what, and what it held before. Read
        // through its own right, `audit.view`, held by the Owner and the
        // Auditor alone — a member trusted to read the books is not
        // automatically trusted to read the trail behind them.
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('api.v1.audit-logs.index');
    });
});
