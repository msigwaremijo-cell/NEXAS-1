<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Banking\DTOs\StatementImportResult;
use App\Domain\Banking\DTOs\StatementMappingData;
use App\Domain\Banking\DTOs\StatementTable;
use App\Domain\Banking\Enums\BankStatementLineStatus;
use App\Domain\Banking\Exceptions\BankStatementException;
use App\Domain\Banking\Exceptions\DuplicateStatementException;
use App\Domain\Banking\Services\CsvStatementReader;
use App\Domain\Banking\Services\StatementAmount;
use App\Domain\Banking\Services\StatementFingerprint;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a stored statement file into bank_statement_lines.
 *
 * Read a whole file, or write nothing at all. Every movement is read, checked
 * and fingerprinted before the first row is written, and the writing happens in
 * one transaction. A part-imported statement is worse than a refused one: the
 * person looking at the line count cannot tell which half of the file landed,
 * and the next attempt at the same file duplicates whatever did.
 *
 * Nothing here reaches the ledger. A statement line is the bank's claim about
 * the account, and it stays a claim until the reconciliation engine ties it to
 * a journal line. Importing a statement therefore cannot change a balance, a
 * trial balance or an ageing report, which is what makes it safe to re-run.
 *
 * Duplicates are refused, not skipped, and there are three kinds:
 *
 *   - the file repeating a movement inside itself, which is what a statement
 *     concatenated with itself looks like;
 *   - movements this account has already imported, which is what re-uploading
 *     an overlapping date range looks like, and which is the normal case rather
 *     than an attack;
 *   - another import of the same file winning the race between the check above
 *     and the insert, which is refused by the unique index rather than by a
 *     second PHP check.
 *
 * All three refuse the whole file and import nothing. That is deliberate: a
 * duplicate that is silently skipped leaves the reader unable to tell "already
 * had it" from "never in the file", and the whole point of the import is to
 * know exactly what the account holds.
 */
final class ImportBankStatement
{
    /**
     * The longest reference the books hold: bank_statement_lines.reference is
     * varchar(60).
     */
    private const REFERENCE_LIMIT = 60;

    /**
     * The longest description the books hold.
     */
    private const DESCRIPTION_LIMIT = 255;

    public function __construct(
        private readonly CsvStatementReader $reader,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(StatementMappingData $mapping): StatementImportResult
    {
        $table = $this->readUpload($mapping);

        $this->assertMappingFitsTheFile($mapping, $table);

        $prepared = $this->prepare($mapping, $table);

        $this->assertTheFileDoesNotRepeatItself($prepared);
        $this->assertTheAccountDoesNotAlreadyHoldThem($mapping, $prepared);

        // Resolved again inside the company, so an account id from another
        // tenant's books cannot be imported into even if a caller found one.
        $bankAccount = BankAccount::query()
            ->where('company_id', $mapping->companyId)
            ->whereKey($mapping->bankAccountId)
            ->firstOrFail();

        return $this->write($mapping, $bankAccount, $prepared);
    }

    /**
     * The stored file the mapping names, or a refusal naming the token.
     *
     * The token is checked against the ULID alphabet before it is used to build
     * a path: caller-supplied text must not reach the filesystem, and a token
     * that is not a ULID could not have been issued by this system.
     */
    private function readUpload(StatementMappingData $mapping): StatementTable
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $mapping->uploadToken) !== 1) {
            throw BankStatementException::unknownUpload($mapping->uploadToken);
        }

        $path = StoreStatementUpload::pathFor(
            $mapping->companyId,
            $mapping->bankAccountId,
            $mapping->uploadToken,
        );

        if (! Storage::disk(StoreStatementUpload::DISK)->exists($path)) {
            throw BankStatementException::unknownUpload($mapping->uploadToken);
        }

        return $this->reader->read(Storage::disk(StoreStatementUpload::DISK)->path($path));
    }

    /**
     * The mapping has to describe this file: every column it names has to be a
     * column the file carries, no column may be asked to do two jobs, and the
     * money has to be described exactly one way.
     */
    private function assertMappingFitsTheFile(StatementMappingData $mapping, StatementTable $table): void
    {
        $columns = $mapping->columns();

        $describesMoney = isset($columns['amount'])
            ? ! isset($columns['money in']) && ! isset($columns['money out'])
            : isset($columns['money in']) || isset($columns['money out']);

        if (! $describesMoney) {
            throw isset($columns['amount'])
                ? BankStatementException::amountMappingAmbiguous()
                : BankStatementException::amountMappingMissing();
        }

        $roles = [];

        foreach ($columns as $role => $column) {
            if (isset($roles[$column])) {
                throw BankStatementException::columnMappedTwice($column);
            }

            $roles[$column] = $role;

            if (! in_array($column, $table->headers, true)) {
                throw BankStatementException::unknownColumn($role, $column, $table->headers);
            }
        }
    }

    /**
     * Every movement in the file, read into the shape the table holds.
     *
     * @return list<array{
     *     line: int,
     *     value_date: string,
     *     reference: string|null,
     *     description: string|null,
     *     debit: string,
     *     credit: string,
     *     balance_after: string|null,
     *     dedupe_hash: string,
     * }>
     */
    private function prepare(StatementMappingData $mapping, StatementTable $table): array
    {
        $prepared = [];

        foreach ($table->rows as $index => $row) {
            $line = $table->lines[$index];

            $valueDate = $this->readDate($mapping, $row, $line);

            // debit is what the bank took out, credit is what it paid in.
            [$debit, $credit] = $this->readMovement($mapping, $row, $line);

            $reference = $this->readText($row, $mapping->referenceColumn, 'reference', self::REFERENCE_LIMIT, $line);
            $description = $this->readText($row, $mapping->descriptionColumn, 'description', self::DESCRIPTION_LIMIT, $line);

            $prepared[] = [
                'line' => $line,
                'value_date' => $valueDate,
                'reference' => $reference,
                'description' => $description,
                'debit' => $debit,
                'credit' => $credit,
                'balance_after' => $this->readBalance($mapping, $row, $line),
                'dedupe_hash' => StatementFingerprint::of($valueDate, $debit, $credit, $reference, $description),
            ];
        }

        return $prepared;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function readDate(StatementMappingData $mapping, array $row, int $line): string
    {
        $raw = trim($row[$mapping->dateColumn] ?? '');

        if ($raw === '') {
            throw BankStatementException::missingDate($mapping->dateColumn, $line);
        }

        $parsed = $mapping->dateFormat->parse($raw);

        if ($parsed === null) {
            throw BankStatementException::unreadableDate(
                $mapping->dateColumn,
                $line,
                $raw,
                $mapping->dateFormat,
            );
        }

        return $parsed;
    }

    /**
     * One movement as the two sides the table holds: money out and money in.
     *
     * @param  array<string, string>  $row
     * @return array{0: string, 1: string} [debit (money out), credit (money in)]
     */
    private function readMovement(StatementMappingData $mapping, array $row, int $line): array
    {
        if (($mapping->amountColumn ?? '') !== '') {
            return $this->readSignedAmount($mapping, $row, $line);
        }

        return $this->readOneDirectionEach($mapping, $row, $line);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: string, 1: string}
     */
    private function readSignedAmount(StatementMappingData $mapping, array $row, int $line): array
    {
        $column = (string) $mapping->amountColumn;
        $raw = $row[$column] ?? '';

        if (StatementAmount::isBlank($raw)) {
            throw BankStatementException::noMovement($line);
        }

        $amount = $this->readAmount('amount', $column, $line, $raw);

        if ($amount->isZero()) {
            throw BankStatementException::noMovement($line);
        }

        $arrived = $mapping->positiveIsMoneyIn
            ? $amount->isPositive()
            : $amount->isNegative();

        return $arrived
            ? ['0.0000', $amount->absolute()->toDecimal()]
            : [$amount->absolute()->toDecimal(), '0.0000'];
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: string, 1: string}
     */
    private function readOneDirectionEach(StatementMappingData $mapping, array $row, int $line): array
    {
        $moneyIn = $this->readDirection('money in', $mapping->moneyInColumn, $row, $line);
        $moneyOut = $this->readDirection('money out', $mapping->moneyOutColumn, $row, $line);

        if (! $moneyIn->isZero() && ! $moneyOut->isZero()) {
            throw BankStatementException::bothSidesFilled($line);
        }

        if ($moneyIn->isZero() && $moneyOut->isZero()) {
            throw BankStatementException::noMovement($line);
        }

        return [$moneyOut->toDecimal(), $moneyIn->toDecimal()];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function readDirection(string $role, ?string $column, array $row, int $line): Money
    {
        if ($column === null || $column === '') {
            return Money::zero();
        }

        $raw = $row[$column] ?? '';

        if (StatementAmount::isBlank($raw)) {
            return Money::zero();
        }

        $amount = $this->readAmount($role, $column, $line, $raw);

        if ($amount->isNegative()) {
            throw BankStatementException::negativeInDirectionColumn($role, $column, $line, trim($raw));
        }

        return $amount;
    }

    private function readAmount(string $role, string $column, int $line, string $raw): Money
    {
        $decimal = StatementAmount::read($raw);

        if ($decimal === null) {
            throw StatementAmount::looksCommaDecimal($raw)
                ? BankStatementException::commaDecimalAmount($column, $line, trim($raw))
                : BankStatementException::unreadableAmount($role, $column, $line, trim($raw));
        }

        if (StatementAmount::exceedsRange($decimal)) {
            throw BankStatementException::amountOutOfRange($role, $column, $line, trim($raw));
        }

        return Money::of($decimal);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function readBalance(StatementMappingData $mapping, array $row, int $line): ?string
    {
        $column = $mapping->balanceColumn;

        if ($column === null || $column === '') {
            return null;
        }

        $raw = $row[$column] ?? '';

        if (StatementAmount::isBlank($raw)) {
            return null;
        }

        // A running balance may legitimately be negative: an overdraft is a
        // real balance and not a broken figure.
        return $this->readAmount('balance', $column, $line, $raw)->toDecimal();
    }

    /**
     * @param  array<string, string>  $row
     */
    private function readText(
        array $row,
        ?string $column,
        string $role,
        int $limit,
        int $line,
    ): ?string {
        if ($column === null || $column === '') {
            return null;
        }

        $value = trim($row[$column] ?? '');

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $limit) {
            throw BankStatementException::textTooLong($role, $line, $limit);
        }

        return $value;
    }

    /**
     * @param  list<array{line: int, dedupe_hash: string}>  $prepared
     */
    private function assertTheFileDoesNotRepeatItself(array $prepared): void
    {
        $seen = [];
        $repeats = [];

        foreach ($prepared as $movement) {
            if (isset($seen[$movement['dedupe_hash']])) {
                $repeats[] = $movement['line'];

                continue;
            }

            $seen[$movement['dedupe_hash']] = true;
        }

        if ($repeats !== []) {
            throw DuplicateStatementException::withinFile($repeats);
        }
    }

    /**
     * @param  list<array{line: int, dedupe_hash: string}>  $prepared
     */
    private function assertTheAccountDoesNotAlreadyHoldThem(StatementMappingData $mapping, array $prepared): void
    {
        $held = BankStatementLine::query()
            ->where('company_id', $mapping->companyId)
            ->where('bank_account_id', $mapping->bankAccountId)
            ->whereIn('dedupe_hash', array_column($prepared, 'dedupe_hash'))
            ->pluck('dedupe_hash')
            ->all();

        if ($held === []) {
            return;
        }

        $heldSet = array_flip($held);
        $lines = [];

        foreach ($prepared as $movement) {
            if (isset($heldSet[$movement['dedupe_hash']])) {
                $lines[] = $movement['line'];
            }
        }

        throw DuplicateStatementException::alreadyImported($lines);
    }

    /**
     * @param  list<array{
     *     line: int,
     *     value_date: string,
     *     reference: string|null,
     *     description: string|null,
     *     debit: string,
     *     credit: string,
     *     balance_after: string|null,
     *     dedupe_hash: string,
     * }>  $prepared
     */
    private function write(
        StatementMappingData $mapping,
        BankAccount $bankAccount,
        array $prepared,
    ): StatementImportResult {
        try {
            return DB::transaction(function () use ($mapping, $bankAccount, $prepared): StatementImportResult {
                $lines = [];

                foreach ($prepared as $movement) {
                    $lines[] = BankStatementLine::create([
                        'company_id' => $mapping->companyId,
                        'bank_account_id' => $mapping->bankAccountId,
                        'value_date' => $movement['value_date'],
                        'description' => $movement['description'],
                        'reference' => $movement['reference'],
                        'dedupe_hash' => $movement['dedupe_hash'],
                        'debit' => $movement['debit'],
                        'credit' => $movement['credit'],
                        'balance_after' => $movement['balance_after'],
                        'status' => BankStatementLineStatus::Unmatched->value,
                        'import_batch' => $mapping->uploadToken,
                    ]);
                }

                $this->audit->record('bank_statement.imported', $bankAccount, null, [
                    'batch' => $mapping->uploadToken,
                    'lines' => count($lines),
                ]);

                return new StatementImportResult(
                    $mapping->uploadToken,
                    $mapping->bankAccountId,
                    $lines,
                );
            });
        } catch (QueryException $race) {
            // The unique index on (company, account, fingerprint) is what makes
            // "already imported" a fact rather than a hope. Reaching it means a
            // second import of the same file committed between the check above
            // and this insert, and the whole transaction has already rolled
            // back by the time this runs.
            if ($this->isDuplicateEntry($race)) {
                throw DuplicateStatementException::raced();
            }

            throw $race;
        }
    }

    private function isDuplicateEntry(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062;
    }
}
