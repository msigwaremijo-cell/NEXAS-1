<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Receivables and payables ageing, in one place.
 *
 * Ageing answers one question: how much of what is owed is late, and by how
 * long. The bucket boundaries are therefore measured from `due_date` and not
 * from the document date, because a document with 30-day terms is not late the
 * day it is raised.
 *
 * The bucketing is done by MySQL rather than by loading every open document
 * into PHP. A company with a few years of invoices would otherwise pull tens of
 * thousands of rows across the wire to add up five numbers, and the answer is
 * one GROUP BY away. The CASE arms are deliberately non-overlapping and
 * exhaustive, so the five buckets always add up to the total.
 *
 * Only documents that still expect money are included. An invoice is open while
 * it is `issued` or `partially_paid`; once it is `paid` it carries no balance,
 * and a `void` one never did. The same rule is expressed for bills, with the
 * addition that a bill must have been approved — an unposted bill has no
 * liability in the ledger, so it has no place in a payables report.
 *
 * A correction is a document too, and it is netted off rather than counted the
 * way its own columns read. A credit note is a row in the invoices table whose
 * `total` is positive, so adding it the way an invoice is added would *raise*
 * the receivable it exists to reduce; a debit note does the same to the
 * payable. Each is therefore given the opposite sign, per party, so the report
 * agrees with the control account it is read against and a party that has been
 * overpaid, credited or debited reads as a negative balance rather than being
 * silently floored at zero.
 *
 * The sign is applied in the same bucket the correction itself falls in — aged
 * from its own due date, which its action sets to its own document date — so a
 * credit raised this month reduces this month's column whether the invoice it
 * answers was raised this month or a year ago. That can leave an individual
 * bucket negative while the party's total stays right, which is the honest
 * reading: the money really has been given back, and hiding it by clamping the
 * column at zero would make the buckets stop adding up to the control account.
 */
final class AgeingQuery
{
    /**
     * Bucket keys in report order: not yet due, then each overdue band.
     *
     * @var list<string>
     */
    public const BUCKETS = ['current', '1_30', '31_60', '61_90', 'over_90'];

    /**
     * What customers owe, bucketed by how late it is.
     *
     * A credit note answers a sale, so it reduces what the customer owes and
     * is given the opposite sign to the invoices it sits beside.
     *
     * @return array<string, mixed>
     */
    public function receivables(int $companyId, ?string $asOf = null): array
    {
        return $this->age(
            Invoice::class,
            'customer_id',
            [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value],
            Customer::class,
            $companyId,
            $asOf,
            correctionType: 'credit_note',
        );
    }

    /**
     * What the company owes suppliers, bucketed by how late it is.
     *
     * A debit note answers a purchase, so it reduces what the company owes and
     * is given the opposite sign to the bills it sits beside.
     *
     * @return array<string, mixed>
     */
    public function payables(int $companyId, ?string $asOf = null): array
    {
        return $this->age(
            Bill::class,
            'supplier_id',
            [BillStatus::Approved->value, BillStatus::PartiallyPaid->value],
            Supplier::class,
            $companyId,
            $asOf,
            // Approval and posting are separate moves for a bill, so an
            // approved bill that has not been posted yet exists. It carries no
            // liability in the ledger, and reporting it would make the ageing
            // disagree with the payables control account.
            requirePosted: true,
            correctionType: 'debit_note',
        );
    }

    /**
     * @param  class-string<Model>  $documentModel
     * @param  list<string>  $openStatuses
     * @param  class-string<Model>  $partyModel
     * @param  string|null  $correctionType  the document_type that reduces the balance
     * @return array<string, mixed>
     */
    private function age(
        string $documentModel,
        string $partyColumn,
        array $openStatuses,
        string $partyModel,
        int $companyId,
        ?string $asOf,
        bool $requirePosted = false,
        ?string $correctionType = null,
    ): array {
        // Ageing is always "as of" a date. Defaulting to today is what makes a
        // report reproducible tomorrow only if the caller pins it, so the
        // resolved date is echoed back in the payload.
        $asOf = $asOf ?? now()->toDateString();

        // The amount a document contributes to the balance. Most documents
        // contribute what is still owed on them; a correction contributes the
        // negative of that, because it exists to take the balance away. The
        // sign is applied to the whole outstanding figure rather than to the
        // buckets, so the five columns and the total always agree.
        $amount = $correctionType === null
            ? 'total - amount_paid'
            : '(CASE WHEN document_type = ? THEN amount_paid - total ELSE total - amount_paid END)';

        // One placeholder per bucket for the as-of date, and — when corrections
        // are netted — one more for the document type inside the same arm. They
        // are positional and appear in the select clause, so the bindings are
        // assembled in the same order the placeholders appear in the SQL text.
        $bucketColumns = [];
        $bindings = [];

        foreach ([
            'current' => 'DATEDIFF(?, due_date) <= 0',
            '1_30' => 'DATEDIFF(?, due_date) BETWEEN 1 AND 30',
            '31_60' => 'DATEDIFF(?, due_date) BETWEEN 31 AND 60',
            '61_90' => 'DATEDIFF(?, due_date) BETWEEN 61 AND 90',
            'over_90' => 'DATEDIFF(?, due_date) > 90',
        ] as $key => $condition) {
            $bucketColumns[] = "SUM(CASE WHEN {$condition} THEN {$amount} ELSE 0 END) AS {$key}";
            $bindings[] = $asOf;

            if ($correctionType !== null) {
                $bindings[] = $correctionType;
            }
        }

        // The party column has to be selected explicitly: grouping by a column
        // does not put it in the result set, and without it every row would
        // come back with a null party and the whole report would collapse into
        // one unlabelled line.
        $expression = implode(', ', [
            $partyColumn,
            'COUNT(*) AS document_count',
            ...$bucketColumns,
            "SUM({$amount}) AS total_outstanding",
        ]);

        if ($correctionType !== null) {
            $bindings[] = $correctionType;
        }

        $rows = $documentModel::query()
            ->selectRaw($expression, $bindings)
            ->where('company_id', $companyId)
            ->whereIn('status', $openStatuses)
            ->when($requirePosted, fn ($query) => $query->whereNotNull('journal_id'))
            ->groupBy($partyColumn)
            ->get();

        $parties = $this->partyLabels($partyModel, $companyId, $rows->pluck($partyColumn)->filter()->all());

        $buckets = [];
        $totals = array_fill_keys(self::BUCKETS, '0.0000');
        $documents = 0;
        $presented = [];

        foreach ($rows as $row) {
            $partyId = (int) $row->getAttribute($partyColumn);
            $documents += (int) $row->document_count;

            $bucket = [];

            foreach (self::BUCKETS as $key) {
                $amount = $this->money($row->getAttribute($key));
                $bucket[$key] = $amount->toDecimal();
                $totals[$key] = Money::of($totals[$key])->plus($amount)->toDecimal();
            }

            $bucket['total'] = $this->money($row->total_outstanding)->toDecimal();

            $presented[] = [
                'party_id' => $partyId,
                'party_code' => $parties[$partyId]['code'] ?? null,
                'party_name' => $parties[$partyId]['name'] ?? null,
                ...$bucket,
            ];
        }

        // Largest debt first: an ageing report is read from the top, and the
        // party that owes the most is the one being chased. A party in credit —
        // more corrections than invoices — sorts to the bottom, where its
        // negative total is plainly visible rather than hidden.
        usort($presented, static fn (array $a, array $b): int => Money::of($b['total'])->compareTo(Money::of($a['total'])));

        $totals['total'] = Money::of(array_reduce(
            self::BUCKETS,
            static fn (Money $carry, string $key): Money => $carry->plus(Money::of($totals[$key])),
            Money::zero(),
        ))->toDecimal();

        return [
            'as_of' => $asOf,
            'buckets' => self::BUCKETS,
            'document_count' => $documents,
            'parties' => $presented,
            'totals' => $totals,
        ];
    }

    /**
     * @param  class-string<Model>  $partyModel
     * @param  array<int, int>  $partyIds
     * @return array<int, array{code: string, name: string}>
     */
    private function partyLabels(string $partyModel, int $companyId, array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        /** @var Builder $query */
        $query = $partyModel::query();

        return $query
            ->where('company_id', $companyId)
            ->whereIn('id', $partyIds)
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Model $party): array => [
                (int) $party->getKey() => [
                    'code' => (string) $party->getAttribute('code'),
                    'name' => (string) $party->getAttribute('name'),
                ],
            ])
            ->all();
    }

    private function money(mixed $value): Money
    {
        // A SUM over a group is never NULL, but a defensive default avoids a
        // Money::of(null) throw on a database that reports an empty sum as NULL.
        return Money::of($value === null ? '0' : (string) $value);
    }
}
