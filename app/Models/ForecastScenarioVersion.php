<?php

namespace App\Models;

use App\Domain\Reporting\Enums\ForecastScenarioType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable state of a saved forecast scenario.
 *
 * A version is written once and read for ever. `updated_at` is deliberately
 * absent — there is no second write — and the database refuses both an UPDATE
 * and a DELETE on the table (see the integrity-trigger migration), so a row that
 * says what the assumptions were at a moment in time cannot later say anything
 * else. Changing a scenario writes the *next* version; restoring an old one
 * writes a *new* version that copies it. Either way the past is not rewritten.
 *
 * The figures are assumptions, not results. `revenue_growth` and
 * `expense_growth` are percentages held as decimal strings and applied to the
 * projection on read, and `adjustments` is a map of account id to a signed
 * decimal amount. None of it is posted, and none of it can appear in an actual
 * report.
 */
#[Fillable([
    'ulid',
    'company_id',
    'forecast_scenario_id',
    'version_no',
    'type',
    'revenue_growth',
    'expense_growth',
    'adjustments',
    'note',
    'created_by_user_id',
    'created_at',
])]
class ForecastScenarioVersion extends Model
{
    use HasPublicId;

    /**
     * A version is written once, so there is no updated timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ForecastScenarioType::class,
            'version_no' => 'integer',
            'revenue_growth' => 'string',
            'expense_growth' => 'string',
            'adjustments' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(ForecastScenario::class, 'forecast_scenario_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
