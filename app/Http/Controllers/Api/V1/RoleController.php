<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessMatrix;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The roles a company may hand out.
 *
 * Roles are fixed by the matrix rather than editable: a company chooses which
 * of the seven roles each member holds, not what a role means. Exposing the
 * grants is what makes the user screen able to show, before an Owner assigns
 * one, exactly what it will hand over — the matrix is the single source of
 * truth, so this reads from it rather than from the seeded tables and cannot
 * drift from what the RoleAndPermissionSeeder installs.
 *
 * Reading it is administration rather than a report, so it is behind the same
 * `user.manage` right as the user list beside it: the Owner alone.
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $roles = [];

        foreach (AccessMatrix::grants() as $role => $permissions) {
            sort($permissions);

            $roles[] = [
                'name' => $role,
                'permissions' => $permissions,
                'is_default' => $role === AccessMatrix::defaultRole(),
            ];
        }

        return response()->json(['data' => $roles]);
    }
}
