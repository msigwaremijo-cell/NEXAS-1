<?php

namespace App\Domain\Files\Services;

use App\Domain\Files\Contracts\VirusScanner;
use App\Domain\Files\DTOs\ValidatedUpload;
use App\Domain\Files\Exceptions\UploadRefusedException;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The one gate every uploaded file passes through.
 *
 * Bank statements, filed documents and anything added later all come here, so
 * there is a single answer to "what may be uploaded" rather than one per
 * surface that can drift apart.
 *
 * Four things are checked, in the order a caller would want them reported:
 *
 *   1. size, against a ceiling that is configuration rather than a constant;
 *   2. the extension, against the types this deployment accepts;
 *   3. the contents, against what that extension is allowed to actually be —
 *      read with finfo rather than trusted from the name;
 *   4. the scanner hook, which is a placeholder in this build.
 *
 * The third check is the one that matters. A file is accepted by what is
 * inside it and not by what it is called, so a text file renamed to `.pdf` is
 * refused even though its name says otherwise. The vocabulary below is what
 * "inside it" means for each extension, and it is written from what finfo
 * really reports on this platform rather than from what the format's
 * specification says it should: a one-line CSV sniffs as plain text, and an
 * XLSX is a ZIP archive and reports as one. Getting those wrong would either
 * refuse honest files or wave through ones whose contents are not what they
 * claim.
 *
 * Nothing here writes anything. It answers a question about a file that is
 * still in the caller's request, and hands back a description of it that the
 * caller can then store.
 */
final class UploadValidator
{
    /**
     * extension => the content types that extension's contents may be.
     *
     * Kept here rather than in configuration on purpose: this is a statement
     * about the file formats themselves, not about a deployment, so it is the
     * same everywhere. What a deployment chooses is which of these extensions
     * it accepts, which is `nexas.attachments.allowed_extensions`.
     *
     * @var array<string, list<string>>
     */
    private const CONTENT_TYPES = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        // A CSV sniffed by finfo is text/csv when it holds more than one line
        // and text/plain when it holds one, because a single line of text with
        // commas in it is indistinguishable from prose. Both are accepted, so
        // a one-row export is not refused for looking like what it also is.
        'csv' => ['text/csv', 'text/plain'],
        // An XLSX is a ZIP archive: it is not an OOXML media type at the file
        // level, and finfo reports it as application/zip. Checking for
        // anything else would refuse every honest spreadsheet.
        'xlsx' => ['application/zip'],
    ];

    public function __construct(private readonly VirusScanner $scanner) {}

    /**
     * @param  string  $purpose  named in every refusal, so a caller holding
     *                           several files knows which one was meant
     * @param  list<string>|null  $extensions  the types this surface accepts;
     *                                         null means the deployment default
     * @param  int|null  $maxKilobytes  the ceiling for this surface; null means
     *                                  the deployment default
     *
     * @throws UploadRefusedException
     */
    public function validate(
        UploadedFile $file,
        string $purpose,
        ?array $extensions = null,
        ?int $maxKilobytes = null,
    ): ValidatedUpload {
        if (! $file->isValid()) {
            throw UploadRefusedException::notAFile($purpose);
        }

        $name = $this->safeName($file->getClientOriginalName());
        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw UploadRefusedException::empty($purpose);
        }

        $limit = $maxKilobytes ?? (int) config('nexas.attachments.max_kilobytes');

        if ($size > $limit * 1024) {
            throw UploadRefusedException::tooLarge($purpose, $name, $limit);
        }

        $allowed = $this->allowed($extensions);
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw UploadRefusedException::typeNotAllowed($purpose, $name, $extension, $allowed);
        }

        $expected = self::CONTENT_TYPES[$extension] ?? [];
        $sniffed = $this->sniff($file);

        if (! in_array($sniffed, $expected, true)) {
            throw UploadRefusedException::contentsDoNotMatch($name, $extension, $sniffed, $expected);
        }

        // The hook. In this build it inspects nothing; see NullVirusScanner.
        $this->scanner->scan((string) $file->getRealPath(), $name);

        return new ValidatedUpload(
            originalName: $name,
            extension: $extension,
            mimeType: $sniffed,
            sizeBytes: $size,
            checksum: (string) hash_file('sha256', (string) $file->getRealPath()),
            storedName: (string) Str::ulid().'.'.$extension,
        );
    }

    /**
     * The extension allow-list for this call: what the surface asked for, or
     * what the deployment configured.
     *
     * @param  list<string>|null  $extensions
     * @return list<string>
     */
    private function allowed(?array $extensions): array
    {
        $extensions ??= (array) config('nexas.attachments.allowed_extensions');

        return array_values(array_unique(array_map(
            static fn (mixed $extension): string => strtolower((string) $extension),
            $extensions,
        )));
    }

    /**
     * What the file's bytes really are, as opposed to what its name says.
     */
    private function sniff(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            return '';
        }

        return (string) (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    }

    /**
     * The name without any directory in front of it.
     *
     * The stored name is a ULID and the path is built from it, so a caller's
     * name never reaches the store; what it does reach is a Content-Disposition
     * header on the way back, which is why the directories are stripped here
     * rather than trusted not to be there.
     */
    private function safeName(string $name): string
    {
        return basename(str_replace('\\', '/', $name));
    }
}
