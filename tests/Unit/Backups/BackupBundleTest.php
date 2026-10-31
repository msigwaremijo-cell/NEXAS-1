<?php

use App\Domain\Backups\Exceptions\BackupRefusedException;
use App\Domain\Backups\Exceptions\BackupsException;
use App\Domain\Backups\Services\BackupBundle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// The container a backup travels in: the database dump and the filed documents
// in one file that can be walked forwards. It is deliberately not a tar or a
// zip, so what these tests pin is the property that matters — a reader never
// trusts a length it has not checked. A part whose bytes do not hash to what
// the header recorded is refused, a part named in a way that would write
// outside the folder is refused before a byte lands, and a file cut short is
// refused as not a bundle at all.
//
// Nothing here touches the database.
function nxBundleDir(): string
{
    $directory = sys_get_temp_dir().'/nexas_bundle_test_'.Str::ulid();

    File::ensureDirectoryExists($directory);

    return $directory;
}

/**
 * A source file for a part, written flat because the directory a part's name
 * implies does not exist yet — that is the extractor's job.
 */
function nxBundleSource(string $directory, string $name, string $contents): string
{
    $path = $directory.'/src_'.str_replace('/', '_', $name);

    file_put_contents($path, $contents);

    return $path;
}

/**
 * Write a bundle of the given parts (name => contents) and answer its path.
 *
 * @param  array<string, string>  $parts
 */
function nxBundleWrite(string $directory, array $parts, string $name = 'bundle'): string
{
    $sources = [];

    foreach ($parts as $part => $contents) {
        $sources[$part] = nxBundleSource($directory, $part, $contents);
    }

    $path = $directory.'/'.$name;

    (new BackupBundle)->write($sources, $path);

    return $path;
}

/*
|--------------------------------------------------------------------------
| Out and back
|--------------------------------------------------------------------------
*/

it('round trips every part and describes them in the manifest', function () {
    $directory = nxBundleDir();

    try {
        $parts = [
            'database.sql' => "-- the dump\nSELECT 1;\n",
            'attachments/01J0/scan.pdf' => str_repeat('%PDF-1.4 ', 500),
            'statements/2026/10/bank.csv' => "date,amount\n2026-10-01,100\n",
        ];

        $bundle = nxBundleWrite($directory, $parts);
        $manifest = (new BackupBundle)->readManifest($bundle);

        expect($manifest)->toHaveCount(3)
            ->and(array_map(fn ($part) => $part->name, $manifest))->toBe(array_keys($parts))
            ->and($manifest[0]->size)->toBe(strlen($parts['database.sql']))
            ->and($manifest[0]->checksum)->toBe(hash('sha256', $parts['database.sql']))
            ->and($manifest[2]->name)->toBe('statements/2026/10/bank.csv');

        // Reading the header alone reports the header's order, not the order
        // the parts happened to be written in.
        $out = $directory.'/out';
        $extracted = (new BackupBundle)->extract($bundle, $out);

        expect($extracted)->toEqual($manifest);

        foreach ($parts as $name => $contents) {
            $landed = $out.'/'.str_replace('/', DIRECTORY_SEPARATOR, $name);

            expect(is_file($landed))->toBeTrue()
                ->and(file_get_contents($landed))->toBe($contents);
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

it('carries parts of any size, including one larger than a single chunk', function () {
    $directory = nxBundleDir();

    try {
        // A real dump is megabytes. The chunk boundary is 1 MiB, so this part
        // crosses it and proves the copy loop is not silently capped.
        $large = str_repeat('a dump line with a little padding in it. ', 40000);

        $bundle = nxBundleWrite($directory, ['database.sql' => $large]);
        $out = $directory.'/out';

        (new BackupBundle)->extract($bundle, $out);

        expect(file_get_contents($out.'/database.sql'))->toBe($large);
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| Refusals — a length the reader has not checked
|--------------------------------------------------------------------------
*/

it('refuses a part whose bytes no longer match the checksum recorded for it', function () {
    $directory = nxBundleDir();

    try {
        $bundle = nxBundleWrite($directory, ['database.sql' => str_repeat('A', 400)]);

        // The header is six bytes of magic, one of version, four of part count,
        // then per part two of name length, the name, eight of size and the
        // thirty-two byte checksum.
        $contentStart = 6 + 1 + 4 + 2 + strlen('database.sql') + 8 + 32;

        $bytes = (string) file_get_contents($bundle);
        $bytes[$contentStart + 5] = 'B';
        file_put_contents($bundle, $bytes);

        expect(fn () => (new BackupBundle)->extract($bundle, $directory.'/out'))
            ->toThrow(BackupRefusedException::class, 'does not match the checksum recorded for it');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses a bundle that has been cut short', function () {
    $directory = nxBundleDir();

    try {
        $bundle = nxBundleWrite($directory, ['database.sql' => str_repeat('A', 400)]);

        // Fifty bytes off the end. The header still declares four hundred, so
        // the file could not possibly hold what it says it holds.
        file_put_contents($bundle, substr((string) file_get_contents($bundle), 0, -50));

        expect(fn () => (new BackupBundle)->extract($bundle, $directory.'/out'))
            ->toThrow(BackupRefusedException::class, 'is not a Nexas backup');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses a file that is not a bundle', function () {
    $directory = nxBundleDir();

    try {
        file_put_contents($directory.'/bundle', 'not a bundle at all, just some text');

        expect(fn () => (new BackupBundle)->extract($directory.'/bundle', $directory.'/out'))
            ->toThrow(BackupRefusedException::class, 'is not a Nexas backup');
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| Refusals — a name that would reach outside the folder
|--------------------------------------------------------------------------
*/

it('refuses to write a part whose name would escape the folder it unpacks into', function () {
    $directory = nxBundleDir();

    try {
        $source = nxBundleSource($directory, 'payload', 'payload');

        $unsafe = ['../escape.sql', '/absolute.sql', 'a/../../escape.sql', "nul\0byte", 'C:\\windows\\x'];

        foreach ($unsafe as $index => $name) {
            expect(fn () => (new BackupBundle)->write([$name => $source], $directory.'/bundle-'.$index))
                ->toThrow(BackupRefusedException::class, 'would write outside the folder');
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses a bundle that names a part outside its own folder, before unpacking any of it', function () {
    $directory = nxBundleDir();

    try {
        // A bundle crafted by hand, because the writer would never produce one:
        // a single part named as a path out of the destination.
        $name = '../escape.sql';
        $content = 'payload';

        $bundle = "NXBN1\0".chr(1).pack('N', 1)
            .pack('n', strlen($name)).$name
            .pack('J', strlen($content)).hex2bin(hash('sha256', $content))
            .$content;

        file_put_contents($directory.'/bundle', $bundle);

        expect(fn () => (new BackupBundle)->extract($directory.'/bundle', $directory.'/out'))
            ->toThrow(BackupRefusedException::class, 'would write outside the folder');

        // The refusal came before anything was written, so the destination was
        // never even created.
        expect(is_dir($directory.'/out'))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses a source file that is not there', function () {
    $directory = nxBundleDir();

    try {
        expect(fn () => (new BackupBundle)->write(
            ['database.sql' => $directory.'/missing.sql'],
            $directory.'/bundle'
        ))->toThrow(BackupsException::class, 'could not be written to the backup disk');
    } finally {
        File::deleteDirectory($directory);
    }
});
