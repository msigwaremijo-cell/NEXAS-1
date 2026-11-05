<?php

namespace App\Models;

use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Accounting\Enums\JournalType;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Journal header.
 *
 * Posted journals are immutable. There is deliberately no soft-delete column,
 * and the integrity triggers reject any UPDATE or DELETE once a journal leaves
 * the draft state. Corrections happen only by posting a reversal journal that
 * points back through reverses_journal_id.
 *
 * total_debit, total_credit and posted_at are deliberately not mass assignable:
 * the BEFORE UPDATE trigger derives them from the lines at the moment of
 * posting, so no caller gets to assert its own totals.
 */
#[Fillable([
    'ulid',
    'company_id',
    'fiscal_year_id',
    'fiscal_period_id',
    'journal_no',
    'journal_type',
    'status',
    'posting_date',
    'document_date',
    'reference',
    'memo',
    'currency_code',
    'exchange_rate',
    'source_type',
    'source_id',
    'reverses_journal_id',
    'reversed_by_journal_id',
    'idempotency_key',
    'posted_by_user_id',
    'created_by_user_id',
    'row_version',
])]
class Journal extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journal_type' => JournalType::class,
            'status' => JournalStatus::class,
            'posting_date' => 'date',
            'document_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'total_debit' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'posted_at' => 'datetime',
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
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * The journal this one reverses.
     *
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    /**
     * The journal that reversed this one.
     *
     * @return BelongsTo<self, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_journal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', JournalStatus::Draft->value);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', JournalStatus::Posted->value);
    }

    public function isDraft(): bool
    {
        return $this->status === JournalStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === JournalStatus::Posted;
    }

    /**
     * Only meaningful once the trigger has derived the header totals, i.e.
     * after posting.
     */
    public function isBalanced(): bool
    {
        return Money::of($this->total_debit)->equals(Money::of($this->total_credit));
    }
}
