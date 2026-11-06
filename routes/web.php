<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('prototype.login');
});

Route::get('/dashboard', function () {
    return view('prototype.dashboard');
})->name('dashboard');

/*
 * Where a signed-in member lands when their role has no portal of its own yet.
 *
 * The sign-in response names this route for every role the matrix does not
 * give a workspace to, so a member who signs in is sent somewhere honest
 * rather than to a screen built for somebody else.
 */
Route::get('/coming-soon', function () {
    return view('prototype.coming-soon');
})->name('coming-soon');

/*
 * The Financial Manager portal.
 *
 * Served as a file rather than through a controller on purpose: the window is
 * static, it holds no server-rendered state and it carries no secrets. Every
 * thing it can do it does by calling the same versioned API the rest of the
 * system calls, with a bearer token and the company header, so this route has
 * nothing to authorise — the API is what refuses a caller who is not a
 * Financial Manager, and the page renders the refusal.
 *
 * Each section has a URL of its own — the dashboard is /financial-manager/
 * dashboard — so a reload, a bookmark or a click in the sidebar lands on the
 * section it names. The page reads its own path to decide which section to
 * draw; the server only decides which paths are real, so a path that is not one
 * of the portal's sections is a 404 rather than a second copy of the shell.
 */
$portal = function (?string $screen = null) {
    $screens = ['dashboard', 'approvals', 'forecast', 'budgets', 'reports', 'periods', 'audit'];

    abort_unless($screen === null || in_array($screen, $screens, true), 404);

    return response()->file(public_path('fm-portal/index.html'), [
        'Content-Type' => 'text/html; charset=utf-8',
    ]);
};

/*
 * A report's own address.
 *
 * The reports screen opens a report at /financial-manager/reports/<report>, so
 * that a reload, a bookmark or a shared link lands on the report named in the
 * path rather than back at the hub. The shell reads its own path to decide
 * which report to draw; the server only decides which report names are real, so
 * a name that is not one of the portal's reports is a 404 rather than a second
 * copy of the shell. This list and the portal's own report catalogue are the
 * two halves of one rule and must be kept together.
 */
$report = function (string $report) use ($portal) {
    $reports = [
        'profit-and-loss',
        'balance-sheet',
        'cash-flow',
        'cash-flow-indirect',
        'trial-balance',
        'general-ledger',
        'receivables-ageing',
        'payables-ageing',
        'stock-valuation',
        'budget-vs-actual',
        'cash-position',
    ];

    abort_unless(in_array($report, $reports, true), 404);

    return $portal('reports');
};

Route::get('/financial-manager', $portal)->name('financial-manager');
Route::get('/financial-manager/{screen}', $portal)
    ->where('screen', '[a-z][a-z-]*')
    ->name('financial-manager.screen');
Route::get('/financial-manager/reports/{report}', $report)
    ->where('report', '[a-z][a-z-]*')
    ->name('financial-manager.report');
