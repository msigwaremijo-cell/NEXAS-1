<?php

namespace App\Models;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\CashFlowCategory;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chart of accounts node. The hierarchy is a materialised path of ancestor
 * codes plus a denormalised depth, so subtree reports never need a recursive
 * query.
 *
 * cash_flow_category arrives by raw ALTER in a later migration, so it is
 * declared here rather than read back from the schema.
 *
 * @property CashFlowCategory|null $cash_flow_category
 */
#[Fillable([
    'ulid',
    'company_id',
    'code',
    'name',
    'account_type',
    'normal_balance',
    'parent_id',
    'path',
    'depth',
    'is_postable',
    'is_cash',
    'is_bank',
    'cash_flow_category',
    'currency_code',
    'description',
    'status',
    'row_version',
])]
class Account extends Model
{
    use HasPublicId, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'cash_flow_category' => CashFlowCategory::class,
            'depth' => 'integer',
            'is_postable' => 'boolean',
            'is_cash' => 'boolean',
            'is_bank' => 'boolean',
            'row_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @return HasMany<LedgerBalance, $this>
     */
    public function ledgerBalances(): HasMany
    {
        return $this->hasMany(LedgerBalance::class);
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true)->where('status', 'active');
    }

    public function scopeOfType(Builder $query, AccountType $type): Builder
    {
        return $query->where('account_type', $type->value);
    }
}
