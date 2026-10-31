<?php

namespace App\Domain\Files\Contracts;

use App\Domain\Files\Exceptions\UploadRefusedException;

/**
 * The hook a real antivirus plugs into.
 *
 * This build ships the placeholder below and binds it in AppServiceProvider.
 * Installing a scanner means writing one class that implements this interface
 * and rebinding it — nothing else in the upload path changes, because the
 * validator calls the interface and not the placeholder.
 *
 * It is passed the path the file is sitting at before anything is written to
 * the store and the name the caller gave it, which is all a scanner that
 * reads a stream needs.
 */
interface VirusScanner
{
    /**
     * @throws UploadRefusedException when the scanner condemns the file
     */
    public function scan(string $path, string $originalName): void;
}
