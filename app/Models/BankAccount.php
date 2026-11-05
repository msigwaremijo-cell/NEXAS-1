<?php

namespace App\Models;

use App\Domain\Banking\Enums\BankAccountStatus;
use App\Domain\Banking\Enums\BankAccountType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One place the company keeps money: a bank account, a cash box or a mobile
 * money wallet.
 *
 * Every one of them is backed by a ledger account, which is what makes "how
 * much is in this account" an accounting question rather than a number the
 * application keeps on the side. The balance is never stored: it is read out of
 * journal_lines through the linked account, so a cash box cannot disagree with
 * the general ledger.
 *
 * There is no live bank connection anywhere in this system. `bank_name` and
 * `account_number` are labels a human typed, and statement lines arrive from a
 * file somebody downloaded from their bank.
 *
 * opening_balance is deliberately not fillable: OpenBankAccount writes it only
 * after the journal carrying it has been posted, so it cannot drift from the
 * ledger.
 */
#[Fillable([
    'ulid',
    'company_id',
    'branch_id',
    'account_id',
    'name',
    'bank_name',
    'account_number',
    'currency_code',
    'account_type',
    'status',
    'row_version',
])]
class BankAccount extends Model
{
    use HasPublicId, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => BankAccountType::class,
            'status' => BankAccountStatus::class,
            'opening_balance' => 'decimal:4',
            'branch_id' => 'integer',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The ledger account this account's money physically sits in. Every receipt,
     * payment and transfer that names this bank account is a journal line
     * against this account, and nothing else.
     *
     * @return BelongsTo<Account, $this>
     */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The movements a statement file claimed for this account. They are the
     * bank's word, not the company's: nothing is in the books until one of them
     * is matched to a journal line.
     *
     * @return HasMany<BankStatementLine, $this>
     */
    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    /**
     * Only an active account may take new money. The scope is spelled out here
     * so the cashbook, the importer and the reconciliation engine do not each
     * invent their own idea of which accounts are usable.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BankAccountStatus::Active->value);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where('name', 'like', $like)
            ->orWhere('account_number', 'like', $like)
            ->orWhere('bank_name', 'like', $like));
    }
}
