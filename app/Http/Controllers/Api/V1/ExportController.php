<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Enums\ExportFormat;
use App\Domain\Reporting\Enums\ExportStatus;
use App\Domain\Reporting\Exports\ReportExportRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateExport;
use App\Models\Export;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report exports: ask for a report as a file, poll it, download it.
 *
 * Three routes and one job. A request here does not render anything: it writes
 * an exports row, hands the rendering to a queued worker, and answers with a
 * record to poll. That is the point of the surface — the reports worth
 * exporting are the heavy ones, and reading a year of the ledger should not
 * hold a request open. The record says where the render has got to, so the
 * caller learns that a report is still being built rather than waiting on a
 * blank.
 *
 * The file never becomes a URL of its own. It is written to a disk outside
 * public, and the only way to read it is the signed, expiring download route
 * below, which checks the signature, the caller's membership of the company
 * that owns it, and the permission to read the report it holds. A file that
 * has outlived its retention window is gone, and the route says so rather than
 * serving a blank.
 *
 * Nothing here computes a figure. Which reports exist and how each is built is
 * the registry's answer; this class validates the request, authorises it
 * against the report it names, and serialises the record.
 */
class ExportController extends Controller
{
    /**
     * The exports this company has asked for, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Export::class);

        $exports = Export::query()
            ->where('company_id', $this->companyId($request))
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Export $export): array => $this->present($export),
                $exports->items()
            ),
            'meta' => [
                'current_page' => $exports->currentPage(),
                'per_page' => $exports->perPage(),
                'total' => $exports->total(),
                'last_page' => $exports->lastPage(),
            ],
        ]);
    }

    /**
     * Ask for a report as a file.
     *
     * The type is checked against the registry before anything is written, so
     * a record can never name a report the pipeline cannot build. The
     * permission is then checked against the definition rather than against
     * the controller, because the definition is what says which right the
     * report it holds is guarded by: a report behind a narrower right than the
     * one above is refused here rather than rendered and refused later.
     *
     * The answer is 202 rather than 201: the record has been created, but the
     * thing the caller actually asked for — the file — has not been made yet.
     */
    public function store(Request $request, ReportExportRegistry $registry): JsonResponse
    {
        $this->authorize('create', Export::class);

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in($registry->types())],
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare' => ['sometimes', 'boolean'],
        ]);

        $definition = $registry->resolve($data['type']);

        abort_unless(
            $request->user()?->can($definition->permission) ?? false,
            403,
            sprintf('You may not export the %s report.', $definition->label),
        );

        $parameters = $registry->parametersFor($data['type'], $data);

        // A range that runs backwards would silently report nothing, exactly as
        // it would on the report endpoint. Refused here so the file that comes
        // back is a file the caller asked for.
        if (isset($parameters['from'], $parameters['to']) && $parameters['from'] > $parameters['to']) {
            throw ValidationException::withMessages([
                'from' => ['The from date must not be later than the to date.'],
            ]);
        }

        $export = Export::create([
            'company_id' => $this->companyId($request),
            'user_id' => (int) $request->user()->getKey(),
            'type' => $data['type'],
            'format' => $data['format'],
            'status' => ExportStatus::Queued,
            'parameters' => $parameters,
            'disk' => (string) config('nexas.exports.disk'),
        ]);

        GenerateExport::dispatch((int) $export->getKey());

        return response()->json(['data' => $this->present($export)], 202);
    }

    /**
     * Where the render has got to, and — once it is done and still within its
     * retention window — a short-lived signed link to the file.
     */
    public function show(Request $request, string $export): JsonResponse
    {
        $record = $this->resolveExport($request, $export);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record)]);
    }

    /**
     * Serve the finished file.
     *
     * Behind the `signed` middleware as well as authentication and company
     * membership, so a link cannot be guessed and cannot be replayed once it
     * has expired. The signature is checked before the policy is reached; what
     * the policy adds is that the member holding a valid link must also be a
     * member of the company the file belongs to and hold the right to read the
     * report it holds, which a leaked link on its own would not prove.
     *
     * A row that is not finished, has expired, or whose file is gone is a 404:
     * there is nothing here to read, and saying which of the three it is would
     * be telling the caller about a file they cannot have.
     */
    public function download(Request $request, string $export): StreamedResponse
    {
        $record = $this->resolveExport($request, $export);

        $this->authorize('download', $record);

        abort_unless(
            $record->isDownloadable() && Storage::disk($record->disk)->exists((string) $record->path),
            404,
            'This export is not ready, or its file has passed its retention window.',
        );

        return Storage::disk($record->disk)->download(
            (string) $record->path,
            $record->fileName(),
            ['Content-Type' => $record->format->mimeType()],
        );
    }

    /**
     * Resolved inside the caller's company in the same query that reads it, so
     * naming another tenant's export is a 404 rather than a 403 that would
     * confirm it exists somewhere else.
     */
    private function resolveExport(Request $request, string $export): Export
    {
        return Export::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $export)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Export $export): array
    {
        return [
            'id' => $export->ulid,
            'type' => $export->type,
            'format' => $export->format->value,
            'status' => $export->status->value,
            'parameters' => $export->parameters,
            'file_name' => $export->file_name,
            'size_bytes' => $export->size_bytes,
            'error' => $export->error,
            'created_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'download_url' => $export->isDownloadable() ? $this->downloadUrl($export) : null,
        ];
    }

    /**
     * A signed link that stops working on its own, well before the file itself
     * is pruned: the file outliving its own link is what signing the link is
     * for. Relative, so the link is the same whichever host the caller reached
     * the application on.
     */
    private function downloadUrl(Export $export): string
    {
        return URL::temporarySignedRoute(
            'api.v1.exports.download',
            now()->addMinutes((int) config('nexas.exports.link_ttl_minutes')),
            ['export' => $export->ulid],
            absolute: false,
        );
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
