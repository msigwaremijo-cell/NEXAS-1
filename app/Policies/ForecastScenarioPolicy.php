<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\ForecastScenario;
use App\Models\User;

/**
 * Who may keep and run a company's saved forecast scenarios.
 *
 * A saved scenario is a set of assumptions, not a figure and not a posting, so
 * the right that guards it is `forecast.scenario` — the same right that lets a
 * member run the projection under changed assumptions. Reading a saved scenario
 * is guarded by it too: what the company saved is the Financial Manager's own
 * working paper, and a member trusted to read the projection but not to test it
 * has no scenario to be shown.
 *
 * Membership is checked by hand as well as by permission, because a policy can
 * be reached with a scenario from a company the caller has never been a member
 * of: route model binding resolves the row by its public id across every
 * company, and the company middleware has already settled which company is in
 * play. A permission check alone would not notice that the two disagree.
 *
 * Laravel resolves this by convention (App\Policies\{Model}Policy).
 */
class ForecastScenarioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_FORECAST_SCENARIO);
    }

    public function view(User $user, ForecastScenario $scenario): bool
    {
        return $user->belongsToCompany((int) $scenario->company_id)
            && $user->can(AccessMatrix::PERMISSION_FORECAST_SCENARIO);
    }

    /**
     * Saving a new scenario. A user-only ability: there is no row to scope yet,
     * and the company is already settled by the middleware.
     */
    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_FORECAST_SCENARIO);
    }

    /**
     * Changing a scenario's assumptions by writing its next version.
     */
    public function update(User $user, ForecastScenario $scenario): bool
    {
        return $user->belongsToCompany((int) $scenario->company_id)
            && $user->can(AccessMatrix::PERMISSION_FORECAST_SCENARIO);
    }

    /**
     * Bringing an old version forward as a new one.
     *
     * Named apart from `restore` on purpose: this model is not soft-deleted, so
     * nothing is being undeleted — a version is copied forwards and the history
     * is left whole.
     */
    public function restoreVersion(User $user, ForecastScenario $scenario): bool
    {
        return $user->belongsToCompany((int) $scenario->company_id)
            && $user->can(AccessMatrix::PERMISSION_FORECAST_SCENARIO);
    }

    /**
     * Setting the company's low-cash alert threshold.
     *
     * Guarded by the scenario right rather than the company-settings right,
     * because the threshold exists only to read the forecast: it is what the
     * runway panel calls "low", and the member who runs the forecast is the
     * member who says what low means to this company.
     */
    public function manageSettings(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_FORECAST_SCENARIO);
    }
}
