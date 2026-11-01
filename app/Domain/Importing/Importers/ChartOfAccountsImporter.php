<?php

namespace App\Domain\Importing\Importers;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\CashFlowCategory;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Importing\DTOs\DatasetPlan;
use App\Domain\Importing\DTOs\ImportRequest;
use App\Domain\Importing\DTOs\ImportRowError;
use App\Domain\Importing\DTOs\ImportTable;
use App\Domain\Importing\Exceptions\ImportRowRefusal;
use App\Domain\Importing\Services\CellReader;
use App\Models\Account;

/**
 * The chart of accounts, brought in as a file.
 *
 * The one dataset that is a tree rather than a list, and almost all of its work
 * is that tree. A file names a parent by its code, so a parent either appears
 * earlier in the same file or is already in the books; a row whose parent is
 * neither is refused rather than imported loose. Because the parent has to come
 * first, a file cannot describe a cycle and no row can be its own ancestor.
 *
 * `depth` and `path` are not read from the file but computed from the parent,
 * exactly as they are when an account is added by hand: they are a
 * denormalisation of the hierarchy, and a file that got them wrong would make
 * every subtree report wrong while looking perfectly well-formed.
 *
 * The account type is inherited from the parent when the file leaves it blank,
 * because a migration export from another system usually states the type once
 * on a root and leaves the accounts under it to say nothing. The normal balance
 * follows the type unless the file overrides it, which is how a contra account
 * — accumulated depreciation, a sales discount — is expressed.
 *
 * The cash flow category is settled the way the seeder settles it: the
 * company's own cash and bank accounts are an internal transfer rather than a
 * flow, an account that declares a category keeps it, and everything else
 * follows the type, with equity being the company's own funding. A roll-up
 * carries no category of its own, because it has no line on the statement.
 */
final class ChartOfAccountsImporter extends AbstractDatasetImporter
{
    /**
     * accounts.code is varchar(30).
     */
    private const CODE_LIMIT = 30;

    /**
     * accounts.name is varchar(255).
     */
    private const NAME_LIMIT = 255;

    /**
     * accounts.description is text.
     */
    private const DESCRIPTION_LIMIT = 2000;

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return [
            'code' => 'account code',
            'name' => 'account name',
            'type' => 'account type',
            'normal_balance' => 'normal balance',
            'parent_code' => 'parent account code',
            'postable' => 'postable',
            'is_cash' => 'cash account',
            'is_bank' => 'bank account',
            'cash_flow_category' => 'cash flow category',
            'description' => 'description',
        ];
    }

    /**
     * The type is not required: an account whose parent names a type inherits
     * it, and in a migration export that is the ordinary case.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return ['code', 'name'];
    }

    public function prepare(ImportRequest $request, ImportTable $table): DatasetPlan
    {
        $existing = $this->accountsByCode($request->companyId);

        $codeLines = $this->valueLines($request, $table, 'code');

        /**
         * The accounts this file has already agreed, so a child may name a
         * parent that exists only because an earlier row created it.
         *
         * @var array<string, array{depth: int, path: string|null, type: AccountType}> $agreed
         */
        $agreed = [];

        [$rows, $errors] = $this->readRows(
            $table,
            function (array $row, int $line) use ($request, $existing, $codeLines, &$agreed): array {
                return $this->readRow($request, $row, $line, $existing, $agreed, $codeLines);
            }
        );

        return new DatasetPlan($rows, $errors, ['accounts' => count($rows)]);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, Account>  $existing
     * @param  array<string, array{depth: int, path: string|null, type: AccountType}>  $agreed
     * @param  array<string, list<int>>  $codeLines
     * @return array<string, mixed>
     */
    private function readRow(
        ImportRequest $request,
        array $row,
        int $line,
        array $existing,
        array &$agreed,
        array $codeLines,
    ): array {
        $code = CellReader::text($this->cell($request, $row, 'code'), 'code', $this->label('code'), $line, self::CODE_LIMIT, true);

        if ($repeat = $this->repeatOf($codeLines, $code, $line)) {
            throw ImportRowRefusal::of(ImportRowError::duplicateInFile($line, 'code', $this->label('code'), $code, $repeat));
        }

        if (isset($existing[$code]) || isset($agreed[$code])) {
            throw ImportRowRefusal::of(ImportRowError::alreadyExists($line, 'code', $this->label('code'), $code, 'account'));
        }

        $name = CellReader::text($this->cell($request, $row, 'name'), 'name', $this->label('name'), $line, self::NAME_LIMIT, true);

        $parentCode = CellReader::text($this->cell($request, $row, 'parent_code'), 'parent_code', $this->label('parent_code'), $line, self::CODE_LIMIT);

        $parent = $this->parent($parentCode, $existing, $agreed, $line);

        $typeRaw = CellReader::option(
            $this->cell($request, $row, 'type'),
            'type',
            $this->label('type'),
            $line,
            array_column(AccountType::cases(), 'value'),
            null,
            $parent === null,
        );

        $type = $typeRaw !== null ? AccountType::from($typeRaw) : $parent['type'];

        $normalRaw = CellReader::option(
            $this->cell($request, $row, 'normal_balance'),
            'normal_balance',
            $this->label('normal_balance'),
            $line,
            array_column(NormalBalance::cases(), 'value'),
        );

        $normal = $normalRaw !== null ? NormalBalance::from($normalRaw) : $type->normalBalance();

        $postable = CellReader::flag($this->cell($request, $row, 'postable'), 'postable', $this->label('postable'), $line);
        $isCash = CellReader::flag($this->cell($request, $row, 'is_cash'), 'is_cash', $this->label('is_cash'), $line, false);
        $isBank = CellReader::flag($this->cell($request, $row, 'is_bank'), 'is_bank', $this->label('is_bank'), $line, false);

        $categoryRaw = CellReader::option(
            $this->cell($request, $row, 'cash_flow_category'),
            'cash_flow_category',
            $this->label('cash_flow_category'),
            $line,
            array_column(CashFlowCategory::cases(), 'value'),
        );

        $description = CellReader::text(
            $this->cell($request, $row, 'description'),
            'description',
            $this->label('description'),
            $line,
            self::DESCRIPTION_LIMIT,
        );

        $depth = $parent === null ? 0 : $parent['depth'] + 1;
        $path = $parent === null
            ? null
            : ($parent['path'] === null ? $parentCode : $parent['path'].'/'.$parentCode);

        $agreed[$code] = ['depth' => $depth, 'path' => $path, 'type' => $type];

        return [
            'code' => $code,
            'name' => $name,
            'account_type' => $type->value,
            'normal_balance' => $normal->value,
            'parent_code' => $parentCode,
            'path' => $path,
            'depth' => $depth,
            'is_postable' => $postable,
            'is_cash' => $isCash,
            'is_bank' => $isBank,
            'cash_flow_category' => $postable ? $this->category($isCash, $isBank, $categoryRaw, $type) : null,
            'description' => $description,
        ];
    }

    /**
     * The parent a row names, from this file first and the books second.
     *
     * @param  array<string, Account>  $existing
     * @param  array<string, array{depth: int, path: string|null, type: AccountType}>  $agreed
     * @return array{depth: int, path: string|null, type: AccountType}|null
     */
    private function parent(?string $parentCode, array $existing, array $agreed, int $line): ?array
    {
        if ($parentCode === null) {
            return null;
        }

        if (isset($agreed[$parentCode])) {
            return $agreed[$parentCode];
        }

        $record = $existing[$parentCode] ?? null;

        if ($record === null) {
            throw ImportRowRefusal::of(
                ImportRowError::unknownReference($line, 'parent_code', $this->label('parent_code'), $parentCode, 'account')
            );
        }

        // The column is cast to the enum by the model, so this is the type the
        // parent actually carries rather than the string the column holds.
        /** @var AccountType $type */
        $type = $record->account_type;

        return [
            'depth' => (int) $record->depth,
            'path' => $record->path === null ? null : (string) $record->path,
            'type' => $type,
        ];
    }

    private function category(bool $isCash, bool $isBank, ?string $declared, AccountType $type): string
    {
        if ($isCash || $isBank) {
            return CashFlowCategory::InternalTransfer->value;
        }

        if ($declared !== null) {
            return $declared;
        }

        return ($type === AccountType::Equity ? CashFlowCategory::Financing : CashFlowCategory::Operating)->value;
    }

    /**
     * The rows are written in file order, which is what lets a parent be found
     * by the id its own row just produced rather than by a second pass.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function commit(ImportRequest $request, array $rows): array
    {
        /** @var array<string, int> $ids */
        $ids = Account::withTrashed()
            ->where('company_id', $request->companyId)
            ->pluck('id', 'code')
            ->all();

        $created = 0;

        foreach ($rows as $row) {
            $account = Account::create([
                'company_id' => $request->companyId,
                'code' => $row['code'],
                'name' => $row['name'],
                'account_type' => $row['account_type'],
                'normal_balance' => $row['normal_balance'],
                'parent_id' => $row['parent_code'] === null ? null : ($ids[$row['parent_code']] ?? null),
                'path' => $row['path'],
                'depth' => $row['depth'],
                'is_postable' => $row['is_postable'],
                'is_cash' => $row['is_cash'],
                'is_bank' => $row['is_bank'],
                'cash_flow_category' => $row['cash_flow_category'],
                'currency_code' => null,
                'description' => $row['description'],
                'status' => 'active',
            ]);

            $ids[$row['code']] = (int) $account->getKey();
            $created++;
        }

        return ['accounts' => $created];
    }
}
