<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BankAccountController;
use App\Http\Controllers\Api\V1\BankStatementController;
use App\Http\Controllers\Api\V1\BankTransferController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\VersionController;
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

        // Read surface. Same middleware pair, so every report is scoped to the
        // company the caller proved membership of.
        Route::get('/accounts', [AccountController::class, 'index'])->name('api.v1.accounts.index');
        Route::get('/reports/general-ledger', [ReportController::class, 'generalLedger'])->name('api.v1.reports.general-ledger');
        Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance'])->name('api.v1.reports.trial-balance');
        Route::get('/reports/receivables-ageing', [ReportController::class, 'receivablesAgeing'])->name('api.v1.reports.receivables-ageing');
        Route::get('/reports/payables-ageing', [ReportController::class, 'payablesAgeing'])->name('api.v1.reports.payables-ageing');

        // Master data the sales and purchase screens pick from.
        Route::get('/customers', [CustomerController::class, 'index'])->name('api.v1.customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])->name('api.v1.customers.store');
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('api.v1.suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('api.v1.suppliers.store');

        // Sales. Issuing is a separate route from writing the draft, because
        // issuing is what reaches the ledger and carries its own permission.
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('api.v1.invoices.index');
        Route::post('/invoices', [InvoiceController::class, 'store'])->name('api.v1.invoices.store');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('api.v1.invoices.show');
        Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('api.v1.invoices.issue');

        // Purchases. A bill passes draft -> pending_approval -> approved ->
        // posted, and each move is its own route so the matrix can withhold
        // approval from the member who entered the bill.
        Route::get('/bills', [BillController::class, 'index'])->name('api.v1.bills.index');
        Route::post('/bills', [BillController::class, 'store'])->name('api.v1.bills.store');
        Route::get('/bills/{bill}', [BillController::class, 'show'])->name('api.v1.bills.show');
        Route::post('/bills/{bill}/submit', [BillController::class, 'submit'])->name('api.v1.bills.submit');
        Route::post('/bills/{bill}/approve', [BillController::class, 'approve'])->name('api.v1.bills.approve');
        Route::post('/bills/{bill}/post', [BillController::class, 'post'])->name('api.v1.bills.post');

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
    });
});
