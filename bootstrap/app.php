<?php

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Exceptions\DocumentLineException;
use App\Domain\Accounting\Exceptions\FiscalPeriodNotOpenException;
use App\Domain\Accounting\Exceptions\FiscalYearStateException;
use App\Domain\Accounting\Exceptions\IdempotencyConflictException;
use App\Domain\Accounting\Exceptions\InvalidJournalException;
use App\Domain\Accounting\Exceptions\JournalStateException;
use App\Domain\Banking\Exceptions\BankingException;
use App\Domain\Banking\Exceptions\DuplicateStatementException;
use App\Domain\Banking\Exceptions\PaymentStateException;
use App\Domain\Banking\Exceptions\ReconciliationStateException;
use App\Domain\Banking\Exceptions\TransferStateException;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Domain\Purchases\Exceptions\PurchasesException;
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

                // A movement asked for against the stock the books currently
                // hold rather than against the request itself: more than is on
                // hand while the policy forbids negatives, a movement already
                // reversed, a costing method locked by movements already
                // posted. Named before InventoryException below, since each is
                // also an inventory refusal.
                $exception instanceof StockStateException => 409,

                // Bad input the caller can fix and resubmit: an unbalanced
                // journal, an unknown tax code, a negative amount.
                $exception instanceof InvalidJournalException,
                $exception instanceof DocumentLineException,
                $exception instanceof SalesException,
                $exception instanceof PurchasesException,
                $exception instanceof BankingException,
                $exception instanceof InventoryException => 422,

                default => 400,
            };

            return response()->json([
                'message' => $exception->getMessage(),
                'error' => class_basename($exception),
            ], $status);
        });
    })->create();
