<?php

namespace App\Models;

use App\Domain\Accounting\Enums\PartyStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A supplier the company buys from.
 *
 * Shape and lifecycle match Customer on purpose: both are master data, both
 * are soft-deleted, and both keep their code reserved once used. The two extra
 * columns are the payable control account and `withholding_agent`, which marks
 * a supplier the company must withhold tax from when it pays them.
 */
#[Fillable([
    'ulid',
    'company_id',
    'code',
    'name',
    'tin',
    'vrn',
    'email',
    'phone',
    'address',
    'currency_code',
    'payable_account_id',
    'credit_limit',
    'payment_terms_days',
    'withholding_agent',
    'status',
    'notes',
    'row_version',
])]
class Supplier extends Model
{
    use HasPublicId, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PartyStatus::class,
            'credit_limit' => 'decimal:4',
            'payment_terms_days' => 'integer',
            'withholding_agent' => 'boolean',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The control account this supplier's balance rolls up into.
     */
    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PartyStatus::Active->value);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where('name', 'like', $like)
            ->orWhere('code', 'like', $like));
    }
}
