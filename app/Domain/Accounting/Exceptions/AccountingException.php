<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

/**
 * Base type for every rule the accounting domain refuses to break.
 *
 * The actions throw these instead of returning false or letting a MySQL
 * SQLSTATE escape, which keeps accounting rules out of the controllers: the
 * HTTP layer can map this one type to a 422 while the database triggers stay
 * the last line of defence.
 */
class AccountingException extends RuntimeException {}
