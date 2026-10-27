<?php

namespace App\Domain\Purchases\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;

/**
 * Base type for the rules supplier bills refuse to break.
 */
class PurchasesException extends AccountingException {}
