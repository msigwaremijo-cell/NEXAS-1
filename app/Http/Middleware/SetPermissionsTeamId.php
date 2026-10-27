<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the company a request works in for the rest of the request.
 *
 * config/permission.php runs Spatie in teams mode keyed on company_id, but the
 * default team resolver keeps the team id in memory only: somebody has to set
 * it per request, or every role check resolves against a null team and silently
 * fails. This middleware is that somebody.
 *
 * The company is taken from the X-Company-Id header when the caller names one,
 * and from the member's default company otherwise. Naming a company the member
 * does not belong to is a 403 rather than a silent fallback, so a mis-scoped
 * client cannot end up writing into the wrong tenant's books.
 *
 * The resolved id is also left on the request as the `company_id` attribute,
 * which is what the controllers read, so the header is parsed exactly once.
 */
final class SetPermissionsTeamId
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $companyId = $this->resolveCompanyId($request, $user);

            setPermissionsTeamId($companyId);

            // The relations Spatie caches on the model are team-scoped, so any
            // copy loaded before this point must not be reused.
            $user->unsetRelation('roles')->unsetRelation('permissions');

            $request->attributes->set('company_id', $companyId);
        }

        return $next($request);
    }

    private function resolveCompanyId(Request $request, User $user): ?int
    {
        $requested = $request->header('X-Company-Id');

        if ($requested === null || $requested === '') {
            return $user->defaultCompanyId();
        }

        if (! ctype_digit((string) $requested)) {
            abort(400, 'The X-Company-Id header must be a numeric company id.');
        }

        $companyId = (int) $requested;

        abort_unless(
            $user->belongsToCompany($companyId),
            403,
            'You do not have access to this company.',
        );

        return $companyId;
    }
}
