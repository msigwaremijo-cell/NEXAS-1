<?php

namespace App\Domain\Importing\Importers;

use App\Domain\Importing\DTOs\ImportRequest;
use App\Domain\Importing\DTOs\ImportRowError;
use App\Domain\Importing\DTOs\ImportTable;
use App\Domain\Importing\Exceptions\ImportRowRefusal;
use App\Models\Account;

/**
 * The shared bones of an importer: how a field is read out of a row, and how a
 * file's rows are walked so that one bad row is one entry on the report rather
 * than the end of the run.
 */
abstract class AbstractDatasetImporter implements DatasetImporter
{
    /**
     * The cell under the column the mapping named for this field, or null when
     * the field was not mapped.
     *
     * @param  array<string, string>  $row
     */
    protected function cell(ImportRequest $request, array $row, string $field): ?string
    {
        $column = $request->column($field);

        if ($column === null) {
            return null;
        }

        return $row[$column] ?? null;
    }

    /**
     * The human label for a field, as it appears in a refusal.
     */
    protected function label(string $field): string
    {
        return $this->fields()[$field] ?? $field;
    }

    /**
     * Walk every row, taking the first complaint from each and carrying on.
     *
     * @param  callable(array<string, string>, int): (array<string, mixed>|null)  $perRow
     * @return array{0: list<array<string, mixed>>, 1: list<ImportRowError>}
     */
    protected function readRows(ImportTable $table, callable $perRow): array
    {
        $rows = [];
        $errors = [];

        foreach ($table->rows as $index => $row) {
            $line = $table->lines[$index];

            try {
                $prepared = $perRow($row, $line);

                if ($prepared !== null) {
                    $rows[] = $prepared;
                }
            } catch (ImportRowRefusal $refusal) {
                $errors[] = $refusal->error;
            }
        }

        return [$rows, $errors];
    }

    /**
     * The physical lines each value of one field appears on.
     *
     * Read before the rows are walked so that a value the file repeats can be
     * refused by naming every line it sits on rather than only the second one,
     * which is the difference between a message an operator can act on and one
     * that sends them looking for the first copy.
     *
     * @return array<string, list<int>>
     */
    protected function valueLines(ImportRequest $request, ImportTable $table, string $field): array
    {
        $column = $request->column($field);

        if ($column === null) {
            return [];
        }

        $lines = [];

        foreach ($table->rows as $index => $row) {
            $value = trim($row[$column] ?? '');

            if ($value !== '') {
                $lines[$value][] = $table->lines[$index];
            }
        }

        return $lines;
    }

    /**
     * The lines of every copy of a value the file carries more than once, or
     * null when this row is the first and only one.
     *
     * @param  array<string, list<int>>  $lines
     * @return list<int>|null
     */
    protected function repeatOf(array $lines, string $value, int $line): ?array
    {
        $occurrences = $lines[$value] ?? [];

        return $occurrences !== [] && $occurrences[0] !== $line ? $occurrences : null;
    }

    /**
     * Every account in the company, keyed by the code a file would name.
     *
     * Soft-deleted accounts are included because the unique index on
     * (company_id, code) does not know about soft deletes: a code that has been
     * used is used, whether or not the account still shows in the chart.
     *
     * @return array<string, Account>
     */
    protected function accountsByCode(int $companyId): array
    {
        return Account::withTrashed()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('code')
            ->all();
    }

    /**
     * Every account a file may name, keyed by its code.
     *
     * Soft-deleted accounts are left out on purpose: a party's control account
     * or an opening balance has to point at an account the books still hold,
     * and a retired account is one they no longer do.
     *
     * @return array<string, Account>
     */
    protected function referenceAccounts(int $companyId): array
    {
        return Account::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('code')
            ->all();
    }

    /**
     * The account a code names, or a refusal naming the code.
     *
     * A heading is refused as well as a missing code: a roll-up has no line of
     * its own in the ledger, so a file that points a party's control account or
     * an opening balance at one would import a posting the books cannot hold.
     *
     * @param  array<string, Account>  $accounts
     */
    protected function account(
        array $accounts,
        ?string $code,
        string $field,
        string $label,
        int $line,
        bool $required = true,
    ): ?Account {
        if ($code === null) {
            if ($required) {
                throw ImportRowRefusal::of(ImportRowError::missing($line, $field, $label));
            }

            return null;
        }

        $account = $accounts[$code] ?? null;

        if ($account === null) {
            throw ImportRowRefusal::of(ImportRowError::unknownReference($line, $field, $label, $code, 'account'));
        }

        if (! $account->is_postable) {
            throw ImportRowRefusal::of(ImportRowError::notPostable($line, $field, $label, $code));
        }

        return $account;
    }
}
