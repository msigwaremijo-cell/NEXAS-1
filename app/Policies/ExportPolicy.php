<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Export;
use App\Models\User;

/**
 * Rendered report files.
 *
 * An export is a report in a file, so the right to ask for one is the right to
 * read the report it holds: `report.view`, the same right the report's own
 * endpoint is guarded by. Nothing here writes to the books — an export reads
 * them — so no other right is involved.
 *
 * Membership is checked by hand, like every other company-scoped policy: the
 * record handed in may belong to a company the caller has never been a member
 * of, and a permission check alone would not notice. The controller also
 * resolves the row inside the caller's company, so another tenant's export is
 * a 404 before it ever reaches here; this is the second half of the same gate.
 *
 * A definition may name a narrower right than `report.view` — a budget report
 * would be behind `budget.view` — and the controller checks that right against
 * the definition as well. What is here is the floor every report shares.
 */
class ExportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_REPORT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_REPORT_VIEW);
    }

    public function view(User $user, Export $export): bool
    {
        return $user->belongsToCompany((int) $export->company_id)
            && $user->can(AccessMatrix::PERMISSION_REPORT_VIEW);
    }

    /**
     * Serving the finished file is reading the same report the record
     * describes, so it is the same right and the same membership test.
     */
    public function download(User $user, Export $export): bool
    {
        return $this->view($user, $export);
    }
}
