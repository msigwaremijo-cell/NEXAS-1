<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Enums\TaxType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The VAT3 return for a period, read from the posted ledger.
 *
 * The tax a company has charged and the tax it has been charged both sit in the
 * ledger as movements on the accounts its tax codes name: a sale credits the
 * code's output account, a purchase debits the code's input account. This class
 * reads those movements and adds them up, and it adds up nothing else — it does
 * not price documents, does not re-derive a rate, and does not reach past the
 * ledger to the invoices and bills behind it.
 *
 * That is the whole of the method, and it is why the return is right: the
 * figure it reports for output tax is the same figure the VAT control account
 * holds, so a return cannot disagree with the books it is drawn from. It also
 * means corrections follow for nothing. A credit note against an earlier sale
 * debits the output account with the tax it gives back, a reversal mirrors
 * whatever it reverses, and both reduce the return of the period they are dated
 * in — no special case, because they are just movements like any other.
 *
 * The breakdown is by tax code, not by account. Two codes may settle into one
 * control account — a standard-rated and a zero-rated VAT code commonly do —
 * and a return that merged them could not show what the standard rate raised.
 * So the posting names its code (see DocumentLineCalculator::taxByCode), and
 * this reads the code back off the line. A line on a tax account that names no
 * code — a hand-written journal — is attributed by its account instead, and
 * only when that account belongs to a single code is it labelled with one.
 *
 * Only journals in the ledger count, and which those are is LedgerQuery's rule
 * rather than one restated here: the movements are read through postedLinesFor.
 * The window's dates are the journal's own posting dates, so a return for March
 * is the tax posted in March and nothing else.
 */
final class VatReturnQuery
{
    public function __construct(private readonly LedgerQuery $ledger) {}

    /**
     * The return for a window, or for the whole ledger when no dates are given.
     *
     * The window bounds are inclusive and are meant to be a fiscal period's own
     * `starts_on` and `ends_on`; the caller resolves the period so the return
     * inherits the calendar rather than guessing at months.
     *
     * @return array<string, mixed>
     */
    public function vatReturn(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $asOf = $to ?? now()->toDateString();

        $codes = $this->taxCodes($companyId);
        $accounts = $this->accounts($codes);

        $output = [];
        $input = [];
        $other = [];

        $outputTotal = Money::zero();
        $inputTotal = Money::zero();
        $otherTotal = Money::zero();

        foreach ($this->movements($companyId, array_keys($accounts), $from, $to) as $row) {
            $accountId = (int) $row->account_id;
            $taxCodeId = $row->tax_code_id === null ? null : (int) $row->tax_code_id;
            $code = $taxCodeId === null ? null : $codes->get($taxCodeId);

            $debit = Money::of((string) $row->debit);
            $credit = Money::of((string) $row->credit);

            $side = $this->side($code, $accountId, $accounts);

            // Which way round the tax is depends on the side it sits. Output tax
            // is what the company charged, so it is the credit side of the
            // output account; input tax is what it was charged, so it is the
            // debit side of the input account. A code that is not VAT has no
            // reclaimable side, so its natural figure is the credit.
            $amount = match ($side) {
                'output' => $credit->minus($debit),
                'input' => $debit->minus($credit),
                default => $credit->minus($debit),
            };

            $line = $this->line($code, $taxCodeId, $accountId, $accounts, $debit, $credit, $amount, $side, $asOf);

            if ($side === 'output') {
                $output[] = $line;
                $outputTotal = $outputTotal->plus($amount);
            } elseif ($side === 'input') {
                $input[] = $line;
                $inputTotal = $inputTotal->plus($amount);
            } else {
                $other[] = $line;
                $otherTotal = $otherTotal->plus($amount);
            }
        }

        $net = $outputTotal->minus($inputTotal);

        return [
            'from' => $from,
            'to' => $to,
            'rate_as_of' => $asOf,
            'currency_code' => $this->currencyCode($companyId),
            'output_vat' => [
                'lines' => $this->sorted($output),
                'total' => $outputTotal->toDecimal(),
            ],
            'input_vat' => [
                'lines' => $this->sorted($input),
                'total' => $inputTotal->toDecimal(),
            ],
            // Positive means the company owes the revenue authority; negative
            // means it is owed the difference, which is the credit the next
            // return carries forward. Reported as one signed figure and as the
            // two possibilities a return actually has a box for.
            'net_vat' => $net->toDecimal(),
            'payable' => ($net->isPositive() ? $net : Money::zero())->toDecimal(),
            'credit_carried_forward' => ($net->isNegative() ? $net->absolute() : Money::zero())->toDecimal(),
            // Everything the company collects that is not VAT: withholding,
            // excise, levy, stamp duty. Reported so the return accounts for the
            // whole of what its tax accounts hold, and kept apart because none
            // of it is claimed back as input tax.
            'other_taxes' => [
                'lines' => $this->sorted($other),
                'total' => $otherTotal->toDecimal(),
            ],
            'note' => 'Read from the posted ledger, so a credit note or a reversal reduces the period '
                .'it is dated in. Rates are shown as they stand at the end of the period; each document '
                .'keeps the rate that was in force on its own date. Withholding tax is not VAT and is '
                .'reported under other taxes.',
        ];
    }

    /**
     * The tax codes the company holds, keyed and ordered by code.
     *
     * Soft-deleted codes are left out: a code nobody uses any more should not
     * put a row on a return, and if it ever posted, its history stays on the
     * account it posted to and is reported there.
     *
     * @return Collection<int, TaxCode>
     */
    private function taxCodes(int $companyId): Collection
    {
        return TaxCode::query()
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get()
            ->keyBy(static fn (TaxCode $code): int => (int) $code->getKey());
    }

    /**
     * Every account a tax code posts to, described once.
     *
     * A code names one account for the tax it charges and one for the tax it is
     * charged, and either may be absent. The two lists are kept apart rather
     * than pooled, because an account on both sides of the same code would
     * otherwise have no side at all.
     *
     * @param  Collection<int, TaxCode>  $codes
     * @return array<int, array{code: ?string, name: ?string, output: bool, input: bool, codes: list<string>}>
     */
    private function accounts(Collection $codes): array
    {
        $accounts = [];

        foreach ($codes as $code) {
            foreach ([['output_account_id', 'output'], ['input_account_id', 'input']] as [$column, $side]) {
                $accountId = $code->{$column};

                if ($accountId === null) {
                    continue;
                }

                $accountId = (int) $accountId;

                $accounts[$accountId] ??= [
                    'code' => null,
                    'name' => null,
                    'output' => false,
                    'input' => false,
                    'codes' => [],
                ];

                $accounts[$accountId][$side] = true;

                if (! in_array($code->code, $accounts[$accountId]['codes'], true)) {
                    $accounts[$accountId]['codes'][] = $code->code;
                }
            }
        }

        if ($accounts === []) {
            return [];
        }

        $labels = Account::withTrashed()
            ->whereIn('id', array_keys($accounts))
            ->get()
            ->keyBy(static fn (Account $account): int => (int) $account->getKey());

        foreach ($accounts as $accountId => $account) {
            $row = $labels->get($accountId);

            $accounts[$accountId]['code'] = $row?->code;
            $accounts[$accountId]['name'] = $row?->name;
        }

        return $accounts;
    }

    /**
     * Posted movements on the tax accounts, grouped by the code and account
     * each line names.
     *
     * One GROUP BY rather than a query per code, and read through
     * LedgerQuery::postedLinesFor so which journals count as being in the
     * ledger is decided in one place. The date range is left to that method
     * too, and its bounds land on the relying index columns.
     *
     * @param  array<int, int>  $accountIds
     * @return Collection<int, JournalLine>
     */
    private function movements(int $companyId, array $accountIds, ?string $from, ?string $to): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        return $this->ledger
            ->postedLinesFor($companyId, $accountIds, $from, $to)
            ->selectRaw(
                'journal_lines.tax_code_id AS tax_code_id,'
                .' journal_lines.account_id AS account_id,'
                .' COALESCE(SUM(journal_lines.debit), 0) AS debit,'
                .' COALESCE(SUM(journal_lines.credit), 0) AS credit'
            )
            ->groupBy('journal_lines.tax_code_id', 'journal_lines.account_id')
            ->get();
    }

    /**
     * Which side of the return a movement belongs on.
     *
     * A line that names a code is classified by that code's own accounts, so
     * two codes sharing a control account are still told apart and a code used
     * only on one side is never mistaken for the other. A line that names no
     * code is classified by the account alone, and goes to `other` when the
     * account serves both sides, because a guess would be a figure somebody
     * could not check.
     *
     * @param  array<int, array{output: bool, input: bool, codes: list<string>}>  $accounts
     */
    private function side(?TaxCode $code, int $accountId, array $accounts): string
    {
        if ($code !== null) {
            if ($code->tax_type !== TaxType::Vat) {
                return 'other';
            }

            if ((int) $code->output_account_id === $accountId) {
                return 'output';
            }

            if ((int) $code->input_account_id === $accountId) {
                return 'input';
            }

            return 'other';
        }

        $account = $accounts[$accountId];

        return match (true) {
            $account['output'] && ! $account['input'] => 'output',
            $account['input'] && ! $account['output'] => 'input',
            default => 'other',
        };
    }

    /**
     * @param  array<int, array{code: ?string, name: ?string, output: bool, input: bool, codes: list<string>}>  $accounts
     * @return array<string, mixed>
     */
    private function line(
        ?TaxCode $code,
        ?int $taxCodeId,
        int $accountId,
        array $accounts,
        Money $debit,
        Money $credit,
        Money $amount,
        string $side,
        string $asOf,
    ): array {
        return [
            'tax_code_id' => $taxCodeId,
            'tax_code' => $code?->code,
            'name' => $code?->name,
            // The rate in force at the end of the period, which is the code's
            // rate today rather than the rate on each document: the ledger line
            // names the code, and a rate that changed mid-period shows here as
            // the rate it stands at. Two rates inside one period are two codes.
            'rate' => $code?->rateOn($asOf)?->rate,
            'tax_type' => $code?->tax_type->value,
            'side' => $side,
            'account_id' => $accountId,
            'account_code' => $accounts[$accountId]['code'],
            'account_name' => $accounts[$accountId]['name'],
            // Named so a reader can see which codes share the account, which is
            // what explains a return carrying several lines for one figure.
            'account_tax_codes' => $accounts[$accountId]['codes'],
            'debit' => $debit->toDecimal(),
            'credit' => $credit->toDecimal(),
            'amount' => $amount->toDecimal(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function sorted(array $lines): array
    {
        usort($lines, static function (array $left, array $right): int {
            return [$left['tax_code'] ?? '', $left['account_code'] ?? '', $left['account_id']]
                <=> [$right['tax_code'] ?? '', $right['account_code'] ?? '', $right['account_id']];
        });

        return $lines;
    }

    private function currencyCode(int $companyId): string
    {
        return (string) Company::query()->whereKey($companyId)->value('base_currency_code');
    }
}
