<?php

namespace App\Models;

use App\Domain\Accounting\Enums\TaxAppliesTo;
use App\Domain\Accounting\Enums\TaxType;
use App\Models\Concerns\HasPublicId;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named tax rule (VAT, withholding, excise, levy, stamp duty).
 *
 * It deliberately carries no rate: rates are dated rows in `tax_rates`, so a
 * rate change is data rather than a code change, and a document keeps the rate
 * that was in force on its own date.
 */
#[Fillable([
    'ulid',
    'company_id',
    'code',
    'name',
    'tax_type',
    'applies_to',
    'is_inclusive',
    'is_recoverable',
    'output_account_id',
    'input_account_id',
    'status',
    'row_version',
])]
class TaxCode extends Model
{
    use HasPublicId, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_type' => TaxType::class,
            'applies_to' => TaxAppliesTo::class,
            'is_inclusive' => 'boolean',
            'is_recoverable' => 'boolean',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->orderBy('effective_from');
    }

    public function outputAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'output_account_id');
    }

    public function inputAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'input_account_id');
    }

    /**
     * The rate row whose effective window contains the given date.
     * Returns null when the code has no rate in force, which the caller must
     * treat as an error rather than assuming a default of zero.
     */
    public function rateOn(DateTimeInterface|string $date): ?TaxRate
    {
        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $this->rates()
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first();
    }
}
