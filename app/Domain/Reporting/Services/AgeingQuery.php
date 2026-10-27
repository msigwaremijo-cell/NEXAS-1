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
        );
    }

    /**
     * What the company owes suppliers, bucketed by how late it is.
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
        );
    }

    /**
     * @param  class-string<Model>  $documentModel
     * @param  list<string>  $openStatuses
     * @param  class-string<Model>  $partyModel
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
    ): array {
        // Ageing is always "as of" a date. Defaulting to today is what makes a
        // report reproducible tomorrow only if the caller pins it, so the
        // resolved date is echoed back in the payload.
        $asOf = $asOf ?? now()->toDateString();

        // One placeholder per bucket, all bound to the as-of date. They are
        // positional and appear in the select clause, so Laravel binds them
        // ahead of the where clause, which is also their order in the SQL text.
        //
        // The party column has to be selected explicitly: grouping by a column
        // does not put it in the result set, and without it every row would
        // come back with a null party and the whole report would collapse into
        // one unlabelled line.
        $expression = implode(', ', [
            $partyColumn,
            'COUNT(*) AS document_count',
            $this->bucket('current', 'DATEDIFF(?, due_date) <= 0'),
            $this->bucket('1_30', 'DATEDIFF(?, due_date) BETWEEN 1 AND 30'),
            $this->bucket('31_60', 'DATEDIFF(?, due_date) BETWEEN 31 AND 60'),
            $this->bucket('61_90', 'DATEDIFF(?, due_date) BETWEEN 61 AND 90'),
            $this->bucket('over_90', 'DATEDIFF(?, due_date) > 90'),
            'SUM(total - amount_paid) AS total_outstanding',
        ]);

        $rows = $documentModel::query()
            ->selectRaw($expression, [$asOf, $asOf, $asOf, $asOf, $asOf])
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
        // party that owes the most is the one being chased.
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

    private function bucket(string $key, string $condition): string
    {
        return "SUM(CASE WHEN {$condition} THEN total - amount_paid ELSE 0 END) AS {$key}";
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
