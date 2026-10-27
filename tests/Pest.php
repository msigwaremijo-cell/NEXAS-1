<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Unit tests both boot the Laravel application, because the
| database-level integrity rules under test (CHECK constraints and triggers)
| only exist once the framework has connected to MySQL.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');
