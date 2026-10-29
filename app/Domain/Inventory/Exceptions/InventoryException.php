<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;

/**
 * Base type for the rules stock and costing refuse to break.
 */
class InventoryException extends AccountingException {}
