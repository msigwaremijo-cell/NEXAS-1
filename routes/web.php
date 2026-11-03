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
 */
Route::get('/financial-manager', function () {
    return response()->file(public_path('financial-manager/index.html'), [
        'Content-Type' => 'text/html; charset=utf-8',
    ]);
})->name('financial-manager');
