<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Actions\ReopenFiscalPeriod;
use App\Domain\Accounting\Enums\FiscalStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The fiscal calendar, and the one write against it: reopening a period.
 *
 * The calendar is read rather than guessed at because a reopen is only ever
 * wanted for a period that is closed, and after a correction that period has
 * to be closed again. Listing every period with its state, and counting the
 * ones that could still be reopened, is what stops a reopened period being
 * forgotten and left open — the one way this control goes wrong.
 *
 * Nothing is decided here. What a reopen touches, which periods it leaves
 * alone and what it records are accounting rules and live in
 * app/Domain/Accounting/Actions/ReopenFiscalPeriod. This class resolves the
 * period the caller named inside the company the `company` middleware already
 * proved they belong to — so naming another tenant's ULID is a 404 and not a
 * reopen — authorises the reopen against it, and serialises the answer.
 *
 * Closing the period and the year again afterwards is the ordinary year-end
 * close at POST /fiscal-years/{ulid}/close. It is deliberately not duplicated
 * here: that endpoint already shuts every period of the year and re-derives the
 * closing entry from the accounts' balance, which is exactly what carrying a
 * correction into retained earnings means.
 */
class FiscalPeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FiscalPeriod::class);

        $periods = FiscalPeriod::query()
            ->where('company_id', $this->companyId($request))
            ->with('fiscalYear')
            ->orderBy('starts_on')
            ->get();

        $reopenable = [];

        $rows = $periods->map(function (FiscalPeriod $period) use (&$reopenable): array {
            $row = $this->present($period);

            if ($row['can_be_reopened']) {
                $reopenable[] = $row['ulid'];
            }

            return $row;
        })->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => count($rows),
                'open' => $periods->filter(fn (FiscalPeriod $p): bool => $p->status === FiscalStatus::Open)->count(),
                'closed' => $periods->filter(fn (FiscalPeriod $p): bool => $p->status === FiscalStatus::Closed)->count(),
                'locked' => $periods->filter(fn (FiscalPeriod $p): bool => $p->status === FiscalStatus::Locked)->count(),
                // What the calendar is read to find out: which periods could
                // still be reopened, so a period left open after a correction
                // is not forgotten. Named by ULID as well as counted, because a
                // count alone does not say which one to go and close.
                'reopenable' => count($reopenable),
                'reopenable_periods' => $reopenable,
            ],
        ]);
    }

    public function reopen(Request $request, string $fiscalPeriod, ReopenFiscalPeriod $reopen): JsonResponse
    {
        $period = $this->findByUlid($request, $fiscalPeriod);

        $this->authorize('reopen', $period);

        // A reopen without a reason is refused before anything is written: the
        // reason is what the trail says the books were opened again for, and an
        // entry that says only "somebody reopened it" is worth nothing to
        // whoever reads it next.
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return response()->json([
            'data' => $reopen($period, $validated['reason'], $request->user()?->getKey()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FiscalPeriod $period): array
    {
        $year = $period->fiscalYear;

        return [
            'id' => (int) $period->getKey(),
            'ulid' => $period->ulid,
            'name' => $period->name,
            'period_no' => (int) $period->period_no,
            'starts_on' => $period->starts_on?->toDateString(),
            'ends_on' => $period->ends_on?->toDateString(),
            'status' => $period->status->value,
            // Closed and its year not locked: the state in which the Owner
            // could reopen it. A locked year is reported as not reopenable
            // because the action refuses that case, and a calendar that
            // offered it would be lying.
            'can_be_reopened' => $period->status->canBeReopened()
                && $year?->status !== FiscalStatus::Locked,
            'closed_at' => $period->closed_at?->toIso8601String(),
            'fiscal_year' => $year === null ? null : [
                'id' => (int) $year->getKey(),
                'ulid' => $year->ulid,
                'name' => $year->name,
                'status' => $year->status->value,
            ],
        ];
    }

    private function findByUlid(Request $request, string $ulid): FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
