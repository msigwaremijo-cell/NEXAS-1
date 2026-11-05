<?php

namespace App\Models;

use App\Domain\Banking\Enums\BankStatementLineStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One movement as the bank printed it, imported from a statement file.
 *
 * Nothing here is a ledger posting and nothing here touches the books. A
 * statement line is a claim by the bank that has not yet been tied to anything
 * the company recorded, which is exactly the thing a reconciliation is for.
 *
 * debit and credit are written the way the bank writes them, not the way this
 * system's own ledger does: `debit` is money that LEFT the account and `credit`
 * is money that ARRIVED in it. That is the opposite of the company's own books,
 * where money arriving in a bank account is a debit to the asset, so a
 * reconciler matching a line has to flip the side. The columns follow the bank
 * because this table is a faithful copy of the document, and `balance_after`
 * only makes sense beside a figure that follows the same convention.
 *
 * dedupe_hash is a fingerprint of value_date, debit, credit, reference and
 * description, and a unique index over it plus the account and company is what
 * makes an already-imported movement a fact MySQL knows rather than a check PHP
 * hopes wins the race.
 *
 * @property string|null $ignored_reason
 */
#[Fillable([
    'ulid',
    'company_id',
    'bank_account_id',
    'value_date',
    'description',
    'reference',
    'dedupe_hash',
    'debit',
    'credit',
    'balance_after',
    'status',
    'ignored_reason',
    'import_batch',
    'row_version',
])]
class BankStatementLine extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_date' => 'date',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'status' => BankStatementLineStatus::class,
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
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * The ties this line has to the books. More than one means a split match:
     * a single movement on the statement covered by more than one ledger entry,
     * or a single ledger entry covered by more than one statement line.
     *
     * @return HasMany<Reconciliation, $this>
     */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    /**
     * The lines of one account. The account, not the company, is what a
     * statement belongs to, so this is the scope every reader starts from.
     */
    public function scopeOfAccount(Builder $query, int $bankAccountId): Builder
    {
        return $query->where('bank_account_id', $bankAccountId);
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->where('status', BankStatementLineStatus::Unmatched->value);
    }
}
