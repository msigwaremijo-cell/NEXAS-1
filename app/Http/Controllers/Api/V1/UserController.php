<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Actions\AssignMemberRole;
use App\Domain\Access\Actions\InviteMember;
use App\Domain\Access\Actions\RemoveMemberRole;
use App\Domain\Access\Actions\SetMemberStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administering the members of a company.
 *
 * Nothing is decided here. What happens to an invitation, a role change and a
 * deactivation are rules, and they live in app/Domain/Access/Actions; this
 * class resolves the member the caller named, authorises the move against them,
 * and serialises the answer.
 *
 * Every route below is a user.manage route, which in the matrix means the Owner
 * alone. That includes reading the list: this is the screen that shows who has
 * which rights, so it is administration rather than a report.
 *
 * The member is resolved scoped to the caller's own company in the same query
 * that finds them, so naming somebody else's member is a 404 and not an
 * existence oracle. There is no delete: a member is deactivated, and the audit
 * chain keeps naming them for as long as the books they moved exist.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $companyId = $this->companyId($request);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in([SetMemberStatus::ACTIVE, SetMemberStatus::INACTIVE])],
            'role' => ['sometimes', 'nullable', Rule::in(AccessMatrix::roles())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $members = User::query()
            // Membership, not the user table, is what this screen lists: the
            // users table is global, so the join is what makes it this company's
            // members rather than everybody's.
            ->whereHas('companies', fn ($query) => $query->whereKey($companyId))
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where(function ($query) use ($filters): void {
                    $query->where('name', 'like', '%'.$filters['search'].'%')
                        ->orWhere('email', 'like', '%'.$filters['search'].'%');
                })
            )
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            // The roles relation is team-scoped, so this asks about the role in
            // the company the request is pinned to rather than about roles the
            // member may hold elsewhere.
            ->when(
                filled($filters['role'] ?? null),
                fn ($query) => $query->whereHas('roles', fn ($query) => $query->where('name', $filters['role']))
            )
            ->with('roles')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => array_map(
                fn (User $member): array => $this->present($member),
                $members->items()
            ),
            'meta' => [
                'current_page' => $members->currentPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
                'last_page' => $members->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, InviteMember $invite): JsonResponse
    {
        $this->authorize('create', User::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // A password is demanded only when the invitation creates the
            // account. Somebody who already has one — because they are a member
            // of another company — keeps it, because the Owner is adding a
            // membership here and not taking the account over.
            'password' => [
                Rule::requiredIf(fn (): bool => ! User::query()
                    ->where('email', $request->input('email'))
                    ->exists()),
                'nullable', 'string', 'min:8', 'max:255',
            ],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'role' => ['sometimes', 'nullable', Rule::in(AccessMatrix::roles())],
        ]);

        $member = $invite($companyId, $data);

        return response()->json(['data' => $this->present($member)], 201);
    }

    public function show(Request $request, string $user): JsonResponse
    {
        $member = $this->findMember($request, $user);

        $this->authorize('view', $member);

        return response()->json(['data' => $this->present($member)]);
    }

    public function assignRole(Request $request, string $user, AssignMemberRole $assign): JsonResponse
    {
        $member = $this->findMember($request, $user);

        $this->authorize('manageRoles', $member);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(AccessMatrix::roles())],
        ]);

        // The action returns the member with its cached roles relation cleared,
        // so the answer is what they now hold rather than what was asked for.
        $member = $assign($member, $this->companyId($request), $data['role']);

        return response()->json(['data' => $this->present($member)]);
    }

    public function removeRole(Request $request, string $user, string $role, RemoveMemberRole $remove): JsonResponse
    {
        $member = $this->findMember($request, $user);

        $this->authorize('manageRoles', $member);

        if (! in_array($role, AccessMatrix::roles(), true)) {
            throw ValidationException::withMessages([
                'role' => ['The selected role is invalid.'],
            ]);
        }

        $member = $remove($member, $this->companyId($request), $role);

        return response()->json(['data' => $this->present($member)]);
    }

    public function deactivate(Request $request, string $user, SetMemberStatus $setStatus): JsonResponse
    {
        $member = $this->findMember($request, $user);

        $this->authorize('manageStatus', $member);

        $member = $setStatus(
            $member,
            $this->companyId($request),
            SetMemberStatus::INACTIVE,
            $request->user()?->getKey(),
        );

        return response()->json(['data' => $this->present($member)]);
    }

    public function activate(Request $request, string $user, SetMemberStatus $setStatus): JsonResponse
    {
        $member = $this->findMember($request, $user);

        $this->authorize('manageStatus', $member);

        $member = $setStatus(
            $member,
            $this->companyId($request),
            SetMemberStatus::ACTIVE,
            $request->user()?->getKey(),
        );

        return response()->json(['data' => $this->present($member)]);
    }

    /**
     * A member of the caller's company, resolved by public identifier.
     */
    private function findMember(Request $request, string $ulid): User
    {
        return User::query()
            ->where('ulid', $ulid)
            ->whereHas('companies', fn ($query) => $query->whereKey($this->companyId($request)))
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $member): array
    {
        return [
            'id' => (int) $member->getKey(),
            'ulid' => $member->ulid,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'job_title' => $member->job_title,
            'status' => $member->status,
            'roles' => $member->getRoleNames()->sort()->values()->all(),
            'last_login_at' => $member->last_login_at?->toIso8601String(),
        ];
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
