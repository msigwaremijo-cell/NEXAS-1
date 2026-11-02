<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

/*
|--------------------------------------------------------------------------
| The permission audit
|--------------------------------------------------------------------------
|
| Every other feature file proves one area of the API is guarded. This file
| proves the whole surface is, and is the one that fails loudly when somebody
| adds a route and forgets the policy behind it.
|
| Three questions are asked, in order:
|
|   1. Does every route behind authentication appear in the map below, and
|      does every permission the map names exist in the matrix?
|   2. Does the controller action behind each of those routes actually reach
|      an authorisation call?
|   3. Does a member of each of the seven roles really get the answer the map
|      promises — a 403 when their role lacks the permission, and anything but
|      a 403 when it holds it?
|
| The third is driven over real HTTP. It runs against the routes that take no
| path parameter, because a route such as /journals/{journal} resolves its
| record before the policy is reached, so a forbidden caller meets a 404 on a
| row that does not exist rather than the 403 this file is about. Those routes
| keep their own 403 assertions in the feature file for their area; what this
| file adds is that no route can exist outside the net at all.
|
*/

uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

/**
 * Every route behind the authentication and company middleware, and the
 * permission the matrix requires for it.
 *
 * A null permission marks a route that answers a question about the caller's
 * own session rather than about the company's books — signing yourself out, or
 * turning your own second factor on. The matrix governs what a member may do
 * to the ledger; it has no say in what they may do to their own login.
 *
 * This is the register the audit is written against, so a route missing from
 * it fails the first test rather than going unguarded in silence.
 *
 * @return array<string, string|null>
 */
function nxRoutePermissions(): array
{
    return [
        // The caller's own session. See the docblock above for why these carry
        // no permission.
        'api.v1.auth.me' => null,
        'api.v1.auth.logout' => null,
        'api.v1.auth.logout-all' => null,
        'api.v1.auth.two-factor.enable' => null,
        'api.v1.auth.two-factor.confirm' => null,
        'api.v1.auth.two-factor.disable' => null,
        'api.v1.auth.two-factor.recovery-codes' => null,

        // The general ledger.
        'api.v1.journals.index' => AccessMatrix::PERMISSION_JOURNAL_VIEW,
        'api.v1.journals.store' => AccessMatrix::PERMISSION_JOURNAL_CREATE,
        'api.v1.journals.show' => AccessMatrix::PERMISSION_JOURNAL_VIEW,
        'api.v1.journals.post' => AccessMatrix::PERMISSION_JOURNAL_POST,
        'api.v1.journals.reverse' => AccessMatrix::PERMISSION_JOURNAL_REVERSE,
        'api.v1.fiscal-years.close' => AccessMatrix::PERMISSION_FISCAL_YEAR_CLOSE,
        // The calendar behind that close: reading which periods are shut is
        // reading the books, and reopening one is the Owner's own right.
        'api.v1.fiscal-periods.index' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.fiscal-periods.reopen' => AccessMatrix::PERMISSION_FISCAL_PERIOD_REOPEN,

        // The chart of accounts, and the one decision made about it.
        'api.v1.accounts.index' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.accounts.cash-flow-category.update' => AccessMatrix::PERMISSION_ACCOUNT_MANAGE,

        // Reports. Reading the books the company holds.
        'api.v1.reports.general-ledger' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.trial-balance' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.receivables-ageing' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.payables-ageing' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.cash-flow' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.cash-flow-indirect' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.cash-position' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.profit-and-loss' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.balance-sheet' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.ledger-verification' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.reports.vat-return' => AccessMatrix::PERMISSION_REPORT_VIEW,
        // What is on the shelves, and what it is worth.
        'api.v1.reports.stock-valuation' => AccessMatrix::PERMISSION_STOCK_VIEW,
        // The plan, which is not the books.
        'api.v1.reports.budget-vs-actual' => AccessMatrix::PERMISSION_BUDGET_VIEW,
        'api.v1.reports.forecast' => AccessMatrix::PERMISSION_BUDGET_VIEW,

        // Exports. Requesting one is reading a report, so it is the report
        // right; a specific export may ask for more, and ExportController
        // checks that on top.
        'api.v1.exports.index' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.exports.store' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.exports.show' => AccessMatrix::PERMISSION_REPORT_VIEW,
        'api.v1.exports.download' => AccessMatrix::PERMISSION_REPORT_VIEW,

        // Documents filed beside records. Reading one is reading the books, so
        // the list and the download are the read right; filing and withdrawing
        // one are writes, so they are the manage right.
        'api.v1.attachments.index' => AccessMatrix::PERMISSION_ATTACHMENT_VIEW,
        'api.v1.attachments.store' => AccessMatrix::PERMISSION_ATTACHMENT_MANAGE,
        'api.v1.attachments.download' => AccessMatrix::PERMISSION_ATTACHMENT_VIEW,
        'api.v1.attachments.destroy' => AccessMatrix::PERMISSION_ATTACHMENT_MANAGE,

        // Migration files, brought in one dataset at a time. Which rights a
        // dataset needs is the dataset's own answer rather than this surface's
        // — importing a chart of accounts is maintaining the chart of accounts,
        // importing opening balances posts a journal — so no single right is
        // the whole of it. What is recorded here is the one right every role
        // able to import anything holds, which is the right to keep the stock
        // books: the four roles that hold it are exactly the four that may
        // import at least one dataset. ImportController checks the dataset's
        // own rights on top, and that is what keeps a Cashier, an Auditor and
        // the Read-only role out of every one of these surfaces.
        'api.v1.imports.uploads.store' => AccessMatrix::PERMISSION_STOCK_MANAGE,
        'api.v1.imports.runs.store' => AccessMatrix::PERMISSION_STOCK_MANAGE,

        // Parties.
        'api.v1.customers.index' => AccessMatrix::PERMISSION_PARTY_VIEW,
        'api.v1.customers.store' => AccessMatrix::PERMISSION_PARTY_MANAGE,
        'api.v1.customers.show' => AccessMatrix::PERMISSION_PARTY_VIEW,
        'api.v1.customers.update' => AccessMatrix::PERMISSION_PARTY_MANAGE,
        'api.v1.suppliers.index' => AccessMatrix::PERMISSION_PARTY_VIEW,
        'api.v1.suppliers.store' => AccessMatrix::PERMISSION_PARTY_MANAGE,
        'api.v1.suppliers.show' => AccessMatrix::PERMISSION_PARTY_VIEW,
        'api.v1.suppliers.update' => AccessMatrix::PERMISSION_PARTY_MANAGE,

        // Sales, and the sales side's correction of itself.
        'api.v1.invoices.index' => AccessMatrix::PERMISSION_INVOICE_VIEW,
        'api.v1.invoices.store' => AccessMatrix::PERMISSION_INVOICE_MANAGE,
        'api.v1.invoices.show' => AccessMatrix::PERMISSION_INVOICE_VIEW,
        'api.v1.invoices.issue' => AccessMatrix::PERMISSION_INVOICE_ISSUE,
        'api.v1.credit-notes.index' => AccessMatrix::PERMISSION_INVOICE_VIEW,
        'api.v1.invoices.credit-notes.store' => AccessMatrix::PERMISSION_INVOICE_MANAGE,
        'api.v1.credit-notes.show' => AccessMatrix::PERMISSION_INVOICE_VIEW,
        'api.v1.credit-notes.issue' => AccessMatrix::PERMISSION_INVOICE_ISSUE,

        // Purchases, and the purchase side's correction of itself.
        'api.v1.bills.index' => AccessMatrix::PERMISSION_BILL_VIEW,
        'api.v1.bills.store' => AccessMatrix::PERMISSION_BILL_MANAGE,
        'api.v1.bills.show' => AccessMatrix::PERMISSION_BILL_VIEW,
        'api.v1.bills.submit' => AccessMatrix::PERMISSION_BILL_MANAGE,
        'api.v1.bills.approve' => AccessMatrix::PERMISSION_BILL_APPROVE,
        'api.v1.bills.post' => AccessMatrix::PERMISSION_BILL_POST,
        'api.v1.debit-notes.index' => AccessMatrix::PERMISSION_BILL_VIEW,
        'api.v1.bills.debit-notes.store' => AccessMatrix::PERMISSION_BILL_MANAGE,
        'api.v1.debit-notes.show' => AccessMatrix::PERMISSION_BILL_VIEW,
        'api.v1.debit-notes.post' => AccessMatrix::PERMISSION_BILL_POST,
        'api.v1.debit-notes.reverse' => AccessMatrix::PERMISSION_BILL_POST,

        // Money in and out.
        'api.v1.payments.index' => AccessMatrix::PERMISSION_PAYMENT_VIEW,
        'api.v1.payments.store' => AccessMatrix::PERMISSION_PAYMENT_RECORD,
        'api.v1.payments.show' => AccessMatrix::PERMISSION_PAYMENT_VIEW,

        // Cash and bank.
        'api.v1.bank-accounts.index' => AccessMatrix::PERMISSION_BANK_VIEW,
        'api.v1.bank-accounts.store' => AccessMatrix::PERMISSION_BANK_MANAGE,
        'api.v1.bank-accounts.show' => AccessMatrix::PERMISSION_BANK_VIEW,
        'api.v1.bank-transfers.store' => AccessMatrix::PERMISSION_PAYMENT_RECORD,
        'api.v1.bank-accounts.statement-lines.index' => AccessMatrix::PERMISSION_BANK_VIEW,
        'api.v1.bank-accounts.statement-uploads.store' => AccessMatrix::PERMISSION_BANK_MANAGE,
        'api.v1.bank-accounts.statement-imports.store' => AccessMatrix::PERMISSION_BANK_MANAGE,
        // Reading the reconciliation is reading the account; every route that
        // decides something about it is the reconcile right instead.
        'api.v1.bank-accounts.reconciliation.show' => AccessMatrix::PERMISSION_BANK_VIEW,
        'api.v1.bank-accounts.reconciliation.auto-match' => AccessMatrix::PERMISSION_BANK_RECONCILE,
        'api.v1.bank-accounts.reconciliation.matches.store' => AccessMatrix::PERMISSION_BANK_RECONCILE,
        'api.v1.bank-accounts.reconciliation.matches.destroy' => AccessMatrix::PERMISSION_BANK_RECONCILE,
        'api.v1.bank-accounts.reconciliation.ignored-lines.store' => AccessMatrix::PERMISSION_BANK_RECONCILE,
        'api.v1.bank-accounts.reconciliation.completion.store' => AccessMatrix::PERMISSION_BANK_RECONCILE,

        // The policy the books are kept under.
        'api.v1.company.inventory-settings.show' => AccessMatrix::PERMISSION_COMPANY_MANAGE,
        'api.v1.company.inventory-settings.update' => AccessMatrix::PERMISSION_COMPANY_MANAGE,

        // Stock.
        'api.v1.products.index' => AccessMatrix::PERMISSION_STOCK_VIEW,
        'api.v1.products.store' => AccessMatrix::PERMISSION_STOCK_MANAGE,
        'api.v1.products.show' => AccessMatrix::PERMISSION_STOCK_VIEW,
        'api.v1.products.update' => AccessMatrix::PERMISSION_STOCK_MANAGE,
        'api.v1.warehouses.index' => AccessMatrix::PERMISSION_STOCK_VIEW,
        'api.v1.warehouses.store' => AccessMatrix::PERMISSION_STOCK_MANAGE,
        'api.v1.warehouses.show' => AccessMatrix::PERMISSION_STOCK_VIEW,
        'api.v1.warehouses.update' => AccessMatrix::PERMISSION_STOCK_MANAGE,
        'api.v1.stock.movements.index' => AccessMatrix::PERMISSION_STOCK_VIEW,
        'api.v1.stock.receipts.store' => AccessMatrix::PERMISSION_STOCK_POST,
        'api.v1.stock.issues.store' => AccessMatrix::PERMISSION_STOCK_POST,
        'api.v1.stock.adjustments.store' => AccessMatrix::PERMISSION_STOCK_POST,
        'api.v1.stock.transfers.store' => AccessMatrix::PERMISSION_STOCK_POST,
        'api.v1.stock.movements.reversal.store' => AccessMatrix::PERMISSION_STOCK_POST,

        // The plan, both halves.
        'api.v1.cost-centers.index' => AccessMatrix::PERMISSION_BUDGET_VIEW,
        'api.v1.cost-centers.store' => AccessMatrix::PERMISSION_BUDGET_MANAGE,
        'api.v1.cost-centers.show' => AccessMatrix::PERMISSION_BUDGET_VIEW,
        'api.v1.cost-centers.update' => AccessMatrix::PERMISSION_BUDGET_MANAGE,
        'api.v1.budgets.index' => AccessMatrix::PERMISSION_BUDGET_VIEW,
        'api.v1.budgets.show' => AccessMatrix::PERMISSION_BUDGET_VIEW,
        'api.v1.budgets.save' => AccessMatrix::PERMISSION_BUDGET_MANAGE,

        // Fixed assets.
        'api.v1.asset-categories.index' => AccessMatrix::PERMISSION_ASSET_VIEW,
        'api.v1.asset-categories.store' => AccessMatrix::PERMISSION_ASSET_MANAGE,
        'api.v1.asset-categories.show' => AccessMatrix::PERMISSION_ASSET_VIEW,
        'api.v1.asset-categories.update' => AccessMatrix::PERMISSION_ASSET_MANAGE,
        'api.v1.assets.index' => AccessMatrix::PERMISSION_ASSET_VIEW,
        'api.v1.assets.store' => AccessMatrix::PERMISSION_ASSET_MANAGE,
        'api.v1.assets.register' => AccessMatrix::PERMISSION_ASSET_VIEW,
        'api.v1.assets.show' => AccessMatrix::PERMISSION_ASSET_VIEW,
        'api.v1.assets.update' => AccessMatrix::PERMISSION_ASSET_MANAGE,
        'api.v1.depreciation-runs.store' => AccessMatrix::PERMISSION_ASSET_POST,
        'api.v1.depreciation-runs.reversal.store' => AccessMatrix::PERMISSION_ASSET_POST,
        'api.v1.assets.disposal.store' => AccessMatrix::PERMISSION_ASSET_POST,
        'api.v1.assets.disposal.reversal.store' => AccessMatrix::PERMISSION_ASSET_POST,

        // Administering members, and reading the trail behind the books.
        'api.v1.users.index' => AccessMatrix::PERMISSION_USER_MANAGE,
        'api.v1.users.store' => AccessMatrix::PERMISSION_USER_MANAGE,
        'api.v1.users.show' => AccessMatrix::PERMISSION_USER_MANAGE,
        'api.v1.users.roles.store' => AccessMatrix::PERMISSION_USER_MANAGE,
        'api.v1.users.roles.destroy' => AccessMatrix::PERMISSION_USER_MANAGE,
        'api.v1.users.deactivate' => AccessMatrix::PERMISSION_USER_MANAGE,
        'api.v1.users.activate' => AccessMatrix::PERMISSION_USER_MANAGE,
        'api.v1.roles.index' => AccessMatrix::PERMISSION_USER_MANAGE,
        'api.v1.audit-logs.index' => AccessMatrix::PERMISSION_AUDIT_VIEW,

        // The approvals queue. Reading the line and raising a document into it
        // are the two halves a member who keeps the books may hold; answering
        // what waits there is the decision, held by the Financial Manager
        // alone. The sign-in the portal arrives through is not in this map: it
        // sits outside authentication, like the main login, because a login is
        // about the caller rather than about the company's books.
        'api.v1.approval-requests.index' => AccessMatrix::PERMISSION_APPROVAL_VIEW,
        'api.v1.approval-requests.store' => AccessMatrix::PERMISSION_APPROVAL_REQUEST,
        'api.v1.approval-requests.show' => AccessMatrix::PERMISSION_APPROVAL_VIEW,
        'api.v1.approval-requests.approve' => AccessMatrix::PERMISSION_APPROVAL_DECIDE,
        'api.v1.approval-requests.reject' => AccessMatrix::PERMISSION_APPROVAL_DECIDE,
    ];
}

/**
 * Every route that sits behind the authentication and company middleware.
 *
 * @return list<RoutingRoute>
 */
function nxGuardedRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/')
            && in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->values()
        ->all();
}

/**
 * Whether the action behind a route reaches an authorisation call.
 *
 * Read out of the method's own source rather than assumed from the map: the
 * point of the audit is to catch the route whose map entry was written but
 * whose controller never asked anybody.
 */
function nxRouteAuthorises(RoutingRoute $route): bool
{
    $uses = $route->getAction('uses');

    if (! is_string($uses) || ! str_contains($uses, '@')) {
        return false;
    }

    [$controller, $method] = explode('@', $uses);

    if (! class_exists($controller) || ! method_exists($controller, $method)) {
        return false;
    }

    $reflection = new ReflectionMethod($controller, $method);
    $lines = file((string) $reflection->getFileName());

    if ($lines === false) {
        return false;
    }

    $body = implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));

    return str_contains($body, '$this->authorize(')
        || str_contains($body, '$this->authorizeAny(')
        || str_contains($body, 'Gate::authorize(');
}

/**
 * A member of a company holding exactly one company-scoped role.
 *
 * Named apart from the helper in AuthorizationTest so the two files can be run
 * together without redeclaring a global function.
 */
function nxRouteMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

it('names every protected route, and no route it does not have', function () {
    $map = nxRoutePermissions();

    $actual = collect(nxGuardedRoutes())
        ->map(fn (RoutingRoute $route): string => (string) $route->getName())
        ->sort()
        ->values()
        ->all();

    $mapped = collect(array_keys($map))->sort()->values()->all();

    // A route with no entry here is a route nobody has decided the rights for.
    expect(array_diff($actual, $mapped))->toBe([]);

    // And an entry with no route is a claim about a surface that no longer
    // exists, which would quietly retire a permission from the audit.
    expect(array_diff($mapped, $actual))->toBe([]);

    // Every permission the map names has to be one the matrix really knows,
    // or the audit is checking against a right nothing can ever hold.
    $known = AccessMatrix::permissions();

    $unknown = collect($map)
        ->reject(fn (?string $permission): bool => $permission === null)
        ->reject(fn (string $permission): bool => in_array($permission, $known, true))
        ->all();

    expect($unknown)->toBe([]);
});

it('authorises inside the action behind every protected route', function () {
    $map = nxRoutePermissions();

    foreach (nxGuardedRoutes() as $route) {
        $name = (string) $route->getName();

        // Session routes are about the caller and are guarded by
        // authentication rather than by a right; there is nothing for the
        // matrix to decide.
        if (($map[$name] ?? null) === null) {
            continue;
        }

        expect(nxRouteAuthorises($route))
            ->toBeTrue("The action behind {$name} never reaches an authorisation call.");
    }
});

it('gives every role the answer the matrix promises, with a 403 when it is refused', function () {
    $this->makeFixtures();

    // Real roles and permissions, not hand-written rows: a test that granted
    // itself a role directly would not notice a broken seeder.
    $this->seed(RoleAndPermissionSeeder::class);

    $grants = AccessMatrix::grants();
    $map = nxRoutePermissions();

    $members = [];

    foreach (array_keys($grants) as $role) {
        $members[$role] = nxRouteMember($this->companyId, $role);
    }

    $asked = 0;

    foreach (nxGuardedRoutes() as $route) {
        $name = (string) $route->getName();
        $permission = $map[$name] ?? null;

        if ($permission === null) {
            continue;
        }

        // See the file docblock: a route with a path parameter resolves its
        // record before the policy is reached, so a forbidden caller meets a
        // 404 on a row that does not exist. Those keep their own 403
        // assertions in the feature file for their area.
        if ($route->parameterNames() !== []) {
            continue;
        }

        $method = $route->methods()[0];
        $uri = '/'.$route->uri();

        foreach ($grants as $role => $held) {
            Sanctum::actingAs($members[$role]);

            $status = $this->json($method, $uri)->getStatusCode();
            $where = "{$method} {$uri} as {$role} (needs {$permission})";

            if (in_array($permission, $held, true)) {
                // Anything but a refusal: a report answers 200, a write
                // answers 422 for the empty body it was handed.
                expect($status)->not->toBe(403, "{$where} was refused.");
            } else {
                expect($status)->toBe(403, "{$where} answered {$status}.");
            }

            $asked++;
        }
    }

    // A sweep that quietly stopped covering anything would still pass; this
    // is the guard against that. It is a floor rather than an exact count so
    // adding a route does not mean editing a number here.
    expect($asked)->toBeGreaterThanOrEqual(300);
});
