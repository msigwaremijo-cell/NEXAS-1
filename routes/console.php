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

// Exported report files live outside public and are only readable through a
// signed, expiring link. Hourly rather than daily, and offset from the ledger
// check, so a file is removed within the hour of its 24-hour window closing
// rather than lingering until the next night. The export row is kept: the file
// goes, the record of what was asked for and what went wrong does not.
Schedule::command('exports:prune')->hourlyAt(15);
