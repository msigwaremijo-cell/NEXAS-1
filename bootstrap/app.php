<?php

use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Access\Exceptions\AccessStateException;
use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Exceptions\DocumentLineException;
use App\Domain\Accounting\Exceptions\FiscalPeriodNotOpenException;
use App\Domain\Accounting\Exceptions\FiscalPeriodStateException;
use App\Domain\Accounting\Exceptions\FiscalYearStateException;
use App\Domain\Accounting\Exceptions\IdempotencyConflictException;
use App\Domain\Accounting\Exceptions\InvalidJournalException;
use App\Domain\Accounting\Exceptions\JournalStateException;
use App\Domain\Approvals\Exceptions\ApprovalsException;
use App\Domain\Approvals\Exceptions\ApprovalStateException;
use App\Domain\Assets\Exceptions\AssetsException;
use App\Domain\Assets\Exceptions\AssetStateException;
use App\Domain\Banking\Exceptions\BankingException;
use App\Domain\Banking\Exceptions\DuplicateStatementException;
use App\Domain\Banking\Exceptions\PaymentStateException;
use App\Domain\Banking\Exceptions\ReconciliationStateException;
use App\Domain\Banking\Exceptions\TransferStateException;
use App\Domain\Files\Exceptions\FilesException;
use App\Domain\Importing\Exceptions\DuplicateImportException;
use App\Domain\Importing\Exceptions\ImportingException;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Domain\Purchases\Exceptions\PurchasesException;
use App\Domain\Reporting\Exceptions\BudgetStateException;
use App\Domain\Reporting\Exceptions\ForecastUnavailableException;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Domain\Sales\Exceptions\SalesException;
use App\Http\Middleware\SetPermissionsTeamId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Registered as an alias rather than pushed onto the api group so that
        // a route can opt in with ->middleware('company') after auth:sanctum.
        // It must run after authentication, because it reads the token's user.
        $middleware->alias([
            'company' => SetPermissionsTeamId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The domain exceptions carry no HTTP knowledge of their own, so the
        // translation to a status code belongs here rather than in the actions.
        // A request the caller cannot fix is a 422; a request that collides
        // with the current state of the books is a 409.
        $exceptions->render(function (AccountingException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                // A collision with what the books currently say: the request
                // itself is well formed, so retrying it unchanged will not help.
                // These must be matched before their parent classes below,
                // because match arms are tested in order and every state
                // exception is also an instance of its domain's base class.
                $exception instanceof JournalStateException,
                $exception instanceof FiscalPeriodNotOpenException,
                $exception instanceof ConcurrentModificationException,
                $exception instanceof IdempotencyConflictException,
                $exception instanceof InvoiceStateException,
                $exception instanceof BillStateException,
                // A year-end close asked for against the state of the books
                // rather than against the request itself: a year already shut,
                // or a chart of accounts with nowhere to put the result. Named
                // before the base class below, since it is also one.
                $exception instanceof FiscalYearStateException,
                // The same for a period reopen: a period that is already open,
                // or one whose year has been locked for good. Well formed, and
                // no change to the request would help.
                $exception instanceof FiscalPeriodStateException,
                $exception instanceof PaymentStateException,
                $exception instanceof TransferStateException,
                // A reconciliation asked for against the state the books are
                // in rather than against the request itself: a line a completed
                // reconciliation has closed, a statement that does not add up,
                // a sign-off with a line still undecided. Named before
                // BankingException below, since each is also a banking refusal.
                $exception instanceof ReconciliationStateException,
                // A statement file that repeats movements the account already
                // holds is not a malformed file and not a bad mapping, so
                // resending it unchanged will produce the same answer every
                // time. It has to be named before BankingException below, since
                // a duplicate statement is also a banking refusal.
                $exception instanceof DuplicateStatementException,

                // A member change that collides with the team as it currently
                // stands: removing the last Owner, or inviting somebody who is
                // already on the books. Named before AccessException below,
                // since it is also an access refusal.
                $exception instanceof AccessStateException,

                // A decision that collides with the state of the queue: a
                // request the member is deciding that they themselves raised,
                // or one that already carries a decision. The request is well
                // formed and no change to it would help — the second attempt
                // will meet the same answer. Named before ApprovalsException
                // below, since it is also an approvals refusal.
                $exception instanceof ApprovalStateException,

                // A movement asked for against the stock the books currently
                // hold rather than against the request itself: more than is on
                // hand while the policy forbids negatives, a movement already
                // reversed, a costing method locked by movements already
                // posted. Named before InventoryException below, since each is
                // also an inventory refusal.
                $exception instanceof StockStateException,

                // A forecast asked for where the calendar cannot answer it: no
                // periods at all, no period covering the date, or a fiscal year
                // that is not open. The request is well formed and no change to
                // it would help, so it is a collision with the shape of the
                // books rather than a bad request.
                $exception instanceof ForecastUnavailableException,

                // A migration file whose exact bytes have already been taken
                // into these books for this company and dataset. The file is
                // well formed and the mapping is right, so no change to the
                // request would help — it is the same file, and it is imported
                // once. Named before ImportingException below, since a repeat
                // is also an import refusal.
                $exception instanceof DuplicateImportException,

                // A fixed-asset request that collides with the state of the
                // register or the calendar: a period already depreciated, an
                // asset already disposed of, a status that only moves one way,
                // nothing left to depreciate. Named before AssetsException
                // below, since each of these is also a fixed-asset refusal.
                $exception instanceof AssetStateException,

                // A budget asked to move in a way its state does not allow: an
                // edit of a plan that has been raised, a lock of one that has
                // not been approved, a resubmission already waiting on a
                // decision. The request names a real plan and a real act, so no
                // change to it would help — the state is what refuses it.
                $exception instanceof BudgetStateException => 409,

                // Bad input the caller can fix and resubmit: an unbalanced
                // journal, an unknown tax code, a negative amount.
                $exception instanceof InvalidJournalException,
                $exception instanceof DocumentLineException,
                // A member change the caller can fix and resubmit: deactivating
                // their own account, removing a role the member does not hold.
                $exception instanceof AccessException,
                // An approvals refusal the caller can fix and resubmit: a
                // document that cannot be found in this company, a rejection
                // that carries no reason, a decision that carries no
                // idempotency key.
                $exception instanceof ApprovalsException,
                $exception instanceof SalesException,
                $exception instanceof PurchasesException,
                $exception instanceof BankingException,
                $exception instanceof InventoryException,
                // A migration file the caller can fix and resend: a mapping that
                // names a column the file does not carry or leaves a required
                // one unmapped, an amount that will not read, a row naming an
                // account or a SKU the books do not hold, an opening balance set
                // that does not agree with itself or with the stock already
                // imported.
                $exception instanceof ImportingException,
                // A fixed-asset request the caller can fix and resubmit: a
                // salvage value above the cost, a category naming an account of
                // the wrong type, a disposal that receives money without naming
                // where it was received.
                $exception instanceof AssetsException => 422,

                // A file the caller can replace: the wrong kind, contents that
                // contradict the name it was filed under, too large to keep.
                // The base type is named rather than the upload refusal alone,
                // so a store that would not take a file that passed every check
                // reads as a refusal too rather than falling through to a blank.
                $exception instanceof FilesException => 422,

                default => 400,
            };

            return response()->json([
                'message' => $exception->getMessage(),
                'error' => class_basename($exception),
            ], $status);
        });
    })->create();
