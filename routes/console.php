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

// The audit trail is only evidence while every link still hashes to what it
// claims, so it is rechecked nightly, just after the ledger. A break writes an
// outbox alert and returns a non-zero exit code, because a tampered trail is
// the one failure that must never pass silently. Offset from the ledger check
// so the two failures are not reported in the same minute.
Schedule::command('audit:verify')->dailyAt('02:45');

// Exported report files live outside public and are only readable through a
// signed, expiring link. Hourly rather than daily, and offset from the ledger
// check, so a file is removed within the hour of its 24-hour window closing
// rather than lingering until the next night. The export row is kept: the file
// goes, the record of what was asked for and what went wrong does not.
Schedule::command('exports:prune')->hourlyAt(15);

// The nightly backup: the whole database — triggers included, because they are
// what enforce the posting rules — together with the documents filed against
// it, in one encrypted file, and then the retention policy over the files
// already held.
//
// Last of the night's work on purpose. The verifications above are read-only
// and finish by 02:45, so by the time the copy is taken a broken ledger or a
// tampered audit trail has already been reported rather than being archived
// with the copy. It runs whether or not they passed: a night with a problem in
// it is not a night to stop keeping a backup of.
Schedule::command('backups:run')->dailyAt('03:15');
