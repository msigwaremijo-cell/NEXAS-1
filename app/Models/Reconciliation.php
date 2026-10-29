<?php

namespace App\Models;

use App\Domain\Banking\Enums\MatchType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tie between a claim the bank made and a movement in the books.
 *
 * The table is deliberately a pair rather than a header: a reconciliation is
 * not an object with its own number and totals, it is the set of these rows
 * that happen to be stamped together when the statement is signed off. Leaving
 * the header out is what makes a split match free — one statement line that
 * covers two ledger entries is two rows sharing a bank_statement_line_id, and
 * the unique index over (bank_statement_line_id, journal_line_id) is what stops
 * the same pair being written twice.
 *
 * reconciled_at and reconciled_by_user_id are the completion stamp, and they
 * are both null while the match is still tentative: a tentative match can be
 * changed or released, and nothing about it is a claim on the books. They are
 * not fillable, because the only code that may set them is the completion
 * action, and it sets them together in one statement so the CHECK that pairs
 * them can never be observed half-satisfied.
 *
 * There is no live bank connection anywhere in this system. A reconciliation
 * compares a file somebody downloaded from their online banking against the
 * company's own ledger, and it posts nothing: matching a line changes no
 * balance, because the ledger already holds every movement a match can find.
 */
#[Fillable([
    'company_id',
    'bank_statement_line_id',
    'journal_line_id',
    'matched_amount',
    'match_type',
])]
class Reconciliation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'matched_amount' => 'decimal:4',
            'match_type' => MatchType::class,
            'reconciled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    /**
     * A match that has not been signed off yet, and so can still be changed.
     */
    public function scopeTentative(Builder $query): Builder
    {
        return $query->whereNull('reconciled_at');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('reconciled_at');
    }

    public function isCompleted(): bool
    {
        return $this->reconciled_at !== null;
    }

    /**
     * Whether this statement line has been signed off, and so is closed to
     * every further edit.
     *
     * The database enforces the same thing with a trigger on
     * bank_statement_lines. Asking here as well is what turns a raw integrity
     * error into a refusal that says which reconciliation closed the line.
     */
    public static function statementLineIsLocked(int $statementLineId): bool
    {
        return self::query()
            ->where('bank_statement_line_id', $statementLineId)
            ->completed()
            ->exists();
    }
}
