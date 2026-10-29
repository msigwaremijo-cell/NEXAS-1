<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Actions\CloseFiscalYear;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ending a fiscal year.
 *
 * Nothing is decided here. What the closing journal holds, which periods shut
 * with the year and in what order are accounting rules and live in
 * app/Domain/Accounting/Actions/CloseFiscalYear. This class resolves the year
 * the caller named, authorises the close against it, and serialises the answer.
 *
 * The year is looked up scoped to the company the `company` middleware already
 * proved the caller belongs to, so naming another tenant's ULID is a 404 and
 * not a close.
 */
class FiscalYearController extends Controller
{
    public function close(Request $request, string $fiscalYear, CloseFiscalYear $close): JsonResponse
    {
        $year = $this->findByUlid($request, $fiscalYear);

        $this->authorize('close', $year);

        return response()->json([
            'data' => $close($year, $request->user()?->getKey()),
        ]);
    }

    private function findByUlid(Request $request, string $ulid): FiscalYear
    {
        return FiscalYear::query()
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
