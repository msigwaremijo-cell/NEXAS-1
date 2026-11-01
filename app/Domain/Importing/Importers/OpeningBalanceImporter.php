<?php

namespace App\Domain\Importing\Importers;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Importing\DTOs\DatasetPlan;
use App\Domain\Importing\DTOs\ImportRequest;
use App\Domain\Importing\DTOs\ImportRowError;
use App\Domain\Importing\DTOs\ImportTable;
use App\Domain\Importing\Exceptions\ImportingException;
use App\Domain\Importing\Exceptions\ImportRowRefusal;
use App\Domain\Importing\Services\CellReader;
use App\Models\Account;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The balances the books open with, as one balanced opening journal.
 *
 * Every row is one line: an account, named by code, with an amount on the debit
 * or the credit side. The file as a whole is one entry, so it is read twice —
 * once per line, and once as a whole — and it is refused unless its two sides
 * agree, because an opening journal that does not balance cannot be posted.
 *
 * The second reading is the check that ties the two opening datasets together.
 * The value of the stock the opening-stock file laid down has to be the value
 * this journal puts on the inventory accounts it debits. Opening the book with
 * a ledger figure the layers disagree with would leave the two out of step from
 * the first day, and every reconciliation afterwards would be chasing a
 * difference that was imported rather than earned. So the journal's debits to
 * the company's inventory accounts are added up and compared with the layers,
 * and a difference refuses the whole file with both figures stated.
 *
 * The check runs on the dry run as well as the commit, so what an operator
 * approves is what the commit enforces. When the rows themselves have errors
 * the file-level checks are held back, because a journal missing the rows that
 * failed to parse would be judged against totals that were never the file's.
 *
 * A journal is dated the day the books begin, taken from the run rather than
 * from the file, and it is both written and posted: an opening balance that sat
 * in a draft would not be in the ledger the rest of the books are read from.
 */
final class OpeningBalanceImporter extends AbstractDatasetImporter
{
    private const CODE_LIMIT = 30;

    private const TEXT_LIMIT = 255;

    public function __construct(
        private readonly CreateJournal $createJournal,
        private readonly PostJournal $postJournal,
    ) {}

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return [
            'account_code' => 'account code',
            'debit' => 'debit',
            'credit' => 'credit',
            'description' => 'description',
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return ['account_code'];
    }

    public function prepare(ImportRequest $request, ImportTable $table): DatasetPlan
    {
        if ($request->asOf === null) {
            throw ImportingException::missingAsOf($request->dataset->label());
        }

        $accounts = $this->referenceAccounts($request->companyId);

        [$rows, $errors] = $this->readRows(
            $table,
            function (array $row, int $line) use ($request, $accounts): array {
                return $this->readRow($request, $row, $line, $accounts);
            }
        );

        $debitTotal = Money::sum(array_map(static fn (array $row): Money => Money::of($row['debit']), $rows));
        $creditTotal = Money::sum(array_map(static fn (array $row): Money => Money::of($row['credit']), $rows));

        // The file-level reading only makes sense on a file whose rows all
        // parsed: a total that is missing a row would be judged against a
        // figure the file never stated.
        if ($errors === []) {
            if (! $debitTotal->equals($creditTotal)) {
                $errors[] = ImportRowError::unbalancedOpening($debitTotal->toDecimal(), $creditTotal->toDecimal());
            } elseif ($mismatch = $this->inventoryMismatch($request, $rows)) {
                $errors[] = $mismatch;
            }
        }

        return new DatasetPlan($rows, $errors, [
            'lines' => count($rows),
            'debit' => $debitTotal->toDecimal(),
            'credit' => $creditTotal->toDecimal(),
        ]);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, Account>  $accounts
     * @return array<string, mixed>
     */
    private function readRow(ImportRequest $request, array $row, int $line, array $accounts): array
    {
        $accountCode = CellReader::text(
            $this->cell($request, $row, 'account_code'),
            'account_code',
            $this->label('account_code'),
            $line,
            self::CODE_LIMIT,
            true,
        );

        $account = $this->account($accounts, $accountCode, 'account_code', $this->label('account_code'), $line);

        $debit = CellReader::amount($this->cell($request, $row, 'debit'), 'debit', $this->label('debit'), $line);
        $credit = CellReader::amount($this->cell($request, $row, 'credit'), 'credit', $this->label('credit'), $line);

        $debitMoney = Money::of($debit);
        $creditMoney = Money::of($credit);

        if ($debitMoney->isPositive() && $creditMoney->isPositive()) {
            throw ImportRowRefusal::of(ImportRowError::bothSidesFilled($line));
        }

        if (! $debitMoney->isPositive() && ! $creditMoney->isPositive()) {
            throw ImportRowRefusal::of(ImportRowError::noAmount($line));
        }

        return [
            'account_id' => (int) $account?->getKey(),
            'account_code' => $accountCode,
            'debit' => $debit,
            'credit' => $credit,
            'description' => CellReader::text(
                $this->cell($request, $row, 'description'),
                'description',
                $this->label('description'),
                $line,
                self::TEXT_LIMIT,
            ),
        ];
    }

    /**
     * The layers the opening stock laid down, against the value this journal
     * puts on the accounts that hold them.
     *
     * The stock value is what the layers received, summed over those laid down
     * by the date the books begin. Nothing has been drawn from them yet — this
     * is the opening day — so the received figure is also what is left.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function inventoryMismatch(ImportRequest $request, array $rows): ?ImportRowError
    {
        $inventoryAccounts = array_fill_keys(
            Product::query()
                ->where('company_id', $request->companyId)
                ->whereNotNull('inventory_account_id')
                ->pluck('inventory_account_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            true
        );

        $journalValue = Money::zero();

        foreach ($rows as $row) {
            if (isset($inventoryAccounts[$row['account_id']])) {
                $journalValue = $journalValue->plus(Money::of($row['debit']));
            }
        }

        $stockRow = DB::table('stock_layers')
            ->where('company_id', $request->companyId)
            ->where('received_on', '<=', $request->asOf)
            ->selectRaw('COALESCE(SUM(quantity_received * unit_cost), 0) AS value')
            ->first();

        $stockValue = Money::of((string) ($stockRow->value ?? '0'));

        return $journalValue->equals($stockValue)
            ? null
            : ImportRowError::inventoryMismatch($journalValue->toDecimal(), $stockValue->toDecimal());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function commit(ImportRequest $request, array $rows): array
    {
        if ($rows === []) {
            return ['journal' => null, 'lines' => 0];
        }

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = Money::of($row['debit'])->isPositive()
                ? JournalLineData::debit($row['account_id'], $row['debit'], $row['description'])
                : JournalLineData::credit($row['account_id'], $row['credit'], $row['description']);
        }

        $journal = ($this->createJournal)(new JournalEntryData(
            companyId: $request->companyId,
            postingDate: (string) $request->asOf,
            journalType: JournalType::Opening,
            lines: $lines,
            memo: 'Opening balances',
            sourceType: 'import',
            createdByUserId: $request->userId,
        ));

        $posted = ($this->postJournal)($journal, null, $request->userId);

        return [
            'journal' => $posted->journal_no,
            'journal_id' => (int) $posted->getKey(),
            'lines' => count($lines),
        ];
    }
}
