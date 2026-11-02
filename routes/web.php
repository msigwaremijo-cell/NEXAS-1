<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('prototype.login');
});

Route::get('/dashboard', function () {
    return view('prototype.dashboard');
})->name('dashboard');

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
