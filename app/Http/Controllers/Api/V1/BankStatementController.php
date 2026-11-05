<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Actions\ImportBankStatement;
use App\Domain\Banking\Actions\StoreStatementUpload;
use App\Domain\Banking\DTOs\StatementMappingData;
use App\Domain\Banking\DTOs\StatementUpload;
use App\Domain\Banking\Enums\BankStatementLineStatus;
use App\Domain\Banking\Enums\StatementDateFormat;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bank statement files: upload and preview, map and import, then read back.
 *
 * Three steps rather than one, because the middle one cannot be guessed. A
 * bank's export calls its columns whatever that bank's reporting tool called
 * them, dates are written in whichever order the bank's country writes them,
 * and a single amount column counts in whichever direction that bank counts.
 * None of that is visible from the file's content, and all of it changes what
 * a movement means, so the caller uploads, looks at what is really in the file,
 * and then says what each column is.
 *
 * The mapping is applied to the whole file before anything is written, so a
 * refusal is all of it or none of it. A half-imported statement leaves the
 * reader unable to tell which half landed.
 *
 * Nothing on this surface contacts a bank. There is no bank feed, no API and no
 * connection of any kind: the file is one a person downloaded from their own
 * online banking and uploaded here, and the lines it produces are the bank's
 * claims, not postings. Importing a statement changes no balance.
 */
class BankStatementController extends Controller
{
    /**
     * The movements of one account, newest first, so a reconciliation can be
     * read down the page the way the statement prints it.
     */
    public function index(Request $request, string $bankAccount): JsonResponse
    {
        $account = $this->resolveAccount($request, $bankAccount);

        $this->authorize('view', $account);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(BankStatementLineStatus::class)],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $lines = BankStatementLine::query()
            ->where('company_id', (int) $account->company_id)
            ->ofAccount((int) $account->getKey())
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                filled($filters['from'] ?? null),
                fn ($query) => $query->whereDate('value_date', '>=', $filters['from'])
            )
            ->when(
                filled($filters['to'] ?? null),
                fn ($query) => $query->whereDate('value_date', '<=', $filters['to'])
            )
            ->orderByDesc('value_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (BankStatementLine $line): array => $this->present($line),
                $lines->items()
            ),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'per_page' => $lines->perPage(),
                'total' => $lines->total(),
                'last_page' => $lines->lastPage(),
            ],
        ]);
    }

    /**
     * Store the file and hand back what is in it, so the mapping can be built
     * against real column names rather than against what the bank was expected
     * to call them.
     */
    public function storeUpload(
        Request $request,
        string $bankAccount,
        StoreStatementUpload $store,
    ): JsonResponse {
        $account = $this->resolveAccount($request, $bankAccount);

        $this->authorize('import', $account);

        $request->validate([
            // 5 MB: a year of a busy current account as CSV is a few hundred
            // kilobytes, and anything far past this is not a statement file.
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $upload = $store(
            (int) $account->company_id,
            $account,
            $request->file('file'),
        );

        return response()->json(['data' => $this->presentUpload($upload)], 201);
    }

    /**
     * Import the movements under a mapping the caller chose.
     *
     * The mapping fields are validated for shape here; everything that decides
     * whether the mapping is true of this particular file is the action's, in
     * the same way an unbalanced journal is the posting rules' business rather
     * than the controller's.
     */
    public function import(
        Request $request,
        string $bankAccount,
        ImportBankStatement $import,
    ): JsonResponse {
        $account = $this->resolveAccount($request, $bankAccount);

        $this->authorize('import', $account);

        $data = $request->validate([
            'upload_token' => ['required', 'string', 'size:26'],
            'date_format' => ['required', Rule::enum(StatementDateFormat::class)],
            'date_column' => ['required', 'string', 'max:255'],
            'amount_column' => ['sometimes', 'nullable', 'string', 'max:255'],
            'positive_is_money_in' => ['sometimes', 'boolean'],
            'money_in_column' => ['sometimes', 'nullable', 'string', 'max:255'],
            'money_out_column' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference_column' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_column' => ['sometimes', 'nullable', 'string', 'max:255'],
            'balance_column' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $result = $import(new StatementMappingData(
            companyId: (int) $account->company_id,
            bankAccountId: (int) $account->getKey(),
            uploadToken: $data['upload_token'],
            dateFormat: StatementDateFormat::from($data['date_format']),
            dateColumn: $data['date_column'],
            amountColumn: $this->column($data, 'amount_column'),
            positiveIsMoneyIn: (bool) ($data['positive_is_money_in'] ?? true),
            moneyInColumn: $this->column($data, 'money_in_column'),
            moneyOutColumn: $this->column($data, 'money_out_column'),
            referenceColumn: $this->column($data, 'reference_column'),
            descriptionColumn: $this->column($data, 'description_column'),
            balanceColumn: $this->column($data, 'balance_column'),
            userId: $request->user()?->getKey(),
        ));

        return response()->json(['data' => [
            'import_batch' => $result->batch,
            'bank_account_id' => $result->bankAccountId,
            'imported' => $result->imported(),
            'lines' => array_map(
                fn (BankStatementLine $line): array => $this->present($line),
                $result->lines
            ),
        ]], 201);
    }

    /**
     * Resolved inside the caller's company in the same query that reads it, so
     * naming another tenant's account is a 404 rather than a 403 that would
     * confirm the account exists somewhere else.
     */
    private function resolveAccount(Request $request, string $bankAccount): BankAccount
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return BankAccount::query()
            ->where('company_id', $companyId)
            ->where('ulid', $bankAccount)
            ->firstOrFail();
    }

    /**
     * A mapping field that was sent as an empty string means "no column", the
     * same as not sending it at all.
     *
     * @param  array<string, mixed>  $data
     */
    private function column(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUpload(StatementUpload $upload): array
    {
        return [
            'upload_token' => $upload->token,
            'bank_account_id' => $upload->bankAccountId,
            'file_name' => $upload->originalName,
            'size_bytes' => $upload->sizeBytes,
            'delimiter' => $upload->delimiter,
            'headers' => $upload->headers,
            'row_count' => $upload->rowCount,
            'preview' => $upload->preview,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BankStatementLine $line): array
    {
        return [
            'id' => (int) $line->getKey(),
            'ulid' => $line->ulid,
            'bank_account_id' => (int) $line->bank_account_id,
            'value_date' => $line->value_date->toDateString(),
            'description' => $line->description,
            'reference' => $line->reference,
            // The bank's own sides, not the books': debit is money that left the
            // account and credit is money that arrived in it.
            'debit' => $line->debit,
            'credit' => $line->credit,
            'balance_after' => $line->balance_after,
            'status' => $line->status->value,
            'import_batch' => $line->import_batch,
        ];
    }
}
