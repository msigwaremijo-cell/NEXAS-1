<?php

namespace App\Domain\Files\Support;

use App\Domain\Files\Contracts\VirusScanner;

/**
 * PLACEHOLDER — no antivirus is installed in this build.
 *
 * This class exists so the hook is real and visible rather than a comment
 * somebody has to remember to act on: the validator already calls it on every
 * upload, so a deployment that installs a scanner swaps this binding and the
 * scan starts happening on the very next request.
 *
 * Until then it does nothing and says so. It deliberately does not pretend to
 * have checked anything, because a hook that reports "clean" without looking
 * is worse than one that is honestly absent: it would let a deployment believe
 * it had a scanner it never installed.
 *
 * To install one: implement VirusScanner, bind it over this class in
 * AppServiceProvider, and have it throw UploadRefusedException::unsafe() when
 * it finds something.
 */
final class NullVirusScanner implements VirusScanner
{
    public function scan(string $path, string $originalName): void
    {
        // No scanner is installed. Nothing is inspected, and nothing is
        // claimed. See the class docblock.
    }
}
