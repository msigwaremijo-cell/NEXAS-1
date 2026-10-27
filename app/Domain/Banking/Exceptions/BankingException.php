<?php

namespace App\Domain\Banking\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;

/**
 * Base type for the rules cash and banking refuse to break.
 */
class BankingException extends AccountingException {}
