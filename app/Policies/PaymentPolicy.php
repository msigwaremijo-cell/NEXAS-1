<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Payment;
use App\Models\User;

/**
 * Who may record and read money movements, and for which company.
 *
 * Recording is deliberately a different permission from raising an invoice.
 * The Cashier role exists for exactly this: taking money over a counter is a
 * daily counter job, while deciding what a customer owes is an accounting one.
 *
 * There is no update or delete ability. A posted payment has moved cash and
 * has a journal behind it, so it is corrected by a reversal through the
 * accounting actions, never by editing the row that recorded it.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_PAYMENT_VIEW);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->worksFor($user, $payment)
            && $user->can(AccessMatrix::PERMISSION_PAYMENT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_PAYMENT_RECORD);
    }

    private function worksFor(User $user, Payment $payment): bool
    {
        return $user->belongsToCompany((int) $payment->company_id);
    }
}
