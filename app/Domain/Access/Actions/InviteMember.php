<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Exceptions\AccessStateException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a member to a company.
 *
 * A user row is global and the role is per company, so an invitation writes
 * both: the account, the company_user link that makes it a member here, and the
 * company-scoped role that gives the link its meaning. All three land in one
 * transaction, because an account with a membership but no role can sign in and
 * see nothing, and an account with neither is a row nobody can explain.
 *
 * Somebody who already has an account — because they are a member of another
 * company — is reused rather than created a second time. Their password is left
 * alone: the Owner is adding a membership, not taking the account over.
 *
 * A freshly invited member starts on the least privileged role that still lets
 * them read the books, so a mistake in the invite screen cannot hand out rights
 * nobody meant to grant.
 */
final class InviteMember
{
    use WorksWithCompanyRoles;

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array{name: string, email: string, password?: ?string, job_title?: ?string, phone?: ?string, role?: ?string}  $data
     */
    public function __invoke(int $companyId, array $data): User
    {
        return DB::transaction(function () use ($companyId, $data): User {
            $role = $data['role'] ?? AccessMatrix::defaultRole();

            $member = User::query()->where('email', $data['email'])->lockForUpdate()->first();

            // Already on this company's books: the answer is to change their
            // role, not to add them a second time and raise a SQLSTATE.
            if ($member !== null && $member->belongsToCompany($companyId)) {
                throw AccessStateException::alreadyMember($member);
            }

            if ($member === null) {
                $member = new User([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                ]);
            }

            $member->fill([
                'name' => $data['name'],
                'job_title' => $data['job_title'] ?? $member->job_title,
                'phone' => $data['phone'] ?? $member->phone,
                // An invitation reactivates a member who was switched off
                // before, which is what makes deactivation reversible.
                'status' => 'active',
            ])->save();

            // The first company a member joins becomes their default, which is
            // the company a request works in when the caller names none.
            $member->companies()->syncWithoutDetaching([
                $companyId => [
                    'is_default' => $member->companies()->count() === 0,
                    'status' => 'active',
                ],
            ]);

            $this->inCompany($companyId, fn () => $member->assignRole($role));

            $this->audit->record('user.invited', $member, null, [
                'name' => $member->name,
                'email' => $member->email,
                'status' => $member->status,
                'role' => $role,
            ], $companyId);

            return $member;
        });
    }
}
