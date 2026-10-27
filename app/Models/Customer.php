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
 * A customer the company sells to.
 *
 * This is master data, so it is soft-deleted and never physically removed:
 * documents issued years ago must keep pointing at the party they were issued
 * to. The unique index on (company_id, code) does not know about soft deletes,
 * so a deleted customer's code stays reserved and is never reused.
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
    'receivable_account_id',
    'credit_limit',
    'payment_terms_days',
    'status',
    'notes',
    'row_version',
])]
class Customer extends Model
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
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The control account this customer's balance rolls up into.
     */
    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * Only active parties may be put on a new document. The scope is spelled
     * out here so invoice and receipt screens do not each re-invent it.
     */
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
