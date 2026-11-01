<?php

namespace App\Domain\Importing\Importers;

use App\Domain\Accounting\Enums\PartyStatus;
use App\Domain\Importing\DTOs\DatasetPlan;
use App\Domain\Importing\DTOs\ImportRequest;
use App\Domain\Importing\DTOs\ImportRowError;
use App\Domain\Importing\DTOs\ImportTable;
use App\Domain\Importing\Exceptions\ImportRowRefusal;
use App\Domain\Importing\Services\CellReader;
use App\Models\Account;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;

/**
 * The customer list, brought in as a file.
 *
 * A customer exists so an invoice has a party to name and a receivable control
 * account to roll up into, so the file has to settle two things the model
 * requires: a code that is unique within the company, and a postable account
 * the balance will sit in. The account is named by code rather than by id,
 * because no export from another system knows this one's ids, and the code is
 * resolved against the company's own chart — never another tenant's.
 *
 * The currency is optional. A file that leaves it blank takes the company's own
 * base currency, which is what the customer screen does, so a single-currency
 * migration does not have to repeat the same code on every row.
 *
 * There is no delete here either: a customer is master data that documents
 * issued years ago still point at, so a code already in the books — even one
 * since retired — is refused rather than reused.
 */
final class CustomerImporter extends AbstractDatasetImporter
{
    private const CODE_LIMIT = 30;

    private const NAME_LIMIT = 255;

    private const TEXT_LIMIT = 255;

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return [
            'code' => 'customer code',
            'name' => 'customer name',
            'tin' => 'TIN',
            'vrn' => 'VRN',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address',
            'currency_code' => 'currency code',
            'receivable_account_code' => 'receivable account code',
            'credit_limit' => 'credit limit',
            'payment_terms_days' => 'payment terms in days',
            'status' => 'status',
            'notes' => 'notes',
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return ['code', 'name', 'receivable_account_code'];
    }

    public function prepare(ImportRequest $request, ImportTable $table): DatasetPlan
    {
        $existing = array_fill_keys(
            Customer::withTrashed()
                ->where('company_id', $request->companyId)
                ->pluck('code')
                ->all(),
            true
        );

        $accounts = $this->referenceAccounts($request->companyId);
        $currencies = Currency::query()->pluck('code')->all();
        $codeLines = $this->valueLines($request, $table, 'code');

        [$rows, $errors] = $this->readRows(
            $table,
            function (array $row, int $line) use ($request, $existing, $accounts, $currencies, $codeLines): array {
                return $this->readRow($request, $row, $line, $existing, $accounts, $currencies, $codeLines);
            }
        );

        return new DatasetPlan($rows, $errors, ['customers' => count($rows)]);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, true>  $existing
     * @param  array<string, Account>  $accounts
     * @param  list<string>  $currencies
     * @param  array<string, list<int>>  $codeLines
     * @return array<string, mixed>
     */
    private function readRow(
        ImportRequest $request,
        array $row,
        int $line,
        array $existing,
        array $accounts,
        array $currencies,
        array $codeLines,
    ): array {
        $code = CellReader::text($this->cell($request, $row, 'code'), 'code', $this->label('code'), $line, self::CODE_LIMIT, true);

        if ($repeat = $this->repeatOf($codeLines, $code, $line)) {
            throw ImportRowRefusal::of(ImportRowError::duplicateInFile($line, 'code', $this->label('code'), $code, $repeat));
        }

        if (isset($existing[$code])) {
            throw ImportRowRefusal::of(ImportRowError::alreadyExists($line, 'code', $this->label('code'), $code, 'customer'));
        }

        $name = CellReader::text($this->cell($request, $row, 'name'), 'name', $this->label('name'), $line, self::NAME_LIMIT, true);

        $accountCode = CellReader::text(
            $this->cell($request, $row, 'receivable_account_code'),
            'receivable_account_code',
            $this->label('receivable_account_code'),
            $line,
            self::CODE_LIMIT,
        );

        $account = $this->account($accounts, $accountCode, 'receivable_account_code', $this->label('receivable_account_code'), $line);

        return [
            'code' => $code,
            'name' => $name,
            'tin' => CellReader::text($this->cell($request, $row, 'tin'), 'tin', $this->label('tin'), $line, 20),
            'vrn' => CellReader::text($this->cell($request, $row, 'vrn'), 'vrn', $this->label('vrn'), $line, 20),
            'email' => CellReader::text($this->cell($request, $row, 'email'), 'email', $this->label('email'), $line, self::TEXT_LIMIT),
            'phone' => CellReader::text($this->cell($request, $row, 'phone'), 'phone', $this->label('phone'), $line, 32),
            'address' => CellReader::text($this->cell($request, $row, 'address'), 'address', $this->label('address'), $line, self::TEXT_LIMIT),
            'currency_code' => $this->currency($request, $row, $line, $currencies),
            'receivable_account_id' => (int) $account?->getKey(),
            'credit_limit' => CellReader::amount($this->cell($request, $row, 'credit_limit'), 'credit_limit', $this->label('credit_limit'), $line),
            'payment_terms_days' => CellReader::integer(
                $this->cell($request, $row, 'payment_terms_days'),
                'payment_terms_days',
                $this->label('payment_terms_days'),
                $line,
                30,
                0,
                365,
            ),
            'status' => CellReader::option(
                $this->cell($request, $row, 'status'),
                'status',
                $this->label('status'),
                $line,
                array_column(PartyStatus::cases(), 'value'),
                PartyStatus::Active->value,
            ),
            'notes' => CellReader::text($this->cell($request, $row, 'notes'), 'notes', $this->label('notes'), $line, self::TEXT_LIMIT),
        ];
    }

    /**
     * The currency a row names, or null when it names none and the company's
     * own base currency should be used instead.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $currencies
     */
    private function currency(ImportRequest $request, array $row, int $line, array $currencies): ?string
    {
        $code = strtoupper((string) CellReader::text(
            $this->cell($request, $row, 'currency_code'),
            'currency_code',
            $this->label('currency_code'),
            $line,
            3,
        ));

        if ($code === '') {
            return null;
        }

        if (! in_array($code, $currencies, true)) {
            throw ImportRowRefusal::of(
                ImportRowError::unknownReference($line, 'currency_code', $this->label('currency_code'), $code, 'currency')
            );
        }

        return $code;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function commit(ImportRequest $request, array $rows): array
    {
        $base = Company::query()->whereKey($request->companyId)->value('base_currency_code');

        $created = 0;

        foreach ($rows as $row) {
            Customer::create([
                'company_id' => $request->companyId,
                'code' => $row['code'],
                'name' => $row['name'],
                'tin' => $row['tin'],
                'vrn' => $row['vrn'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'address' => $row['address'],
                'currency_code' => $row['currency_code'] ?? $base,
                'receivable_account_id' => $row['receivable_account_id'],
                'credit_limit' => $row['credit_limit'],
                'payment_terms_days' => $row['payment_terms_days'],
                'status' => $row['status'],
                'notes' => $row['notes'],
                'row_version' => 0,
            ]);

            $created++;
        }

        return ['customers' => $created];
    }
}
