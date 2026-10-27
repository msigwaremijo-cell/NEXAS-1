<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Invoice;
use App\Models\User;

/**
 * Who may raise an invoice, and for which company.
 *
 * Writing and issuing are separate abilities on purpose. Preparing an invoice
 * is clerical work; issuing it puts a receivable and a revenue figure into the
 * ledger, which is the moment the books change. A Cashier may look an invoice
 * up to take a receipt against it, but must not be able to create one.
 *
 * There is no delete ability: an invoice that has been issued is a financial
 * document and is corrected by a credit note, never by removal. A draft that
 * should never have existed is the only thing deletion would be for, and
 * leaving the ability off denies it to every role including the Owner, which
 * is enforced rather than merely intended.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_INVOICE_VIEW);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->worksFor($user, $invoice)
            && $user->can(AccessMatrix::PERMISSION_INVOICE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_INVOICE_MANAGE);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->worksFor($user, $invoice)
            && $user->can(AccessMatrix::PERMISSION_INVOICE_MANAGE);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->worksFor($user, $invoice)
            && $user->can(AccessMatrix::PERMISSION_INVOICE_ISSUE);
    }

    private function worksFor(User $user, Invoice $invoice): bool
    {
        return $user->belongsToCompany((int) $invoice->company_id);
    }
}
