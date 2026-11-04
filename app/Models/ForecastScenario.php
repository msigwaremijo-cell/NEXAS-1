<?php

namespace App\Models;

use App\Domain\Reporting\Enums\ForecastScenarioType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A saved forecast scenario: the identity, and a pointer to the version that is
 * currently live.
 *
 * The scenario row holds nothing but the name, the type and where the current
 * version sits. Every figure lives on {@see ForecastScenarioVersion}, one
 * immutable row per saved state, so editing a scenario writes a new version and
 * moves `current_version_no`, and restoring an old one writes a further version
 * rather than rewinding. That split is what makes the history truthful: there is
 * no row that can be edited into disagreement with itself.
 *
 * Nothing here is accounting. A saved scenario is a set of assumptions — growth
 * rates and per-account adjustments — and saving one posts no journal and
 * touches no ledger. The projection is still derived on demand from the plan and
 * the posted ledger, re-stated under these assumptions at read time.
 */
#[Fillable([
    'ulid',
    'company_id',
    'name',
    'type',
    'current_version_no',
    'created_by_user_id',
    'row_version',
])]
class ForecastScenario extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ForecastScenarioType::class,
            'current_version_no' => 'integer',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Every saved state, newest first. The history is the point, so it is kept
     * whole and never pruned.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ForecastScenarioVersion::class);
    }

    /**
     * The version that is live.
     *
     * Read as the newest version rather than by the pointer column, because the
     * two are the same by construction and only the newest can be eager-loaded:
     * versions are appended and never deleted, and `current_version_no` only
     * ever advances to a version that was just written. The pointer is kept on
     * the row so the live version is recorded rather than inferred.
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(ForecastScenarioVersion::class)->latestOfMany('version_no');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
