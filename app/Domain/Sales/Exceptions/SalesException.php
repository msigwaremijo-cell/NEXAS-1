<?php

namespace App\Domain\Sales\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;

/**
 * Base type for the rules the sales documents refuse to break. Extending the
 * accounting base is what lets bootstrap/app.php map the whole family onto an
 * HTTP status without the controllers knowing anything about it.
 */
class SalesException extends AccountingException {}
