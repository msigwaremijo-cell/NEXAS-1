<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ledger_balances is a cache of journal_lines, so it is only worth having while
// the two agree. A nightly check turns a silent drift into a non-zero exit code
// and an outbox alert, rather than a statement that quietly reads a stale
// figure and still adds up. Deliberately not run in the same minute as other
// nightly work, so a failure here is not lost in another job's noise.
Schedule::command('ledger:verify')->dailyAt('02:30');
